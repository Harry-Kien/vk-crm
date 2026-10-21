<?php

namespace App\Actions\Matter;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Portal\TriageClientRequest;
use App\Actions\Task\MoveTasksOnHandover;
use App\Actions\Task\ReassignTask;
use App\Enums\MatterRole;
use App\Enums\TaskStatus;
use App\Exceptions\TeamMemberHasOpenWork;
use App\Jobs\SendTeamDepartureNotices;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit;
use App\Support\OpenWork;
use App\Support\Tasks\ReassignmentDueDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Gỡ MỘT thành viên khỏi đội ngũ vụ việc (SPEC §4.7, §7.2; M6.5 Task 3, R6).
 *
 * Cổng: `MatterPolicy::manageTeam` — cùng cổng với {@see AddTeamMember}, cùng lý do (đọc docblock
 * lớp đó cho lý do `matter_user` cần một đường ghi có Action đứng sau, và vì sao `observer` không
 * tự hạn chế quyền gì).
 *
 * # Vai `lead` KHÔNG BAO GIỜ gỡ được qua đây (fix round 1, finding S3)
 *
 * **Bản gốc của Task 3 SAI ở chỗ này — ghi lại để không lặp lại.** Bản đầu không có nhánh
 * `role_in_matter === Lead` riêng: nó dựa hẳn vào `OpenWork::leadMatters`, thứ chỉ chặn khi vụ
 * việc ĐANG MỞ (`closed_at` null). Hệ quả: gỡ một lead khỏi một vụ ĐÃ ĐÓNG lọt qua trót lọt — một
 * hành vi cụ thể một test cũ (`TeamMemberTest`, "allows removing the lead once the matter is
 * closed") còn GHIM LẠI như đúng. R6 nói "vai `lead` CHỈ ĐỔI qua `ReassignMatter`" — không có
 * điều kiện "trừ khi vụ đã đóng". Một vụ đã đóng vẫn cần MỘT người đứng tên là lead trong lịch sử
 * (M7 Task 3 dựng `matter_archives` trên chính `closed_at`/đội ngũ này) — gỡ trắng lead của nó
 * qua một nút "Gỡ thành viên" chung chung, không qua `ReassignMatter`, là đúng lỗ hổng SPEC §6.11
 * tồn tại để chặn. Giờ nhánh này đứng NGAY SAU khi xác nhận còn là thành viên, TRƯỚC `OpenWork`
 * — kể cả một lead của một vụ đã đóng (nơi `OpenWork::leadMatters` không còn thấy gì) cũng bị
 * chặn ở đây.
 *
 * **`OpenWork` vẫn được gọi sau đó, và điều đó không thừa.** `OpenWork::forUser($member, $matter)`
 * còn hai việc `role_in_matter === Lead` không làm: mốc hạn chưa xong và yêu cầu khách chưa đóng
 * của MỌI thành viên, kể cả `associate`/`assistant`/`observer`. Nhánh `leadMatters` bên trong nó
 * giờ chỉ còn "vô hại" với lời gọi này — với một `$member` đã qua được nhánh `Lead` ở trên (tức
 * KHÔNG phải lead của `$matter`), `leadMatters` giới hạn vào đúng `$matter` sẽ luôn rỗng (bất biến
 * của hệ thống: `pivot.role_in_matter === Lead` khớp đúng với `matter.lead_lawyer_id === $member`,
 * không có đường nào trong `app/` hôm nay làm hai giá trị đó lệch nhau) — không xoá nó vì `OpenWork`
 * là helper DÙNG CHUNG với việc nghỉ việc (Task 4, hỏi trên toàn bộ vụ việc, `$matter = null`),
 * nơi bất biến trên không còn đúng (một người có thể là lead của NHIỀU vụ việc khác).
 *
 * # Kiểm tra "có phải thành viên không" TRƯỚC `OpenWork`, không phải sau
 *
 * Thứ tự này bắt buộc, không phải gọn gàng: `OpenWork::forUser()` không hỏi gì về việc người đó
 * có trong `team()` của vụ việc hay không — nó hỏi về mốc hạn/yêu cầu khách/vai lead trên TOÀN
 * dữ liệu, độc lập với `matter_user`. Một người CHƯA từng vào đội ngũ của vụ việc này vẫn có thể
 * đứng tên một mốc hạn của vụ đó (mốc hạn không đòi người phụ trách phải ở trong đội ngũ tại thời
 * điểm bị gỡ — chỉ đòi lúc GÁN, xem `DeadlinesRelationManager::responsibleOptions()`), nên hỏi
 * `OpenWork` trước sẽ cho một câu trả lời không liên quan gì tới câu hỏi thật ("người này có
 * trong đội ngũ để mà gỡ không").
 *
 * # Khoá dòng vụ việc, trong một transaction (fix round 1, finding I3; khép lại ở fix round 2 và
 * round 3)
 *
 * Đọc pivot, hỏi `OpenWork`, `detach()` và ghi audit giờ nằm trong CÙNG một `DB::transaction`,
 * và câu ĐẦU TIÊN bên trong transaction đó là khoá dòng `matters` bằng `lockForUpdate()` — không
 * câu đọc trần (không khoá) nào đứng trước nó. Thứ tự này BẮT BUỘC, không chỉ để gọn: trên
 * MariaDB, mức cô lập REPEATABLE READ cố định READ VIEW của một transaction tại LẦN ĐỌC KHÔNG
 * KHOÁ ĐẦU TIÊN của nó (một câu `FOR UPDATE` không cố định gì, nó luôn đọc dữ liệu mới nhất) —
 * một câu đọc trần đứng TRƯỚC khoá này sẽ khiến MỌI câu đọc trần SAU ĐÓ (kể cả sau khi khoá đã
 * được cấp) vẫn thấy dữ liệu CŨ. Đây chính xác là lỗi round 3 sửa ở
 * {@see TriageClientRequest::open()} — xem docblock hàm đó và
 * {@see TriageClientRequest::realMatterId()} cho cơ chế đầy đủ; hàm NÀY không có câu đọc trần
 * nào trước khoá — đã rà lại (fix round 3): `Gate::forUser($actor)->authorize('manageTeam',
 * $matter)` là câu DUY NHẤT chạy trước, và nó chạy TRƯỚC `DB::transaction()` mở (dòng 116), không
 * phải bên trong nó, nên không cố định gì cho transaction NÀY. Cùng luật đã kiểm lại cho
 * {@see AddTeamMember}.
 *
 * **Khoá này chỉ đóng được đúng khe hở finding I3 nhắm tới TỪ FIX ROUND 3 TRỞ ĐI**, khi
 * {@see TriageClientRequest::open()} CŨNG khoá đúng dòng `matters` này làm câu ĐẦU TIÊN của nó
 * (không phải chỉ "trước `client_requests`" như round 2 làm — round 2 vẫn còn MỘT câu đọc trần
 * đứng trước cả hai khoá, xem finding I3 residual). Hai Action tranh chấp trên CÙNG một vụ việc
 * giờ luôn xếp hàng: dù ai xin khoá `matters` trước, phía CÒN LẠI đợi tới khi phía đó COMMIT rồi
 * mới chạy tiếp — và vì KHÔNG câu đọc trần nào chạy trước khoá ở CẢ HAI phía, câu đọc đầu tiên
 * (dù là câu nào) của phía đợi luôn cố định READ VIEW của nó SAU khi phía kia đã commit — không
 * còn khoảng hở cho một READ VIEW cũ sống sót qua một lần commit của phía kia.
 *
 * Cụ thể cho cặp `RemoveTeamMember`/`TriageClientRequest::assign()`: nếu `assign()` xin khoá
 * trước, nó giao xong yêu cầu VÀ COMMIT rồi `RemoveTeamMember` mới đọc được `OpenWork` — thấy
 * đúng yêu cầu vừa giao, chặn gỡ đúng. Nếu `RemoveTeamMember` xin khoá trước, nó gỡ xong người đó
 * khỏi `team()` VÀ COMMIT rồi `assign()` mới chạy tiếp — `canHoldTheThread()` hỏi lại
 * `MatterPolicy::update` trên chính người vừa bị gỡ, với một READ VIEW cố định SAU khi
 * `RemoveTeamMember` đã commit, nên tự từ chối đúng (họ không còn `matter.view` qua `team()`, trừ
 * khi có `matter.viewAny`).
 *
 * **Hai `AddMatterDeadline` (hoặc `AddMatterDeadline` với `Add`/`RemoveTeamMember`) cùng lúc trên
 * CÙNG một vụ việc CŨNG xếp hàng đúng vì lý do NÀY, không phải một lý do khác** —
 * `OpensDeadline::openMatterForDeadline()` khoá dòng `matters` làm câu ĐẦU TIÊN của nó, cùng thứ
 * tự toàn cục (vụ việc trước, bảng con sau) mà hàm này dùng. (Sửa lại một câu sai ở bản fix round
 * 2: bản đó nói hai `AddMatterDeadline` xếp hàng "vì lý do khác" — không đúng, chúng xếp hàng
 * chính vì tranh chấp CÙNG một khoá `matters` này.)
 *
 * **Khe hở ĐÃ TỪNG CÒN LẠI ở đây, không phải một điều kiện của khoá này — nay đã ĐÓNG, ở Task 18
 * (fix round 1).** `setStatus()` mở lại một luồng ĐÃ ĐÓNG mà người đang đứng tên (`assigned_to`)
 * đã rời đội ngũ TRONG LÚC luồng đóng từng không được đối chiếu lại: hàm chỉ đổi cột `status`, và
 * không gọi `canHoldTheThread()` (khi đó chỉ `assign()` gọi hàm đó). Khoá `matters` ở đây giải
 * quyết đúng vấn đề ĐỘC LẬP về ĐỌC DỮ LIỆU CŨ (REPEATABLE READ snapshot); nó không thêm một điều
 * kiện NGHIỆP VỤ nào cho `setStatus()` — điều kiện đó nằm ở chính {@see \App\Actions\Portal\
 * TriageClientRequest::setStatus()}, hỏi lại `canHoldTheThread()` khi MỞ LẠI một luồng `closed`,
 * và gỡ người không còn giữ được ra kèm một thông báo tiếng Việt cho người thao tác.
 *
 * # Việc nội bộ người đó đang giữ trên vụ (làn fa2, phán quyết 1)
 *
 * Rời đội ngũ thì người đó (không có quyền xem mọi vụ) không còn thấy việc của vụ — việc "Chờ nhận"/"Đang làm" của
 * họ không được biến mất im lặng. Còn giữ việc thì caller PHẢI chọn: `$taskRecipient` (người nhận thay — mỗi việc đi
 * qua "Giao lại" có sẵn, {@see ReassignTask}, trong CÙNG transaction, sau khoá dòng vụ: người mới nhận chuông + thư
 * "được giao"; người nhận thay phải hợp lệ như mọi lần giao lại), hoặc `$leaveForReassignment` (việc ở lại, hiện "Cần giao lại" — `Task::scopeNeedsReassignment()`). Không
 * chọn gì thì từ chối, không gỡ. Việc "Bị trả lại" đã về tay người giao — không thuộc lựa chọn này.
 *
 * Sau commit, {@see SendTeamDepartureNotices}: người bị gỡ nhận MỘT chuông "đã rời đội ngũ hồ sơ … — n việc đã
 * chuyển / chờ giao lại", và người giao của các việc đó (hay người nhận thay họ — `forTaskAssigner()`) nhận một
 * chuông cho các việc của mình. Dòng `team_member_removed` ghi số việc đã chuyển, số việc để lại, người nhận thay.
 *
 * Vòng sửa 1 (minor 2), gộp integ-fa: người nhận thay có thể là chính người bấm (luật sư phụ trách gỡ trợ lý của mình)
 * — họ NHẬN THAY (`ReassignTask` `takeOver`): các việc sang "Đang làm" của họ, không tự báo mình; "Giao lại" vẫn hỏi họ
 * có giữ được việc không (ghế giữ việc), lời từ chối hiện dưới ô chọn và không gỡ gì. Mọi lời gọi `ReassignTask` đi
 * qua MỘT chỗ, {@see self::reassignHeldTask()} (cờ lời báo: xem đó).
 * `$notify = false` (chỉ {@see TransferStaffWork}): không xếp {@see SendTeamDepartureNotices} — người gọi tự gửi
 * MỘT lời báo tổng hợp cho cả lô.
 *
 * # Việc người đó ĐÃ GIAO trên vụ (sửa sau kiểm toán 2026-10-21, mục 3)
 *
 * Trước bản sửa chỉ việc người bị gỡ đang GIỮ được xử lý: việc họ đã giao cho người khác ở lại với họ làm người giao,
 * trong khi họ không còn mở được hồ sơ — không ở "Tôi đã giao" của ai, lời "bị trả lại", "chưa nhận", "quá hạn" cho
 * người giao rơi vào khoảng trống. Nay, SAU khi gỡ, nếu người đó không còn mở được hồ sơ (`MatterPolicy::view`, tài
 * khoản còn hoạt động — quản lý/quản trị viên xem mọi hồ sơ thì không đổi gì), mọi việc còn mở (chờ nhận, đang làm, bị
 * trả lại) của vụ mà họ là người giao — kể cả việc riêng của họ vừa chuyển hay để "Cần giao lại" ở trên — đổi người giao
 * sang luật sư phụ trách, cùng cách {@see MoveTasksOnHandover::transferAssigner()} ({@see self::transferAssigned()}):
 * người nhận, trạng thái, hạn, lý do trả lại giữ nguyên. Dòng `team_member_removed` ghi `tasks_followed_by_lead`;
 * {@see SendTeamDepartureNotices} báo luật sư phụ trách MỘT chuông nêu số việc nay họ theo dõi (không khi chính họ bấm).
 * Hộp thoại gỡ nói trước con số đó ({@see self::assignedTasksLeadWouldFollow()}). `$transferAssigned = false`: chỉ
 * {@see TransferStaffWork} (tự chuyển các việc đó sang người nhận của nó).
 */
class RemoveTeamMember
{
    use ReadsWithoutPortalScope;

    /** Giá trị ô "người nhận thay" có nghĩa "để Cần giao lại" (màn hình gửi lên). */
    public const LEAVE_FOR_REASSIGNMENT = 'leave';

    /** `reason` của dòng `task_assigner_changed` khi việc người bị gỡ đã giao sang luật sư phụ trách (sửa 2026-10-21). */
    public const ASSIGNER_REASON = 'team_member_removed';

    /**
     * @param  User|null  $taskRecipient  làn fa2: người nhận thay việc nội bộ người bị gỡ đang giữ trên vụ
     * @param  bool  $leaveForReassignment  làn fa2: để các việc đó "Cần giao lại" thay vì chuyển
     * @param  bool  $notify  làn fa2, vòng sửa 1 (I2): `false` — không xếp {@see SendTeamDepartureNotices} (xem
     *                        docblock lớp)
     * @param  bool  $transferAssigned  sửa sau kiểm toán 2026-10-21 (mục 3): `false` — không đổi người giao của việc
     *                                  người bị gỡ đã giao (docblock lớp, mục "Việc người đó ĐÃ GIAO"); chỉ
     *                                  {@see TransferStaffWork}, nơi tự chuyển các việc đó (sang người nhận) trước khi gỡ,
     *                                  hay gỡ rồi thêm lại chính người nhận
     *
     * @throws ValidationException
     * @throws TeamMemberHasOpenWork
     */
    public function handle(Matter $matter, User $actor, User $member, ?User $taskRecipient = null, bool $leaveForReassignment = false, bool $notify = true, bool $transferAssigned = true): void
    {
        Gate::forUser($actor)->authorize('manageTeam', $matter);

        DB::transaction(function () use ($matter, $actor, $member, $taskRecipient, $leaveForReassignment, $notify, $transferAssigned): void {
            $locked = Matter::query()->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

            $pivot = $locked->team()->whereKey($member->getKey())->first()?->pivot;

            if ($pivot === null) {
                throw ValidationException::withMessages([
                    'user_id' => [__('actions.remove_team_member.not_member')],
                ]);
            }

            if ($pivot->role_in_matter === MatterRole::Lead) {
                throw ValidationException::withMessages([
                    'user_id' => [__('actions.remove_team_member.lead_role_denied')],
                ]);
            }

            $openWork = OpenWork::forUser($member, $locked);

            if (! $openWork->isEmpty()) {
                throw TeamMemberHasOpenWork::make($member, $locked, $openWork);
            }

            // Làn fa2, phán quyết 1 — xem docblock lớp, mục "Việc nội bộ người đó đang giữ trên vụ".
            $held = $this->scopelessly(Task::query())
                ->where('matter_id', $locked->getKey())
                ->where('assignee_id', $member->getKey())
                ->held()
                ->orderBy('id')
                ->get();

            if ($held->isNotEmpty() && $taskRecipient === null && ! $leaveForReassignment) {
                throw ValidationException::withMessages([
                    'task_recipient_id' => [__('internal_flow.team_removal.choose_recipient', ['count' => $held->count()])],
                ]);
            }

            $moved = [];

            if ($held->isNotEmpty() && $taskRecipient !== null) {
                // Gộp integ-fa (rà soát chéo, mục 9): "Giao lại" chỉ chạy trên hồ sơ đang mở (`TaskPolicy::reassign`).
                // Hồ sơ đã kết thúc: việc chỉ còn để "Cần giao lại" — nói thẳng thay vì câu từ chối chung của "Giao lại".
                if (! $locked->isOpen()) {
                    throw ValidationException::withMessages(['task_recipient_id' => [__('internal_flow.team_removal.closed_leave_only')]]);
                }

                if ($taskRecipient->is($member)) {
                    throw ValidationException::withMessages(['task_recipient_id' => [__('internal_flow.team_removal.recipient_is_member')]]);
                }

                foreach ($held as $task) {
                    try {
                        $this->reassignHeldTask($task, $actor, $taskRecipient);
                    } catch (ValidationException $exception) {
                        // Lời từ chối của "Giao lại" (người nhận thay không giữ được việc) — dưới đúng ô chọn.
                        throw ValidationException::withMessages(['task_recipient_id' => collect($exception->errors())->flatten()->all()]);
                    }

                    $moved[] = $task->getKey();
                }
            }

            $left = $moved === [] ? $held->modelKeys() : [];

            $locked->team()->detach($member->getKey());

            // Sửa sau kiểm toán 2026-10-21 (mục 3) — xem docblock lớp, mục "Việc người đó ĐÃ GIAO trên vụ". Hỏi SAU khi gỡ.
            $followed = $transferAssigned && ! $this->stillOpens($member, $locked)
                ? $this->transferAssigned($locked, $member, $actor)
                : [];

            $removed = Audit::record('team_member_removed', $locked, [
                'user_id' => $member->getKey(),
                'role' => $pivot->role_in_matter->value,
                'tasks_moved' => count($moved),
                'tasks_left_for_reassignment' => count($left),
                'task_recipient_id' => $moved === [] ? null : $taskRecipient?->getKey(),
                'tasks_followed_by_lead' => count($followed),
            ], $actor);

            if ($notify && $removed !== null) {
                SendTeamDepartureNotices::dispatch(
                    $locked->getKey(),
                    $member->getKey(),
                    $actor->getKey(),
                    (int) $removed->getKey(),
                    $moved,
                    $left,
                    $moved === [] ? null : $taskRecipient?->getKey(),
                    $followed,
                )->afterCommit();
            }
        });
    }

    /**
     * Số việc còn mở `$member` đã giao cho NGƯỜI KHÁC trên `$matter` mà luật sư phụ trách sẽ theo dõi thay nếu gỡ họ bây
     * giờ (docblock lớp, mục "Việc người đó ĐÃ GIAO trên vụ") — câu của hộp thoại gỡ. 0 khi họ vẫn mở được hồ sơ sau khi
     * rời đội ngũ (quản lý, quản trị viên…): hỏi CHÍNH `MatterPolicy::view` trên một bản sao của vụ mang đội ngũ không có
     * họ, không viết lại luật xem vụ. Việc giao cho chính luật sư phụ trách (sẽ thành việc riêng của họ) không đếm.
     */
    public function assignedTasksLeadWouldFollow(Matter $matter, User $member): int
    {
        if ($matter->lead_lawyer_id === null) {
            return 0;
        }

        $without = (clone $matter)->setRelation('team', $matter->team()->get()->reject(fn (User $user): bool => $user->is($member))->values());

        if ($member->is_active && ! $member->trashed() && Gate::forUser($member)->allows('view', $without)) {
            return 0;
        }

        return $this->scopelessly(Task::query())
            ->where('matter_id', $matter->getKey())
            ->where('assigned_by', $member->getKey())
            ->where('assignee_id', '!=', $member->getKey())
            ->open()
            ->get(['id', 'assignee_id', 'status'])
            ->reject(fn (Task $task): bool => self::becomesLeadsOwn($task, (int) $matter->lead_lawyer_id))
            ->count();
    }

    /** `$member` (vừa rời đội ngũ) còn mở được `$locked` — tài khoản còn hoạt động và `MatterPolicy::view`. */
    private function stillOpens(User $member, Matter $locked): bool
    {
        return $member->is_active && ! $member->trashed() && Gate::forUser($member)->allows('view', $locked);
    }

    /**
     * Docblock lớp, mục "Việc người đó ĐÃ GIAO trên vụ": mọi việc còn mở (chờ nhận, đang làm, bị trả lại) của vụ mà
     * `$member` là người giao đổi người giao sang luật sư phụ trách — cùng cách {@see MoveTasksOnHandover::transferAssigner()}
     * (dòng `task_assigner_changed`, `reason` = {@see self::ASSIGNER_REASON}; việc luật sư phụ trách còn giữ thành việc
     * riêng của họ, `own_task`).
     *
     * @return list<int> id các việc NGƯỜI KHÁC luật sư phụ trách đang giữ (hay đã trả lại) — luật sư phụ trách theo dõi
     */
    private function transferAssigned(Matter $locked, User $member, User $actor): array
    {
        $leadId = $locked->lead_lawyer_id;

        if ($leadId === null) {
            return [];
        }

        $tasks = $this->scopelessly(Task::query())
            ->where('matter_id', $locked->getKey())
            ->where('assigned_by', $member->getKey())
            ->open()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $followed = [];

        foreach ($tasks as $task) {
            $own = self::becomesLeadsOwn($task, $leadId);

            $task->update(['assigned_by' => $leadId]);

            Audit::record('task_assigner_changed', $task, [
                'matter_id' => $locked->getKey(),
                'status' => $task->status->value,
                'previous_assigner_id' => $member->getKey(),
                'assigner_id' => $leadId,
                'reason' => self::ASSIGNER_REASON,
                ...($own ? ['own_task' => true] : []),
            ], causer: $actor);

            if (! $own) {
                $followed[] = (int) $task->getKey();
            }
        }

        return $followed;
    }

    /** Việc thành việc RIÊNG của luật sư phụ trách khi họ thành người giao: họ còn giữ nó (không phải đã trả lại). */
    private static function becomesLeadsOwn(Task $task, int $leadId): bool
    {
        return $task->assignee_id === $leadId && $task->status !== TaskStatus::Declined;
    }

    /**
     * MỘT chỗ gọi {@see ReassignTask} của lần gỡ (vòng sửa 1, minor 1 / phán quyết 1; nối cờ khi gộp integ-fa):
     * người bị gỡ chỉ nhận MỘT chuông chung, và người giao MỘT chuông cho mọi việc của họ — cả hai từ
     * {@see SendTeamDepartureNotices} — nên `notifyFormerHolder: false` (không chuông `REASSIGNED_AWAY` từng việc) và
     * `notifyAssigner: false` (không chuông `REASSIGNED_BY_OTHER` từng việc). Người nhận thay vẫn nhận chuông + thư
     * "được giao" của từng việc — trừ khi đó là chính người bấm: `takeOver`, việc sang "Đang làm" của họ, không tự báo.
     * Gộp integ-fa (rà soát chéo, mục 2): hạn đã qua không đi theo việc — hạn mới theo
     * {@see ReassignmentDueDate::suggest()}, cùng luật ô "Hạn" của "Giao lại".
     */
    private function reassignHeldTask(Task $task, User $actor, User $recipient): void
    {
        app(ReassignTask::class)->handle(
            $task,
            $actor,
            $recipient,
            ReassignmentDueDate::suggest($task),
            setDueDate: true,
            notifyFormerHolder: false,
            notifyAssigner: false,
            takeOver: $recipient->is($actor),
        );
    }
}
