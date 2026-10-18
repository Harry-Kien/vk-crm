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
 * Hồ sơ người đó đang PHỤ TRÁCH thì bỏ qua (dòng kết quả nói dùng phần "Bàn giao" ở trên — lần bàn giao vụ chuyển cả
 * việc của luật sư phụ trách cũ).
 *
 * # Mỗi hồ sơ một transaction, khoá dòng vụ trước
 *
 * Mỗi hồ sơ, trong MỘT transaction mà câu đầu tiên là khoá dòng `matters` (mọi Action được gọi sau cũng khoá dòng đó
 * trước — cùng thứ tự toàn cục), qua đúng các Action có sẵn, nên mọi cổng của chúng giữ nguyên:
 *
 *  1. `manageTeam` của người bấm, hỏi lại dưới khoá;
 *  2. ghế cho người nhận (vòng sửa 1, I3 + minor 4): người đó ngồi một ghế GIỮ VIỆC chưa trả lại, hay còn giữ việc
 *     trên hồ sơ (kể cả việc "Cần giao lại" sau một lần gỡ — họ không còn ghế), mà người nhận chưa có ghế giữ việc hợp
 *     lệ ({@see TaskAssignees::holdsSeat()}) → người nhận vào đội ngũ ({@see AddTeamMember}, vai {@see self::roleFor()};
 *     ghế "người theo dõi" hay ghế đã trả lại của người nhận được gỡ rồi thêm lại thành ghế giữ việc, chờ người nhận
 *     bấm "Đã nhận hồ sơ"). Người đó chỉ có ghế "người theo dõi" / ghế đã trả lại mà còn mốc hay yêu cầu của khách:
 *     người nhận chưa ở đội ngũ thì vào với ghế giữ việc — mốc và yêu cầu đòi người giữ ở trong đội ngũ. Ghế "người
 *     theo dõi" / đã trả lại của người đó KHÔNG được "chuyển" — chỉ gỡ ở bước 6;
 *  3. việc người đó đang giữ → {@see ReassignTask} từng việc ({@see self::reassignTask()}). "Giao lại" từ chối một việc
 *     (người nhận là chính người bấm — luật "không tự giao việc cho mình" — hay không giữ được việc) thì VIỆC ĐÓ ở lại,
 *     các bước khác vẫn chạy, dòng kết quả (đỏ) nói số việc và câu của "Giao lại" (vòng sửa 1, minor 3);
 *  4. việc còn mở người đó đã giao → đổi người giao ({@see self::moveAssigner()}, cùng khuôn
 *     {@see MoveTasksOnHandover::transferAssigner()} nhưng lý do {@see self::TRANSFER_REASON});
 *  5. mốc chưa xong → {@see ChangeDeadlineResponsible}; yêu cầu của khách chưa đóng → {@see TriageClientRequest::assign()};
 *  6. gỡ người đó khỏi đội ngũ khi họ còn ghế ({@see RemoveTeamMember}; việc "Giao lại" đã từ chối ở bước 3 ở lại
 *     "Cần giao lại").
 *
 * Một bước khác bước 3 từ chối thì CẢ hồ sơ đó quay lại, kèm câu tiếng Việt của bước đó; hồ sơ khác vẫn chạy. Cuối
 * cùng, việc CHUNG (không thuộc hồ sơ): việc người đó đang giữ → {@see self::reassignTask()} từng việc (từ chối thì ở
 * lại, dòng kết quả nói rõ); việc người đó đã giao còn mở → đổi người giao (bước 4, một transaction).
 *
 * Người nhận có thể là chính người bấm (vòng sửa 1, minor 3: trưởng phòng tự nhận ghế, mốc, yêu cầu của trợ lý nghỉ);
 * việc nội bộ thì theo luật của "Giao lại" (bước 3).
 *
 * # Một lời báo cho cả lô (vòng sửa 1, I2)
 *
 * `AddTeamMember`, `ChangeDeadlineResponsible`, `RemoveTeamMember` chạy với `notify: false`; sau lô, MỘT
 * {@see SendStaffWorkTransferNotices} (`->afterCommit()`): người nhận một chuông + một thư tổng hợp, người cũ một
 * chuông. `ReassignTask` hôm nay chưa có cờ đó (làn fa1) — mọi lời gọi nó đi qua MỘT chỗ, {@see self::reassignTask()},
 * và lời báo tổng hợp đã đếm các việc, để sau khi gộp nó là lời báo duy nhất. `TriageClientRequest::assign()` không
 * báo ai.
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
 * hồ sơ họ phụ trách — phần "Bàn giao") — nên luật nghỉ việc ({@see GuardsStaffOffboarding}) cho qua.
 */
class TransferStaffWork
{
    use ReadsWithoutPortalScope;

    /** Giá trị `reason` của dòng `task_assigner_changed` do lần chuyển việc này ghi (nhãn: `activity.reasons`). */
    public const TRANSFER_REASON = 'staff_work_transferred';

    /** Trần lý do chuyển việc (ghi vào `properties` của dòng nhật ký) — cùng trần ô lý do "Bàn giao" của màn hình. */
    public const REASON_MAX = 5000;

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
        $totals = ['tasks' => 0, 'followed' => 0, 'deadlines' => 0, 'requests' => 0, 'seats' => 0, 'seats_removed' => 0, 'failed' => 0];
        $unmanaged = 0;
        $changedMatterIds = [];
        $failedMatters = 0;

        foreach ($this->matterIds($from) as $matterId) {
            $matter = $this->scopelessly(Matter::query())->find($matterId);

            if ($matter === null) {
                $unmanaged++;

                continue;
            }

            try {
                $outcome = DB::transaction(fn (): array => $this->transferOnMatter($matter, $from, $to, $actor));

                foreach (array_keys($totals) as $key) {
                    $totals[$key] += $outcome[$key];
                }

                if ($outcome['tasks'] + $outcome['followed'] + $outcome['deadlines'] + $outcome['requests'] + $outcome['seats'] + $outcome['seats_removed'] > 0) {
                    $changedMatterIds[] = (int) $matter->getKey();
                }

                $message = __('internal_flow.staff_work.results.done', $outcome);

                if ($outcome['failed'] > 0) {
                    $failedMatters++;
                    $message .= ' '.__('internal_flow.staff_work.results.tasks_failed', ['count' => $outcome['failed'], 'reason' => $outcome['reason']]);
                }

                $results[] = $this->row($matter, $outcome['failed'] === 0, $message);
            } catch (AuthorizationException) {
                // Hồ sơ người bấm không quản lý được đội ngũ (I1) — chỉ được đếm, xem docblock lớp.
                $unmanaged++;
            } catch (ValidationException $exception) {
                $failedMatters++;
                $results[] = $this->row($matter, false, collect($exception->errors())->flatten()->implode(' '));
            } catch (DomainException $exception) {
                $failedMatters++;
                $results[] = $this->row($matter, false, $exception->getMessage());
            } catch (Throwable $exception) {
                report($exception);
                $failedMatters++;
                $results[] = $this->row($matter, false, __('reassign.bulk.results.unexpected_error'));
            }
        }

        $general = $this->scopelessly(Task::query())->whereNull('matter_id')->where('assignee_id', $from->getKey())->held()->orderBy('id')->get();
        $generalMoved = 0;
        $generalRefusals = [];

        foreach ($general as $task) {
            try {
                $this->reassignTask($task, $actor, $to);
                $generalMoved++;
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
        // người giao là người liên quan), nên `$toMayFollow` luôn đúng ở đây.
        $generalFollowed = DB::transaction(fn (): int => $this->moveAssigner(null, $from, $to, $actor, true));

        if ($general->isNotEmpty() || $generalFollowed > 0) {
            $message = __('internal_flow.staff_work.results.general', ['moved' => $generalMoved, 'count' => $general->count(), 'followed' => $generalFollowed]);

            if ($generalRefusals !== []) {
                $message .= ' '.__('internal_flow.staff_work.results.general_failed', ['count' => count($generalRefusals), 'reason' => collect($generalRefusals)->unique()->implode(' ')]);
            }

            $results[] = ['matterId' => null, 'success' => $generalRefusals === [], 'message' => $message, 'matterCode' => null, 'matterTitle' => null];
        }

        if ($unmanaged > 0) {
            $results[] = ['matterId' => null, 'success' => false, 'message' => __('internal_flow.staff_work.results.unmanaged', ['count' => $unmanaged]), 'matterCode' => null, 'matterTitle' => null];
        }

        $counts = [
            'tasks' => $totals['tasks'] + $generalMoved,
            'followed' => $totals['followed'] + $generalFollowed,
            'deadlines' => $totals['deadlines'],
            'requests' => $totals['requests'],
            'seats' => $totals['seats'],
        ];

        // Vòng sửa 1, minor 9: không gì thay đổi thì không có dòng "đã chuyển" nào, không lời báo nào.
        if (array_sum($counts) + $totals['seats_removed'] === 0) {
            return $results;
        }

        $event = Audit::record('staff_work_transferred', $from, [
            'to_user_id' => $to->getKey(),
            'reason' => $reason,
            'tasks_moved' => $counts['tasks'],
            'tasks_followed' => $counts['followed'],
            'deadlines_moved' => $counts['deadlines'],
            'client_requests_moved' => $counts['requests'],
            'seats_moved' => $counts['seats'],
            'seats_removed' => $totals['seats_removed'],
            'matters_moved' => count($changedMatterIds),
            'matters_failed' => $failedMatters,
            'matters_unmanaged' => $unmanaged,
            'tasks_not_moved' => $totals['failed'] + count($generalRefusals),
        ], $actor);

        if ($event !== null) {
            SendStaffWorkTransferNotices::dispatch(
                (int) $from->getKey(),
                (int) $to->getKey(),
                (int) $actor->getKey(),
                (int) $event->getKey(),
                $changedMatterIds,
                $counts,
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
     * Bước 1–6 của docblock lớp trên một hồ sơ (gọi trong transaction của hồ sơ đó).
     *
     * @return array{tasks: int, followed: int, deadlines: int, requests: int, seats: int, seats_removed: int, failed: int, reason: string}
     */
    private function transferOnMatter(Matter $matter, User $from, User $to, User $actor): array
    {
        $locked = $this->scopelessly(Matter::query())->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

        Gate::forUser($actor)->authorize('manageTeam', $locked);

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

        $moved = 0;
        $refusals = [];

        foreach ($held as $task) {
            try {
                $this->reassignTask($task, $actor, $to);
                $moved++;
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
            'tasks' => $moved,
            'followed' => $followed,
            'deadlines' => $deadlines->count(),
            'requests' => $requests->count(),
            'seats' => $seats,
            'seats_removed' => $removed,
            'failed' => count($refusals),
            'reason' => collect($refusals)->unique()->implode(' '),
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

    /** MỌI lời gọi {@see ReassignTask} của lần chuyển (vòng sửa 1, I2) — một chỗ để nối cờ lời báo của làn fa1. */
    private function reassignTask(Task $task, User $actor, User $to): void
    {
        // integ: pass notifyFormerHolder: false, notifyNewHolder: false once fa1 is merged — lời báo tổng hợp
        // (SendStaffWorkTransferNotices) đã đếm các việc này. Hôm nay ReassignTask còn gửi chuông + thư "được giao"
        // cho `$to` và chuông REASSIGNED_AWAY cho người cũ, mỗi việc một lần (test "counts the moved tasks…").
        app(ReassignTask::class)->handle($task, $actor, $to);
    }

    /**
     * Bước 4 của docblock lớp: việc còn mở (chờ nhận, đang làm, bị trả lại) `$from` đã giao cho NGƯỜI KHÁC — trên
     * `$locked` (đã khoá, gọi trong transaction của hồ sơ), hay việc chung khi `$locked` là `null` — đổi người giao:
     * sang `$to` khi `$to` mở được việc (`$toMayFollow`) và không phải chính người giữ việc (người giao không tự theo
     * dõi việc mình giữ); không thì sang người bấm (trưởng phòng / quản trị viên — cổng `bulkReassign`, quản lý được
     * mọi việc) khi người bấm không giữ việc đó. Trạng thái, người nhận, hạn, lý do trả lại giữ nguyên. Mỗi việc một
     * dòng `task_assigner_changed` (cùng khoá với {@see MoveTasksOnHandover::transferAssigner()}, `reason` =
     * {@see self::TRANSFER_REASON}). Không chuông: người giữ việc không đổi gì phải làm.
     *
     * @return int số việc đã đổi người giao
     */
    private function moveAssigner(?Matter $locked, User $from, User $to, User $actor, bool $toMayFollow): int
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
        $moved = 0;

        foreach ($tasks as $task) {
            $assigner = match (true) {
                $toMayFollow && $task->assignee_id !== $to->getKey() => $to,
                $task->assignee_id !== $actor->getKey() => $actor,
                default => null,
            };

            if ($assigner === null) {
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

            $moved++;
        }

        return $moved;
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

    /** @return array{matterId: int, success: bool, message: string, matterCode: string|null, matterTitle: string|null} */
    private function row(Matter $matter, bool $success, string $message): array
    {
        return [
            'matterId' => (int) $matter->getKey(),
            'success' => $success,
            'message' => $message,
            'matterCode' => $matter->code,
            'matterTitle' => $matter->title,
        ];
    }
}
