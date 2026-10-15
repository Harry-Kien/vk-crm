<?php

namespace App\Actions\Matter\Concerns;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\MatterAssignmentStatus;
use App\Jobs\SendMatterAssignmentNotice;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * Mở GHẾ của chính người đang thao tác trong đội ngũ một vụ để ghi — dùng bởi
 * `AcceptMatterAssignment` và `DeclineMatterAssignment` (làn tk, Task 2). Gọi BÊN TRONG
 * `DB::transaction()` của Action.
 *
 * # Thứ tự khoá: vụ việc trước, ghế sau
 *
 * Câu ĐẦU TIÊN là khoá dòng `matters` (thứ tự toàn cục của dự án — `ReassignMatter`, `RemoveTeamMember`
 * cũng khoá dòng vụ trước khi chạm `matter_user`), rồi khoá dòng ghế. Một lần "Bàn giao" hay "Gỡ thành
 * viên" và một lần "Đã nhận hồ sơ" trên cùng vụ vì vậy xếp hàng trên dòng vụ: lần sau đọc đúng ghế lần
 * trước đã ghi (ghế đã bị gỡ thì không còn gì để nhận).
 *
 * # Một câu từ chối chung
 *
 * Vụ không còn (đã huỷ), tài khoản đã bị vô hiệu, người gọi không còn xem được vụ, hay không có ghế trong
 * đội ngũ: cùng một câu `tasks.seat.errors.unavailable` — không phân biệt được với nhau (SPEC §10.10).
 * Ghế CÓ nhưng ở tình trạng khác (đã trả lại rồi muốn nhận, đã nhận rồi muốn trả lại): câu nói tình trạng
 * hiện tại ({@see self::refuseSeatState()}), vì người đó vốn thấy nó trên màn hình.
 */
trait OpensMatterSeat
{
    use ChecksAccountActive;
    use ReadsWithoutPortalScope;

    /** @return array{0: Matter, 1: MatterUser} vụ và ghế, cả hai đã khoá */
    protected function openSeat(Matter $matter, User $actor): array
    {
        $locked = $this->scopelessly(Matter::query())->lockForUpdate()->find($matter->getKey());

        if ($locked === null || ! $this->accountIsActive($actor) || Gate::forUser($actor)->denies('view', $locked)) {
            $this->refuseSeat();
        }

        $seat = $this->scopelessly(MatterUser::query())
            ->where('matter_id', $locked->getKey())
            ->where('user_id', $actor->getKey())
            ->lockForUpdate()
            ->first();

        if ($seat === null) {
            $this->refuseSeat();
        }

        return [$locked, $seat];
    }

    protected function refuseSeat(): never
    {
        throw new AuthorizationException(__('tasks.seat.errors.unavailable'));
    }

    protected function refuseSeatState(MatterAssignmentStatus $status): never
    {
        throw new DomainException(__('tasks.seat.errors.not_in_state', ['status' => $status->label()]));
    }

    /**
     * Xếp lời báo của một lần giao/nhận/trả lại ghế cho MỘT người — `->afterCommit()` (M6.5 R2); job
     * lọc lại người nhận khi chạy ({@see SendMatterAssignmentNotice}). `$activity` là dòng nhật ký của
     * chính lần đó: id của nó là khoá chống trùng.
     */
    protected function queueSeatNotice(Matter $matter, int $memberId, string $kind, ?int $recipientId, ?Activity $activity): void
    {
        if ($recipientId === null || $activity === null) {
            return;
        }

        SendMatterAssignmentNotice::dispatch($matter->getKey(), $memberId, $kind, $recipientId, $activity->getKey())->afterCommit();
    }
}
