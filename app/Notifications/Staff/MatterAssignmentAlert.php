<?php

namespace App\Notifications\Staff;

use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Jobs\SendMatterAssignmentNotice;
use App\Jobs\SendWorkReminder;
use App\Models\Matter;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * Chuông TRONG HỆ THỐNG của "Nhận hồ sơ" (làn tk, Task 2) — một lớp cho năm loại lời báo về MỘT ghế trong
 * đội ngũ một vụ (hằng số dưới đây): người được giao ghế, người giao được báo đã nhận / bị trả lại, và hai
 * lời nhắc của lịch {@see SendWorkReminder} (người nhận chưa bấm "Đã nhận hồ sơ"; báo người giao khi đã
 * quá lâu). Chỉ {@see SendMatterAssignmentNotice} và {@see SendWorkReminder} gửi nó, SAU khi đã lọc người
 * nhận: đang hoạt động, chưa xoá, còn xem được vụ (`ResolveStaffRecipients::qualifies()`) — một vụ hạn chế
 * không bao giờ tới người không mở được nó.
 *
 * Cùng khuôn {@see TaskAlert}: kênh `database`, không `ShouldQueue`, `e()` mọi giá trị nội suy (lý do trả
 * lại là chữ người dùng gõ). Mang mã vụ và tên người, không tên khách, không tiêu đề vụ.
 *
 * `viewData.seat_key` là khoá chống trùng của job: `event@<id dòng nhật ký>` cho lời báo một sự kiện,
 * khoá của lần nhắc cho lời nhắc.
 */
class MatterAssignmentAlert extends Notification
{
    /** Gửi người vừa được giao ghế: bấm "Đã nhận hồ sơ" hay "Trả lại". */
    public const ASSIGNED = 'assigned';

    /** Gửi người giao: người nhận đã bấm "Đã nhận hồ sơ". */
    public const ACCEPTED = 'accepted';

    /** Gửi người giao, luật sư phụ trách, người giám sát vụ: người nhận đã trả lại, kèm lý do. */
    public const DECLINED = 'declined';

    /** Lịch nhắc: gửi người nhận — ghế đã chờ quá ngưỡng giờ làm việc. */
    public const REMINDER_AWAITING = 'reminder_awaiting';

    /** Lịch nhắc: gửi người giao — người nhận vẫn chưa nhận sau ngưỡng thứ hai. */
    public const REMINDER_UNACCEPTED = 'reminder_unaccepted';

    /**
     * @param  string  $kind  một trong các hằng số của lớp
     * @param  string  $memberName  người ngồi ghế
     * @param  string  $actorName  người giao (ASSIGNED), hay rỗng
     * @param  string  $key  khoá chống trùng (xem docblock lớp)
     */
    public function __construct(
        private readonly Matter $matter,
        private readonly string $kind,
        private readonly string $memberName,
        private readonly string $actorName,
        private readonly string $roleLabel,
        private readonly ?string $reason,
        private readonly string $key,
        private readonly string $since = '',
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $toMember = in_array($this->kind, [self::ASSIGNED, self::REMINDER_AWAITING], true);
        $warning = in_array($this->kind, [self::DECLINED, self::REMINDER_AWAITING, self::REMINDER_UNACCEPTED], true);

        return [
            'actions' => [
                Action::make('open')
                    ->label(__('tasks.alert.open'))
                    ->url($toMember
                        ? TeamMember::getUrl(['user' => $notifiable->getKey()], panel: 'admin')
                        : MatterResource::getUrl('view', ['record' => $this->matter->getKey()], panel: 'admin'))
                    ->markAsRead()
                    ->toArray(),
            ],
            'body' => __('tasks.seat.alert.'.$this->kind.'.body', [
                'code' => e($this->matter->code),
                'member' => e($this->memberName),
                'who' => e($this->actorName),
                'role' => e($this->roleLabel),
                'reason' => e((string) $this->reason),
                'since' => e($this->since),
            ]),
            'color' => $warning ? 'warning' : 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => $warning ? 'warning' : 'info',
            'title' => __('tasks.seat.alert.'.$this->kind.'.title', ['code' => e($this->matter->code)]),
            'view' => null,
            'viewData' => ['matter_id' => $this->matter->getKey(), 'seat_key' => $this->key],
            'format' => 'filament',
        ];
    }
}
