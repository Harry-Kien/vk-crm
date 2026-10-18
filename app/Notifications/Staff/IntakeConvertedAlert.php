<?php

namespace App\Notifications\Staff;

use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Jobs\SendIntakeConvertedNotice;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * Chuông TIN "bản ghi tiếp nhận anh/chị phụ trách đã thành hồ sơ" (gộp integ-fa, rà soát chéo 2026-10-18, mục 15) —
 * chỉ {@see SendIntakeConvertedNotice} gửi, cho người đang được giao bản ghi tiếp nhận (người tư vấn) khi NGƯỜI KHÁC
 * bấm "Chuyển thành hồ sơ" và người đó không phải luật sư phụ trách của hồ sơ mới (luật sư phụ trách đã có chuông + thư
 * "được giao hồ sơ" của `OpenMatter`).
 *
 * `$matterCode` / `$leadName` chỉ khi người nhận mở được hồ sơ mới (job hỏi `ResolveStaffRecipients::qualifies()`);
 * không thì câu chung — vụ hạn chế không lộ mã hay luật sư phụ trách. Kênh `database`, không `ShouldQueue`, `e()` mọi
 * giá trị nội suy. `viewData.intake_converted_key` là khoá chống trùng của job.
 */
class IntakeConvertedAlert extends Notification
{
    public function __construct(
        private readonly string $intakeCode,
        private readonly string $actorName,
        private readonly ?int $matterId,
        private readonly ?string $matterCode,
        private readonly ?string $leadName,
        private readonly string $key,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $named = $this->matterCode !== null && $this->matterId !== null;

        return [
            'actions' => $named ? [
                Action::make('open')
                    ->label(__('intake.converted_alert.open'))
                    ->url(MatterResource::getUrl('view', ['record' => $this->matterId], panel: 'admin'))
                    ->markAsRead()
                    ->toArray(),
            ] : [],
            'body' => __($named ? 'intake.converted_alert.body' : 'intake.converted_alert.body_hidden', [
                'intake' => e($this->intakeCode),
                'who' => e($this->actorName),
                'matter' => e((string) $this->matterCode),
                'lead' => e((string) $this->leadName),
            ]),
            'color' => 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'info',
            'title' => __('intake.converted_alert.title'),
            'view' => null,
            'viewData' => ['intake_converted_key' => $this->key],
            'format' => 'filament',
        ];
    }
}
