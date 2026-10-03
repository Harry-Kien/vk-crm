<?php

namespace App\Notifications\Staff;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindUnseenUpdates;
use App\Models\Matter;
use App\Models\StageLog;
use App\Support\UnseenStageLogs;
use Illuminate\Notifications\Notification;

/**
 * Thông báo TRONG HỆ THỐNG khi khách chưa mở một cập nhật đã công bố quá
 * {@see UnseenStageLogs::AFTER_DAYS} ngày (SPEC §4.18, §7.1 mục 5) — để luật sư phụ trách GỌI ĐIỆN
 * cho khách. Gửi bởi {@see RemindUnseenUpdates}, một lần mỗi (người nhận, hồ sơ, dòng chưa xem
 * mới nhất).
 *
 * Cùng lý do {@see StaleMatterAlert} không dùng `Filament\Notifications\Notification`: nơi gọi là
 * một `App\Actions\*` (`tests/Feature/ArchitectureTest.php` cấm `Filament\...` ở đó), nên lớp này là
 * một `Illuminate\Notifications\Notification` thường, kênh `database`, tự dựng ĐÚNG hình dạng
 * mảng mà `Filament\Notifications\Notification::getDatabaseMessage()` sinh ra.
 *
 * Nội dung nói việc phải làm ("gọi điện cho khách"), kèm mã hồ sơ, tiêu đề, số cập nhật chưa xem và
 * ngày công bố cũ nhất. KHÔNG có `internal_note`, `public_content` hay tên khách (không cần: mã hồ
 * sơ là đủ để mở hồ sơ, và thông báo không nên chứa nhiều hơn thứ người nhận cần).
 *
 * `e()` trên `code`/`title`: thân thông báo được Filament vẽ THÔ (chỉ qua `sanitizeHtml`, vẫn giữ
 * `<a href>`), và tiêu đề vụ việc do nhân sự gõ tự do (cùng lỗi Task 4 fix round 1, C1).
 *
 * Người nhận do {@see ResolveStaffRecipients} chọn (R3): chỉ người `is_active` và qua
 * `Gate::view()` của vụ — một vụ `restricted` không lộ mã/tiêu đề hồ sơ cho ai không xem được nó
 * (Review Focus 1).
 */
class UnseenUpdatesAlert extends Notification
{
    public function __construct(
        private readonly Matter $matter,
        private readonly StageLog $newest,
        private readonly StageLog $oldest,
        private readonly int $count,
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
            'body' => __('matters.unseen_notification.body', [
                'code' => e($this->matter->code),
                'title' => e($this->matter->title),
                'count' => $this->count,
                'days' => UnseenStageLogs::AFTER_DAYS,
                'since' => $this->oldest->published_at?->format('d/m/Y') ?? '',
            ]),
            'color' => 'warning',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'warning',
            'title' => __('matters.unseen_notification.title'),
            'view' => null,
            // Chống lặp của RemindUnseenUpdates::alreadyNotified() — (người nhận, hồ sơ, lô chưa xem).
            'viewData' => [
                'matter_id' => $this->matter->getKey(),
                'stage_log_id' => $this->newest->getKey(),
            ],
            'format' => 'filament',
        ];
    }
}
