<?php

namespace App\Actions\Matter;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Deadline\ChangeDeadlineResponsible;
use App\Actions\Portal\TriageClientRequest;
use App\Actions\Task\MoveTasksOnHandover;
use App\Actions\Task\ReassignTask;
use App\Actions\Task\TaskAssignees;
use App\Actions\User\Concerns\GuardsStaffOffboarding;
use App\Enums\ClientRequestStatus;
use App\Enums\MatterRole;
use App\Enums\TaskStatus;
use App\Filament\Admin\Pages\BulkReassign;
use App\Jobs\SendStaffWorkTransferNotices;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Chuyển việc nội bộ và ghế" của MỘT nhân sự sang MỘT người khác (làn fa2, phán quyết 2) — phần thứ hai của màn
 * hình {@see BulkReassign}, cho người sắp nghỉ/bị khoá mà KHÔNG phải luật sư phụ trách (trợ lý, chuyên viên, luật sư
 * cộng sự): trước đây không có đường nào chuyển việc của họ hàng loạt — chỉ "Giao lại" từng việc, "Đổi người phụ trách"
 * từng mốc, gỡ từng ghế.
 *
 * # Chỉ hồ sơ người bấm quản lý được đội ngũ (vòng sửa 1, I1)
 *
 * Hồ sơ đang mở nơi người đó ngồi một ghế KHÔNG phải luật sư phụ trách, hay giữ / đã giao việc nội bộ còn mở
 * ({@see self::matterIds()}), mỗi hồ sơ lọc theo `manageTeam` của người bấm — MỘT câu hỏi, dưới khoá dòng vụ (bước 1
 * bên dưới), trước mọi đọc hay ghi khác của hồ sơ đó. Hồ sơ không qua (một vụ hạn chế với trưởng phòng) không có dòng
 * kết quả riêng, không id, mã hay tiêu đề nào rời khỏi Action: chỉ MỘT dòng chung "còn n hồ sơ cần quản trị viên
 * chuyển", và không góp vào lời báo hay dòng nhật ký nào ngoài con số đó.
 *
 * Gộp integ-fa (minor của rà soát cuối làn fa2): mã và tiêu đề của một hồ sơ chỉ được đọc SAU khi câu hỏi đó qua
 * ({@see self::$cleared}). Một lỗi bất ngờ TRƯỚC đó (hồ sơ biến mất, câu hỏi quyền hỏng) cho một dòng đỏ không id, mã
 * hay tiêu đề; một lời từ chối quyền của một Action con SAU đó (quyền đổi giữa chừng) là dòng đỏ của chính hồ sơ ấy
 * (`results.refused`), không bao giờ là "cần quản trị viên chuyển" — người bấm quản lý được hồ sơ đó.
 * Hồ sơ người đó đang PHỤ TRÁCH thì bỏ qua (dòng kết quả nói dùng phần "Bàn giao" ở trên — lần bàn giao vụ chuyển cả
 * việc của luật sư phụ trách cũ).
 *
 * # Mỗi hồ sơ một transaction, khoá dòng vụ trước
 *
 * Mỗi hồ sơ, trong MỘT transaction mà câu đầu tiên là khoá dòng `matters` (mọi Action được gọi sau cũng khoá dòng đó
 * trước — cùng thứ tự toàn cục), qua đúng các Action có sẵn, nên mọi cổng của chúng giữ nguyên:
 *
 *  1. `manageTeam` của người bấm, hỏi dưới khoá;
 *  2. ghế cho người nhận (vòng sửa 1, I3 + minor 4): người đó ngồi một ghế GIỮ VIỆC chưa trả lại, hay còn giữ việc
 *     trên hồ sơ (kể cả việc "Cần giao lại" sau một lần gỡ — họ không còn ghế), mà người nhận chưa có ghế giữ việc hợp
 *     lệ ({@see TaskAssignees::holdsSeat()}) → người nhận vào đội ngũ ({@see AddTeamMember}, vai {@see self::roleFor()};
 *     ghế "người theo dõi" hay ghế đã trả lại của người nhận được gỡ rồi thêm lại thành ghế giữ việc, chờ người nhận
 *     bấm "Đã nhận hồ sơ"). Người đó chỉ có ghế "người theo dõi" / ghế đã trả lại mà còn mốc hay yêu cầu của khách:
 *     người nhận chưa ở đội ngũ thì vào với ghế giữ việc — mốc và yêu cầu đòi người giữ ở trong đội ngũ. Ghế "người
 *     theo dõi" / đã trả lại của người đó KHÔNG được "chuyển" — chỉ gỡ ở bước 6;
 *  3. việc người đó đang giữ → {@see ReassignTask} từng việc ({@see self::reassignTask()}). Người nhận là chính người
 *     bấm (gộp integ-fa): người bấm NHẬN THAY — `takeOver`, việc sang thẳng "Đang làm" của họ. "Giao lại" từ chối một
 *     việc (người nhận không giữ được việc) thì VIỆC ĐÓ ở lại, các bước khác vẫn chạy, dòng kết quả (đỏ) nói số việc
 *     và câu của "Giao lại" (vòng sửa 1, minor 3);
 *  4. việc còn mở người đó đã giao → đổi người giao ({@see self::moveAssigner()}, cùng khuôn
 *     {@see MoveTasksOnHandover::transferAssigner()} nhưng lý do {@see self::TRANSFER_REASON});
 *  5. mốc chưa xong → {@see ChangeDeadlineResponsible}; yêu cầu của khách chưa đóng → {@see TriageClientRequest::assign()};
 *  6. gỡ người đó khỏi đội ngũ khi họ còn ghế ({@see RemoveTeamMember}; việc "Giao lại" đã từ chối ở bước 3 ở lại
 *     "Cần giao lại").
 *
 * Một bước khác bước 3 từ chối thì CẢ hồ sơ đó quay lại, kèm câu tiếng Việt của bước đó; hồ sơ khác vẫn chạy. Cuối
 * cùng, việc CHUNG (không thuộc hồ sơ): việc người đó đang giữ → {@see self::reassignTask()} từng việc (từ chối thì ở
 * lại, dòng kết quả nói rõ); việc người đó đã giao còn mở → đổi người giao (bước 4, một transaction; hỏng thì dòng việc
 * chung đỏ, còn nhật ký và lời báo của các phần đã chuyển vẫn ghi — gộp integ-fa).
 *
 * Người nhận có thể là chính người bấm (vòng sửa 1, minor 3: trưởng phòng tự nhận ghế, mốc, yêu cầu của trợ lý nghỉ;
 * gộp integ-fa: cả việc nội bộ, bước 3).
 *
 * # Một lời báo cho cả lô (vòng sửa 1, I2; gộp integ-fa)
 *
 * `AddTeamMember`, `ChangeDeadlineResponsible`, `RemoveTeamMember` chạy với `notify: false`, và `ReassignTask` với
 * `notifyFormerHolder: false, notifyNewHolder: false, notifyAssigner: false` ({@see self::reassignTask()}); sau lô,
 * MỘT {@see SendStaffWorkTransferNotices} (`->afterCommit()`): người nhận một chuông + một thư tổng hợp (liệt kê TỪNG
 * mốc hạn đã sang tay họ — tên, hồ sơ, hạn, số ngày còn lại, mức độ — thay cho thư "được giao mốc" của từng mốc), người
 * cũ một chuông, và mỗi người giao của các việc đã chuyển (hay người nhận thay họ) MỘT chuông cho mọi việc của họ trong
 * lô. Payload mang id các việc và các mốc đã chuyển. `TriageClientRequest::assign()` không báo ai.
 *
 * # Lý do, nhật ký
 *
 * Lý do bắt buộc (tối đa {@see self::REASON_MAX} ký tự, hỏi lại ở đây — vòng sửa 1, minor 5). Dòng nhật ký
 * `staff_work_transferred` (chủ thể là người đó) chỉ ghi khi CÓ thay đổi (vòng sửa 1, minor 9): các con số, lý do, và số
 * hồ sơ có lỗi / không quản lý được, số việc không chuyển được. Không id hay mã hồ sơ nào.
 *
 * Người bấm phải qua cổng `bulkReassign` (cùng cổng của màn hình) — hỏi ngay đầu {@see self::handle()}, trước mọi
 * thay đổi.
 *
 * Sau một lần chạy trọn không dòng đỏ, `OpenTaskWork` của người đó không còn gì trên hồ sơ đang mở hay việc chung (trừ
 * hồ sơ họ phụ trách — phần "Bàn giao") — nên luật nghỉ việc ({@see GuardsStaffOffboarding}) cho qua. Một việc đã giao
 * không đổi được người giao không bao giờ nằm im sau một dòng xanh (gộp integ-fa, {@see self::moveAssigner()}).
 */
class TransferStaffWork
{
    use ReadsWithoutPortalScope;

    /** Giá trị `reason` của dòng `task_assigner_changed` do lần chuyển việc này ghi (nhãn: `activity.reasons`). */
    public const TRANSFER_REASON = 'staff_work_transferred';

    /** Trần lý do chuyển việc (ghi vào `properties` của dòng nhật ký) — cùng trần ô lý do "Bàn giao" của màn hình. */
    public const REASON_MAX = 5000;

    /**
     * Hồ sơ đang xét đã qua `manageTeam` của người bấm dưới khoá (gộp integ-fa) — chỉ khi đó dòng kết quả được mang mã
     * và tiêu đề của nó. Đặt lại `null` trước mỗi hồ sơ.
     */
    private ?Matter $cleared = null;

    /**
     * @return list<array{matterId: int|null, success: bool, message: string, matterCode: string|null, matterTitle: string|null}>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $from, User $to, User $actor, string $reason): array
    {
        // Cùng cổng của màn hình {@see BulkReassign} (trưởng phòng, quản trị viên), hỏi lại ở đây: việc CHUNG (bước
        // cuối) không có hồ sơ nào để hỏi `manageTeam`, và đổi người giao không đi qua Action việc nào có cổng riêng.
        Gate::forUser($actor)->authorize('bulkReassign');

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw ValidationException::withMessages(['reason' => [__('internal_flow.staff_work.validation.reason_required', ['max' => self::REASON_MAX])]]);
        }

        if ($to->is($from) || ! $to->is_active || $to->trashed()) {
            throw ValidationException::withMessages(['recipient_id' => [__('internal_flow.staff_work.validation.recipient_invalid')]]);
        }

        $results = [];
        $totals = ['tasks' => 0, 'followed' => 0, 'followed_by_actor' => 0, 'deadlines' => 0, 'requests' => 0, 'seats' => 0, 'seats_removed' => 0, 'failed' => 0, 'stuck' => 0];
        $unmanaged = 0;
        $changedMatterIds = [];
        $failedMatters = 0;
        $movedTaskIds = [];
        $movedDeadlineIds = [];

        foreach ($this->matterIds($from) as $matterId) {
            $this->cleared = null;

            try {
                $outcome = DB::transaction(fn (): ?array => $this->transferOnMatter($matterId, $from, $to, $actor));

                if ($outcome === null) {
                    // Hồ sơ người bấm không quản lý được đội ngũ (I1), hay đã biến mất — chỉ được đếm, xem docblock lớp.
                    $unmanaged++;

                    continue;
                }

                foreach (array_keys($totals) as $key) {
                    $totals[$key] += $outcome[$key];
                }

                $movedTaskIds = [...$movedTaskIds, ...$outcome['task_ids']];
                $movedDeadlineIds = [...$movedDeadlineIds, ...$outcome['deadline_ids']];

                if ($outcome['tasks'] + $outcome['followed'] + $outcome['followed_by_actor'] + $outcome['deadlines'] + $outcome['requests'] + $outcome['seats'] + $outcome['seats_removed'] > 0) {
                    $changedMatterIds[] = $matterId;
                }

                $message = __('internal_flow.staff_work.results.done', Arr::only($outcome, ['tasks', 'followed', 'deadlines', 'requests', 'seats']));

                if ($outcome['followed_by_actor'] > 0) {
                    $message .= ' '.__('internal_flow.staff_work.results.followed_by_you', ['count' => $outcome['followed_by_actor']]);
                }

                if ($outcome['failed'] > 0) {
                    $message .= ' '.__('internal_flow.staff_work.results.tasks_failed', ['count' => $outcome['failed'], 'reason' => $outcome['reason']]);
                }

                if ($outcome['stuck'] > 0) {
                    $message .= ' '.__('internal_flow.staff_work.results.followed_stuck', ['count' => $outcome['stuck']]);
                }

                $success = $outcome['failed'] + $outcome['stuck'] === 0;
                $failedMatters += $success ? 0 : 1;
                $results[] = $this->row($success, $message);
            } catch (AuthorizationException) {
                // Gộp integ-fa: một Action con từ chối SAU khi người bấm đã qua `manageTeam` — quyền đổi giữa chừng. Hồ sơ
                // quay lại; dòng đỏ của chính hồ sơ (chưa qua cổng thì không mã — {@see self::row()}).
                $failedMatters++;
                $results[] = $this->row(false, __('internal_flow.staff_work.results.refused'));
            } catch (ValidationException $exception) {
                $failedMatters++;
                $results[] = $this->row(false, collect($exception->errors())->flatten()->implode(' '));
            } catch (DomainException $exception) {
                $failedMatters++;
                $results[] = $this->row(false, $exception->getMessage());
            } catch (Throwable $exception) {
                report($exception);
                $failedMatters++;
                $results[] = $this->row(false, __('reassign.bulk.results.unexpected_error'));
            }
        }

        $this->cleared = null;
        $general = $this->scopelessly(Task::query())->whereNull('matter_id')->where('assignee_id', $from->getKey())->held()->orderBy('id')->get();
        $generalMoved = 0;
        $generalRefusals = [];

        foreach ($general as $task) {
            try {
                $this->reassignTask($task, $actor, $to);
                $generalMoved++;
                $movedTaskIds[] = (int) $task->getKey();
            } catch (ValidationException $exception) {
                $generalRefusals[] = (string) collect($exception->errors())->flatten()->first();
            } catch (AuthorizationException|DomainException $exception) {
                $generalRefusals[] = $exception->getMessage();
            } catch (Throwable $exception) {
                report($exception);
                $generalRefusals[] = __('reassign.bulk.results.unexpected_error');
            }
        }

        // Việc chung `$from` đã giao còn mở: người nhận việc mở được mọi việc chung họ theo dõi (`TaskPolicy::view` —
        // người giao là người liên quan), nên `$toMayFollow` luôn đúng ở đây. Gộp integ-fa: trong `try` — hỏng ở đây
        // không được nuốt nhật ký và lời báo của những gì đã chuyển ở trên.
        $generalFollowFailed = false;

        try {
            $generalFollow = DB::transaction(fn (): array => $this->moveAssigner(null, $from, $to, $actor, true));
        } catch (Throwable $exception) {
            report($exception);
            $generalFollow = ['to' => 0, 'actor' => 0, 'stuck' => 0];
            $generalFollowFailed = true;
        }

        if ($general->isNotEmpty() || array_sum($generalFollow) > 0 || $generalFollowFailed) {
            $message = __('internal_flow.staff_work.results.general', ['moved' => $generalMoved, 'count' => $general->count(), 'followed' => $generalFollow['to']]);

            if ($generalFollow['actor'] > 0) {
                $message .= ' '.__('internal_flow.staff_work.results.followed_by_you', ['count' => $generalFollow['actor']]);
            }

            if ($generalRefusals !== []) {
                $message .= ' '.__('internal_flow.staff_work.results.general_failed', ['count' => count($generalRefusals), 'reason' => collect($generalRefusals)->unique()->implode(' ')]);
            }

            if ($generalFollow['stuck'] > 0) {
                $message .= ' '.__('internal_flow.staff_work.results.followed_stuck', ['count' => $generalFollow['stuck']]);
            }

            if ($generalFollowFailed) {
                $message .= ' '.__('reassign.bulk.results.unexpected_error');
            }

            $generalOk = $generalRefusals === [] && $generalFollow['stuck'] === 0 && ! $generalFollowFailed;
            $results[] = ['matterId' => null, 'success' => $generalOk, 'message' => $message, 'matterCode' => null, 'matterTitle' => null];
        }

        if ($unmanaged > 0) {
            $results[] = ['matterId' => null, 'success' => false, 'message' => __('internal_flow.staff_work.results.unmanaged', ['count' => $unmanaged]), 'matterCode' => null, 'matterTitle' => null];
        }

        $counts = [
            'tasks' => $totals['tasks'] + $generalMoved,
            'followed' => $totals['followed'] + $generalFollow['to'],
            'deadlines' => $totals['deadlines'],
            'requests' => $totals['requests'],
            'seats' => $totals['seats'],
        ];
        $followedByActor = $totals['followed_by_actor'] + $generalFollow['actor'];

        // Vòng sửa 1, minor 9: không gì thay đổi thì không có dòng "đã chuyển" nào, không lời báo nào.
        if (array_sum($counts) + $totals['seats_removed'] + $followedByActor === 0) {
            return $results;
        }

        $event = Audit::record('staff_work_transferred', $from, [
            'to_user_id' => $to->getKey(),
            'reason' => $reason,
            'tasks_moved' => $counts['tasks'],
            'tasks_followed' => $counts['followed'],
            'tasks_followed_by_actor' => $followedByActor,
            'deadlines_moved' => $counts['deadlines'],
            'client_requests_moved' => $counts['requests'],
            'seats_moved' => $counts['seats'],
            'seats_removed' => $totals['seats_removed'],
            'matters_moved' => count($changedMatterIds),
            'matters_failed' => $failedMatters,
            'matters_unmanaged' => $unmanaged,
            'tasks_not_moved' => $totals['failed'] + count($generalRefusals),
            'assigners_not_moved' => $totals['stuck'] + $generalFollow['stuck'],
            ...($generalFollowFailed ? ['general_assigners_failed' => true] : []),
        ], $actor);

        if ($event !== null) {
            SendStaffWorkTransferNotices::dispatch(
                (int) $from->getKey(),
                (int) $to->getKey(),
                (int) $actor->getKey(),
                (int) $event->getKey(),
                $changedMatterIds,
                $counts,
                $movedTaskIds,
                $movedDeadlineIds,
            )->afterCommit();
        }

        return $results;
    }

    /**
     * Hồ sơ đang mở có việc của `$user` để chuyển — xem docblock lớp. Hồ sơ `$user` phụ trách cũng có mặt, để dòng
     * kết quả chỉ đường cho phần "Bàn giao". KHÔNG lọc theo người bấm — {@see self::handle()} lọc (`manageTeam`
     * dưới khoá của từng hồ sơ).
     *
     * @return list<int>
     */
    public function matterIds(User $user): array
    {
        $id = $user->getKey();

        return $this->scopelessly(Matter::query())
            ->open()
            ->where(fn (Builder $has): Builder => $has
                ->whereHas('team', fn (Builder $team): Builder => $team->whereKey($id))
                ->orWhereHas('tasks', fn (Builder $tasks): Builder => $tasks->where(fn (Builder $who): Builder => $who
                    ->where('assignee_id', $id)->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Accepted->value])
                    ->orWhere(fn (Builder $assigned): Builder => $assigned->where('assigned_by', $id)->open()))))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * Bước 1–6 của docblock lớp trên một hồ sơ (gọi trong transaction của hồ sơ đó). `null`: hồ sơ không còn, hay người
     * bấm không quản lý được đội ngũ của nó — không đọc gì thêm, không ghi gì.
     *
     * @return array{tasks: int, followed: int, followed_by_actor: int, deadlines: int, requests: int, seats: int, seats_removed: int, failed: int, stuck: int, reason: string, task_ids: list<int>, deadline_ids: list<int>}|null
     */
    private function transferOnMatter(int $matterId, User $from, User $to, User $actor): ?array
    {
        $locked = $this->scopelessly(Matter::query())->whereKey($matterId)->lockForUpdate()->first();

        if ($locked === null || ! Gate::forUser($actor)->allows('manageTeam', $locked)) {
            return null;
        }

        $this->cleared = $locked;

        if ($locked->lead_lawyer_id === $from->getKey()) {
            throw new DomainException(__('internal_flow.staff_work.results.is_lead'));
        }

        $fromSeat = $locked->team()->whereKey($from->getKey())->first()?->pivot;
        $held = $this->scopelessly(Task::query())->where('matter_id', $locked->getKey())->where('assignee_id', $from->getKey())->held()->orderBy('id')->get();
        $deadlines = Deadline::query()->where('matter_id', $locked->getKey())->where('responsible_user_id', $from->getKey())->where('is_completed', false)->orderBy('id')->get();
        $requests = ClientRequest::query()->where('matter_id', $locked->getKey())->where('assigned_to', $from->getKey())->where('status', '!=', ClientRequestStatus::Closed->value)->orderBy('id')->get();

        // Bước 2 — xem docblock lớp.
        $fromWorks = $fromSeat instanceof MatterUser && self::isWorkingSeat($fromSeat);
        $seats = 0;

        if (($fromWorks || $held->isNotEmpty()) && ! app(TaskAssignees::class)->holdsSeat($to, $locked)) {
            $seats = $this->seatRecipient($locked, $actor, $to, $fromWorks ? $fromSeat->role_in_matter : MatterRole::Associate);
        } elseif (($deadlines->isNotEmpty() || $requests->isNotEmpty()) && ! $locked->team()->whereKey($to->getKey())->exists()) {
            $seats = $this->seatRecipient($locked, $actor, $to, MatterRole::Associate);
        }

        $moved = [];
        $refusals = [];

        foreach ($held as $task) {
            try {
                $this->reassignTask($task, $actor, $to);
                $moved[] = (int) $task->getKey();
            } catch (ValidationException $exception) {
                // "Giao lại" từ chối việc này (savepoint của nó đã quay lại) — việc ở lại, hồ sơ chạy tiếp (minor 3).
                $refusals[] = (string) collect($exception->errors())->flatten()->first();
            }
        }

        $followed = $this->moveAssigner($locked, $from, $to, $actor, Gate::forUser($to)->allows('view', $locked));

        foreach ($deadlines as $deadline) {
            app(ChangeDeadlineResponsible::class)->handle($deadline, $actor, $to, notify: false);
        }

        foreach ($requests as $request) {
            app(TriageClientRequest::class)->assign($request, $actor, $to);
        }

        $removed = 0;

        if ($fromSeat !== null) {
            app(RemoveTeamMember::class)->handle($locked, $actor, $from, leaveForReassignment: $refusals !== [], notify: false);
            $removed = 1;
        }

        return [
            'tasks' => count($moved),
            'followed' => $followed['to'],
            'followed_by_actor' => $followed['actor'],
            'deadlines' => $deadlines->count(),
            'requests' => $requests->count(),
            'seats' => $seats,
            'seats_removed' => $removed,
            'failed' => count($refusals),
            'stuck' => $followed['stuck'],
            'reason' => collect($refusals)->unique()->implode(' '),
            'task_ids' => $moved,
            'deadline_ids' => array_map(intval(...), $deadlines->modelKeys()),
        ];
    }

    /**
     * Ghế giữ việc cho `$to` (bước 2): ghế "người theo dõi" hay ghế đã trả lại của họ được gỡ trước (việc họ còn giữ
     * trên đó ở lại tới khi ghế mới có — rồi lại hợp lệ), rồi {@see AddTeamMember} với vai {@see self::roleFor()}.
     * Không lời báo riêng (lời báo tổng hợp đếm ghế này). Trả 1.
     */
    private function seatRecipient(Matter $locked, User $actor, User $to, MatterRole $role): int
    {
        if ($locked->team()->whereKey($to->getKey())->exists()) {
            app(RemoveTeamMember::class)->handle($locked, $actor, $to, leaveForReassignment: true, notify: false);
        }

        app(AddTeamMember::class)->handle($locked, $actor, $to, self::roleFor($to, $role), notify: false);

        return 1;
    }

    /** Ghế giữ việc (luật sư phụ trách, luật sư cộng sự, trợ lý) chưa trả lại — cùng luật `TaskAssignees::holdsSeat()`. */
    private static function isWorkingSeat(MatterUser $seat): bool
    {
        return in_array($seat->role_in_matter->value, TaskAssignees::workingSeats(), true)
            && ($seat->accepted_at !== null || $seat->declined_at === null);
    }

    /**
     * MỌI lời gọi {@see ReassignTask} của lần chuyển (vòng sửa 1, I2; nối cờ khi gộp integ-fa): không lời báo từng việc
     * nào — người nhận, người cũ và người giao đều có lời báo tổng hợp của {@see SendStaffWorkTransferNotices} — và
     * người nhận là chính người bấm thì nhận thay (`takeOver`: "Đang làm", không bước "Đã nhận").
     */
    private function reassignTask(Task $task, User $actor, User $to): void
    {
        app(ReassignTask::class)->handle(
            $task,
            $actor,
            $to,
            notifyFormerHolder: false,
            notifyNewHolder: false,
            notifyAssigner: false,
            takeOver: $to->is($actor),
        );
    }

    /**
     * Bước 4 của docblock lớp: việc còn mở (chờ nhận, đang làm, bị trả lại) `$from` đã giao cho NGƯỜI KHÁC — trên
     * `$locked` (đã khoá, gọi trong transaction của hồ sơ), hay việc chung khi `$locked` là `null` — đổi người giao:
     * sang `$to` khi `$to` mở được việc (`$toMayFollow`) và không phải chính người giữ việc (việc `$to` giữ thì một
     * người KHÁC theo dõi); không thì sang người bấm (trưởng phòng / quản trị viên — cổng `bulkReassign`, quản lý được
     * mọi việc). Gộp integ-fa (M-b của rà soát cuối làn fa2): KHÔNG việc nào bị bỏ qua im lặng — người bấm chính là
     * người giữ việc (không còn ai khác để theo dõi) thì việc thành việc riêng của họ (người giao = người giữ; cùng luật
     * của lần nhận thay, `ReassignTask`). Chỉ khi người bấm chính là `$from` (không ai khác để sang) việc mới ở lại —
     * được đếm ở `stuck`, và dòng kết quả đỏ. Trạng thái, người nhận, hạn, lý do trả lại giữ nguyên. Mỗi việc đổi một dòng
     * `task_assigner_changed` (cùng khoá với {@see MoveTasksOnHandover::transferAssigner()}, `reason` =
     * {@see self::TRANSFER_REASON}). Không chuông: người giữ việc không đổi gì phải làm.
     *
     * @return array{to: int, actor: int, stuck: int} số việc đổi người giao sang `$to`, sang người bấm (khi người bấm
     *                                                không phải `$to`), và số việc không đổi được
     */
    private function moveAssigner(?Matter $locked, User $from, User $to, User $actor, bool $toMayFollow): array
    {
        $tasks = $this->scopelessly(Task::query())
            ->when(
                $locked === null,
                fn (Builder $query): Builder => $query->whereNull('matter_id'),
                fn (Builder $query): Builder => $query->where('matter_id', $locked?->getKey()),
            )
            ->where('assigned_by', $from->getKey())
            ->where('assignee_id', '!=', $from->getKey())
            ->open()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $counts = ['to' => 0, 'actor' => 0, 'stuck' => 0];

        foreach ($tasks as $task) {
            $assigner = $toMayFollow && $task->assignee_id !== $to->getKey() ? $to : $actor;

            if ($assigner->is($from)) {
                $counts['stuck']++;

                continue;
            }

            $task->update(['assigned_by' => $assigner->getKey()]);

            Audit::record('task_assigner_changed', $task, [
                'matter_id' => $task->matter_id,
                'status' => $task->status->value,
                'previous_assigner_id' => $from->getKey(),
                'assigner_id' => $assigner->getKey(),
                'reason' => self::TRANSFER_REASON,
            ], causer: $actor);

            $counts[$assigner->is($to) ? 'to' : 'actor']++;
        }

        return $counts;
    }

    /**
     * Vai của người nhận: đúng vai `$role` nếu họ giữ được (`AddTeamMember::eligibleForRole()`), không thì luật sư
     * cộng sự (luật sư, trưởng phòng) hay trợ lý (trợ lý) — ghế giữ việc; còn lại vai `$role` (AddTeamMember từ chối,
     * kèm câu của nó).
     */
    public static function roleFor(User $to, MatterRole $role): MatterRole
    {
        foreach ([$role, MatterRole::Associate, MatterRole::Assistant] as $candidate) {
            if (AddTeamMember::eligibleForRole($to, $candidate)) {
                return $candidate;
            }
        }

        return $role;
    }

    /**
     * Dòng kết quả của hồ sơ đang xét. Mã, tiêu đề và id chỉ khi người bấm đã qua `manageTeam` của nó dưới khoá
     * ({@see self::$cleared}); không thì một dòng không id, mã hay tiêu đề (gộp integ-fa — xem docblock lớp).
     *
     * @return array{matterId: int|null, success: bool, message: string, matterCode: string|null, matterTitle: string|null}
     */
    private function row(bool $success, string $message): array
    {
        $matter = $this->cleared;

        return [
            'matterId' => $matter === null ? null : (int) $matter->getKey(),
            'success' => $success,
            'message' => $message,
            'matterCode' => $matter?->code,
            'matterTitle' => $matter?->title,
        ];
    }
}
