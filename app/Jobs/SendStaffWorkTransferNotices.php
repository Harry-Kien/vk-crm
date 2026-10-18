<?php

namespace App\Jobs;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Matter\TransferStaffWork;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\Confidentiality;
use App\Enums\OutboundStatus;
use App\Mail\Staff\StaffWorkTransferred;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\StaffWorkTransferAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * MỘT lời báo cho cả lô "Chuyển việc nội bộ và ghế" ({@see TransferStaffWork}, làn fa2, Task 1, vòng sửa 1 — I2):
 * các Action con của lô chạy ở chế độ không báo (`AddTeamMember`, `ChangeDeadlineResponsible`, `RemoveTeamMember` với
 * `notify: false`), và job này thay chúng. Xếp `->afterCommit()` sau dòng nhật ký `staff_work_transferred`; payload
 * chỉ mang id (người cũ, người nhận, người bấm, dòng nhật ký, các hồ sơ có thay đổi) và các con số của lần chuyển.
 *
 *  - Người NHẬN: một chuông {@see StaffWorkTransferAlert::RECEIVED} + một thư {@see StaffWorkTransferred} — khi còn
 *    hoạt động, chưa xoá, không phải người bấm (người tự nhận việc thì không tự báo mình), và có ít nhất một thứ
 *    đã sang tay họ.
 *  - Người CŨ: một chuông {@see StaffWorkTransferAlert::HANDED_OVER} — khi còn hoạt động, chưa xoá, không phải người
 *    bấm. Không thư (cùng mức `TeamDepartureAlert::MEMBER_LEFT`).
 *
 * Mã hồ sơ: cùng luật {@see SendTeamDepartureNotices} — hiện khi vụ không hạn chế, hay người nhận lời báo còn mở được
 * nó (`ResolveStaffRecipients::qualifies()`, đọc lúc chạy); vụ hạn chế người đó không mở được, và hồ sơ đã huỷ sau lần
 * chuyển, chỉ được đếm.
 *
 * Các con số là của lần chuyển (đã xảy ra), không đọc lại: việc đã sang tay người nhận vẫn là "đã chuyển" dù sau đó
 * họ đã bấm nhận hay giao tiếp.
 *
 * # Chống trùng khi thử lại
 *
 * Khoá `staff_work@<id dòng nhật ký>` ở `viewData.staff_work_key` của chuông và `payload.tier` của dòng sổ thư.
 */
class SendStaffWorkTransferNotices implements ShouldQueue
{
    use Queueable;
    use ReadsWithoutPortalScope;

    public int $tries = 5;

    /**
     * @param  list<int>  $matterIds  hồ sơ có thay đổi trong lô (không gồm hồ sơ thất bại hay không quản lý được)
     * @param  array{tasks: int, followed: int, deadlines: int, requests: int, seats: int}  $counts
     */
    public function __construct(
        public readonly int $fromId,
        public readonly int $toId,
        public readonly int $actorId,
        public readonly int $eventId,
        public readonly array $matterIds,
        public readonly array $counts,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $from = User::query()->withTrashed()->find($this->fromId);
        $to = User::query()->withTrashed()->find($this->toId);

        if ($from === null || $to === null) {
            return;
        }

        $key = 'staff_work@'.$this->eventId;
        $actorName = (string) User::query()->withTrashed()->whereKey($this->actorId)->value('name');
        $matters = $this->scopelessly(Matter::query())->withTrashed()->whereKey($this->matterIds)->orderBy('id')->get();
        $counts = $this->counts + ['tasks' => 0, 'followed' => 0, 'deadlines' => 0, 'requests' => 0, 'seats' => 0];

        if ($this->reachable($to) && array_sum($counts) > 0) {
            [$codes, $hidden] = $this->named($to, $matters, $recipients);

            $this->ring($to, new StaffWorkTransferAlert(StaffWorkTransferAlert::RECEIVED, $actorName, (string) $from->name, (string) $to->name, $codes, $hidden, $counts, $key), $key);

            if (! $this->alreadyMailed($to, $key)) {
                Mail::to($to->email)->send(new StaffWorkTransferred($to, $actorName, (string) $from->name, $codes, $hidden, $counts, $key));
            }
        }

        if ($this->reachable($from)) {
            [$codes, $hidden] = $this->named($from, $matters, $recipients);

            $this->ring($from, new StaffWorkTransferAlert(StaffWorkTransferAlert::HANDED_OVER, $actorName, (string) $from->name, (string) $to->name, $codes, $hidden, $counts, $key), $key);
        }
    }

    private function reachable(User $user): bool
    {
        return $user->is_active && ! $user->trashed() && $user->getKey() !== $this->actorId;
    }

    /**
     * Mã hồ sơ `$user` được thấy, và số hồ sơ còn lại chỉ được đếm — xem docblock lớp.
     *
     * @param  Collection<int, Matter>  $matters
     * @return array{0: list<string>, 1: int}
     */
    private function named(User $user, Collection $matters, ResolveStaffRecipients $recipients): array
    {
        [$named, $hidden] = $matters->partition(fn (Matter $matter): bool => ! $matter->trashed()
            && ($matter->confidentiality !== Confidentiality::Restricted || $recipients->qualifies($user, $matter)));

        return [$named->map(fn (Matter $matter): string => (string) $matter->code)->values()->all(), $hidden->count()];
    }

    private function ring(User $user, StaffWorkTransferAlert $alert, string $key): void
    {
        $already = $user->notifications()
            ->where('type', StaffWorkTransferAlert::class)
            ->where('data->viewData->staff_work_key', $key)
            ->exists();

        if (! $already) {
            $user->notify($alert);
        }
    }

    private function alreadyMailed(User $recipient, string $key): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('template', StaffWorkTransferred::TEMPLATE)
            ->where('recipient', $recipient->email)
            ->where('payload->tier', $key)
            ->where('status', OutboundStatus::Sent)
            ->exists();
    }
}
