<?php

namespace App\Notifications\Staff;

use App\Actions\Schedule\CheckStaleMatters;
use App\Models\Matter;
use App\Support\MatterStaleness;
use Illuminate\Notifications\Notification;

/**
 * Thông báo TRONG HỆ THỐNG khi một hồ sơ quá 14 ngày chưa cập nhật cho khách (SPEC §6.4) —
 * {@see CheckStaleMatters}, mốc 14 ngày của {@see MatterStaleness::DANGER_AFTER_DAYS}.
 *
 * Cùng lý do {@see DeadlineOverdueAlert} KHÔNG dùng `Filament\Notifications\Notification`: nơi
 * gọi nó là `CheckStaleMatters`, một `App\Actions\*`, và `tests/Feature/ArchitectureTest.php` cấm
 * mọi lớp trong đó dùng `Filament\...`. Lớp này là một `Illuminate\Notifications\Notification`
 * thường, kênh `database`, tự dựng ĐÚNG hình dạng mảng mà
 * `Filament\Notifications\Notification::getDatabaseMessage()` sinh ra, để chuông của panel đọc
 * được qua `Notification::fromDatabase()` như mọi thông báo khác.
 *
 * `e()` trên `code`/`title` (Task 4 fix round 1, C1 — cùng lý lẽ: thân thông báo này render RAW
 * qua `sanitizeHtml` của Filament, thứ vẫn giữ lại `<a href>`/`<img>`/`style`; một tiêu đề vụ việc
 * do nhân sự gõ tự do là dữ liệu người dùng, không phải hằng số).
 *
 * Cùng đối tượng nhận với R3 (M6.5 Task 8): `CheckStaleMatters::recipientsFor($matter, 'notice')`
 * — chỉ luật sư phụ trách (qua chuỗi dự phòng "không bao giờ im lặng" của
 * `ResolveStaffRecipients`), nên chỉ người qua được `Gate::view()` của vụ mới thấy mã/tên hồ sơ ở
 * đây — một vụ `restricted` không lộ gì cho người không xem được nó (Review Focus 1).
 */
class StaleMatterAlert extends Notification
{
    public function __construct(
        private readonly Matter $matter,
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
            'body' => __('matters.stale_notification.body', [
                'code' => e($this->matter->code),
                'title' => e($this->matter->title),
            ]),
            'color' => 'warning',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'warning',
            'title' => __('matters.stale_notification.title'),
            'view' => null,
            // Chống lặp của CheckStaleMatters::alreadyNotified() — (người nhận, vụ việc, đợt).
            'viewData' => ['matter_id' => $this->matter->getKey()],
            'format' => 'filament',
        ];
    }
}
