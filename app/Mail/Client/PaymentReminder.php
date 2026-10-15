<?php

namespace App\Mail\Client;

use App\Enums\Confidentiality;
use App\Jobs\SendPaymentDueSoonMail;
use App\Jobs\SendPaymentOverdueReminderMail;
use App\Mail\BrandedMailable;
use App\Mail\OutboundHeaders;
use App\Models\ClientUser;
use App\Models\Instalment;
use App\Models\Matter;
use App\Support\Billing\ClientPaymentReminders;
use App\Support\Billing\Money;
use App\Support\Billing\VietQr;
use App\Support\OfficeProfile;
use App\Support\PortalUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Hai mẫu thư nhắc tiền cho khách (làn nt Task 2), chỉ khi công tắc "Nhắc lịch thanh toán cho khách" bật:
 *
 *  - `client.payment_reminder.due_soon` ({@see self::KIND_DUE_SOON}) — báo trước hạn, gửi bởi
 *    {@see SendPaymentDueSoonMail} theo lịch 08:15;
 *  - `client.payment_reminder.overdue` ({@see self::KIND_OVERDUE}) — nhắc một đợt đã quá hạn, gửi bởi
 *    {@see SendPaymentOverdueReminderMail} sau khi người quản lý hợp đồng bấm "Gửi thư nhắc" và xem trước.
 *
 * Người nhận đã qua R12 và "cổng còn cho xem vụ" ({@see ClientPaymentReminders::recipients()}), nên lời
 * mời đăng nhập cổng là thật lúc thư đi.
 *
 * **Nội dung (vụ thường):** mã hồ sơ, tên đợt, số tiền CÒN PHẢI TRẢ của đợt (nơi dựng tính và truyền vào
 * — mailable không tự truy vấn), ngày hạn, thông tin chuyển khoản của văn phòng, nội dung chuyển khoản
 * gợi ý ({@see VietQr::transferNote()} — cùng chuỗi khách thấy trên cổng) và — bản HTML, khi máy chủ có
 * extension `gd` — ảnh mã QR VietQR của đúng số tiền và nội dung đó, ĐÍNH VÀO THƯ (`cid:`): ứng dụng thư
 * không hiện ảnh `data:`/SVG, và không ảnh nào tải từ bên ngoài. Không tiêu đề vụ, không ghi chú.
 * Thiếu thông tin chuyển khoản (chỉ có thể do dữ liệu sửa tay — trang cấu hình không cho bật khi thiếu)
 * thì bỏ hẳn phần chuyển khoản và ảnh QR, không in nửa vời.
 *
 * **Vụ hạn chế** (`confidentiality = restricted`): chỉ mã hồ sơ và lời mời đăng nhập cổng — không tên
 * đợt, số tiền, ngày, số tài khoản hay ảnh QR. Cùng luật thư xác nhận đã nhận tiền của Task 1.
 *
 * Tiêu đề có mã hồ sơ (một người có thể đại diện hai khách). Dòng sổ thư gắn với VỤ VIỆC (ai xem được vụ
 * thì xem được dòng) và mang khoá chống trùng `$ledgerKey` ({@see ClientPaymentReminders::dueSoonKey()},
 * {@see ClientPaymentReminders::overdueKey()}).
 *
 * {@see self::previewText()} in bản chữ của CHÍNH thư này cho hộp thoại xem trước của nút "Gửi thư nhắc":
 * cùng view, cùng dữ liệu với phần văn bản thuần của thư sẽ gửi.
 */
class PaymentReminder extends BrandedMailable
{
    public const KIND_DUE_SOON = 'due_soon';

    public const KIND_OVERDUE = 'overdue';

    public const TEMPLATE_PREFIX = 'client.payment_reminder.';

    /**
     * @param  array{bank_bin: string, bank_name: string, account_number: string, account_holder: string, bank_branch: ?string}|null  $account
     */
    public function __construct(
        public string $kind,
        public Instalment $instalment,
        public Matter $matter,
        public ClientUser $recipient,
        public int $outstandingAmount,
        public ?array $account,
        public string $ledgerKey,
    ) {}

    public static function templateFor(string $kind): string
    {
        return self::TEMPLATE_PREFIX.$kind;
    }

    protected function template(): string
    {
        return self::templateFor($this->kind);
    }

    protected function relatedRecord(): ?Model
    {
        return $this->matter;
    }

    /** @return array<string, string> */
    protected function additionalLedgerHeaders(): array
    {
        return [OutboundHeaders::LEDGER_TIER => $this->ledgerKey];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function subjectLine(): string
    {
        return __('client_payments.reminder_mail.'.$this->kind.'.subject', ['code' => $this->matter->code]);
    }

    /** Đọc thông tin văn phòng LÚC RENDER, không lúc xếp hàng — xem docblock `OfficeProfile`. */
    public function content(): Content
    {
        return new Content(
            view: 'emails.client.payment-reminder',
            text: 'emails.client.payment-reminder-text',
            with: $this->viewData(),
        );
    }

    /** Bản chữ của thư — đúng view văn bản thuần và dữ liệu của {@see self::content()}. */
    public function previewText(): string
    {
        return trim(view('emails.client.payment-reminder-text', $this->viewData())->render());
    }

    /** Thư có kèm ảnh mã QR không (vụ thường, đủ thông tin chuyển khoản, có số tiền, có `gd`). */
    public function hasQr(): bool
    {
        return $this->viewData()['qrPng'] !== null;
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        $office = OfficeProfile::current();
        $restricted = $this->matter->confidentiality === Confidentiality::Restricted;
        $amount = $this->outstandingAmount;
        $note = VietQr::transferNote((string) $this->matter->code, (int) $this->instalment->sequence);
        $account = $restricted ? null : $this->account;

        return [
            'kind' => $this->kind,
            'name' => $this->recipient->name,
            'matterCode' => $this->matter->code,
            'restricted' => $restricted,
            'instalmentName' => $restricted ? null : (string) $this->instalment->name,
            'amount' => $restricted ? null : Money::format($amount),
            'dueOn' => $restricted ? null : $this->instalment->due_date?->format('d/m/Y'),
            'transfer' => $account === null ? null : [
                'bank' => $account['bank_name'],
                'account_number' => $account['account_number'],
                'account_holder' => $account['account_holder'],
                'bank_branch' => $account['bank_branch'],
                'note' => $note,
            ],
            'qrPng' => $account === null || $amount < 1
                ? null
                : VietQr::pngBinary(VietQr::payload($account['bank_bin'], $account['account_number'], $amount, $note)),
            'portalUrl' => PortalUrl::base(),
            'office' => $office->legalName(),
            'hotline' => $office->hotline(),
        ];
    }
}
