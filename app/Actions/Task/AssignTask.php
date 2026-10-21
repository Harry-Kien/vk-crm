<?php

namespace App\Actions\Task;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Task\Concerns\QueuesTaskNotices;
use App\Actions\Task\Concerns\ValidatesTaskInput;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Policies\TaskPolicy;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * "Giao việc" (làn tk, Task 1) — một việc của một vụ, hoặc một việc chung (`$matter` rỗng).
 *
 * # Ai giao, ai nhận
 *
 * Người giao: {@see TaskPolicy::assignOnMatter()} (luật sư phụ trách/cộng sự của vụ, quản lý, quản
 * trị) hoặc {@see TaskPolicy::create()} cho việc chung (làn fa1: mọi nhân sự có `matter.view` — luật sư, trợ
 * lý, quản lý, quản trị), tài khoản còn hoạt động.
 * Người nhận: {@see TaskAssignees::qualifies()} — hỏi lại DƯỚI KHOÁ dòng của người đó, không tin ô chọn
 * của màn hình (một người bị vô hiệu hoá giữa lúc mở form và lúc bấm "Giao" bị từ chối ở đây). Không ai
 * tự giao việc cho chính mình.
 *
 * # Khoá: vụ việc trước, người nhận sau
 *
 * Thứ tự toàn cục của dự án (vụ trước, người thứ ba sau cùng — `ChangeDeadlineResponsible`). Khoá dòng
 * người nhận còn xếp hàng hai lần gửi CÙNG một hộp thoại cho cùng một người, để câu chống trùng dưới đây
 * đọc được lần trước.
 *
 * Giới hạn đã biết (làn fa1, vòng sửa 1 — rà soát Task 1, m12): lần đọc dòng nhật ký `task_assigned` của
 * {@see self::sameSubmission()} là một lần đọc THƯỜNG (không khoá). Trên MariaDB/InnoDB, mức REPEATABLE READ chụp ảnh
 * dữ liệu ở lần đọc thường đầu tiên của transaction — và trước khi chờ khoá người nhận, transaction đã đọc thường
 * (tài khoản người giao còn hoạt động, quyền qua `Gate`). Hai lần gửi THẬT SỰ chồng nhau thì lần sau, khi được khoá
 * người nhận, vẫn đọc ảnh cũ và không thấy dòng nhật ký lần trước vừa commit: hai việc. Không đổi sang đọc có khoá
 * (`lockForUpdate`/`sharedLock`): `submission_key` nằm trong JSON, không có chỉ mục, nên lần đọc có khoá đặt khoá
 * khoảng (gap lock) trên cả dải chỉ mục nó quét (người gây ra, hay `event, created_at`) — hai lần giao KHÁC nhau cùng
 * lúc của cùng một người giao, cho hai người nhận (không cùng khoá người nhận), cùng giữ khoá khoảng đó rồi cùng chèn
 * dòng `task_assigned`: deadlock, một lần giao thật hỏng. Rủi ro còn lại thấp và có từ trước (khoá cũ theo tiêu đề
 * cũng vậy): Livewire xếp hàng các request của MỘT component ở trình duyệt, nên hai lần bấm cùng một hộp thoại tới
 * máy chủ lần lượt — lần sau bắt đầu transaction SAU khi lần đầu đã commit, và thấy dòng nhật ký đó.
 *
 * # Bấm trùng không tạo hai việc — theo hộp thoại, không theo tiêu đề (làn fa1, phán quyết 4)
 *
 * Màn hình sinh một mã một lần (`$submissionKey`, UUID) khi MỞ hộp thoại "Giao việc" và gửi kèm mỗi lần lưu.
 * Một lần giao trước của CÙNG người giao mang đúng mã đó (`properties.submission_key` của dòng `task_assigned`,
 * trong {@see self::SUBMISSION_KEY_DAYS} ngày qua) là CHÍNH lần bấm trước (nút gửi hai lần, mạng chậm gửi lại):
 * trả về việc đó, không ghi thêm dòng nhật ký nào, không báo lần hai. Hai lần giao THẬT (mở hộp thoại hai lần)
 * mang hai mã — cùng tiêu đề vẫn là hai việc; trước làn fa1, khoá theo tiêu đề nuốt mất lần giao thứ hai (một
 * lần sửa hạn/mô tả, hay hai việc "Photo hồ sơ") mà vẫn báo thành công. Không mã (gọi từ nơi không có hộp thoại),
 * hay mã không đúng dạng: không chống trùng.
 *
 * # Vụ không còn giao việc được (sửa sau kiểm toán 2026-10-21, mục 1)
 *
 * `$matter` đã chọn mà, dưới khoá, vụ đã huỷ (không tìm thấy) hay `assignOnMatter` từ chối (vụ đã kết thúc, người giao
 * không còn ngồi ghế giao việc, đã trả lại ghế…): {@see ValidationException} trên ô `matter_id` với MỘT câu
 * (`tasks.lifecycle.matter_unavailable`) — không bao giờ một việc chung thay thế. Ô "Vụ việc" của trang Công việc luôn gửi
 * giá trị đã chọn tới đây (`ListTasks::submittedMatter()`); màn hình không đoán. Tab "Công việc" của vụ không có ô đó:
 * câu đi bằng thông báo (`ReportsActionFailures`). Người giao bị vô hiệu / không được giao việc chung vẫn là lời từ
 * chối chung {@see AuthorizationException}.
 *
 * # Báo người nhận
 *
 * Chuông + thư `staff.task.assigned`, xếp `->afterCommit()` ({@see QueuesTaskNotices}); job lọc
 * lại người nhận khi chạy (`App\Jobs\SendTaskNotice`, chuông {@see TaskAlert}).
 */
class AssignTask
{
    use ChecksAccountActive;
    use QueuesTaskNotices;
    use ReadsWithoutPortalScope;
    use ValidatesTaskInput;

    /** Mã một lần của hộp thoại còn được so trong ngần này ngày — xem docblock lớp. */
    public const SUBMISSION_KEY_DAYS = 1;

    /** Dạng của mã một lần (UUID, hay bất kỳ chữ-số-gạch tới 64 ký tự). */
    private const SUBMISSION_KEY_PATTERN = '/^[A-Za-z0-9-]{8,64}$/';

    /**
     * @param  string|null  $submissionKey  mã một lần của hộp thoại — xem docblock lớp
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        User $actor,
        ?Matter $matter,
        User $assignee,
        string $title,
        ?string $description = null,
        ?string $dueDate = null,
        TaskPriority $priority = TaskPriority::Normal,
        ?string $submissionKey = null,
    ): Task {
        $submissionKey = is_string($submissionKey) && preg_match(self::SUBMISSION_KEY_PATTERN, $submissionKey) === 1 ? $submissionKey : null;
        $title = $this->cleanTitle($title);
        $description = $this->cleanText($description, 'description', Task::DESCRIPTION_MAX_LENGTH);
        $dueDate = $this->cleanDueDate($dueDate);

        return DB::transaction(function () use ($actor, $matter, $assignee, $title, $description, $dueDate, $priority, $submissionKey): Task {
            $lockedMatter = null;

            if ($matter !== null) {
                // Câu ĐẦU TIÊN: khoá vụ việc, đọc lại từ CSDL (vụ đã huỷ không tìm thấy).
                $lockedMatter = $this->scopelessly(Matter::query())->lockForUpdate()->find($matter->getKey());

                if ($lockedMatter === null) {
                    $this->refuseMatter();
                }
            }

            if (! $this->accountIsActive($actor)) {
                $this->refuse();
            }

            $allowed = $lockedMatter === null
                ? Gate::forUser($actor)->allows('create', Task::class)
                : Gate::forUser($actor)->allows('assignOnMatter', [Task::class, $lockedMatter]);

            if (! $allowed) {
                $lockedMatter === null ? $this->refuse() : $this->refuseMatter();
            }

            $lockedAssignee = User::query()->withTrashed()->whereKey($assignee->getKey())->lockForUpdate()->first();

            if ($lockedAssignee === null) {
                $this->refuseAssignee();
            }

            if ($lockedAssignee->is($actor)) {
                throw ValidationException::withMessages(['assignee_id' => [__('tasks.validation.self_assign')]]);
            }

            if (! app(TaskAssignees::class)->qualifies($lockedAssignee, $lockedMatter)) {
                $this->refuseAssignee();
            }

            $duplicate = $this->sameSubmission($actor, $submissionKey);

            if ($duplicate !== null) {
                return $duplicate;
            }

            $task = $this->scopelessly(Task::query())->create([
                'matter_id' => $lockedMatter?->getKey(),
                'title' => $title,
                'description' => $description,
                'assignee_id' => $lockedAssignee->getKey(),
                'assigned_by' => $actor->getKey(),
                'due_date' => $dueDate,
                'priority' => $priority,
                'status' => TaskStatus::Pending,
                'assigned_at' => now(),
            ]);

            $activity = Audit::record('task_assigned', $task, [
                ...($lockedMatter !== null ? ['matter_id' => $lockedMatter->getKey()] : []),
                'assignee_id' => $lockedAssignee->getKey(),
                'to' => TaskStatus::Pending->value,
                'priority' => $priority->value,
                'due_date' => $dueDate,
                ...($submissionKey !== null ? ['submission_key' => $submissionKey] : []),
            ], causer: $actor);

            $this->queueNotice($task, TaskAlert::ASSIGNED, $lockedAssignee->getKey(), $activity);

            return $task;
        });
    }

    /**
     * Việc mà `$actor` đã giao bằng CHÍNH hộp thoại mang `$key` (docblock lớp, mục "Bấm trùng") — còn tồn tại; không
     * mã thì `null`. Dòng nhật ký không bao giờ sửa, nên nó là sổ của mọi lần giao.
     */
    private function sameSubmission(User $actor, ?string $key): ?Task
    {
        if ($key === null) {
            return null;
        }

        $taskId = Activity::query()
            ->where('event', 'task_assigned')
            ->where('causer_type', $actor->getMorphClass())
            ->where('causer_id', $actor->getKey())
            ->where('created_at', '>=', now()->subDays(self::SUBMISSION_KEY_DAYS))
            ->where('properties->submission_key', $key)
            ->latest('id')
            ->value('subject_id');

        return $taskId === null ? null : $this->scopelessly(Task::query())->find($taskId);
    }

    private function refuse(): never
    {
        throw new AuthorizationException(__('tasks.errors.unavailable'));
    }

    /**
     * Sửa sau kiểm toán 2026-10-21 (mục 1) — xem docblock lớp, mục "Vụ không còn giao việc được": gắn vào ô "Vụ việc", một
     * câu cho mọi nguyên nhân (đã kết thúc, đã huỷ, không tồn tại, người giao không còn giao việc được trên vụ) — không
     * phân biệt "không có quyền" với "không tồn tại" (SPEC §10.10).
     */
    private function refuseMatter(): never
    {
        throw ValidationException::withMessages(['matter_id' => [__('tasks.lifecycle.matter_unavailable')]]);
    }

    private function refuseAssignee(): never
    {
        throw ValidationException::withMessages(['assignee_id' => [__('tasks.validation.assignee_ineligible')]]);
    }
}
