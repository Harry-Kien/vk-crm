<?php

namespace App\Notifications\Staff;

use App\Jobs\SendSuspendedHolderNotices;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * Chuông "người giữ việc vừa bị khoá truy cập" (kiểm toán luồng Công việc 2026-10-21, Q4) — chỉ
 * {@see SendSuspendedHolderNotices} gửi, MỘT chuông cho mỗi người giao (hay người nhận thay họ —
 * `ResolveStaffRecipients::forTaskAssigner(decisionNeeded: true)`, đã lọc người còn mở được việc) cho mọi việc của họ
 * mà người bị khoá đang giữ. Cùng khuôn chuông "đã rời đội ngũ" (`TeamDepartureAlert::TASKS_HANDLED`): kênh `database`,
 * `e()` mọi giá trị nội suy, không tên khách.
 *
 * Thân nêu tên người bị khoá, số việc và tối đa {@see self::TITLES_SHOWN} tiêu đề (người nhận xem được từng việc);
 * nút "Mở" về trang Công việc lọc sẵn đúng các việc đó ("Cần giao lại", người nhận là người bị khoá; tab "Tôi đã giao"
 * khi mọi việc do chính người nhận giao). `viewData.holder_suspended_key` là khoá chống trùng của job.
 */
class HolderSuspendedAlert extends Notification
{
    public const TITLES_SHOWN = 5;

    /** @param  list<string>  $titles  tiêu đề các việc, chưa escape */
    public function __construct(
        private readonly string $holderName,
        private readonly array $titles,
        private readonly string $url,
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
        $shown = array_slice($this->titles, 0, self::TITLES_SHOWN);
        $list = implode('; ', array_map(fn (string $title): string => '"'.e($title).'"', $shown))
            .(count($this->titles) > count($shown) ? '; …' : '');

        return [
            'actions' => [
                Action::make('open')
                    ->label(__('tasks.alert.open'))
                    ->url($this->url)
                    ->markAsRead()
                    ->toArray(),
            ],
            'body' => __('tasks.holder_suspended.body', [
                'who' => e($this->holderName),
                'count' => count($this->titles),
                'list' => $list,
            ]),
            'color' => 'warning',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'warning',
            'title' => __('tasks.holder_suspended.title', ['count' => count($this->titles)]),
            'view' => null,
            'viewData' => ['holder_suspended_key' => $this->key],
            'format' => 'filament',
        ];
    }
}
