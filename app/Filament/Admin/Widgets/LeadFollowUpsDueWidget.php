<?php

namespace App\Filament\Admin\Widgets;

use App\Actions\Intake\ChangeIntakeStatus;
use App\Enums\IntakeStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use App\Models\IntakeRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * "Lead đến hẹn liên hệ lại" (làn kd, 2026-10-14) — trang chủ của NHÂN VIÊN KINH DOANH: lead còn mở mà hẹn
 * liên hệ lại (`next_follow_up_at`, đặt mỗi lần "Ghi lần chăm sóc") rơi vào hôm nay hoặc đã qua, hẹn sớm
 * nhất lên trước.
 *
 * **Tập dòng:** {@see self::rowsFor()} — lead người này thấy được (`IntakeRequest::scopeVisibleTo()`), ở
 * một bước còn làm việc (`ChangeIntakeStatus::OPEN`), hẹn ≤ cuối ngày hôm nay (lead không hẹn — cột null
 * — không bao giờ khớp so sánh đó; ẩn danh xoá hẹn, `AnonymiseProspect`). Cột: mã, tên người liên hệ
 * (lead của chính họ — họ là người gọi lại), trạng thái, giờ hẹn; liên kết sang trang lead.
 *
 * **Ai thấy:** chỉ vai `Role::Sales` còn qua cổng `viewAny` của tiếp nhận, như {@see MyLeadsWidget}; `boot()`
 * trả 404 cho người khác, kể cả khi widget được mount thẳng.
 */
class LeadFollowUpsDueWidget extends TableWidget
{
    // Ngay dưới hàng ô "Lead của tôi" (-5), trên ô tài khoản của Filament (-3). Cùng số -4 với "Hồ sơ đứng
    // im" ở trang chủ các vai khác: không bao giờ cùng hiện (widget đó cần `matter.view`, widget này chỉ vai
    // `sales`). Dải "Tình trạng hệ thống" (-6) đứng trên cả hai widget kinh doanh.
    protected static ?int $sort = -4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->hasRole(Role::Sales->value)
            && Gate::forUser($user)->allows('viewAny', IntakeRequest::class);
    }

    /** Hook `boot` của Livewire: chạy trước `mount()` và trước MỌI request sau đó — 404 cho người không qua `canView()`, kể cả khi widget được mount thẳng. */
    public function boot(): void
    {
        abort_unless(static::canView(), 404);
    }

    /** Truy vấn của widget — static để test đọc được mà không dựng cả bảng (cùng thành ngữ các widget khác). */
    public static function rowsFor(User $user): Builder
    {
        return IntakeRequest::query()
            ->visibleTo($user)
            ->whereIn('status', array_map(fn (IntakeStatus $status): string => $status->value, ChangeIntakeStatus::OPEN))
            ->where('next_follow_up_at', '<=', now()->endOfDay());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('leads.widgets.follow_ups_due.heading'))
            ->description(__('leads.widgets.follow_ups_due.description'))
            ->emptyStateHeading(__('leads.widgets.follow_ups_due.empty'))
            ->query(fn (): Builder => static::rowsFor(static::currentUser()))
            ->columns([
                TextColumn::make('code')
                    ->label(__('leads.widgets.follow_ups_due.columns.code')),
                TextColumn::make('contact_name')
                    ->label(__('leads.widgets.follow_ups_due.columns.contact_name')),
                TextColumn::make('status')
                    ->label(__('leads.widgets.follow_ups_due.columns.status'))
                    ->formatStateUsing(fn (IntakeStatus $state): string => $state->label()),
                TextColumn::make('next_follow_up_at')
                    ->label(__('leads.widgets.follow_ups_due.columns.next_follow_up_at'))
                    ->dateTime('H:i d/m/Y')
                    ->color(fn (IntakeRequest $record): string => $record->next_follow_up_at?->isPast() ? 'danger' : 'gray'),
            ])
            ->defaultSort('next_follow_up_at')
            ->recordActions([
                Action::make('open')
                    ->label(__('leads.widgets.follow_ups_due.open'))
                    ->url(fn (IntakeRequest $record): string => IntakeRequestResource::getUrl('edit', ['record' => $record], panel: 'admin')),
            ])
            ->paginated([5, 10, 25]);
    }

    /** `canView()` đã chặn trước khi widget render — cùng thành ngữ phòng thủ với `UnansweredIntakesWidget`. */
    private static function currentUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
