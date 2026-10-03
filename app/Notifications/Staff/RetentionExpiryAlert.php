<?php

namespace App\Notifications\Staff;

use App\Actions\Schedule\FlagRetentionExpiry;
use App\Models\Matter;
use App\Models\MatterArchive;
use Illuminate\Notifications\Notification;

/**
 * M7 Task 6 (R5) — thông báo TRONG HỆ THỐNG (chuông của panel admin) khi một hồ sơ đã quá hạn lưu
 * trữ mà chưa ghi quyết định tiêu huỷ. Gửi bởi {@see FlagRetentionExpiry}, chỉ tới admin đang hoạt
 * động (`ResolveStaffRecipients::activeAdminsFor()`), nên mã hồ sơ của một vụ `restricted` chỉ tới
 * người xem được nó.
 *
 * Cùng hình dạng {@see DeadlineOverdueAlert}: một `Illuminate\Notifications\Notification` kênh
 * `database` (Action không được dùng Filament — `ArchitectureTest`), `toDatabase()` dựng đúng mảng
 * mà `Filament\Notifications\Notification::getDatabaseMessage()` sinh ra. Một nút "Mở vụ việc" dựng
 * tay theo hình dạng `Filament\Actions\Action::toArray()` mà `Action::fromArray()` đọc lại (chỉ
 * `name`, `label`, `url`, `view` — các khoá còn lại có mặc định).
 *
 * Không `ShouldQueue`: kênh `database` chỉ là một câu INSERT; R2 ("mọi thư qua hàng đợi") nói về
 * thư. Không có thư nào ở đây.
 *
 * `viewData.matter_id` + `viewData.retention_until` là khoá chống lặp của
 * `FlagRetentionExpiry::alreadyAlerted()`: một người nhận đúng MỘT cảnh báo cho mỗi lần một hồ sơ
 * quá hạn — hồ sơ được đóng lại với hạn mới rồi quá hạn lần nữa thì là một lần mới.
 */
class RetentionExpiryAlert extends Notification
{
    public function __construct(
        private readonly Matter $matter,
        private readonly MatterArchive $archive,
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
                'name' => 'openMatter',
                'label' => __('archive.retention_alert.open'),
                'url' => route('filament.admin.resources.matters.view', ['record' => $this->matter->getKey()]),
                'view' => 'filament-actions::link-action',
            ]],
            'body' => __('archive.retention_alert.body', [
                'code' => $this->matter->code,
                'date' => $this->archive->retention_until?->format('d/m/Y') ?? '—',
            ]),
            'color' => 'warning',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'warning',
            'title' => __('archive.retention_alert.title'),
            'view' => null,
            'viewData' => [
                'matter_id' => $this->matter->getKey(),
                'retention_until' => $this->archive->retention_until?->toDateString(),
            ],
            'format' => 'filament',
        ];
    }
}
