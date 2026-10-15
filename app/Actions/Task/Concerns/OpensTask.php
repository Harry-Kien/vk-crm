<?php

namespace App\Actions\Task\Concerns;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Mở một việc để ghi — dùng bởi mọi Action chuyển trạng thái trong `app/Actions/Task/`. Gọi BÊN TRONG
 * `DB::transaction()` của Action.
 *
 * # Thứ tự khoá: vụ việc trước, việc sau
 *
 * Cùng thứ tự toàn cục của dự án (`ReassignMatter`, `ChangeDeadlineResponsible`: vụ việc trước, bảng con
 * sau, người thứ ba sau cùng), để một lần bàn giao vụ và một lần nhận việc trên cùng vụ không bao giờ
 * khoá theo hai chiều ngược nhau. `matter_id` của một việc không bao giờ đổi, nên lần đọc KHÔNG khoá
 * đầu tiên (chỉ để biết vụ nào) không mở khe hở nào; dòng việc được đọc lại dưới khoá ngay sau đó và mọi
 * câu hỏi trạng thái/quyền hỏi trên bản đọc lại — không bao giờ trên `$task` caller đưa vào (có thể là
 * một màn hình mở từ trước). Hai lần bấm "Đã nhận" cùng lúc vì vậy xếp hàng trên dòng việc: lần sau
 * thấy trạng thái lần trước đã ghi.
 *
 * # Hai câu từ chối
 *
 *  - {@see self::refuse()}: một câu chung (`tasks.errors.unavailable`) cho mọi lần người gọi không được
 *    xem việc, việc/vụ không còn, hay tài khoản đã bị vô hiệu — không phân biệt được với nhau (SPEC §10.10).
 *  - {@see self::refuseState()}: người gọi XEM ĐƯỢC việc nhưng việc đã sang trạng thái khác (người khác vừa
 *    huỷ, vừa giao lại…): nói trạng thái hiện tại, vì người đó vốn thấy nó trên danh sách.
 */
trait OpensTask
{
    use ChecksAccountActive;
    use ReadsWithoutPortalScope;

    protected function openTask(Task $task, User $actor): Task
    {
        $matterId = $this->scopelessly(Task::query())->whereKey($task->getKey())->value('matter_id');

        $matter = null;

        if ($matterId !== null) {
            $matter = $this->scopelessly(Matter::query())->lockForUpdate()->find($matterId);

            if ($matter === null) {
                $this->refuse();
            }
        }

        $fresh = $this->scopelessly(Task::query())->lockForUpdate()->find($task->getKey());

        if ($fresh === null || ! $this->accountIsActive($actor)) {
            $this->refuse();
        }

        $fresh->setRelation('matter', $matter);

        if (Gate::forUser($actor)->denies('view', $fresh)) {
            $this->refuse();
        }

        return $fresh;
    }

    /**
     * Hỏi `$ability` trên bản đã khoá. Người gọi xem được việc (đã qua {@see self::openTask()}): nếu họ
     * đúng vai cho khả năng này mà việc đã sang trạng thái khác thì nói trạng thái; ngược lại câu chung.
     *
     * @param  list<TaskStatus>  $states  các trạng thái mà khả năng này cho phép
     */
    protected function authorizeTransition(Task $fresh, User $actor, string $ability, array $states): void
    {
        if (Gate::forUser($actor)->allows($ability, $fresh)) {
            return;
        }

        if (! in_array($fresh->status, $states, true)) {
            $this->refuseState($fresh);
        }

        $this->refuse();
    }

    protected function refuse(): never
    {
        throw new AuthorizationException(__('tasks.errors.unavailable'));
    }

    protected function refuseState(Task $fresh): never
    {
        throw new DomainException(__('tasks.errors.not_in_state', ['status' => $fresh->status->label()]));
    }
}
