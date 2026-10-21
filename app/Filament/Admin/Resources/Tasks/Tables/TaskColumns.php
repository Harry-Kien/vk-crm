<?php

namespace App\Filament\Admin\Resources\Tasks\Tables;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\RelationManagers\TasksRelationManager;
use App\Models\Task;
use App\Models\User;
use App\Support\Search\LetterD;
use Closure;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Cột của mọi bảng việc giao nội bộ (làn tk, Task 1) — trang "Công việc", tab "Công việc" của một vụ,
 * ba khối "Việc của tôi". Một chỗ, để bốn bảng nói cùng một ngôn ngữ (cùng màu hạn, cùng nhãn).
 *
 * Chỉ hiển thị: không cột nào là cổng. Bảng nào cũng đọc từ `Task::visibleTo()`, nên mọi giá trị ở đây
 * (mã vụ, tên người) là của một việc người xem đã được thấy. Không cột tên khách: việc nội bộ không cần
 * nó, và một cột ít hơn là một chỗ lộ ít hơn.
 *
 * Kiểu dáng viết bằng `style=` trên biến màu của Filament — dự án không có bước dựng CSS, một lớp
 * Tailwind viết tay tô ra đúng số không (xem `DeadlinesRelationManager`).
 *
 * # Hai bố cục trên cùng một bảng (kiểm toán màn hình 2026-10-21, mục 1–2)
 *
 * Đo trên điện thoại 390px trước bản sửa: bảng rộng 1132px, cột "Việc" 77px, cột "Hạn" ở x=472, nút "Đã nhận" ở
 * x=782 — phải vuốt ngang hai màn hình mới tới nút (đúng đường người dùng tới từ thông báo đẩy). Nay:
 *
 *  - dưới {@see self::FULL_FROM} chỉ MỘT cột, "summary" ({@see self::renderSummary()}): tiêu đề; trạng thái, mức
 *    ưu tiên (khi không phải "Thường"), hạn; mã vụ · người giao (hay người nhận); dòng "cần giao lại" khi có. Chỉ
 *    một nút chính đứng cạnh (`ProvidesTaskActions::taskRecordActions()`);
 *  - từ {@see self::FULL_FROM} trở lên các cột chi tiết như cũ, cột "Việc" rộng tối thiểu 15rem (ở 1366px nó từng bị
 *    bóp còn mỗi chữ một dòng, dòng cao 180px; 15rem chứ không 16rem: ở 1280px tab "Tất cả" vừa khít 896px), tiêu đề
 *    các cột hẹp được xuống dòng, trạng thái nằm dưới tiêu đề việc ({@see self::renderStatus()}) thay cho một cột
 *    riêng. Đo lại sau bản sửa: tab "Tất cả" rộng đúng bằng vùng nội dung ở 1280px (896) và 1366px (982).
 *
 * Hai lớp `xl:fi-hidden` / `xl:fi-visible` là của chính Filament (có trong `theme.css` đã biên dịch) — không lớp
 * Tailwind nào viết tay. Cột luôn là chính người xem (người nhận ở "Chờ tôi nhận"/"Đang làm", người giao ở "Tôi
 * đã giao") ẩn qua hai closure của {@see self::make()}.
 */
final class TaskColumns
{
    /**
     * Từ cỡ màn hình này trở lên: bảng đủ cột; dưới đó: một cột tóm tắt (docblock lớp, "Hai bố cục"). `xl` (1280px),
     * không `md`: từ `lg` thanh menu trái chiếm ~384px, nên ở 1024–1279px vùng nội dung chỉ 640–896px — đo trên trình
     * duyệt, tám cột của tab "Tất cả" cần ~950px; ở 768–1023px (không thanh menu) cũng không đủ.
     */
    public const FULL_FROM = 'xl';

    /** Trong ngần này ngày tới hạn thì tô vàng — một việc nội bộ ngắn hơn một mốc tố tụng (7 ngày). */
    public const DUE_SOON_DAYS = 2;

    /** Mã của `Task::reassignmentReason()` ghi dưới tên NGƯỜI NHẬN — nhãn `tasks.columns.assignee_<mã>`. */
    private const ASSIGNEE_REASONS = ['inactive', 'off_team', 'seat_declined', 'off_access'];

    /** Mã của `Task::reassignmentReason()` ghi dưới tên NGƯỜI GIAO — nhãn `tasks.columns.<mã>`. */
    private const ASSIGNER_REASONS = ['assigner_gone', 'assigner_off_access'];

    /**
     * Quan hệ nạp sẵn cho mọi bảng việc: người nhận/người giao KỂ CẢ đã nghỉ (vẫn hiện tên), vụ cùng đội
     * ngũ của nó (để `TaskPolicy::view` → `MatterPolicy::view` trả lời trong bộ nhớ, không một truy vấn
     * mỗi nút mỗi dòng).
     */
    public static function eagerLoad(Builder $query): Builder
    {
        // `withLastRestoredAt()`: dòng "Chờ nhận từ …" đọc `Task::awaitingSince()` không một truy vấn mỗi dòng.
        return $query->withLastRestoredAt()->with([
            'matter.team',
            'assignee' => fn (BelongsTo $assignee): BelongsTo => $assignee->withTrashed(),
            'assigner' => fn (BelongsTo $assigner): BelongsTo => $assigner->withTrashed(),
        ]);
    }

    /**
     * @param  (Closure(): bool)|null  $hideAssignee  `true` khi cột "Người nhận" luôn là chính người xem (tab/khối
     *                                                "Chờ tôi nhận", "Đang làm") — đọc lúc vẽ, nên đổi tab là đổi theo
     * @param  (Closure(): bool)|null  $hideAssigner  như trên cho cột "Người giao" (tab/khối "Tôi đã giao")
     * @return list<TextColumn>
     */
    public static function make(bool $withMatter = true, ?Closure $hideAssignee = null, ?Closure $hideAssigner = null): array
    {
        return array_values(array_filter([
            TextColumn::make('summary')
                ->label(__('tasks.columns.title'))
                ->state(fn (Task $record): string => $record->title)
                ->formatStateUsing(fn (Task $record): HtmlString => self::renderSummary($record))
                ->html()
                ->wrap()
                ->hiddenFrom(self::FULL_FROM),
            TextColumn::make('title')
                ->label(__('tasks.columns.title'))
                ->weight('semibold')
                // Trạng thái nằm ngay dưới tiêu đề (chip + lý do trả lại / ghi chú hoàn thành / chờ nhận từ bao giờ), không
                // thành một cột riêng: đo ở 1280–1366px, tám cột không vừa vùng nội dung (docblock lớp, "Hai bố cục").
                ->description(fn (Task $record): HtmlString => self::renderStatus($record))
                ->wrap()
                ->extraHeaderAttributes(['style' => 'min-width:15rem'])
                ->visibleFrom(self::FULL_FROM)
                // Gộp integ-fa (làn fa3): "đ" như "d" — chỉ thu hẹp truy vấn của bảng, `visibleTo()` không đổi.
                ->searchable(query: LetterD::searchable('title')),
            $withMatter ? TextColumn::make('matter.code')
                ->label(__('tasks.columns.matter'))
                ->placeholder(__('tasks.columns.general'))
                ->url(fn (Task $record): ?string => $record->matter_id === null ? null : self::matterTabUrl($record->matter_id))
                ->visibleFrom(self::FULL_FROM)
                ->searchable(query: LetterD::searchable('matter.code')) : null,
            TextColumn::make('assignee.name')
                ->label(__('tasks.columns.assignee'))
                ->description(fn (Task $record): ?string => in_array($record->reassignmentReason(), self::ASSIGNEE_REASONS, true)
                    ? __('tasks.columns.assignee_'.$record->reassignmentReason())
                    : null)
                ->color(fn (Task $record): ?string => in_array($record->reassignmentReason(), self::ASSIGNEE_REASONS, true) ? 'danger' : null)
                ->wrap()
                ->wrapHeader()
                ->visibleFrom(self::FULL_FROM)
                ->hidden($hideAssignee ?? false)
                // Kiểm toán màn hình 2026-10-21 (mục 7): tìm theo tên người, kể cả người đã nghỉ.
                ->searchable(query: self::searchPerson('assignee')),
            // Vòng sửa 1 (I1): việc bị trả lại mà người giao đã nghỉ (vòng sửa cuối 1, F4: hay không còn mở được
            // hồ sơ) — "Cần giao lại" ghi ở cột người giao.
            TextColumn::make('assigner.name')
                ->label(__('tasks.columns.assigner'))
                ->description(fn (Task $record): ?string => in_array($record->reassignmentReason(), self::ASSIGNER_REASONS, true)
                    ? __('tasks.columns.'.$record->reassignmentReason())
                    : null)
                ->color(fn (Task $record): ?string => in_array($record->reassignmentReason(), self::ASSIGNER_REASONS, true) ? 'danger' : null)
                ->wrap()
                ->wrapHeader()
                ->visibleFrom(self::FULL_FROM)
                ->hidden($hideAssigner ?? false)
                ->searchable(query: self::searchPerson('assigner'))
                ->toggleable(),
            TextColumn::make('due_date')
                ->label(__('tasks.columns.due_date'))
                ->html()
                ->formatStateUsing(fn (Task $record): HtmlString => self::renderDue($record))
                ->placeholder(__('tasks.due.none'))
                ->wrap()
                ->wrapHeader()
                ->visibleFrom(self::FULL_FROM)
                ->sortable(),
            TextColumn::make('priority')
                ->label(__('tasks.columns.priority'))
                ->badge()
                ->formatStateUsing(fn (TaskPriority $state): string => $state->label())
                ->color(fn (TaskPriority $state): string => $state->color())
                ->wrapHeader()
                ->visibleFrom(self::FULL_FROM)
                // Kiểm toán màn hình 2026-10-21 (mục 3): tăng dần = Gấp trước (`Task::priorityRankSql()`).
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                    ->orderByRaw(Task::priorityRankSql().' '.($direction === 'desc' ? 'desc' : 'asc'))),
            TextColumn::make('assigned_at')
                ->label(__('tasks.columns.assigned_at'))
                ->dateTime('H:i d/m/Y')
                ->visibleFrom(self::FULL_FROM)
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    /**
     * Tìm theo tên NGƯỜI (`assignee`/`assigner`) — kiểm toán màn hình 2026-10-21 (mục 7): gõ "Ngô Thị Lan" ra việc
     * của chị Lan. Không dấu "đ" như mọi ô tìm khác ({@see LetterD::whereContains()}); kể cả người đã nghỉ (xoá mềm —
     * bảng vẫn hiện tên họ, nên tìm cũng phải ra). Chỉ THU HẸP truy vấn của bảng: `visibleTo()` không đổi, nên tên
     * một người không bao giờ kéo ra việc của một hồ sơ người xem không thấy.
     *
     * @return Closure(Builder, string): Builder
     */
    private static function searchPerson(string $relation): Closure
    {
        return static fn (Builder $query, string $search): Builder => $query->whereHas(
            $relation,
            fn (Builder $person): Builder => LetterD::whereContains($person->withTrashed(), $person->qualifyColumn('name'), $search),
        );
    }

    /**
     * Ô tóm tắt của MỘT việc — cột duy nhất dưới {@see self::FULL_FROM} (docblock lớp, "Hai bố cục"). Mọi chữ người dùng gõ (tiêu đề),
     * tên người và mã vụ đi qua `e()`; màu chỉ qua biến CSS của Filament. Cùng câu chữ với các cột chi tiết: trạng
     * thái `TaskStatus::label()`, hạn {@see self::dueParts()}, lý do "cần giao lại" `tasks.columns.*`.
     */
    public static function renderSummary(Task $task): HtmlString
    {
        $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent)';
        $viewer = Auth::user();
        $viewerId = $viewer instanceof User ? $viewer->getKey() : null;

        $who = match (true) {
            $viewerId !== null && $task->assignee_id === $viewerId => __('tasks.summary.from', ['name' => (string) $task->assigner?->name]),
            $viewerId !== null && $task->assigned_by === $viewerId => __('tasks.summary.to', ['name' => (string) $task->assignee?->name]),
            default => __('tasks.summary.between', ['from' => (string) $task->assigner?->name, 'to' => (string) $task->assignee?->name]),
        };

        [$date, $text, $colour] = self::dueParts($task);
        $due = $date === null
            ? sprintf('<span>%s</span>', e($text))
            : sprintf('<span style="font-weight:600;color:%s">%s · %s</span>', $colour, e($date), e($text));

        $chips = self::chip($task->status->color(), $task->status->label());

        if ($task->priority !== TaskPriority::Normal) {
            $chips .= self::chip($task->priority->color(), $task->priority->label());
        }

        $reason = $task->reassignmentReason();
        $flag = match (true) {
            in_array($reason, self::ASSIGNEE_REASONS, true) => __('tasks.columns.assignee_'.$reason),
            in_array($reason, self::ASSIGNER_REASONS, true) => __('tasks.columns.'.$reason),
            default => null,
        };

        // Dòng 1: tiêu đề, cả bề ngang. Dòng 2: trạng thái, ưu tiên (khi không phải "Thường"), hạn. Dòng 3: mã vụ · ai
        // giao cho ai. Dòng 4 (khi có): vì sao cần giao lại.
        return new HtmlString(sprintf(
            '<div data-vk-task-summary style="display:flex;flex-direction:column;gap:0.25rem;min-width:0;white-space:normal">'
                .'<span style="font-weight:600;overflow-wrap:anywhere">%s</span>'
                .'<span style="display:flex;flex-wrap:wrap;align-items:center;gap:0.25rem 0.375rem;font-size:0.8125rem">%s%s</span>'
                .'<span style="font-size:0.8125rem;%s">%s · %s</span>%s</div>',
            e($task->title),
            $chips,
            $due,
            $muted,
            e($task->matter?->code ?? __('tasks.columns.general')),
            e($who),
            $flag === null ? '' : sprintf('<span style="font-size:0.75rem;font-weight:600;color:var(--danger-600)">%s</span>', e($flag)),
        ));
    }

    /**
     * Trạng thái dưới tiêu đề ở bảng đủ cột: chip `TaskStatus::label()` và — như cột "Trạng thái" trước đây — lý do trả
     * lại, ghi chú hoàn thành, hay "chờ nhận từ …". Chữ người dùng gõ qua `e()`.
     */
    public static function renderStatus(Task $task): HtmlString
    {
        $note = match ($task->status) {
            TaskStatus::Declined => $task->decline_reason,
            TaskStatus::Completed => $task->completion_note,
            // Kiểm toán 2026-10-21 (Q1, làn nhắc việc): cùng mốc "bắt đầu chờ nhận" của lịch nhắc — lần khôi phục muộn hơn mốc giao.
            TaskStatus::Pending => ($since = $task->awaitingSince()) === null ? null : __('tasks.waiting', ['since' => $since->diffForHumans()]),
            default => null,
        };

        return new HtmlString(sprintf(
            '<span style="display:flex;flex-direction:column;align-items:flex-start;gap:0.125rem;margin-top:0.25rem;font-weight:400">%s%s</span>',
            self::chip($task->status->color(), $task->status->label()),
            blank($note) ? '' : sprintf('<span style="font-size:0.8125rem;white-space:pre-line;color:color-mix(in srgb, var(--gray-500) 95%%, transparent)">%s</span>', e($note)),
        ));
    }

    /** Một chip màu (trạng thái, ưu tiên) — bản nội tuyến của badge các cột (cùng nhãn, cùng màu `color()` của enum). */
    private static function chip(string $colour, string $label): string
    {
        return sprintf(
            '<span style="flex:none;font-size:0.75rem;font-weight:600;line-height:1.25rem;padding:0 0.375rem;border-radius:0.375rem;'
                .'color:var(--%1$s-600);background:color-mix(in srgb, var(--%1$s-500) 12%%, transparent)">%2$s</span>',
            $colour,
            e($label),
        );
    }

    /**
     * Ô hạn: ngày, và ngay dưới là còn bao lâu — đỏ khi quá hạn hoặc hôm nay, vàng trong
     * {@see self::DUE_SOON_DAYS} ngày, xám cho phần còn lại và cho mọi việc đã đóng (một dòng đỏ không
     * còn việc gì để làm dạy người ta bỏ qua màu đỏ — cùng lý lẽ `DeadlinesRelationManager::renderDueDate()`).
     */
    public static function renderDue(Task $task): HtmlString
    {
        [$date, $text, $colour] = self::dueParts($task);

        if ($date === null) {
            return new HtmlString(e($text));
        }

        return new HtmlString(sprintf(
            '<span style="font-weight:600">%s</span><span style="display:block;font-size:0.75rem;font-weight:600;color:%s">%s</span>',
            e($date),
            $colour,
            e($text),
        ));
    }

    /**
     * Ngày hạn (`d/m/Y`, `null` khi không hạn), câu "còn bao lâu" và màu của nó — luật màu của {@see self::renderDue()},
     * dùng chung với ô tóm tắt ({@see self::renderSummary()}).
     *
     * @return array{0: ?string, 1: string, 2: string}
     */
    private static function dueParts(Task $task): array
    {
        $muted = 'color-mix(in srgb, var(--gray-500) 90%, transparent)';

        if ($task->due_date === null) {
            return [null, __('tasks.due.none'), $muted];
        }

        $daysLeft = (int) today()->diffInDays($task->due_date, false);

        [$text, $colour] = match (true) {
            ! $task->isOpen() => [__('tasks.due.done'), $muted],
            $daysLeft < 0 => [__('tasks.due.overdue', ['count' => abs($daysLeft)]), 'var(--danger-600)'],
            $daysLeft === 0 => [__('tasks.due.today'), 'var(--danger-600)'],
            $daysLeft === 1 => [__('tasks.due.tomorrow'), 'var(--warning-600)'],
            $daysLeft <= self::DUE_SOON_DAYS => [__('tasks.due.in_days', ['count' => $daysLeft]), 'var(--warning-600)'],
            default => [__('tasks.due.in_days', ['count' => $daysLeft]), $muted],
        };

        return [$task->due_date->format('d/m/Y'), $text, $colour];
    }

    /** Trang vụ việc, mở thẳng tab "Công việc". */
    public static function matterTabUrl(int $matterId): string
    {
        $parameters = ['record' => $matterId];
        $tab = array_search(TasksRelationManager::class, MatterResource::getRelations(), true);

        if ($tab !== false) {
            $parameters['relation'] = $tab;
        }

        return MatterResource::getUrl('view', $parameters, panel: 'admin');
    }
}
