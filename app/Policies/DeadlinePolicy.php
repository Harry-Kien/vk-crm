<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

class DeadlinePolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    /**
     * `is_published` đọc thẳng trên bản ghi, đứng cạnh `visibleToPortal()` — cùng lý lẽ với
     * `StageLogPolicy::view()` và `MatterPolicy::releasedToPortal()`, thêm ở M5 Task 2. Khối 6
     * của SPEC §8.3 ("Mốc thời hạn sắp tới — chỉ mốc `is_published`") dựng trên đúng điều kiện
     * này, và một điều kiện chỉ được phát biểu một lần thì không phải một tầng.
     */
    public function view(User|ClientUser $user, Deadline $deadline): bool
    {
        return $user instanceof ClientUser
            ? (bool) $deadline->is_published
                && $this->visibleToPortal($user, $deadline)
                && $this->canSeeMatter($user, $deadline->matter)
            : $this->canSeeMatter($user, $deadline->matter);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::MatterUpdate->value);
    }

    /**
     * Cùng điều kiện với `create()` ở trên, chỉ khác là đã biết vụ việc nên hỏi thẳng
     * `MatterPolicy::update`. Trên bảng quyền SPEC §5 hôm nay điều này chưa loại thêm vai trò
     * nào — bốn vai trò có `matter.view` đều có `matter.update` — nhưng nó gỡ luật ra khỏi bảng
     * quyền hiện hành: hôm nào văn phòng cấp một vai trò chỉ-đọc (`matter.view` mà không
     * `matter.update`, đúng chữ "hạn chế" ở ô trợ lý trong SPEC §5) thì hàng mốc thời hạn khoá
     * lại mà không phải sửa policy. Nó cũng chặn sửa mốc trên một vụ việc đã xoá mềm.
     *
     * Đây vẫn là cổng cho các thao tác THƯỜNG NGÀY trên một mốc (đổi tên, ngày, người phụ
     * trách, đánh dấu hoàn thành) — trợ lý đi qua cổng này bình thường. Riêng việc CÔNG BỐ mốc
     * cho khách đi qua {@see self::publish()}, một cổng RIÊNG và CHẶT hơn (R5, M6.5 Task 10):
     * đây chính là nửa "hôm nào văn phòng cấp một vai trò hạn chế" mà đoạn trên nói tới, chỉ khác
     * là nó tới sớm hơn dự kiến — không phải một vai trò MỚI, mà là trợ lý SẴN CÓ hôm nay.
     */
    public function update(User|ClientUser $user, Deadline $deadline): bool
    {
        return $user instanceof User && $this->canUpdateMatter($user, $deadline->matter);
    }

    public function delete(User|ClientUser $user, Deadline $deadline): bool
    {
        return $this->update($user, $deadline);
    }

    /**
     * Công bố/gỡ mốc hạn cho khách (SPEC §8.3 khối 6) — R5 (roles-05, M6.5 Task 10): "Các công
     * tắc công bố (cổng của vụ việc, công bố mốc hạn) đòi `stageLog.publish`", cùng luật với
     * `MatterPolicy::setPortalPublication()`. Trước bản sửa này, `SetDeadlinePublication` chỉ đi
     * qua `OpensDeadline::openDeadline()` (hỏi `update`), và trợ lý CÓ `matter.update` nhưng
     * KHÔNG có `stageLog.publish` — nên trợ lý công bố được một mốc hạn cho khách, đúng loại
     * quyết định SPEC §5 dành riêng cho ai có quyền công bố.
     *
     * **Fix round 1 — ruling (task-10-fix1-findings.md): chỉ CHIỀU BẬT đòi `stageLog.publish`.**
     * Bản đầu áp CẢ HAI chiều, với lý lẽ "quyền của actor với loại quyết định này không nên bất
     * đối xứng như điều kiện 'vụ việc đã bật portal'". Chủ nhiệm chốt lại NGƯỢC với lý lẽ đó: BẬT
     * là quyết định đưa MỘT MỐC HẠN ra trước mắt khách — nhưng GỠ chỉ RÚT nó khỏi cổng, tức THU
     * HẸP những gì khách thấy, không phải một quyết định "đưa gì ra cho khách" mới. Cùng ruling áp
     * cho `MatterPolicy::setPortalPublication()` — hai công tắc cùng hình dạng, cùng luật.
     *
     * `$publish` là tham số THỨ HAI của ability — truyền qua mảng khi hỏi Gate:
     * `Gate::allows('publish', [$deadline, $publish])`. KHÔNG có giá trị mặc định, cùng lý do với
     * `MatterPolicy::setPortalPublication()`.
     */
    public function publish(User|ClientUser $user, Deadline $deadline, bool $publish): bool
    {
        return $user instanceof User
            && $this->update($user, $deadline)
            && (! $publish || $user->can(Permission::StageLogPublish->value));
    }
}
