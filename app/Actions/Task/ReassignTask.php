<?php

namespace App\Actions\Task;

use App\Actions\Matter\TransferStaffWork;
use App\Actions\Task\Concerns\OpensTask;
use App\Actions\Task\Concerns\QueuesTaskNotices;
use App\Actions\Task\Concerns\ValidatesTaskInput;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Giao lại" — chuyển một việc còn mở sang người khác: việc bị trả lại, người nhận đã nghỉ/bị vô hiệu
 * hay rời đội ngũ vụ, hoặc người giao/luật sư phụ trách/quản lý chủ động chuyển một việc đang chờ nhận
 * hay đang làm (người đang giữ quá tải, vắng mặt). Việc trở về "Chờ nhận" cho người mới (mốc nhận, mốc trả lại và lý do cũ xoá — lịch sử nằm ở
 * nhật ký, dòng `task_reassigned` và `task_declined`); người giao ban đầu (`assigned_by`) giữ nguyên, để
 * việc vẫn nằm ở "Tôi đã giao" của họ. Cổng: `TaskPolicy::reassign` (người giao, luật sư phụ trách vụ,
 * quản lý/quản trị).
 *
 * Người mới qua {@see TaskAssignees::qualifies()} DƯỚI KHOÁ dòng của họ (khoá sau vụ và việc — thứ tự
 * toàn cục). Giao lại cho CHÍNH người đang giữ chỉ được khi họ đã trả lại (sau khi hai bên trao đổi);
 * việc đang chờ nhận/đang làm mà chọn lại đúng người đó thì không có gì để chuyển.
 *
 * Người mới nhận chuông + thư `staff.task.assigned`. Người cũ (vòng sửa 1, I1): việc biến khỏi danh
 * sách của họ — chỉ người nhận HIỆN TẠI xem được việc — nên khi họ còn GIỮ nó (chờ nhận hay đang làm) họ
 * nhận MỘT chuông chung `TaskAlert::REASSIGNED_AWAY` (không tiêu đề, không mã vụ, không thư), để không
 * làm tiếp một việc đã sang tay người khác. Họ đã trả lại thì đã buông việc: không báo. Họ đã nghỉ/bị vô
 * hiệu: job bỏ họ (`ResolveStaffRecipients::forFormerTaskHolder()`).
 *
 * Làn fa1 (phán quyết 7, kiểm tra luồng nội bộ 2026-10-16): lần giao lại đặt được hạn mới (`$setDueDate`; màn
 * hình điền sẵn hạn cũ nếu còn ở tương lai, gợi ý một hạn mới nếu đã qua — `ProvidesTaskActions`); dòng nhật ký
 * ghi `due_date_from`/`due_date_to` khi hạn đổi, và thư "được giao" của người mới mang hạn mới. Người bấm không
 * phải người giao (luật sư phụ trách hay quản lý chuyển việc của một người giao khác): người giao gốc nhận một
 * CHUÔNG {@see TaskAlert::REASSIGNED_BY_OTHER} nêu người nhận mới (job lọc qua `forTask()`).
 *
 * # Người gọi hàng loạt (làn fa1, vòng sửa 1 — cho làn fa2)
 *
 * `$notifyFormerHolder = false` bỏ ĐÚNG MỘT lời báo: chuông {@see TaskAlert::REASSIGNED_AWAY} cho người vừa mất việc.
 * Dành cho người gọi chuyển NHIỀU việc của cùng một người một lượt (gỡ khỏi đội ngũ, nghỉ việc — làn fa2) và tự gửi
 * cho người đó MỘT chuông tổng hợp: không có tham số này người cũ nhận n + 1 chuông. Mặc định `true` (màn hình "Giao
 * lại" một việc).
 *
 * Gộp integ-fa, hai cờ nữa, cùng luật "một cờ bỏ đúng một lời báo" (mặc định `true`, nhật ký `task_reassigned` không
 * đổi):
 *  - `$notifyNewHolder = false` bỏ chuông + thư `staff.task.assigned` (và thông báo đẩy của nó) cho người MỚI —
 *    {@see TransferStaffWork} gửi người nhận một chuông + một thư tổng hợp cho cả lô;
 *  - `$notifyAssigner = false` bỏ chuông {@see TaskAlert::REASSIGNED_BY_OTHER} cho người giao gốc — cả hai người gọi
 *    hàng loạt đã báo người giao (hay người nhận thay họ, `ResolveStaffRecipients::forTaskAssigner()`) MỘT chuông cho
 *    mọi việc của họ trong lần đó (`SendTeamDepartureNotices` — `TASKS_HANDLED`; `SendStaffWorkTransferNotices` —
 *    `StaffWorkTransferAlert::ASSIGNER`), nên chuông từng việc là lời báo thứ hai về cùng một chuyện.
 *
 * # Nhận thay (`$takeOver`, gộp integ-fa — chỉ đường gỡ thành viên / chuyển việc nội bộ)
 *
 * `$takeOver = true` VÀ người mới chính là người bấm: luật "không tự giao việc cho mình" không áp (người bấm nhận
 * việc của một người đang rời đi — trưởng phòng nhận thay trợ lý nghỉ việc, luật sư phụ trách nhận lại việc của trợ lý
 * bị gỡ), nhưng {@see TaskAssignees::qualifies()} vẫn hỏi như mọi lần (ghế giữ việc, mở được vụ). Việc sang thẳng "Đang
 * làm" (`accepted_at` = `assigned_at` = lúc nhận — người bấm đã chọn nhận, không có bước "Đã nhận"), dòng
 * `task_reassigned` ghi `to = accepted` và `taken_over: true`, và không chuông/thư "được giao" nào tới chính họ. Các
 * lời báo khác theo cờ của chúng. Người mới không phải người bấm thì cờ này không đổi gì. Màn hình "Giao việc" / "Giao
 * lại" không bao giờ truyền cờ này: ở đó người bấm không có trong danh sách và vẫn bị cấm tự giao.
 *
 * Người giao (`assigned_by`) giữ nguyên, kể cả khi người nhận chính là người giao (người nhận thay, hay một lần "Giao
 * lại" trả việc về người đã giao nó): việc thành việc RIÊNG của họ (người giao = người giữ), và các chỗ đọc người giao
 * coi như việc không có người theo dõi riêng — không ở "Tôi đã giao" (`Task::scopeAssignedBy()`), không "Trả lại" được
 * (`TaskPolicy::decline` — họ "Giao lại" hay "Huỷ" bằng quyền người giao), lời báo cho người giao về nó tới luật sư phụ
 * trách hay người quản lý của vụ (`ResolveStaffRecipients::forTaskAssigner()`), không tới chính họ.
 */
class ReassignTask
{
    use OpensTask;
    use QueuesTaskNotices;
    use ValidatesTaskInput;

    /**
     * @param  bool  $setDueDate  làn fa1: `true` thì hạn của việc thành `$dueDate` (rỗng = không hạn; một ngày đã
     *                            qua chỉ được khi đó đúng là hạn việc đang mang — `ValidatesTaskInput::cleanDueDate()`);
     *                            `false` (gọi từ nơi không có ô hạn) thì giữ hạn cũ
     * @param  bool  $notifyFormerHolder  `false`: không xếp chuông {@see TaskAlert::REASSIGNED_AWAY} cho người vừa mất
     *                                    việc — người gọi hàng loạt tự gửi một chuông tổng hợp (docblock lớp); mọi lời
     *                                    báo khác không đổi
     * @param  bool  $notifyNewHolder  gộp integ-fa: `false` — không chuông + thư "được giao" cho người mới (docblock lớp)
     * @param  bool  $notifyAssigner  gộp integ-fa: `false` — không chuông {@see TaskAlert::REASSIGNED_BY_OTHER} cho
     *                                người giao gốc (docblock lớp)
     * @param  bool  $takeOver  gộp integ-fa: người mới là người bấm thì nhận thay, sang "Đang làm" (docblock lớp, mục
     *                          "Nhận thay"); chỉ đường gỡ thành viên / chuyển việc nội bộ truyền
     */
    public function handle(
        Task $task,
        User $actor,
        User $newAssignee,
        ?string $dueDate = null,
        bool $setDueDate = false,
        bool $notifyFormerHolder = true,
        bool $notifyNewHolder = true,
        bool $notifyAssigner = true,
        bool $takeOver = false,
    ): Task {
        return DB::transaction(function () use ($task, $actor, $newAssignee, $dueDate, $setDueDate, $notifyFormerHolder, $notifyNewHolder, $notifyAssigner, $takeOver): Task {
            $fresh = $this->openTask($task, $actor);

            $this->authorizeTransition($fresh, $actor, 'reassign', TaskStatus::open());

            $locked = User::query()->withTrashed()->whereKey($newAssignee->getKey())->lockForUpdate()->first();
            // Xem docblock lớp, mục "Nhận thay".
            $takingOver = $takeOver && $locked !== null && $locked->is($actor);

            if ($locked !== null && $locked->is($actor) && ! $takingOver) {
                throw ValidationException::withMessages(['assignee_id' => [__('tasks.validation.self_assign')]]);
            }

            if ($locked === null || ! app(TaskAssignees::class)->qualifies($locked, $fresh->matter)) {
                throw ValidationException::withMessages(['assignee_id' => [__('tasks.validation.assignee_ineligible')]]);
            }

            if ($locked->getKey() === $fresh->assignee_id && $fresh->status !== TaskStatus::Declined) {
                throw ValidationException::withMessages(['assignee_id' => [__('tasks.validation.same_assignee')]]);
            }

            $from = $fresh->status;
            $previous = $fresh->assignee_id;
            $oldDue = $fresh->due_date?->toDateString();
            $newDue = $setDueDate ? $this->cleanDueDate($dueDate, $oldDue) : $oldDue;
            $to = $takingOver ? TaskStatus::Accepted : TaskStatus::Pending;
            $now = now();

            $fresh->update([
                'assignee_id' => $locked->getKey(),
                'status' => $to,
                'assigned_at' => $now,
                'accepted_at' => $takingOver ? $now : null,
                'declined_at' => null,
                'decline_reason' => null,
                'due_date' => $newDue,
            ]);

            $activity = Audit::record('task_reassigned', $fresh, [
                ...($fresh->matter_id !== null ? ['matter_id' => $fresh->matter_id] : []),
                'from' => $from->value,
                'to' => $to->value,
                'previous_assignee_id' => $previous,
                'assignee_id' => $locked->getKey(),
                ...($newDue !== $oldDue ? ['due_date_from' => $oldDue, 'due_date_to' => $newDue] : []),
                ...($takingOver ? ['taken_over' => true] : []),
            ], causer: $actor);

            // Gộp integ-fa: người nhận thay không tự báo mình; người gọi hàng loạt tắt riêng lời này (docblock lớp).
            if ($notifyNewHolder && ! $takingOver) {
                $this->queueNotice($fresh, TaskAlert::ASSIGNED, $locked->getKey(), $activity);
            }

            // Làn tk, Task 2 (rà soát Task 1, r1): người giao lại chính là người đang giữ (một quản lý giao
            // cho luật sư phụ trách, rồi luật sư đó chuyển tiếp) thì không tự báo mình.
            // Làn fa1, vòng sửa 1: người gọi hàng loạt tắt riêng lời này (docblock lớp, mục "Người gọi hàng loạt").
            if ($notifyFormerHolder && $from !== TaskStatus::Declined && $previous !== $actor->getKey()) {
                $this->queueNotice($fresh, TaskAlert::REASSIGNED_AWAY, $previous, $activity);
            }

            // Làn fa1 (phán quyết 7): luật sư phụ trách hay quản lý giao lại việc của một người giao khác — người
            // giao gốc được báo (một chuông), trừ khi chính họ là người vừa mất hay vừa nhận việc (đã có lời báo trên).
            // Gộp integ-fa: người gọi hàng loạt đã báo người giao một chuông cho cả lần đó (docblock lớp).
            if ($notifyAssigner && ! in_array($fresh->assigned_by, [$actor->getKey(), $previous, $locked->getKey()], true)) {
                $this->queueNotice($fresh, TaskAlert::REASSIGNED_BY_OTHER, $fresh->assigned_by, $activity);
            }

            return $fresh;
        });
    }
}
