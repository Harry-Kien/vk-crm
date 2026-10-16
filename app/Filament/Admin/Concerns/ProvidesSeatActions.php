<?php

namespace App\Filament\Admin\Concerns;

use App\Actions\Matter\AcceptMatterAssignment;
use App\Actions\Matter\DeclineMatterAssignment;
use App\Enums\MatterAssignmentStatus;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Filament\Admin\Widgets\Tasks\MatterAssignmentsAwaitingMeWidget;
use App\Models\Matter;
use App\Models\MatterUser;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Hai nút "Nhận hồ sơ" (làn tk, Task 2) trên MỘT dòng ghế: "Đã nhận hồ sơ" (MỘT cú bấm, không hộp xác
 * nhận) và "Trả lại" (bắt buộc lý do). Dùng chung bởi khối "Hồ sơ chờ tôi nhận" của "Việc của tôi"
 * ({@see MatterAssignmentsAwaitingMeWidget}, dòng là `MatterUser`) và tab "Đội ngũ" của vụ
 * ({@see TeamRelationManager}, dòng là `User` kèm `pivot`). Lớp dùng trait phải dùng cả
 * {@see ReportsActionFailures}.
 *
 * Không một dòng nghiệp vụ nào ở đây: hai nút gọi `AcceptMatterAssignment` / `DeclineMatterAssignment`,
 * hai Action tự hỏi lại người, ghế và tình trạng dưới khoá. `->visible()` chỉ để ẨN nút khỏi dòng không
 * phải ghế đang chờ của chính người xem.
 */
trait ProvidesSeatActions
{
    /**
     * @param  Closure(mixed): ?MatterUser  $seatOf  ghế của một dòng
     * @param  Closure(mixed): Matter  $matterOf  vụ của một dòng
     */
    protected function acceptSeatAction(Closure $seatOf, Closure $matterOf): Action
    {
        return Action::make('acceptSeat')
            ->label(__('tasks.seat.actions.accept'))
            ->icon(Heroicon::OutlinedHandThumbUp)
            ->color('success')
            ->button()
            ->size('sm')
            ->visible(fn (mixed $record): bool => $this->isMyPendingSeat($seatOf($record)))
            ->successNotificationTitle(__('tasks.seat.actions.accept_success'))
            ->action(fn (Action $action, mixed $record) => $this->runAction(
                $action,
                fn () => app(AcceptMatterAssignment::class)->handle($matterOf($record), Auth::user()),
            ));
    }

    /**
     * @param  Closure(mixed): ?MatterUser  $seatOf
     * @param  Closure(mixed): Matter  $matterOf
     */
    protected function declineSeatAction(Closure $seatOf, Closure $matterOf): Action
    {
        return Action::make('declineSeat')
            ->label(__('tasks.seat.actions.decline'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (mixed $record): bool => $this->isMyPendingSeat($seatOf($record)))
            ->modalHeading(__('tasks.seat.actions.decline_heading'))
            ->modalDescription(__('tasks.seat.actions.decline_description'))
            ->modalSubmitActionLabel(__('tasks.seat.actions.decline_submit'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('tasks.fields.reason'))
                    ->helperText(__('tasks.seat.fields.decline_reason_help'))
                    ->required()
                    ->maxLength(MatterUser::DECLINE_REASON_MAX_LENGTH)
                    ->rows(3),
            ])
            ->successNotificationTitle(__('tasks.seat.actions.decline_success'))
            ->action(fn (Action $action, mixed $record, array $data) => $this->runAction(
                $action,
                fn () => app(DeclineMatterAssignment::class)->handle($matterOf($record), Auth::user(), (string) ($data['reason'] ?? '')),
            ));
    }

    private function isMyPendingSeat(?MatterUser $seat): bool
    {
        return $seat !== null
            && $seat->user_id === (int) Auth::id()
            && $seat->assignmentStatus() === MatterAssignmentStatus::Pending;
    }
}
