<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\CommunicationLog;
use App\Models\Matter;
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

    /**
     * Ghi một dòng nhật ký liên lạc vào `$matter` (M7 Task 8 — chữ ký này giữ nguyên cho M11, nơi
     * công cụ MCP ghi nhật ký hỏi đúng câu này qua
     * `Gate::forUser($actor)->…('create', [CommunicationLog::class, $matter])`).
     *
     * - Khách: luôn `false` — bảng này không có cửa ghi nào từ cổng (SPEC §8.3, phán quyết 3 M5).
     * - Nhân sự KHÔNG nêu vụ việc: `false`. Bản trước trả lời "có `matter.update` là ghi được",
     *   tức một cửa không biết mình mở vào hồ sơ nào.
     * - Nhân sự nêu vụ việc: đúng `MatterPolicy::update` qua {@see ChecksMatterAccess::canUpdateMatter()}
     *   — vụ chưa xoá mềm, có `matter.update`, VÀ xem được vụ (đội ngũ / `restricted`). Ba điều
     *   kiện đó có một định nghĩa duy nhất; không chép lại ở đây.
     */
    public function create(User|ClientUser $user, ?Matter $matter = null): bool
    {
        return $user instanceof User && $this->canUpdateMatter($user, $matter);
    }

    /**
     * Không màn hình nào sửa nhật ký liên lạc (bằng chứng, SPEC §4.17), nhưng cửa này vẫn trả lời,
     * nên nó không được RỘNG hơn cửa ghi: đúng điều kiện của {@see self::create()} trên vụ của
     * dòng, và không bao giờ trên một dòng đã xoá mềm. Bản trước mở cho bất kỳ ai XEM được vụ.
     */
    public function update(User|ClientUser $user, CommunicationLog $communicationLog): bool
    {
        return ! $communicationLog->trashed()
            && $this->create($user, $communicationLog->matter);
    }

    /**
     * Xoá = xoá MỀM kèm lý do qua `App\Actions\Communication\DeleteCommunicationLog`. Cùng điều
     * kiện với {@see self::update()}; một dòng đã xoá không xoá lại được (một lần xoá, một dòng audit).
     */
    public function delete(User|ClientUser $user, CommunicationLog $communicationLog): bool
    {
        return $this->update($user, $communicationLog);
    }

    /** Nhật ký liên lạc không bao giờ bị xoá cứng (M7 R5). */
    public function forceDelete(User|ClientUser $user, CommunicationLog $communicationLog): bool
    {
        return false;
    }
}
