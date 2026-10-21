<?php

namespace App\Actions\Task;

use App\Actions\Performance\BuildTaskPerformance;
use App\Actions\Task\Concerns\OpensTask;
use App\Actions\Task\Concerns\QueuesTaskNotices;
use App\Actions\Task\Concerns\ValidatesTaskInput;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Policies\TaskPolicy;
use App\Support\Audit;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Mở lại" một việc đã Hoàn thành (làn fa1, phán quyết 2 — kiểm tra luồng nội bộ 2026-10-16): kết quả chưa đạt
 * ("yêu cầu làm lại") hay người nhận bấm nhầm "Hoàn thành". Cổng: {@see TaskPolicy::reopen()} — người giao, luật sư
 * phụ trách vụ, quản lý/quản trị; hay chính người đã hoàn thành trong {@see TaskPolicy::REOPEN_OWN_HOURS} giờ đầu.
 *
 * Việc trở về "Đang làm" với CÙNG người giữ: `completed_at` và `completion_note` xoá trên dòng (giữ trong dòng
 * nhật ký `task_reopened` — `completed_at`, `completion_note`, `reason`), `accepted_at` giữ nguyên. Lý do BẮT BUỘC
 * khi người mở lại không phải người đã hoàn thành (đó là nhận xét của người yêu cầu làm lại); tuỳ chọn với chính
 * người đã hoàn thành.
 *
 * Vụ đã kết thúc (hay việc của vụ đã huỷ — `openTask()` từ chối chung): từ chối bằng một câu rõ ràng
 * (`tasks.reopen.errors.matter_closed`) — trên vụ đã kết thúc không còn việc gì để làm tiếp.
 *
 * Khoá: vụ việc rồi dòng việc ({@see OpensTask}); mọi câu hỏi trạng thái/quyền hỏi trên bản đã khoá. Bấm lần hai
 * (việc đã về "Đang làm"): lời từ chối trạng thái của `authorizeTransition()`.
 *
 * Lời báo, sau commit ({@see QueuesTaskNotices}): người khác mở lại → người giữ nhận chuông + thư
 * `staff.task.reopened` ({@see TaskAlert::REOPENED}) kèm lý do; chính người giữ mở lại → người giao (hay người nhận
 * thay — `forTaskAssigner()`) nhận một chuông {@see TaskAlert::REOPENED_BY_HOLDER}: lời "đã hoàn thành" họ nhận
 * trước đó không còn đúng.
 *
 * # Người giữ không còn giữ được việc (làn fa1, vòng sửa 1 — rà soát Task 1, m2)
 *
 * Người giao, luật sư phụ trách hay quản lý mở lại một việc mà người đã hoàn thành nó từ đó đã nghỉ, rời đội ngũ,
 * sang "người theo dõi", trả lại hồ sơ hay không còn mở được hồ sơ: cùng lần hỏi của `RestoreTask` —
 * {@see TaskAssignees::qualifies()} dưới khoá dòng người giữ. Việc vẫn về "Đang làm" (vẫn mang người giữ cũ, như mọi
 * việc "Cần giao lại" — `Task::scopeNeedsReassignment()`), nhưng KHÔNG ai được báo "cần làm tiếp": người theo dõi vẫn
 * xem được vụ nên bộ lọc người nhận của job không tự chặn được họ. Dòng `task_reopened` ghi `needs_reassignment`, và
 * câu xong của màn hình nói việc hiện "Cần giao lại" (`tasks.reopen.success_needs_reassignment`), không hứa "họ được
 * báo". Chính người giữ mở lại thì họ còn giữ được việc (`TaskPolicy::completedRecentlyBy()` hỏi `stillHolds`).
 *
 * Số Hiệu suất: một việc mở lại không còn trong tập "đã hoàn thành" ({@see BuildTaskPerformance}, `Task::
 * scopeCompletedWithDueDateBetween()`) cho tới khi xong lần nữa; nếu đã quá hạn, nó vào mẫu số như mọi việc quá hạn
 * còn mở.
 */
class ReopenTask
{
    use OpensTask;
    use QueuesTaskNotices;
    use ValidatesTaskInput;

    public function handle(Task $task, User $actor, ?string $reason): Task
    {
        $reason = $this->cleanText($reason, 'reason', Task::REASON_MAX_LENGTH);

        return DB::transaction(function () use ($task, $actor, $reason): Task {
            $fresh = $this->openTask($task, $actor);

            $this->authorizeTransition($fresh, $actor, 'reopen', [TaskStatus::Completed]);

            if ($fresh->matter !== null && ! $fresh->matter->isOpen()) {
                throw new DomainException(__('tasks.reopen.errors.matter_closed'));
            }

            // Người đã hoàn thành là người nhận (việc đã hoàn thành không giao lại được — `TaskPolicy::completedRecentlyBy()`).
            $own = $fresh->assignee_id === $actor->getKey();

            if ($reason === null && ! $own) {
                throw ValidationException::withMessages(['reason' => [__('tasks.validation.reason_required')]]);
            }

            $completedAt = $fresh->completed_at?->toDateTimeString();
            $note = $fresh->completion_note;

            // Xem docblock lớp, mục "Người giữ không còn giữ được việc" — hỏi dưới khoá dòng của họ (sau vụ và việc).
            $holder = User::query()->withTrashed()->whereKey($fresh->assignee_id)->lockForUpdate()->first();
            $qualifies = $holder !== null && app(TaskAssignees::class)->qualifies($holder, $fresh->matter);

            $fresh->update([
                'status' => TaskStatus::Accepted,
                'accepted_at' => $fresh->accepted_at ?? now(),
                'completed_at' => null,
                'completion_note' => null,
            ]);

            $activity = Audit::record('task_reopened', $fresh, [
                ...($fresh->matter_id !== null ? ['matter_id' => $fresh->matter_id] : []),
                'from' => TaskStatus::Completed->value,
                'to' => TaskStatus::Accepted->value,
                'reason' => $reason,
                'completed_at' => $completedAt,
                'completion_note' => $note,
                'by_holder' => $own,
                'needs_reassignment' => ! $qualifies,
            ], causer: $actor);

            if ($own) {
                $this->queueNotice($fresh, TaskAlert::REOPENED_BY_HOLDER, $fresh->assigned_by, $activity);
            } elseif ($qualifies) {
                $this->queueNotice($fresh, TaskAlert::REOPENED, $fresh->assignee_id, $activity);
            }

            return $fresh;
        });
    }
}
