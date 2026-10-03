<?php

namespace App\Notifications\Staff;

use App\Actions\Document\ChecklistProgress;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindMissingDocuments;
use App\Models\Matter;
use Illuminate\Notifications\Notification;

/**
 * Thông báo TRONG HỆ THỐNG khi một hồ sơ có giấy tờ BẮT BUỘC khách chưa nộp quá
 * {@see ChecklistProgress::STUCK_AFTER_DAYS} ngày (SPEC §6.9, bullet cuối: "thông báo cho lead
 * lawyer là hồ sơ đang đình trệ vì thiếu giấy tờ" — để luật sư gọi điện). Gửi bởi
 * {@see RemindMissingDocuments}, một lần mỗi đợt thiếu.
 *
 * Cùng lý do {@see StaleMatterAlert} không dùng `Filament\Notifications\Notification`: nơi gọi là
 * một `App\Actions\*` (`tests/Feature/ArchitectureTest.php` cấm `Filament\...` ở đó), nên lớp này là
 * một `Illuminate\Notifications\Notification` thường, kênh `database`, tự dựng ĐÚNG hình dạng
 * mảng mà `Filament\Notifications\Notification::getDatabaseMessage()` sinh ra.
 *
 * `e()` trên `code`/`title`: thân thông báo được Filament vẽ THÔ (chỉ qua `sanitizeHtml`, vẫn giữ
 * `<a href>`), và tiêu đề vụ việc do nhân sự gõ tự do (cùng lỗi Task 4 fix round 1, C1).
 *
 * Người nhận do {@see ResolveStaffRecipients} chọn (R3): chỉ người `is_active` và qua
 * `Gate::view()` của vụ — một vụ `restricted` không lộ mã/tên hồ sơ cho ai không xem được nó
 * (Review Focus 1).
 */
class MissingDocumentsStuckAlert extends Notification
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
            'body' => __('matters.missing_documents_notification.body', [
                'code' => e($this->matter->code),
                'title' => e($this->matter->title),
            ]),
            'color' => 'warning',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'warning',
            'title' => __('matters.missing_documents_notification.title'),
            'view' => null,
            // Chống lặp của RemindMissingDocuments::alreadyNotified() — (người nhận, vụ việc, đợt).
            'viewData' => ['matter_id' => $this->matter->getKey()],
            'format' => 'filament',
        ];
    }
}
