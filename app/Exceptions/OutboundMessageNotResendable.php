<?php

namespace App\Exceptions;

use App\Actions\Notification\ResendOutboundMessage;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * {@see ResendOutboundMessage} từ chối gửi lại một dòng nhật ký thư (`outbound_messages`) vì
 * **TRẠNG THÁI/LOẠI** của dòng đó, không vì thiếu quyền. Cùng phân tách với `ClientRequestNotOpen`:
 * policy (`OutboundMessagePolicy::resend()`) trả lời "ai được bấm", Action này trả lời "dòng này
 * có đang ở trạng thái gửi lại được không". Cổng quyền chạy TRƯỚC (người đọc được câu từ chối đã
 * là admin xem được đúng dòng đó), nên các câu dưới đây nói thẳng sự thật mà không rò rỉ gì.
 *
 * Câu chữ nằm ở `lang/vi/outbound.php` (mục `resend.refused`); không câu nào nêu mã vụ việc, tiêu
 * đề hay tên khách (Review Focus 1: vụ `restricted`) — người bấm đã thấy dòng đó trong bảng.
 *
 * `DomainException` để `App\Filament\Admin\Concerns\ReportsActionFailures` và nút "Gửi lại" vẽ
 * đúng câu này lên một thông báo đỏ.
 */
class OutboundMessageNotResendable extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * Mẫu thư không nằm trong danh sách gửi lại được — `client.otp`, `staff.deadline_reminder`,
     * `client.activation`, `undeclared`, hoặc một mẫu tương lai chưa ai khai. Mỗi mẫu bị loại có
     * MỘT lý do riêng (việc khác mới đúng đường), mẫu lạ nhận câu chung.
     */
    public static function template(string $template): self
    {
        // Tên mẫu CÓ dấu chấm (`client.otp`), nên không ghép được vào một khoá dịch dạng chấm
        // (`...template_reasons.client.otp` bị hiểu là mảng lồng `client` → `otp` và không bao giờ
        // khớp): đọc cả mảng lý do rồi tra theo đúng tên mẫu.
        $reasons = (array) __('outbound.resend.refused.template_reasons');

        return new self(__('outbound.resend.refused.template', [
            'reason' => $reasons[$template] ?? $reasons['default'],
        ]));
    }

    /** Chỉ dòng `failed` mới gửi lại được: `queued`/`sent` không phải một lần gửi hỏng. */
    public static function notFailed(): self
    {
        return new self(__('outbound.resend.refused.not_failed'));
    }

    /** Bản ghi mà thư nói về (dòng tiến độ, tài liệu, ...) đã bị xoá cứng: không dựng lại được thư. */
    public static function relatedGone(): self
    {
        return new self(__('outbound.resend.refused.related_gone'));
    }

    /**
     * Bản ghi còn đó nhưng không còn là ĐÚNG sự việc mà dòng hỏng nói về — hiện chỉ có
     * `client.document_rejected`: dòng hỏng mang khoá lần từ chối cũ (`payload.tier`), đầu mục đã bị
     * từ chối LẦN MỚI. Gửi lại dòng cũ sẽ gửi thư của lần mới dưới danh nghĩa lần cũ.
     */
    public static function superseded(): self
    {
        return new self(__('outbound.resend.refused.superseded'));
    }

    /** Dòng này đã được bấm "Gửi lại" rồi — khoá chống bấm hai lần. */
    public static function alreadyRequested(Carbon $at): self
    {
        return new self(__('outbound.resend.refused.already_requested', [
            'time' => $at->timezone(config('app.timezone'))->format('d/m/Y H:i'),
        ]));
    }

    /** Lúc này không còn ai đủ điều kiện nhận (R3 nhân sự, R12 khách, cổng của chính mẫu thư). */
    public static function noEligibleRecipient(): self
    {
        return new self(__('outbound.resend.refused.no_eligible_recipient'));
    }

    /** Mọi người đủ điều kiện nhận đều đã có một dòng `sent` cho đúng thư này. */
    public static function alreadyDelivered(): self
    {
        return new self(__('outbound.resend.refused.already_delivered'));
    }
}
