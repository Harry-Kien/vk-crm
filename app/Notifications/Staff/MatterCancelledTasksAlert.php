<?php

namespace App\Notifications\Staff;

use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Jobs\SendMatterCancelledTaskNotices;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * Chuông "hồ sơ đã bị huỷ — việc của anh/chị trên hồ sơ này tạm ẩn" (gộp integ-fa, rà soát chéo 2026-10-18, mục 10) —
 * chỉ {@see SendMatterCancelledTaskNotices} gửi, sau commit của "Huỷ hồ sơ mở nhầm", MỘT chuông mỗi người đang giữ hay
 * đã giao một việc nội bộ còn mở của hồ sơ. Hồ sơ huỷ thì các việc đó rời trang Công việc của mọi người và thôi nhắc —
 * không có chuông này, người đang làm dở chỉ thấy việc biến mất.
 *
 * Mã hồ sơ chỉ khi job cho phép (`$code` rỗng: "một hồ sơ") — vụ hạn chế người nhận không mở được không bao giờ hiện mã.
 * Không tiêu đề việc, không tên khách. Nút "Mở" về trang Công việc (hồ sơ đã huỷ không mở được). Kênh `database`, không
 * `ShouldQueue`, `e()` mọi giá trị nội suy. `viewData.matter_cancelled_key` là khoá chống trùng của job.
 */
class MatterCancelledTasksAlert extends Notification
{
    /**
     * @param  string|null  $code  mã hồ sơ được phép hiện; `null`: câu chung "một hồ sơ"
     * @param  int  $held  số việc người nhận đang giữ (chờ nhận, đang làm) trên hồ sơ
     * @param  int  $assigned  số việc còn mở người nhận đã giao cho người khác trên hồ sơ
     */
    public function __construct(
        private readonly ?string $code,
        private readonly string $actorName,
        private readonly int $held,
        private readonly int $assigned,
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
        $items = array_values(array_filter([
            $this->held > 0 ? __('internal_flow.matter_cancelled.held', ['count' => $this->held]) : null,
            $this->assigned > 0 ? __('internal_flow.matter_cancelled.assigned', ['count' => $this->assigned]) : null,
        ]));

        return [
            'actions' => [
                Action::make('open')
                    ->label(__('internal_flow.matter_cancelled.open'))
                    ->url(TaskResource::getUrl('index', panel: 'admin'))
                    ->markAsRead()
                    ->toArray(),
            ],
            'body' => __('internal_flow.matter_cancelled.body', [
                'matter' => $this->code === null
                    ? __('internal_flow.team_removal.matter_hidden')
                    : __('internal_flow.team_removal.matter_named', ['code' => e($this->code)]),
                'who' => e($this->actorName),
                'items' => implode(__('internal_flow.matter_cancelled.and'), $items),
            ]),
            'color' => 'warning',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => 'warning',
            'title' => __('internal_flow.matter_cancelled.title'),
            'view' => null,
            'viewData' => ['matter_cancelled_key' => $this->key],
            'format' => 'filament',
        ];
    }
}
