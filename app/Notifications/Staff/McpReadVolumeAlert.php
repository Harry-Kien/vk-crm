<?php

namespace App\Notifications\Staff;

use App\Actions\Mcp\AlertOnMcpReadVolume;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Thông báo TRONG HỆ THỐNG (chuông của panel admin) khi một nhân sự đọc quá
 * {@see AlertOnMcpReadVolume::THRESHOLD} bản ghi qua trợ lý AI trong một giờ (M11 R8, Task 8,
 * [DC:156]). Gửi bởi {@see AlertOnMcpReadVolume}, tối đa một lần mỗi giờ cho mỗi nhân sự.
 *
 * Chỉ tên nhân sự và ngưỡng — không id, mã hay tiêu đề vụ việc nào (`notifications.data` là một nơi dữ
 * liệu nằm lâu, và người nhận không nhất thiết xem được mọi vụ người kia đã đọc). Chi tiết từng lần
 * gọi nằm ở trang Nhật ký hệ thống, lọc theo kênh AI.
 *
 * Cùng khuôn `IntakeUnansweredAlert`: kênh `database`, mảng đúng hình dạng Filament đọc được, không
 * `ShouldQueue` (một câu INSERT), gọi ngoài mọi transaction.
 */
class McpReadVolumeAlert extends Notification
{
    public function __construct(
        private readonly User $reader,
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
            'body' => __('mcp_audit.read_volume_alert.body', [
                'name' => $this->reader->name,
                'count' => AlertOnMcpReadVolume::THRESHOLD,
            ]),
            'color' => 'warning',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'warning',
            'title' => __('mcp_audit.read_volume_alert.title'),
            'view' => null,
            'viewData' => [
                'mcp_read_volume_user_id' => $this->reader->getKey(),
            ],
            'format' => 'filament',
        ];
    }
}
