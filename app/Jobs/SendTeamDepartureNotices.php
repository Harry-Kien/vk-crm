<?php

namespace App\Jobs;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Matter\RemoveTeamMember;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\Confidentiality;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TeamDepartureAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Lời báo của MỘT lần gỡ thành viên khỏi đội ngũ ({@see RemoveTeamMember}, làn fa2, phán quyết 1). Xếp
 * `->afterCommit()`; payload chỉ mang id: vụ, người bị gỡ, người bấm, dòng `team_member_removed`, các việc đã chuyển
 * (`$movedTaskIds`, sang `$recipientId`) và các việc để "Cần giao lại" (`$leftTaskIds`).
 *
 * # Ai nhận, đọc lại lúc chạy
 *
 *  - Người bị gỡ: còn hoạt động, chưa xoá, không phải người bấm — {@see TeamDepartureAlert::MEMBER_LEFT}, một chuông
 *    kể cả khi không giữ việc nào. Mã hồ sơ hiện khi vụ không hạn chế (họ vừa ngồi trong đội ngũ, đã biết mã) hay họ
 *    vẫn mở được hồ sơ (`qualifies()`); vụ hạn chế họ không mở được thì câu chung.
 *  - Với mỗi việc còn đúng như lần gỡ để lại (việc đã chuyển: người giữ vẫn là người nhận thay; việc để lại: người bị
 *    gỡ vẫn giữ, chưa ai xử lý), người giao của nó hay người nhận thay họ (`ResolveStaffRecipients::forTaskAssigner()`
 *    — chỉ người mở được việc, nên vụ hạn chế không lộ), trừ người bấm, người bị gỡ và người nhận thay (họ đã có chuông
 *    "được giao" của từng việc) — {@see TeamDepartureAlert::TASKS_HANDLED}, một chuông mỗi người cho mọi việc của họ.
 *    Gộp integ-fa: đây là lời báo DUY NHẤT người giao nhận về lần gỡ (`RemoveTeamMember` gọi "Giao lại" với
 *    `notifyAssigner: false`); người nhận thay là chính người bấm (nhận thay) thì câu nói các việc đã ở "Đang làm".
 *    Gộp integ-fa (rà soát chéo, mục 6): với việc ĐÃ CHUYỂN lời báo chỉ là tin (`forTaskAssigner(decisionNeeded:
 *    false)` — dừng ở luật sư phụ trách, không rơi tới người quản lý của vụ); việc để "Cần giao lại" cần người quyết
 *    định nên giữ cả chuỗi.
 *
 * # Luật sư phụ trách theo dõi thay (sửa sau kiểm toán 2026-10-21, mục 3)
 *
 * Người bị gỡ không còn mở được hồ sơ thì `RemoveTeamMember` đổi người giao của các việc còn mở họ đã giao sang luật sư
 * phụ trách (`$followedTaskIds` — trừ việc thành việc riêng của luật sư phụ trách). Luật sư phụ trách nhận MỘT chuông
 * {@see TeamDepartureAlert::TASKS_FOLLOWED} nêu số việc nay họ theo dõi — trừ khi chính họ bấm gỡ (hộp thoại đã nói con
 * số đó).
 *
 * # Chống trùng khi thử lại
 *
 * Khoá `team@<id dòng nhật ký>` ở `viewData.team_departure_key` (`#followed` cho chuông theo dõi thay).
 */
class SendTeamDepartureNotices implements ShouldQueue
{
    use Queueable;
    use ReadsWithoutPortalScope;

    public int $tries = 5;

    /**
     * Sửa sau kiểm toán 2026-10-21 (mục 3): các việc người bị gỡ đã giao mà `RemoveTeamMember` vừa đổi người giao sang
     * luật sư phụ trách (docblock lớp, mục "Luật sư phụ trách theo dõi thay"). Không `readonly`, có giá trị mặc định: một
     * job xếp TRƯỚC bản cập nhật (payload không có thuộc tính này) unserialize bằng giá trị mặc định — cùng cách
     * `SendMatterAssignmentNotice::$withMail`.
     *
     * @var list<int>
     */
    public array $followedTaskIds = [];

    /**
     * @param  list<int>  $movedTaskIds
     * @param  list<int>  $leftTaskIds
     * @param  list<int>  $followedTaskIds
     */
    public function __construct(
        public readonly int $matterId,
        public readonly int $memberId,
        public readonly int $actorId,
        public readonly int $eventId,
        public readonly array $movedTaskIds,
        public readonly array $leftTaskIds,
        public readonly ?int $recipientId,
        array $followedTaskIds = [],
    ) {
        $this->followedTaskIds = $followedTaskIds;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ResolveStaffRecipients $recipients): void
    {
        $matter = $this->scopelessly(Matter::query())->find($this->matterId);
        $member = User::query()->withTrashed()->find($this->memberId);

        if ($matter === null || $member === null) {
            return;
        }

        $key = 'team@'.$this->eventId;
        $actorName = (string) User::query()->withTrashed()->whereKey($this->actorId)->value('name');
        $toName = $this->recipientId === null ? '' : (string) User::query()->withTrashed()->whereKey($this->recipientId)->value('name');

        if ($member->is_active && ! $member->trashed() && $member->getKey() !== $this->actorId) {
            $named = $matter->confidentiality !== Confidentiality::Restricted || $recipients->qualifies($member, $matter);

            $this->ring($member, new TeamDepartureAlert(
                TeamDepartureAlert::MEMBER_LEFT,
                (int) $matter->getKey(),
                $named ? (string) $matter->code : null,
                (string) $member->name,
                $actorName,
                count($this->movedTaskIds),
                count($this->leftTaskIds),
                $toName,
                $key,
            ), $key);
        }

        /** @var array<int, array{user: User, moved: int, left: int}> $assigners */
        $assigners = [];

        // Gộp integ-fa (rà soát chéo, mục 6): việc đã chuyển — lời báo chỉ là TIN (đã có người giữ mới), không rơi tới
        // người quản lý của vụ; việc để "Cần giao lại" cần một người quyết định — giữ cả chuỗi.
        foreach ($this->stillAsLeft() as [$task, $moved]) {
            foreach ($recipients->forTaskAssigner($task, decisionNeeded: ! $moved) as $user) {
                if (in_array($user->getKey(), [$this->actorId, $this->memberId, $this->recipientId], true)) {
                    continue;
                }

                $assigners[$user->getKey()] ??= ['user' => $user, 'moved' => 0, 'left' => 0];
                $assigners[$user->getKey()][$moved ? 'moved' : 'left']++;
            }
        }

        foreach ($assigners as $row) {
            $this->ring($row['user'], new TeamDepartureAlert(
                TeamDepartureAlert::TASKS_HANDLED,
                (int) $matter->getKey(),
                (string) $matter->code,
                (string) $member->name,
                $actorName,
                $row['moved'],
                $row['left'],
                $toName,
                $key,
                // Gộp integ-fa: người bấm tự nhận các việc (ReassignTask takeOver) — chúng ở "Đang làm" của họ.
                takenOver: $this->recipientId !== null && $this->recipientId === $this->actorId,
            ), $key);
        }

        $this->ringLeadAboutFollowedTasks($matter, $member, $actorName, $key, $recipients);
    }

    /**
     * Docblock lớp, mục "Luật sư phụ trách theo dõi thay": các việc trong `$followedTaskIds` còn mở và còn do luật sư phụ
     * trách HIỆN TẠI giao (đọc lại lúc chạy). Luật sư phụ trách còn hoạt động, mở được hồ sơ, không phải người bấm: MỘT
     * chuông {@see TeamDepartureAlert::TASKS_FOLLOWED} với số việc. Khoá riêng (`#followed`): họ có thể đã nhận chuông
     * {@see TeamDepartureAlert::TASKS_HANDLED} của cùng lần gỡ.
     */
    private function ringLeadAboutFollowedTasks(Matter $matter, User $member, string $actorName, string $key, ResolveStaffRecipients $recipients): void
    {
        $lead = $matter->lead_lawyer_id === null ? null : User::query()->find($matter->lead_lawyer_id);

        if ($this->followedTaskIds === [] || $lead === null || $lead->getKey() === $this->actorId || ! $recipients->qualifies($lead, $matter)) {
            return;
        }

        $count = $this->scopelessly(Task::query())
            ->whereKey($this->followedTaskIds)
            ->where('assigned_by', $lead->getKey())
            ->open()
            ->count();

        if ($count === 0) {
            return;
        }

        $this->ring($lead, new TeamDepartureAlert(
            TeamDepartureAlert::TASKS_FOLLOWED,
            (int) $matter->getKey(),
            (string) $matter->code,
            (string) $member->name,
            $actorName,
            $count,
            0,
            '',
            $key.'#followed',
        ), $key.'#followed');
    }

    /**
     * Việc còn đúng như lần gỡ để lại — xem docblock lớp. Cặp `[việc, đã chuyển?]`.
     *
     * @return list<array{0: Task, 1: bool}>
     */
    private function stillAsLeft(): array
    {
        $rows = [];

        foreach ([[$this->movedTaskIds, true, $this->recipientId], [$this->leftTaskIds, false, $this->memberId]] as [$ids, $moved, $holderId]) {
            if ($ids === [] || $holderId === null) {
                continue;
            }

            $tasks = $this->scopelessly(Task::query())
                ->whereKey($ids)
                ->where('assignee_id', $holderId)
                ->held()
                ->with(['assigner', 'matter.leadLawyer'])
                ->orderBy('id')
                ->get();

            foreach ($tasks as $task) {
                $rows[] = [$task, $moved];
            }
        }

        return $rows;
    }

    private function ring(User $user, TeamDepartureAlert $alert, string $key): void
    {
        $already = $user->notifications()
            ->where('type', TeamDepartureAlert::class)
            ->where('data->viewData->team_departure_key', $key)
            ->exists();

        if (! $already) {
            $user->notify($alert);
        }
    }
}
