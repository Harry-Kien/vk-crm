<?php

namespace App\Notifications\Staff;

use App\Jobs\SendMatterAssignmentNotice;
use App\Jobs\SendReassignmentDigest;
use App\Jobs\SendSeatReminderDigest;
use App\Jobs\SendWorkReminder;
use App\Models\Matter;
use App\Models\User;
use App\Support\Tasks\SeatInbox;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * Chuông TRONG HỆ THỐNG của "Nhận hồ sơ" (làn tk, Task 2) — một lớp cho năm loại lời báo về MỘT ghế trong
 * đội ngũ một vụ (hằng số dưới đây): người được giao ghế, người giao được báo đã nhận / bị trả lại, và hai
 * lời nhắc của lịch {@see SendWorkReminder} (người nhận chưa bấm "Đã nhận hồ sơ"; báo người giao khi đã
 * quá lâu); cộng ba loại GỘP (vòng sửa polish 1 — phán quyết controller (3)): một chuông cho nhiều hồ sơ
 * của một lô bàn giao hàng loạt ({@see self::ASSIGNED_BATCH}), và hai lời nhắc trên cho nhiều hồ sơ của
 * cùng một người trong một lượt lịch ({@see self::REMINDER_AWAITING_BATCH},
 * {@see self::REMINDER_UNACCEPTED_BATCH}). Chỉ {@see SendMatterAssignmentNotice}, {@see SendWorkReminder},
 * {@see SendReassignmentDigest} và {@see SendSeatReminderDigest} gửi nó, SAU khi đã lọc người nhận: đang
 * hoạt động, chưa xoá, còn xem được vụ (`ResolveStaffRecipients::qualifies()`) — một vụ hạn chế không bao
 * giờ tới người không mở được nó. Loại gộp: `$matter` là hồ sơ đầu (theo mã), `$batchCodes` là dòng của
 * từng hồ sơ (mã, hay "mã (người được giao)" với lời báo người giao).
 *
 * Cùng khuôn {@see TaskAlert}: kênh `database`, không `ShouldQueue`, `e()` mọi giá trị nội suy (lý do trả
 * lại là chữ người dùng gõ). Mang mã vụ và tên người, không tên khách, không tiêu đề vụ.
 *
 * `viewData.seat_key` là khoá chống trùng của job: `event@<id dòng nhật ký>` cho lời báo một sự kiện,
 * khoá của lần nhắc cho lời nhắc.
 *
 * Nút "Mở" (vòng sửa cuối 1, F2): lời báo cho NGƯỜI NGỒI GHẾ (được giao, nhắc chờ nhận) trỏ tới chỗ họ bấm
 * nhận — "Việc của tôi", hay tab "Đội ngũ" của vụ với người không mở được trang đó (quản trị viên…), câu
 * ":where" theo cùng luật ({@see SeatInbox}); lời báo cho người giao/người quản lý trỏ tab "Đội ngũ" của vụ,
 * nơi họ phân công lại.
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
     * Bàn giao hàng loạt (vòng sửa polish 1): gửi luật sư phụ trách mới — MỘT chuông cho nhiều hồ sơ chờ nhận,
     * rung bởi thư tổng hợp của lô (`SendReassignmentDigest`).
     */
    public const ASSIGNED_BATCH = 'assigned_batch';

    /** Lịch nhắc gộp (vòng sửa polish 1): {@see self::REMINDER_AWAITING} cho nhiều hồ sơ của một người. */
    public const REMINDER_AWAITING_BATCH = 'reminder_awaiting_batch';

    /** Lịch nhắc gộp (vòng sửa polish 1): {@see self::REMINDER_UNACCEPTED} cho nhiều hồ sơ, một bản mỗi người nhận. */
    public const REMINDER_UNACCEPTED_BATCH = 'reminder_unaccepted_batch';

    /** Số dòng hồ sơ in trong một chuông gộp; còn lại là "…". */
    public const BATCH_CODES_SHOWN = 10;

    /**
     * @param  string  $kind  một trong các hằng số của lớp
     * @param  string  $memberName  người ngồi ghế
     * @param  string  $actorName  người giao (ASSIGNED, ASSIGNED_BATCH), hay rỗng
     * @param  string  $key  khoá chống trùng (xem docblock lớp)
     * @param  list<string>  $batchCodes  dòng của từng hồ sơ của một loại gộp; rỗng với loại khác
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
        private readonly array $batchCodes = [],
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $toMember = in_array($this->kind, [self::ASSIGNED, self::ASSIGNED_BATCH, self::REMINDER_AWAITING, self::REMINDER_AWAITING_BATCH], true);
        $warning = in_array($this->kind, [self::DECLINED, self::REMINDER_AWAITING, self::REMINDER_UNACCEPTED, self::REMINDER_AWAITING_BATCH, self::REMINDER_UNACCEPTED_BATCH], true);
        $codes = array_slice($this->batchCodes, 0, self::BATCH_CODES_SHOWN);
        $codeList = e(implode(', ', $codes)).(count($this->batchCodes) > count($codes) ? ', …' : '');

        return [
            'actions' => [
                Action::make('open')
                    ->label(__('tasks.alert.open'))
                    ->url($toMember && $notifiable instanceof User
                        ? SeatInbox::urlFor($notifiable, $this->matter)
                        : SeatInbox::teamTabUrl((int) $this->matter->getKey()))
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
                'where' => $notifiable instanceof User ? __(SeatInbox::whereKey($notifiable)) : '',
                'count' => count($this->batchCodes),
                'codes' => $codeList,
            ]),
            'color' => $warning ? 'warning' : 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => $warning ? 'warning' : 'info',
            'title' => __('tasks.seat.alert.'.$this->kind.'.title', ['code' => e($this->matter->code), 'count' => count($this->batchCodes)]),
            'view' => null,
            'viewData' => ['matter_id' => $this->matter->getKey(), 'seat_key' => $this->key],
            'format' => 'filament',
        ];
    }
}
