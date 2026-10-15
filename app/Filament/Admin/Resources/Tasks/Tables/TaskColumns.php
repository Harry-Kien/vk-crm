<?php

namespace App\Filament\Admin\Resources\Tasks\Tables;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\RelationManagers\TasksRelationManager;
use App\Models\Task;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 */
final class TaskColumns
{
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
        return $query->with([
            'matter.team',
            'assignee' => fn (BelongsTo $assignee): BelongsTo => $assignee->withTrashed(),
            'assigner' => fn (BelongsTo $assigner): BelongsTo => $assigner->withTrashed(),
        ]);
    }

    /** @return list<TextColumn> */
    public static function make(bool $withMatter = true): array
    {
        return array_values(array_filter([
            TextColumn::make('title')
                ->label(__('tasks.columns.title'))
                ->weight('semibold')
                ->wrap()
                ->searchable(),
            $withMatter ? TextColumn::make('matter.code')
                ->label(__('tasks.columns.matter'))
                ->placeholder(__('tasks.columns.general'))
                ->url(fn (Task $record): ?string => $record->matter_id === null ? null : self::matterTabUrl($record->matter_id))
                ->searchable() : null,
            TextColumn::make('assignee.name')
                ->label(__('tasks.columns.assignee'))
                ->description(fn (Task $record): ?string => in_array($record->reassignmentReason(), self::ASSIGNEE_REASONS, true)
                    ? __('tasks.columns.assignee_'.$record->reassignmentReason())
                    : null)
                ->color(fn (Task $record): ?string => in_array($record->reassignmentReason(), self::ASSIGNEE_REASONS, true) ? 'danger' : null),
            // Vòng sửa 1 (I1): việc bị trả lại mà người giao đã nghỉ (vòng sửa cuối 1, F4: hay không còn mở được
            // hồ sơ) — "Cần giao lại" ghi ở cột người giao.
            TextColumn::make('assigner.name')
                ->label(__('tasks.columns.assigner'))
                ->description(fn (Task $record): ?string => in_array($record->reassignmentReason(), self::ASSIGNER_REASONS, true)
                    ? __('tasks.columns.'.$record->reassignmentReason())
                    : null)
                ->color(fn (Task $record): ?string => in_array($record->reassignmentReason(), self::ASSIGNER_REASONS, true) ? 'danger' : null)
                ->toggleable(),
            TextColumn::make('due_date')
                ->label(__('tasks.columns.due_date'))
                ->html()
                ->formatStateUsing(fn (Task $record): HtmlString => self::renderDue($record))
                ->placeholder(__('tasks.due.none'))
                ->sortable(),
            TextColumn::make('priority')
                ->label(__('tasks.columns.priority'))
                ->badge()
                ->formatStateUsing(fn (TaskPriority $state): string => $state->label())
                ->color(fn (TaskPriority $state): string => $state->color()),
            TextColumn::make('status')
                ->label(__('tasks.columns.status'))
                ->badge()
                ->formatStateUsing(fn (TaskStatus $state): string => $state->label())
                ->color(fn (TaskStatus $state): string => $state->color())
                ->description(fn (Task $record): ?string => match ($record->status) {
                    TaskStatus::Declined => $record->decline_reason,
                    TaskStatus::Completed => $record->completion_note,
                    TaskStatus::Pending => __('tasks.waiting', ['since' => $record->assigned_at->diffForHumans()]),
                    default => null,
                })
                ->wrap(),
            TextColumn::make('assigned_at')
                ->label(__('tasks.columns.assigned_at'))
                ->dateTime('H:i d/m/Y')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    /**
     * Ô hạn: ngày, và ngay dưới là còn bao lâu — đỏ khi quá hạn hoặc hôm nay, vàng trong
     * {@see self::DUE_SOON_DAYS} ngày, xám cho phần còn lại và cho mọi việc đã đóng (một dòng đỏ không
     * còn việc gì để làm dạy người ta bỏ qua màu đỏ — cùng lý lẽ `DeadlinesRelationManager::renderDueDate()`).
     */
    public static function renderDue(Task $task): HtmlString
    {
        if ($task->due_date === null) {
            return new HtmlString(e(__('tasks.due.none')));
        }

        $muted = 'color-mix(in srgb, var(--gray-500) 90%, transparent)';
        $daysLeft = (int) today()->diffInDays($task->due_date, false);

        [$text, $colour] = match (true) {
            ! $task->isOpen() => [__('tasks.due.done'), $muted],
            $daysLeft < 0 => [__('tasks.due.overdue', ['count' => abs($daysLeft)]), 'var(--danger-600)'],
            $daysLeft === 0 => [__('tasks.due.today'), 'var(--danger-600)'],
            $daysLeft === 1 => [__('tasks.due.tomorrow'), 'var(--warning-600)'],
            $daysLeft <= self::DUE_SOON_DAYS => [__('tasks.due.in_days', ['count' => $daysLeft]), 'var(--warning-600)'],
            default => [__('tasks.due.in_days', ['count' => $daysLeft]), $muted],
        };

        return new HtmlString(sprintf(
            '<span style="font-weight:600">%s</span><span style="display:block;font-size:0.75rem;font-weight:600;color:%s">%s</span>',
            e($task->due_date->format('d/m/Y')),
            $colour,
            e($text),
        ));
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
