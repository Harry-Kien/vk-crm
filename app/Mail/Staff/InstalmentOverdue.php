<?php

namespace App\Mail\Staff;

use App\Filament\Admin\Pages\Receivables;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Jobs\SendInstalmentOverdueMail;
use App\Mail\BrandedMailable;
use App\Mail\OutboundHeaders;
use App\Models\Instalment;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\AccountantBillingRow;
use App\Support\Billing\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Gate;

/**
 * Mẫu `staff.instalment_overdue` của SPEC §9 — thư nội bộ về một đợt thanh toán quá hạn.
 *
 * **Nội dung CHỈ gồm các trường của {@see AccountantBillingRow}** (mã hồ sơ, loại vụ việc, tên khách
 * hàng, tên đợt, số tiền còn lại, ngày đến hạn) cộng số ngày quá hạn suy từ ngày đến hạn — đúng
 * ranh giới lộ thông tin mà màn hình "Công nợ" của kế toán mang, vì kế toán là một người nhận và
 * kế toán không có `matter.view`. **Không** tiêu đề vụ việc, tóm tắt, ghi chú nội bộ, tên các bên;
 * khác {@see DeadlineReminder} (đó là thư về một mốc thời hạn tố tụng và mang tiêu đề vụ việc). Lớp
 * này nhận dòng {@see AccountantBillingRow} đã dựng sẵn thay vì tự đọc `Matter`, nên không có đường
 * nào cho một trường khác lọt vào chỉ vì ai đó viết thêm một dòng ở view.
 *
 * **Liên kết (thư nội bộ đầu tiên có liên kết):** người vào được trang "Công nợ"
 * ({@see Receivables::canBeOpenedBy()}: admin, quản lý, kế toán) nhận liên kết tới trang đó — trang
 * đã lọc theo phạm vi của chính họ, và không có id vụ việc nào trong URL, nên kế toán (không xem
 * được trang vụ việc) không nhận một liên kết vào nơi họ bị 404. Người còn lại — luật sư phụ trách,
 * không có `revenue.viewAny` — nhận liên kết tới tab "Hợp đồng và thanh toán" của vụ, chọn bằng tham
 * số truy vấn `?relation=<khoá của tab>` (Filament 5, `HasRelationManagers::$activeRelationManager`).
 * Khoá đó tra bằng `array_search(BillingRelationManager::class, MatterResource::getRelations())` chứ
 * không gõ tay một số: `getRelations()` không đặt khoá chuỗi, nên khoá là VỊ TRÍ của tab, và một tab
 * khác chèn vào trước nó (M6/M7) đổi vị trí. Tra ngược thì liên kết đi theo.
 * `array_filter` của Filament giữ nguyên khoá, nên vị trí này không đổi theo việc tab nào đang bị ẩn.
 * URL dựng ngoài request (hàng đợi) qua `getUrl(panel: 'admin')`, không qua panel "hiện hành".
 *
 * Lớp này KHÔNG `ShouldQueue`: nó chỉ được dựng bên trong {@see SendInstalmentOverdueMail}, một job
 * ĐÃ nằm trên hàng đợi (M6.5 R2).
 */
class InstalmentOverdue extends BrandedMailable
{
    /** Tên mẫu theo SPEC §9 — một hằng để sổ thư và job không gõ tay lần thứ hai. */
    public const TEMPLATE = 'staff.instalment_overdue';

    public function __construct(
        public Instalment $instalment,
        public AccountantBillingRow $row,
        public User $recipient,
    ) {}

    /**
     * Khoá chống gửi trùng của sổ thư: `overdue@<ngày đến hạn>` — MỘT định nghĩa, ghi vào header ở
     * {@see self::additionalLedgerHeaders()} và so ở {@see SendInstalmentOverdueMail::alreadyReminded()}.
     * Xem docblock job, mục "Khoá mang ngày đến hạn".
     */
    public static function ledgerTier(string $dueDate): string
    {
        return 'overdue@'.$dueDate;
    }

    protected function template(): string
    {
        return self::TEMPLATE;
    }

    protected function relatedRecord(): ?Model
    {
        return $this->instalment;
    }

    /** @return array<string, string> */
    protected function additionalLedgerHeaders(): array
    {
        return [OutboundHeaders::LEDGER_TIER => self::ledgerTier($this->instalment->due_date->toDateString())];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('billing.overdue_email.subject', [
                'days' => $this->daysOverdue(),
                'instalment' => $this->row->instalmentName,
                'code' => $this->row->matterCode,
            ]),
        );
    }

    public function content(): Content
    {
        [$linkUrl, $linkLabel] = $this->link();

        return new Content(
            view: 'emails.staff.instalment-overdue',
            text: 'emails.staff.instalment-overdue-text',
            with: [
                'recipientName' => $this->recipient->name,
                'days' => $this->daysOverdue(),
                'matterCode' => $this->row->matterCode,
                'matterTypeName' => $this->row->matterTypeName,
                'clientName' => $this->row->clientName,
                'instalmentName' => $this->row->instalmentName,
                'outstanding' => Money::format($this->row->outstanding),
                'dueDate' => $this->row->dueDate?->format('d/m/Y'),
                'linkUrl' => $linkUrl,
                'linkLabel' => $linkLabel,
                'actionLine' => $this->actionLine(),
                'office' => config('vkcrm.brand.legal_name'),
            ],
        );
    }

    /**
     * Câu "việc cần làm" cuối thư, chọn theo cái NGƯỜI NHẬN này thật sự làm được — hỏi đúng hai
     * policy đang canh các nút đó, không suy từ vai:
     *
     *  - ghi khoản thu: `PaymentPolicy::create` theo ĐỢT (kế toán, admin; luật sư phụ trách chỉ trên
     *    vụ `restricted`; quản lý và luật sư trên vụ thường thì không);
     *  - cập nhật phụ lục: `ContractPolicy::update` (admin, quản lý, luật sư; kế toán thì không).
     *
     * Ai không làm được thì câu chỉ tới vai làm được ("Kế toán ghi khoản thu…", "luật sư phụ trách
     * cập nhật phụ lục…") thay vì bảo họ tự làm một việc mà giao diện không cho. Câu về thư nhắc lại
     * sau bảy ngày là câu chung, luôn có.
     */
    private function actionLine(): string
    {
        $gate = Gate::forUser($this->recipient);

        return implode(' ', [
            __($gate->allows('create', [Payment::class, $this->instalment])
                ? 'billing.overdue_email.action.record_self'
                : 'billing.overdue_email.action.record_other'),
            __($gate->allows('update', $this->instalment->contract)
                ? 'billing.overdue_email.action.amend_self'
                : 'billing.overdue_email.action.amend_other'),
            __('billing.overdue_email.action.repeat'),
        ]);
    }

    /** Số ngày ĐÃ trôi qua kể từ ngày đến hạn (đến hạn hôm qua = 1). */
    private function daysOverdue(): int
    {
        return $this->row->dueDate === null ? 0 : (int) $this->row->dueDate->startOfDay()->diffInDays(today());
    }

    /**
     * @return array{0: string, 1: string} URL tuyệt đối và nhãn của nút — xem docblock lớp.
     */
    private function link(): array
    {
        if (Receivables::canBeOpenedBy($this->recipient)) {
            return [Receivables::getUrl(panel: 'admin'), __('billing.overdue_email.open_receivables')];
        }

        $tab = array_search(BillingRelationManager::class, MatterResource::getRelations(), true);
        $parameters = ['record' => $this->instalment->contract->matter];

        if ($tab !== false) {
            $parameters['relation'] = $tab;
        }

        return [MatterResource::getUrl('view', $parameters, panel: 'admin'), __('billing.overdue_email.open_matter')];
    }
}
