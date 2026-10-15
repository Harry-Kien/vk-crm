<?php

namespace App\Notifications\Staff;

use App\Actions\Billing\NotifyLeadLawyerOfContractEntry;
use Illuminate\Notifications\Notification;

/**
 * Làn kt (2026-10-15) — chuông cho luật sư phụ trách khi MỘT NGƯỜI KHÁC (kế toán, trưởng phòng, quản
 * trị viên) nhập hay kích hoạt hợp đồng của vụ: "Kế toán X đã nhập hợp đồng HD-… cho hồ sơ VK-… (tổng
 * … ₫, n đợt) — anh/chị kiểm tra lại." Người nhận và lúc gửi: {@see NotifyLeadLawyerOfContractEntry}.
 *
 * **Ranh giới dữ liệu:** mã hợp đồng, mã hồ sơ, tổng giá trị, số đợt, ngày ký, tên và vai trò người
 * nhập — không tiêu đề vụ, không tên khách (`notifications.data` nằm lâu trong CSDL). Người nhận luôn
 * thấy tiền của vụ (qua `ResolveStaffRecipients::forBilling()`). Kênh `database`, không `ShouldQueue`:
 * một dòng chuông, gửi sau khi transaction tiền đã commit — không có thư (không thư nội bộ nào của vòng
 * đời hợp đồng tồn tại; thư tiền nội bộ duy nhất là nhắc đợt quá hạn).
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
            'actions' => [],
            'body' => __('contract_entry.notifications.'.$this->event, $this->replace),
            'color' => 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'info',
            'title' => __('contract_entry.notifications.title', ['matter' => $this->replace['matter']]),
            'view' => null,
            'viewData' => ['matter_id' => $this->matterId, 'contract_id' => $this->contractId],
            'format' => 'filament',
        ];
    }
}
