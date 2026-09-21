<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;
use App\Policies\Concerns\ReadsPortalParents;

class ClientRequestPolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;
    use ReadsPortalParents;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    /**
     * **"Của chính mình" ở đây là theo `Client`, không theo `ClientUser`** — phán quyết ngày
     * 19/09/2026 của chủ văn phòng, ghim bằng test ở `PortalIsolationSweepTest`. Hai tài khoản
     * portal của cùng một khách hàng (SPEC §4.3 nêu ví dụ hai vợ chồng) ĐỌC ĐƯỢC yêu cầu của
     * nhau. Câu chữ ở SPEC §5 đọc được theo cả hai nghĩa, và cách đọc theo `Client` là cách đang
     * chạy từ M2; điều kiện thật nằm ở một chỗ duy nhất —
     * {@see ClientRequest::applyClientPortalConstraints()} — nên đổi cách đọc là đổi
     * đúng một hàm, và test sẽ đỏ để lần đổi đó là một quyết định chứ không phải một lần trượt.
     */
    public function view(User|ClientUser $user, ClientRequest $request): bool
    {
        $matter = $this->parentWithoutPortalScope($request, 'matter');

        return $user instanceof ClientUser
            ? $this->visibleToPortal($user, $request) && $this->canSeeMatter($user, $matter)
            : $this->canSeeMatter($user, $matter);
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
