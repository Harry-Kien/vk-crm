<?php

namespace App\Filament\Admin\Resources\OutboundMessages\Actions;

use App\Actions\Notification\ResendOutboundMessage;
use App\Models\OutboundMessage;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Nút "Gửi lại" trên một dòng nhật ký thư `failed` (M6 Task 10) — dùng CHUNG cho hàng của bảng
 * (`OutboundMessagesTable`) và cho trang xem (`ViewOutboundMessage`), để hai nơi không lệch nhau.
 * Toàn bộ nghiệp vụ nằm ở {@see ResendOutboundMessage}; lớp này chỉ là cổng hiển thị + vẽ kết quả.
 *
 * # Hai cổng hiển thị, và cổng thứ ba nằm trong Action
 *
 *  - `->visible()`: dòng có gửi lại được KHÔNG ({@see ResendOutboundMessage::canResend()} — mẫu gửi
 *    lại được và đang `failed`); dòng `sent`/`queued` và dòng của mọi mục trong
 *    `ResendTargets::NOT_RESENDABLE` (`client.otp`, `staff.deadline_reminder`, `client.activation`,
 *    `staff.instalment_overdue`, họ `staff.backup_alert.*`, `undeclared` — lý do từng mục ở
 *    docblock `ResendTargets`) không có nút, mẫu lạ chưa ai khai cũng không.
 *  - `->authorize()`: AI được bấm (`OutboundMessagePolicy::resend()` — admin, và xem được đúng dòng
 *    đó). Filament ẩn nút VÀ từ chối lời gọi trực tiếp khi không qua cổng này.
 *  - `ResendOutboundMessage::handle()` tự hỏi lại cả Gate lẫn trạng thái, nên một lời gọi ép vào nút
 *    đã bị ẩn (Livewire cho phép gọi tên action bất kỳ) vẫn bị Action chặn, không chỉ giao diện.
 *
 * Lời từ chối của Action ({@see DomainException}) hiện thành một thông báo đỏ và `halt()` — thông
 * báo "đã xếp hàng" KHÔNG được gửi. Không câu nào nêu mã vụ việc, tiêu đề hay tên khách, và thông
 * báo thành công chỉ nêu SỐ người nhận, không nêu địa chỉ (Review Focus 1).
 */
class ResendOutboundMessageAction
{
    public static function make(): Action
    {
        return Action::make('resend')
            ->label(__('outbound.resend.label'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (OutboundMessage $record): bool => ResendOutboundMessage::canResend($record))
            ->authorize(fn (OutboundMessage $record): bool => Gate::allows('resend', $record))
            ->requiresConfirmation()
            ->modalHeading(__('outbound.resend.modal_heading'))
            ->modalDescription(__('outbound.resend.modal_description'))
            ->modalSubmitActionLabel(__('outbound.resend.submit'))
            ->action(function (OutboundMessage $record, Action $action): void {
                try {
                    $count = app(ResendOutboundMessage::class)->handle(Auth::user(), $record);
                } catch (DomainException $exception) {
                    self::fail($action, $exception->getMessage());
                } catch (AuthorizationException) {
                    self::fail($action, __('actions.unauthorized'));
                }

                Notification::make()
                    ->title(__('outbound.resend.success_title'))
                    ->body(__('outbound.resend.success', ['count' => $count]))
                    ->success()
                    ->send();
            });
    }

    /**
     * `halt()` ném ngoại lệ nên hàm này không bao giờ quay lại: dòng thông báo thành công của
     * action không chạy sau một lần từ chối.
     */
    private static function fail(Action $action, string $message): never
    {
        Notification::make()
            ->title(__('actions.failed_title'))
            ->body($message)
            ->danger()
            ->persistent()
            ->send();

        $action->halt();
    }
}
