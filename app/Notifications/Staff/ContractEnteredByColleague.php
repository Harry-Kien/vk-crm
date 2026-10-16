<?php

namespace App\Notifications\Staff;

use App\Actions\Billing\NotifyLeadLawyerOfContractEntry;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use Illuminate\Notifications\Notification;

/**
 * Làn kt (2026-10-15) — chuông cho luật sư phụ trách khi MỘT NGƯỜI KHÁC (kế toán, trưởng phòng, quản
 * trị viên) nhập hay kích hoạt hợp đồng của vụ: "Kế toán X đã nhập hợp đồng HD-… cho hồ sơ VK-… (tổng
 * … ₫, n đợt) — anh/chị kiểm tra lại." Người nhận và lúc gửi: {@see NotifyLeadLawyerOfContractEntry}.
 *
 * **Tiêu đề theo việc** (đợt polish, m2): `contract_entry.notifications.titles.{event}` — "vừa được
 * nhập (bản nháp)", "vừa được kích hoạt", "vừa được nhập và kích hoạt" — cùng ba khoá với câu thân.
 *
 * **Một nút "Mở tab Hợp đồng và thanh toán"** (đợt polish, m9): câu thân bảo người nhận "kiểm tra lại",
 * nên chuông dẫn thẳng tới tab tiền của vụ, chọn bằng `?relation=<khoá của tab>` — khoá tra ngược
 * bằng `array_search(BillingRelationManager::class, MatterResource::getRelations())`, cùng cách
 * `App\Mail\Staff\InstalmentOverdue` (vị trí của tab, không gõ tay một số). Nút dựng tay theo hình
 * dạng `Filament\Actions\Action::toArray()` mà chuông đọc lại, như {@see RetentionExpiryAlert}. Người
 * nhận mở được trang đó: người nhận (`NotifyLeadLawyerOfContractEntry`) là luật sư phụ trách hay, dự
 * phòng, người quản lý của vụ — cả hai đều qua `ResolveStaffRecipients::qualifiesForBilling()` (thấy tiền
 * của vụ); kế toán (không `matter.view`) không bao giờ là người nhận ở đây. Không có thư nào để thêm liên kết.
 *
 * **Ranh giới dữ liệu:** mã hợp đồng, mã hồ sơ, tổng giá trị, số đợt, ngày ký, tên và vai trò người
 * nhập — không tiêu đề vụ, không tên khách (`notifications.data` nằm lâu trong CSDL); URL chỉ mang id
 * vụ. Kênh `database`, không `ShouldQueue`: một dòng chuông, gửi sau khi transaction tiền đã commit —
 * không có thư (không thư nội bộ nào của vòng đời hợp đồng tồn tại; thư tiền nội bộ duy nhất là nhắc
 * đợt quá hạn).
 */
class ContractEnteredByColleague extends Notification
{
    /** @param  array<string, string|int>  $replace  tham số của câu `contract_entry.notifications.{event}` */
    public function __construct(
        private readonly string $event,
        private readonly array $replace,
        private readonly int $matterId,
        private readonly int $contractId,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'actions' => [[
                'name' => 'openBillingTab',
                'label' => __('contract_entry.notifications.open_tab'),
                // Dựng ngoài request của panel (sau commit, có khi từ lệnh dữ liệu mẫu): `panel: 'admin'`.
                'url' => MatterResource::getUrl('view', [
                    'record' => $this->matterId,
                    'relation' => array_search(BillingRelationManager::class, MatterResource::getRelations(), true),
                ], panel: 'admin'),
                'view' => 'filament-actions::link-action',
            ]],
            'body' => __('contract_entry.notifications.'.$this->event, $this->replace),
            'color' => 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'info',
            'title' => __('contract_entry.notifications.titles.'.$this->event, ['matter' => $this->replace['matter']]),
            'view' => null,
            'viewData' => ['matter_id' => $this->matterId, 'contract_id' => $this->contractId],
            'format' => 'filament',
        ];
    }
}
