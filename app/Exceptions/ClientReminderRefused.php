<?php

namespace App\Exceptions;

use App\Actions\Billing\RemindClientOfOverdueInstalment;
use DomainException;

/**
 * Nút "Gửi thư nhắc" (làn nt Task 2, {@see RemindClientOfOverdueInstalment}) không gửi được thư nhắc
 * đợt quá hạn ngay bây giờ — mỗi lý do một câu tiếng Việt nói rõ việc cần làm tiếp
 * (`lang/vi/client_payments.php`, khối `remind_action`). Là `DomainException` nên màn hình hiện nó bằng
 * một thông báo `persistent()` (`ReportsActionFailures`) và không ghi gì.
 *
 * Câu "không có quyền" KHÔNG ở đây: Action từ chối quyền bằng `AuthorizationException` (một câu chung,
 * SPEC §10.10). Mọi lý do dưới đây chỉ tới được người ĐÃ qua cổng quyền.
 */
class ClientReminderRefused extends DomainException
{
    public static function switchedOff(): self
    {
        return new self(__('client_payments.remind_action.switch_off'));
    }

    public static function notOverdue(): self
    {
        return new self(__('client_payments.remind_action.not_overdue'));
    }

    public static function noRecipients(): self
    {
        return new self(__('client_payments.remind_action.no_recipients'));
    }

    public static function alreadySentToday(string $time, string $name): self
    {
        return new self(__('client_payments.remind_action.already_sent_today', ['time' => $time, 'name' => $name]));
    }
}
