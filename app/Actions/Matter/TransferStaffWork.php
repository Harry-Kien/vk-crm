<?php

namespace App\Actions\Matter;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Deadline\ChangeDeadlineResponsible;
use App\Actions\Portal\TriageClientRequest;
use App\Actions\Task\MoveTasksOnHandover;
use App\Actions\Task\ReassignTask;
use App\Enums\ClientRequestStatus;
use App\Enums\MatterRole;
use App\Enums\TaskStatus;
use App\Filament\Admin\Pages\BulkReassign;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
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
 * # Mỗi hồ sơ một transaction, khoá dòng vụ trước
 *
 * Hồ sơ đang mở nơi người đó ngồi một ghế KHÔNG phải luật sư phụ trách, hay giữ / đã giao việc nội bộ còn mở. Hồ sơ
 * người đó đang PHỤ TRÁCH thì bỏ qua (dòng kết quả nói dùng phần "Bàn giao" ở trên — lần bàn giao vụ chuyển cả việc
 * của luật sư phụ trách cũ). Mỗi hồ sơ, trong MỘT transaction mà câu đầu tiên là khoá dòng `matters` (mọi Action được
 * gọi sau cũng khoá dòng đó trước — cùng thứ tự toàn cục), qua đúng các Action có sẵn, nên mọi cổng và mọi lời báo của
 * chúng giữ nguyên:
 *
 *  1. `manageTeam` của người bấm trên hồ sơ — không qua thì một dòng kết quả không mã, không tiêu đề;
 *  2. người nhận vào đội ngũ ({@see AddTeamMember}: cùng vai nếu họ giữ được vai đó, không thì luật sư cộng sự / trợ
 *     lý theo chức danh; ghế chờ người nhận bấm "Đã nhận hồ sơ", kèm chuông) — khi người đó có ghế và người nhận
 *     chưa có;
 *  3. việc người đó đang giữ → {@see ReassignTask} từng việc ("Chờ nhận", chuông + thư cho người nhận);
 *  4. việc còn mở người đó đã giao → đổi người giao ({@see self::moveAssigner()}, cùng khuôn
 *     {@see MoveTasksOnHandover::transferAssigner()} nhưng lý do {@see self::TRANSFER_REASON});
 *  5. mốc chưa xong → {@see ChangeDeadlineResponsible} (người nhận được báo ngay — phán quyết 6); yêu cầu của khách
 *     chưa đóng → {@see TriageClientRequest::assign()};
 *  6. gỡ người đó khỏi đội ngũ ({@see RemoveTeamMember} — không còn việc dở nào để chặn).
 *
 * Một bước từ chối (người nhận không giữ được vai, hồ sơ hạn chế họ không mở được…) thì CẢ hồ sơ đó quay lại, kèm
 * câu tiếng Việt của bước đó; hồ sơ khác vẫn chạy. Cuối cùng, việc CHUNG (không thuộc hồ sơ): việc người đó đang giữ
 * → {@see ReassignTask} từng việc; việc người đó đã giao còn mở → đổi người giao (bước 4, một transaction). Dòng nhật
 * ký `staff_work_transferred` (chủ thể là người đó) ghi tổng số.
 *
 * Người bấm phải qua cổng `bulkReassign` (cùng cổng của màn hình) — hỏi ngay đầu {@see self::handle()}, trước mọi
 * thay đổi.
 *
 * Sau một lần chạy trọn, `OpenTaskWork` của người đó không còn gì trên hồ sơ đang mở hay việc chung (trừ hồ sơ họ
 * phụ trách — phần "Bàn giao") — nên luật nghỉ việc ({@see GuardsStaffOffboarding}) cho qua.
 */
class TransferStaffWork
{
    use ReadsWithoutPortalScope;

    /** Giá trị `reason` của dòng `task_assigner_changed` do lần chuyển việc này ghi (nhãn: `activity.reasons`). */
    public const TRANSFER_REASON = 'staff_work_transferred';

    /**
     * @return list<array{matterId: int|null, success: bool, message: string, matterCode: string|null, matterTitle: string|null}>
     */
    public function handle(User $from, User $to, User $actor): array
    {
        // Cùng cổng của màn hình {@see BulkReassign} (trưởng phòng, quản trị viên), hỏi lại ở đây: việc CHUNG (bước
        // cuối) không có hồ sơ nào để hỏi `manageTeam`, và đổi người giao không đi qua Action việc nào có cổng riêng.
        Gate::forUser($actor)->authorize('bulkReassign');

        if ($to->is($from) || $to->is($actor) || ! $to->is_active || $to->trashed()) {
            throw ValidationException::withMessages(['recipient_id' => [__('internal_flow.staff_work.validation.recipient_invalid')]]);
        }

        $results = [];
        $totals = ['tasks' => 0, 'followed' => 0, 'deadlines' => 0, 'requests' => 0, 'seats' => 0];

        foreach ($this->matterIds($from) as $matterId) {
            $matter = null;

            try {
                $matter = $this->scopelessly(Matter::query())->find($matterId);

                if ($matter === null) {
                    throw new AuthorizationException;
                }

                $counts = DB::transaction(fn (): array => $this->transferOnMatter($matter, $from, $to, $actor));

                foreach ($counts as $key => $count) {
                    $totals[$key] += $count;
                }

                $results[] = $this->row($matter, true, __('internal_flow.staff_work.results.done', $counts));
            } catch (AuthorizationException) {
                $results[] = ['matterId' => $matterId, 'success' => false, 'message' => __('reassign.bulk.results.unavailable'), 'matterCode' => null, 'matterTitle' => null];
            } catch (ValidationException $exception) {
                $results[] = $this->row($matter, false, collect($exception->errors())->flatten()->implode(' '));
            } catch (DomainException $exception) {
                $results[] = $this->row($matter, false, $exception->getMessage());
            } catch (Throwable $exception) {
                report($exception);
                $results[] = ['matterId' => $matterId, 'success' => false, 'message' => __('reassign.bulk.results.unexpected_error'), 'matterCode' => null, 'matterTitle' => null];
            }
        }

        $general = $this->scopelessly(Task::query())->whereNull('matter_id')->where('assignee_id', $from->getKey())->held()->orderBy('id')->get();
        $generalMoved = 0;

        foreach ($general as $task) {
            try {
                app(ReassignTask::class)->handle($task, $actor, $to);
                $generalMoved++;
            } catch (Throwable $exception) {
                if (! $exception instanceof ValidationException && ! $exception instanceof AuthorizationException && ! $exception instanceof DomainException) {
                    report($exception);
                }
            }
        }

        // Việc chung `$from` đã giao còn mở: người nhận việc mở được mọi việc chung họ theo dõi (`TaskPolicy::view` —
        // người giao là người liên quan), nên `$toMayFollow` luôn đúng ở đây.
        $generalFollowed = DB::transaction(fn (): int => $this->moveAssigner(null, $from, $to, $actor, true));

        if ($general->isNotEmpty() || $generalFollowed > 0) {
            $results[] = [
                'matterId' => null,
                'success' => $generalMoved === $general->count(),
                'message' => __('internal_flow.staff_work.results.general', ['moved' => $generalMoved, 'count' => $general->count(), 'followed' => $generalFollowed]),
                'matterCode' => null,
                'matterTitle' => null,
            ];
        }

        Audit::record('staff_work_transferred', $from, [
            'to_user_id' => $to->getKey(),
            'tasks_moved' => $totals['tasks'] + $generalMoved,
            'tasks_followed' => $totals['followed'] + $generalFollowed,
            'deadlines_moved' => $totals['deadlines'],
            'client_requests_moved' => $totals['requests'],
            'seats_moved' => $totals['seats'],
        ], $actor);

        return $results;
    }

    /**
     * Hồ sơ đang mở có việc của `$user` để chuyển — xem docblock lớp. Hồ sơ `$user` phụ trách cũng có mặt, để dòng
     * kết quả chỉ đường cho phần "Bàn giao".
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

    /** @return array{tasks: int, followed: int, deadlines: int, requests: int, seats: int} */
    private function transferOnMatter(Matter $matter, User $from, User $to, User $actor): array
    {
        $locked = $this->scopelessly(Matter::query())->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

        Gate::forUser($actor)->authorize('manageTeam', $locked);

        if ($locked->lead_lawyer_id === $from->getKey()) {
            throw new DomainException(__('internal_flow.staff_work.results.is_lead'));
        }

        $seat = $locked->team()->whereKey($from->getKey())->first()?->pivot;
        $seats = 0;

        if ($seat !== null && ! $locked->team()->whereKey($to->getKey())->exists()) {
            app(AddTeamMember::class)->handle($locked, $actor, $to, self::roleFor($to, $seat->role_in_matter));
            $seats = 1;
        }

        $held = $this->scopelessly(Task::query())->where('matter_id', $locked->getKey())->where('assignee_id', $from->getKey())->held()->orderBy('id')->get();

        foreach ($held as $task) {
            app(ReassignTask::class)->handle($task, $actor, $to);
        }

        $followed = $this->moveAssigner($locked, $from, $to, $actor, Gate::forUser($to)->allows('view', $locked));

        $deadlines = Deadline::query()->where('matter_id', $locked->getKey())->where('responsible_user_id', $from->getKey())->where('is_completed', false)->orderBy('id')->get();

        foreach ($deadlines as $deadline) {
            app(ChangeDeadlineResponsible::class)->handle($deadline, $actor, $to);
        }

        $requests = ClientRequest::query()->where('matter_id', $locked->getKey())->where('assigned_to', $from->getKey())->where('status', '!=', ClientRequestStatus::Closed->value)->orderBy('id')->get();

        foreach ($requests as $request) {
            app(TriageClientRequest::class)->assign($request, $actor, $to);
        }

        if ($seat !== null) {
            app(RemoveTeamMember::class)->handle($locked, $actor, $from);
        }

        return ['tasks' => $held->count(), 'followed' => $followed, 'deadlines' => $deadlines->count(), 'requests' => $requests->count(), 'seats' => $seats];
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
     * Vai của người nhận: đúng vai người cũ nếu họ giữ được (`AddTeamMember::eligibleForRole()`), không thì luật sư
     * cộng sự (luật sư, trưởng phòng) hay trợ lý (trợ lý) — ghế giữ việc; còn lại vai cũ (AddTeamMember từ chối, kèm
     * câu của nó).
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

    /** @return array{matterId: int|null, success: bool, message: string, matterCode: string|null, matterTitle: string|null} */
    private function row(?Matter $matter, bool $success, string $message): array
    {
        return [
            'matterId' => $matter?->getKey(),
            'success' => $success,
            'message' => $message,
            'matterCode' => $matter?->code,
            'matterTitle' => $matter?->title,
        ];
    }
}
