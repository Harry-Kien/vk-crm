<?php

namespace App\Notifications\Staff;

use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Jobs\SendDeadlineHandedAwayNotice;
use App\Support\MatterTab;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * Chuông TIN "mốc anh/chị phụ trách đã chuyển cho X" (gộp integ-fa, rà soát chéo 2026-10-18, mục 16) — chỉ
 * {@see SendDeadlineHandedAwayNotice} gửi, cho người giữ mốc CŨ khi người khác đổi người phụ trách mốc ("Đổi người phụ
 * trách", sửa mốc). Không thư: người cũ không phải làm gì, chỉ cần biết thôi theo dõi mốc đó.
 *
 * Tên mốc, mã hồ sơ, người nhận chỉ khi job cho phép (`$named` — người cũ còn mở được hồ sơ); không thì câu chung — vụ
 * hạn chế không lộ gì. Kênh `database`, không `ShouldQueue`, `e()` mọi giá trị nội suy (tên mốc là chữ người dùng gõ).
 * `viewData.deadline_handed_away_key` là khoá chống trùng của job.
 */
class DeadlineHandedAwayAlert extends Notification
{
    public function __construct(
        private readonly int $matterId,
        private readonly bool $named,
        private readonly string $deadlineName,
        private readonly string $matterCode,
        private readonly string $toName,
        private readonly string $actorName,
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
        return [
            'actions' => $this->named ? [
                Action::make('open')
                    ->label(__('internal_flow.deadline_handed_away.open'))
                    ->url(MatterTab::url($this->matterId, DeadlinesRelationManager::class))
                    ->markAsRead()
                    ->toArray(),
            ] : [],
            'body' => __($this->named ? 'internal_flow.deadline_handed_away.body' : 'internal_flow.deadline_handed_away.body_hidden', [
                'name' => e($this->deadlineName),
                'code' => e($this->matterCode),
                'to' => e($this->toName),
                'who' => e($this->actorName),
            ]),
            'color' => 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'info',
            'title' => __('internal_flow.deadline_handed_away.title'),
            'view' => null,
            'viewData' => ['deadline_handed_away_key' => $this->key],
            'format' => 'filament',
        ];
    }
}
