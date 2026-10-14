<?php

namespace App\Jobs;

use App\Actions\Billing\RecordPayment;
use App\Actions\Notification\ResolveClientRecipients;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\ContractStatus;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Mail\Client\PaymentReceived;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\ClientPaymentSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Gửi thư `client.payment_received` (làn nt Task 1) — văn phòng vừa ghi một khoản thu, khách nhận xác
 * nhận. Xếp bởi {@see RecordPayment} bằng `->afterCommit()` (R2: thư không bao giờ đi từ bên trong một
 * transaction tiền), CHỈ khi công tắc "Nhắc lịch thanh toán cho khách" bật ({@see ClientPaymentSettings}).
 *
 * # Tính lại TOÀN BỘ lúc gửi, không tin ảnh chụp lúc xếp
 *
 * Payload chỉ mang `paymentId`. {@see self::context()} đọc lại mọi thứ, KHÔNG qua scope cổng nào (job
 * có thể chạy trong một tiến trình mà ngữ cảnh cổng còn treo — cùng lý do `RecordOutboundMessage`):
 *
 *  1. **Công tắc còn bật** — tắt giữa lúc xếp và lúc gửi thì không gửi (thư đã xếp không được đi sau
 *     khi văn phòng tắt);
 *  2. **khoản thu còn, CHƯA huỷ** — phán quyết của làn: huỷ một khoản thu không bao giờ gửi thư "huỷ"
 *     cho khách; khoản đã huỷ trước khi job chạy thì cũng không có thư xác nhận. Khoản huỷ SAU khi thư
 *     đã đi thì thôi (khối tiền trên cổng tự bỏ khoản đã huỷ);
 *  3. **vụ việc còn** (chưa xoá mềm);
 *  4. **người nhận** = {@see ResolveClientRecipients::recipientsFor()} của khách sở hữu vụ — R12: tài
 *     khoản đang hoạt động, đã kích hoạt, khách chưa bị xoá. Không thêm bước "vụ còn trên cổng": thư
 *     xác nhận tiền KHÁCH đã trả, nói về chính khoản tiền đó, và chỉ mời vào trang chủ của cổng (không
 *     liên kết tới trang của vụ, nên không có trang 404 nào để dẫn tới).
 *
 * "Còn lại bao nhiêu" cũng tính lúc gửi: tổng {@see Instalment::outstanding()} của các đợt chưa huỷ của
 * hợp đồng (hợp đồng không còn `active` thì 0) — nên hai khoản thu ghi liền nhau cho hai thư nói đúng
 * số còn lại lúc mỗi thư đi.
 *
 * # Không gửi trùng khi thử lại
 *
 * Mỗi người một lần thử; một người hỏng không chặn người sau; ngoại lệ đầu tiên được ném lại sau khi
 * thử hết để hàng đợi thử lại. {@see self::alreadyDelivered()} hỏi sổ thư (dòng `sent` của ĐÚNG khoản
 * thu này — khoá `payment:{id}` ở `payload.tier` — và đúng địa chỉ) trước mỗi thư.
 *
 * Không có nút "Gửi lại" ở sổ thư cho mẫu này (`ResendTargets::NOT_RESENDABLE`, lý do ở đó): dòng sổ
 * thư gắn với VỤ VIỆC (để ai xem được vụ thì xem được dòng), từ vụ việc không dựng lại được khoản thu
 * nào. Hỏng hẳn thì {@see self::failed()} báo luật sư phụ trách.
 */
class SendPaymentReceivedMail implements ShouldQueue
{
    use Queueable;

    /** Mẫu thư trong sổ thư. */
    public const TEMPLATE = 'client.payment_received';

    public int $tries = 5;

    public function __construct(
        public readonly int $paymentId,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $context = $this->context();

        if ($context === null) {
            return;
        }

        [$payment, $matter, $outstanding, $recipients] = $context;

        $failure = null;

        foreach ($recipients as $recipient) {
            if (self::alreadyDelivered($payment, $recipient)) {
                continue;
            }

            try {
                Mail::to($recipient->email)->send(new PaymentReceived($payment, $matter, $recipient, $outstanding));
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /** Khoá "thư này nói về khoản thu nào" trong sổ thư (`payload.tier`) — mailable ghi, job đọc lại. */
    public static function ledgerKeyFor(Payment $payment): string
    {
        return 'payment:'.$payment->getKey();
    }

    /** Đã có một dòng `sent` của mẫu này, cho ĐÚNG khoản thu này và ĐÚNG địa chỉ này chưa. */
    public static function alreadyDelivered(Payment $payment, ClientUser $recipient): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('template', self::TEMPLATE)
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->where('payload->tier', self::ledgerKeyFor($payment))
            ->exists();
    }

    /**
     * Bốn bước tính lại của `handle()` (xem docblock lớp). `null` = không gửi gì.
     *
     * @return array{0: Payment, 1: Matter, 2: int, 3: Collection<int, ClientUser>}|null
     */
    private function context(): ?array
    {
        if (! ClientPaymentSettings::enabled()) {
            return null;
        }

        /** @var Payment|null $payment */
        $payment = Payment::query()->withoutGlobalScopes()->whereKey($this->paymentId)->whereNull('voided_at')->first();

        if ($payment === null) {
            return null;
        }

        /** @var Instalment|null $instalment */
        $instalment = Instalment::query()->withoutGlobalScopes()->find($payment->instalment_id);
        /** @var Contract|null $contract */
        $contract = $instalment === null ? null : Contract::query()->withoutGlobalScopes()->find($instalment->contract_id);
        /** @var Matter|null $matter */
        $matter = $contract === null ? null : Matter::query()->withoutGlobalScopes()->whereNull('deleted_at')->find($contract->matter_id);

        if ($matter === null) {
            return null;
        }

        $recipients = app(ResolveClientRecipients::class)->recipientsFor((int) $matter->client_id);

        if ($recipients->isEmpty()) {
            return null;
        }

        return [$payment, $matter, $this->outstanding($contract), $recipients];
    }

    /** Còn phải thu của cả hợp đồng — tổng `outstanding()` của các đợt chưa huỷ; 0 khi hợp đồng không `active`. */
    private function outstanding(Contract $contract): int
    {
        if ($contract->status !== ContractStatus::Active) {
            return 0;
        }

        return (int) Instalment::query()
            ->withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->get()
            ->each(fn (Instalment $instalment) => $instalment->setRelation('contract', $contract))
            ->sum(fn (Instalment $instalment): int => $instalment->outstanding());
    }

    /**
     * Chạy đúng MỘT lần sau khi cả `$tries` lần đều hỏng — cùng hình dạng
     * {@see SendMissingDocumentsMail::failed()}: một dòng `payment_received_mail_failed` trên vụ việc và
     * chuông cho luật sư phụ trách qua {@see ResolveStaffRecipients::handle()} (R3 — chuỗi dự phòng, vụ
     * hạn chế không lộ mã cho người không xem được). Vụ không còn thì rơi xuống mọi admin đang hoạt động.
     */
    public function failed(?Throwable $exception): void
    {
        $matterId = Payment::query()->withoutGlobalScopes()
            ->join('instalments', 'instalments.id', '=', 'payments.instalment_id')
            ->join('contracts', 'contracts.id', '=', 'instalments.contract_id')
            ->where('payments.id', $this->paymentId)
            ->value('contracts.matter_id');

        /** @var Matter|null $matter */
        $matter = $matterId === null ? null : Matter::query()->withoutGlobalScopes()->find($matterId);

        Audit::record('payment_received_mail_failed', $matter, [
            'payment_id' => $this->paymentId,
        ]);

        $recipients = $matter !== null
            ? app(ResolveStaffRecipients::class)->handle($matter, [$matter->leadLawyer])
            : User::query()->where('is_active', true)->role(Role::Admin->value)->get();

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title(__('client_payments.failed_notification.title'))
                ->body(__('client_payments.failed_notification.body', [
                    'code' => e($matter->code ?? ''),
                ]))
                ->color('danger')
                ->sendToDatabase($recipient);
        }
    }
}
