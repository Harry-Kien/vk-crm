<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Intake\FirstResponseClock;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * SPEC §7.1, đính chính 2026-09-24 (M10): "Liên hệ chưa ai gọi lại" — những lần có người liên hệ còn
 * ở `new` quá ngưỡng phản hồi (mặc định 4 giờ làm việc, `INTAKE_RESPONSE_HOURS`). Một danh sách phải
 * hành động theo, như mục 1 và mục 3, nên đứng ngay dưới mục 3 ("Tài liệu chờ duyệt").
 *
 * **Chọn dòng: MỘT định nghĩa "quá hạn phản hồi"** — {@see FirstResponseClock::overdue()}, cùng đồng
 * hồ giờ làm việc mà tác vụ nhắc (`RemindUnansweredIntakes`) dùng, nên widget và thư không bao giờ
 * nói khác nhau về cùng một bản ghi. **Phạm vi: `IntakeRequest::scopeVisibleTo()`** của người xem —
 * cùng luật với resource tiếp nhận (người chỉ có `intake.create` thấy bản mình ghi hoặc được giao;
 * `intake.viewAny` thấy mọi bản). Mọi dòng và mọi con số của bảng đi qua đó.
 *
 * **Không tên, không số điện thoại** của người liên hệ: mỗi dòng mã bản ghi, nguồn, lúc nhận, thời gian
 * đã chờ (giờ làm việc) và người được giao (một nhân sự). Trang chủ là màn hình hay bị nhìn qua vai;
 * người cần gọi lại bấm "Mở bản ghi" và đọc phần còn lại sau `IntakeRequestPolicy::view`.
 *
 * **`canView()` tự hỏi quyền** (`IntakeRequestPolicy::viewAny`: có `intake.create` hoặc
 * `intake.viewAny`) — kế toán và mọi vai không có quyền `intake.*` không thấy widget.
 */
class UnansweredIntakesWidget extends TableWidget
{
    // SPEC §7.1 đính chính M10: ngay dưới mục 3 (`PendingChecklistReviewsWidget`, -2), trên mục 4
    // (`MattersMissingDocumentsWidget`, dời -1 → 0) — xem `DashboardWidgetOrderTest`.
    protected static ?int $sort = -1;

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && Gate::forUser($user)->allows('viewAny', IntakeRequest::class);
    }

    /**
     * Truy vấn của widget — static, cùng thành ngữ `rowsFor()` của các widget trang chủ khác.
     *
     * @return Builder<IntakeRequest>
     */
    public static function rowsFor(User $user): Builder
    {
        return FirstResponseClock::fromConfig()
            ->overdue(IntakeRequest::query()->visibleTo($user))
            ->with(['assignee']);
    }

    public function table(Table $table): Table
    {
        $clock = FirstResponseClock::fromConfig();

        return $table
            ->heading(__('widgets.unanswered_intakes.heading'))
            ->description(__('widgets.unanswered_intakes.description', ['hours' => $clock->thresholdHours]))
            ->emptyStateHeading(__('widgets.unanswered_intakes.empty_state'))
            ->query(fn (): Builder => static::rowsFor(static::currentUser()))
            ->columns([
                TextColumn::make('code')
                    ->label(__('widgets.unanswered_intakes.columns.code')),
                TextColumn::make('source')
                    ->label(__('widgets.unanswered_intakes.columns.source'))
                    ->formatStateUsing(fn (IntakeRequest $record): string => $record->source->label()),
                TextColumn::make('received_at')
                    ->label(__('widgets.unanswered_intakes.columns.received_at'))
                    ->dateTime('H:i d/m/Y')
                    ->sortable(),
                TextColumn::make('waited')
                    ->label(__('widgets.unanswered_intakes.columns.waited'))
                    ->state(fn (IntakeRequest $record): string => FirstResponseClock::formatMinutes($clock->waitedMinutes($record))),
                TextColumn::make('assignee.name')
                    ->label(__('widgets.unanswered_intakes.columns.assignee'))
                    ->placeholder(__('widgets.unanswered_intakes.unassigned')),
            ])
            // Chờ lâu nhất lên trước: đó là người văn phòng đã để chờ lâu nhất.
            ->defaultSort('received_at')
            ->recordActions([
                Action::make('open')
                    ->label(__('widgets.unanswered_intakes.open'))
                    ->url(fn (IntakeRequest $record): string => IntakeRequestResource::getUrl(
                        'edit',
                        ['record' => $record],
                        panel: 'admin',
                    )),
            ])
            ->paginated([5, 10, 25]);
    }

    /** `canView()` đã chặn trước khi widget này render — cùng thành ngữ phòng thủ với `StaleMattersWidget`. */
    private static function currentUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
