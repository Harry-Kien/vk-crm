<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

class ClientRequestPolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, ClientRequest $request): bool
    {
        return $user instanceof ClientUser
            ? $this->visibleToPortal($user, $request) && $this->canSeeMatter($user, $request->matter)
            : $this->canSeeMatter($user, $request->matter);
    }

    /**
     * Khách gửi yêu cầu; nhân sự trả lời (SPEC §5 portal: "Tạo và xem `ClientRequest` của chính
     * mình"). $matter là ngữ cảnh tuỳ chọn theo quy ước Laravel — giao diện hỏi ability này
     * không kèm vụ việc khi mới chỉ quyết định có hiện nút "Gửi yêu cầu" hay không, còn chặn
     * thật nằm ở nhánh đã biết vụ việc.
     *
     * Hai guard, hai luật: khách chỉ cần vụ việc nằm trong tầm nhìn portal của mình; nhân sự
     * phải ghi được vào vụ việc đó (`MatterPolicy::update`), nên kế toán — không có
     * `matter.update` — không mở được yêu cầu thay khách.
     *
     * Kiểu `mixed` là cố ý: khai báo hẹp biến một lần gọi sai ngữ cảnh thành `TypeError` — lỗi
     * 500 — thay vì một lần từ chối. Ngữ cảnh không phải `Matter` rơi xuống `$matter = null` và
     * bị từ chối, chứ không được coi là "không có ngữ cảnh". Xem `DocumentPolicy::create`.
     *
     * @param  Matter|null  $context
     */
    public function create(User|ClientUser $user, mixed $context = null): bool
    {
        if ($context === null) {
            return $user instanceof ClientUser || $user->can(Permission::MatterUpdate->value);
        }

        $matter = $context instanceof Matter ? $context : null;

        return $user instanceof ClientUser
            ? $this->canSeeMatter($user, $matter)
            : $this->canUpdateMatter($user, $matter);
    }

    /**
     * Nhận yêu cầu, đổi trạng thái, gán người xử lý, trả lời — tất cả là GHI vào vụ việc, nên
     * điều kiện phải trùng với `create()` ở nhánh nhân sự: `MatterPolicy::update`, không chỉ
     * "thấy được vụ việc". Khách không sửa yêu cầu đã gửi (SPEC §4.14 không có bước nào cho
     * việc đó), nên nhánh `ClientUser` vẫn là không.
     */
    public function update(User|ClientUser $user, ClientRequest $request): bool
    {
        return $user instanceof User && $this->canUpdateMatter($user, $request->matter);
    }
}
