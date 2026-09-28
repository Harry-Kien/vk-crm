<?php

namespace App\Filament\Admin\Widgets;

use App\Actions\Deadline\DeleteDeadline;
use App\Enums\DeadlineSeverity;
use App\Enums\Permission;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Models\Deadline;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * SPEC §7.1 mục 2: "Mốc thời hạn 7 ngày tới" — widget rơi mất giữa ba milestone (M6.5 Task 14,
 * `deadlines/F5`, `spec-gap-05`).
 *
 * # Chỗ trống này tồn tại vì sao, và vì sao nó nguy hiểm hơn một chỗ trống thẩm mỹ thường
 *
 * Kế hoạch M3 hẹn "các widget cần dữ liệu … hạn tố tụng" cho M4/M6; PROGRESS hẹn lại cho M5–M7;
 * kế hoạch M6 lại không có task nào nhận nó (Task 5 là relation manager tab, Task 6 là
 * `CheckDeadlines`). Kết quả: sau đúng MỘT thư "quá hạn" (khoá `overdue` trong `reminders_sent`
 * chỉ bắn một lần — xem docblock `CheckDeadlines`), một mốc tố tụng đã trễ chỉ còn thấy được khi
 * ai đó tự mở tab "Mốc thời hạn" của TỪNG vụ việc. Trưởng phòng không có một chỗ nào nhìn toàn văn
 * phòng; khi email hỏng (F1, đã sửa ở Task 11) thì kênh còn lại cũng không có. Với nghiệp vụ mang
 * rủi ro trách nhiệm nghề nghiệp như hạn tố tụng, đây không phải một khoảng trống thẩm mỹ.
 *
 * # Đơn vị của bảng là MỐC, không phải VỤ VIỆC
 *
 * Khác `StaleMattersWidget`/`MattersMissingDocumentsWidget` (một dòng một vụ việc), ở đây một vụ
 * việc có thể góp NHIỀU dòng nếu nó có nhiều mốc trong cửa sổ 7 ngày — đúng nghĩa "danh sách việc
 * phải làm", không phải "danh sách hồ sơ cần chú ý".
 *
 * # Cửa sổ 7 ngày CỘNG quá hạn chưa xong — dùng lại {@see Deadline::scopeUpcoming()}
 *
 * `Deadline::scopeUpcoming(7)` (có từ M1, trước widget này chưa nơi nào gọi) đã đúng là "chưa hoàn
 * thành, đến hạn trong N ngày tới, KỂ CẢ đã quá hạn" (`due_date <= today()->addDays(7)`, không có
 * cận dưới). Dùng lại NÓ thay vì tự viết một điều kiện tương tự trong widget: định nghĩa "sắp tới
 * hoặc quá hạn" nằm trên model, cùng chỗ với `Matter::scopeOpen()`/`scopeListableBy()`.
 *
 * # Phạm vi: `Matter::scopeListableBy()`, và CHỈ vụ việc còn mở
 *
 * Cùng định nghĩa duy nhất "nhân sự này thấy vụ việc nào" (SPEC §5) mà mọi widget khác dùng — một
 * vụ `restricted` chỉ hiện với luật sư phụ trách và admin, đúng ranh giới `DeadlinesRelationManager`
 * đã giữ ở tab của từng vụ. `Matter::scopeOpen()` (R8, M6.5 Task 5) loại vụ đã đóng hoặc đã xoá
 * mềm: một mốc của một vụ đã kết thúc không còn là việc phải làm, đúng luật `CheckDeadlines` đã
 * dùng để dừng nhắc (`whereHas('matter', fn ($q) => $q->open())`) — hai nơi phải đồng ý với nhau,
 * nếu không widget sẽ hiện một mốc mà `CheckDeadlines` đã âm thầm thôi nhắc từ lâu.
 *
 * # Mốc đã gỡ (R14, Task 14) biến mất mà không cần điều kiện riêng
 *
 * `Deadline` dùng `SoftDeletes` từ M1; `Deadline::query()` mang sẵn `SoftDeletingScope`, nên
 * {@see DeleteDeadline} không cần widget này biết gì thêm.
 *
 * # Tô đỏ mục `critical` — bằng `style=`, không bằng lớp Tailwind
 *
 * Không có bước dựng CSS trong dự án (CLAUDE.md) — xem docblock
 * `DeadlinesRelationManager::renderDueDate()` cho lý lẽ đầy đủ và {@see self::renderSeverity()}
 * cho phép đo của CHÍNH cột này (`tests/Feature/Filament/UpcomingDeadlinesWidgetTest.php` đối
 * chiếu từng biến màu với `FilamentColor`, cùng công thức `colourVariablesIn()`/
 * `unregisteredColourVariables()` ở `tests/Pest.php`).
 */
class UpcomingDeadlinesWidget extends TableWidget
{
    // SPEC §7.1 mục 2: giữa mục 1 (`StaleMattersWidget`, -4) và mục 3
    // (`PendingChecklistReviewsWidget`, -2) — đúng con số brief giao, dù nó trùng với
    // `Filament\Widgets\AccountWidget` (-3, đăng ký sẵn ở `AdminPanelProvider`): AccountWidget
    // không nằm trong danh sách bảy widget đánh số của SPEC §7.1, nên `DashboardWidgetOrderTest`
    // (so thứ tự TƯƠNG ĐỐI giữa bảy widget đó) không đo cặp này.
    protected static ?int $sort = -3;

    /** Cùng cửa sổ với {@see Deadline::scopeUpcoming()} và SPEC §7.1 mục 2. */
    public const WINDOW_DAYS = 7;

    public static function canView(): bool
    {
        return (bool) Auth::user()?->can(Permission::MatterView->value);
    }

    /**
     * Truy vấn của widget, tách static để test được mà không dựng cả bảng Livewire.
     *
     * @return Builder<Deadline>
     */
    public static function rowsFor(User $user): Builder
    {
        return Deadline::query()
            ->upcoming(self::WINDOW_DAYS)
            ->whereHas('matter', fn (Builder $matter): Builder => $matter->open()->listableBy($user))
            ->with([
                'matter.client',
                // Cùng lý do `DeadlinesRelationManager::table()` nạp kèm tài khoản đã xoá mềm: một
                // luật sư đã nghỉ việc vẫn phải hiện tên, không phải "—".
                'responsible' => fn (BelongsTo $responsible): BelongsTo => $responsible->withTrashed(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('widgets.upcoming_deadlines.heading'))
            ->description(__('widgets.upcoming_deadlines.description'))
            ->emptyStateHeading(__('widgets.upcoming_deadlines.empty_state'))
            ->query(fn (): Builder => static::rowsFor(static::currentUser()))
            ->columns([
                TextColumn::make('due_date')
                    ->label(__('widgets.upcoming_deadlines.columns.due_date'))
                    ->html()
                    ->formatStateUsing(fn (Deadline $record): HtmlString => DeadlinesRelationManager::renderDueDate($record))
                    ->sortable(),
                TextColumn::make('matter.code')
                    ->label(__('widgets.upcoming_deadlines.columns.code')),
                TextColumn::make('matter.client.name')
                    ->label(__('widgets.upcoming_deadlines.columns.client')),
                TextColumn::make('name')
                    ->label(__('widgets.upcoming_deadlines.columns.name'))
                    ->wrap(),
                TextColumn::make('severity')
                    ->label(__('widgets.upcoming_deadlines.columns.severity'))
                    ->html()
                    ->formatStateUsing(fn (DeadlineSeverity $state): HtmlString => static::renderSeverity($state)),
                TextColumn::make('responsible.name')
                    ->label(__('widgets.upcoming_deadlines.columns.responsible'))
                    ->placeholder('—'),
            ])
            ->defaultSort('due_date')
            ->recordActions([
                Action::make('open')
                    ->label(__('widgets.upcoming_deadlines.open'))
                    ->url(fn (Deadline $record): string => MatterResource::getUrl(
                        'view',
                        ['record' => $record->matter_id],
                        panel: 'admin',
                    )),
            ])
            ->paginated([5, 10, 25]);
    }

    /**
     * Mức độ, tô đỏ cho `critical` — SPEC §7.1 mục 2 ("tô đỏ mục critical"). Tách static để test
     * được không cần dựng cả bảng, cùng thành ngữ
     * {@see DeadlinesRelationManager::renderDueDate()}.
     *
     * **Style nội tuyến trên biến màu của Filament, không `->badge()->color()`** (thứ tab "Mốc thời
     * hạn" dùng qua `DeadlinesRelationManager::severityColor()`): brief M6.5 đòi assert màu trên
     * markup sinh ra, và một `style="color:var(--danger-600)"` là thứ đo thẳng được trên HTML —
     * `UpcomingDeadlinesWidgetTest` đo cả hàm này lẫn widget đã vẽ, và đối chiếu từng biến màu với
     * `FilamentColor` để một sắc độ gõ nhầm không lọt qua.
     */
    public static function renderSeverity(DeadlineSeverity $severity): HtmlString
    {
        $colour = match ($severity) {
            DeadlineSeverity::Critical => 'var(--danger-600)',
            DeadlineSeverity::Normal => 'color-mix(in srgb, var(--gray-500) 90%, transparent)',
        };

        return new HtmlString(sprintf(
            '<span style="font-weight:600;color:%s">%s</span>',
            $colour,
            e($severity->label()),
        ));
    }

    /** Cùng thành ngữ phòng thủ với {@see StaleMattersWidget::currentUser()}. */
    private static function currentUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
