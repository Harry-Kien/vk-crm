<?php

namespace App\Mail\Client;

use App\Enums\Confidentiality;
use App\Jobs\SendPaymentReceivedMail;
use App\Mail\BrandedMailable;
use App\Mail\OutboundHeaders;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\Payment;
use App\Support\Billing\Money;
use App\Support\OfficeProfile;
use App\Support\PortalUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mẫu `client.payment_received` (làn nt Task 1) — xác nhận văn phòng đã nhận một khoản thanh toán.
 * Chỉ gửi khi công tắc "Nhắc lịch thanh toán cho khách" bật; xếp và gửi bởi
 * {@see SendPaymentReceivedMail}, tới tài khoản đã qua R12 mà cổng còn cho xem vụ (job lọc bằng
 * `ResolveClientRecipients::onPortal()`) — nên lúc thư đi, lời mời đăng nhập cổng để xem chi tiết là thật.
 *
 * **Nội dung — chỉ những gì khách đã biết về tiền của mình:** mã hồ sơ, số tiền đã nhận, ngày nhận
 * (`paid_on`), và một câu về số còn lại theo `$balance` mà job tính lúc gửi và truyền vào (mailable không
 * tự truy vấn): số > 0 → "còn phải thanh toán …"; 0 → "đã thanh toán đủ" (hợp đồng hoàn tất, hay đang hiệu
 * lực mà không còn gì phải trả); `null` (hợp đồng đã huỷ) → KHÔNG câu nào, thư chỉ xác nhận khoản đã nhận.
 * Không tên đợt (tên đợt có thể nói giai đoạn của vụ), không tiêu đề vụ, không cách
 * trả, mã giao dịch, ghi chú hay người ghi.
 *
 * **Vụ hạn chế** (`confidentiality = restricted`): thân thư chỉ nêu mã hồ sơ và mời đăng nhập cổng
 * khách hàng để xem chi tiết — không số tiền, không ngày, không số còn lại.
 *
 * Tiêu đề chỉ có mã hồ sơ (một người có thể đại diện hai khách hàng, tiêu đề phải nói hồ sơ nào). Dòng
 * sổ thư gắn với VỤ VIỆC ({@see self::relatedRecord()}) — ai xem được vụ thì xem được dòng, vụ hạn chế
 * theo đúng luật của sổ thư — và mang khoá `payment:{id}` ({@see SendPaymentReceivedMail::ledgerKeyFor()})
 * để job không gửi trùng cùng một khoản thu.
 */
class PaymentReceived extends BrandedMailable
{
    public function __construct(
        public Payment $payment,
        public Matter $matter,
        public ClientUser $recipient,
        public ?int $balance,
    ) {}

    protected function template(): string
    {
        return SendPaymentReceivedMail::TEMPLATE;
    }

    protected function relatedRecord(): ?Model
    {
        return $this->matter;
    }

    /** @return array<string, string> */
    protected function additionalLedgerHeaders(): array
    {
        return [OutboundHeaders::LEDGER_TIER => SendPaymentReceivedMail::ledgerKeyFor($this->payment)];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('client_payments.mail.subject', ['code' => $this->matter->code]),
        );
    }

    /** Đọc thông tin văn phòng LÚC RENDER, không lúc xếp hàng — xem docblock `OfficeProfile`. */
    public function content(): Content
    {
        $office = OfficeProfile::current();
        $restricted = $this->matter->confidentiality === Confidentiality::Restricted;

        return new Content(
            view: 'emails.client.payment-received',
            text: 'emails.client.payment-received-text',
            with: [
                'name' => $this->recipient->name,
                'matterCode' => $this->matter->code,
                'restricted' => $restricted,
                'amount' => $restricted ? null : Money::format($this->payment->amount),
                'paidOn' => $restricted ? null : $this->payment->paid_on->format('d/m/Y'),
                'outstanding' => $restricted || $this->balance === null || $this->balance <= 0 ? null : Money::format($this->balance),
                'settled' => ! $restricted && $this->balance === 0,
                'portalUrl' => PortalUrl::base(),
                'office' => $office->legalName(),
                'hotline' => $office->hotline(),
            ],
        );
    }
}
