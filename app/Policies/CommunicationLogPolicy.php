<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\CommunicationLog;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

class CommunicationLogPolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    /**
     * **Không màn hình nào của portal đọc bảng này** — SPEC §5 không liệt kê nhật ký liên lạc, và
     * phán quyết 3 của M5 giữ nguyên như vậy. Dòng `is_visible_to_client` dưới đây vì thế không
     * mở một cửa nào; nó đóng một cửa mà rà soát M5 Task 2 đã ĐO ĐƯỢC là đang mở.
     *
     * **Phép đo, ghi ra để nó là một con số chứ không một lo ngại.** Chạy nghi thức ba tầng của
     * `PortalIsolationSweepTest` — thay global scope bằng một scope rỗng, tức đúng hình dạng "ai
     * đó quên một câu `where`" — thì nhánh khách ở đây trả **`true`** cho một dòng
     * `is_visible_to_client = false`. Nó không chỉ là một bản sao của tầng truy vấn (thứ ít nhất
     * còn sụp xuống cùng câu trả lời); nó là một tầng KHÔNG hỏi gì về cái cột quyết định, nên khi
     * tầng kia hỏng thì nó cho qua. Nhật ký liên lạc là nơi ghi ai đã nói gì với ai — mặc định
     * của SPEC §4.17 là ĐÓNG, và cột đó là toàn bộ công tắc.
     *
     * Đọc thẳng `is_visible_to_client` trên bản ghi là cùng thiết bị với
     * {@see StageLogPolicy::view()}, {@see StageLogViewPolicy::create()} và
     * `Document::isReleasedToPortal()`: một điều kiện được phát biểu hai lần, bằng hai thứ ngôn
     * ngữ, không chung một câu lệnh nào.
     */
    public function view(User|ClientUser $user, CommunicationLog $communicationLog): bool
    {
        return $user instanceof ClientUser
            ? (bool) $communicationLog->is_visible_to_client
                && ! $communicationLog->trashed()
                && $this->visibleToPortal($user, $communicationLog)
                && $this->canSeeMatter($user, $communicationLog->matter)
            : $this->canSeeMatter($user, $communicationLog->matter);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::MatterUpdate->value);
    }

    public function update(User|ClientUser $user, CommunicationLog $communicationLog): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $communicationLog->matter);
    }

    public function delete(User|ClientUser $user, CommunicationLog $communicationLog): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $communicationLog->matter);
    }
}
