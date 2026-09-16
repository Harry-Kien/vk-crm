<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

/**
 * Policy này trả lời cho HAI guard theo hai luật khác nhau. Nhân sự (`web`) đi qua quyền spatie
 * cộng tầm nhìn vụ việc; khách (`client`) không chạm tới spatie bao giờ — quyền của họ là global
 * scope cộng policy (SPEC §5 phần Portal). Mọi ability dưới đây phải rẽ nhánh theo lớp của
 * $user trước khi làm bất cứ việc gì khác.
 *
 * Nhánh khách của `view()` kiểm tra HAI lần cùng một luật, cố ý:
 *
 * - `visibleToPortal()` chạy lại đúng global scope thật, nên policy không bao giờ NỚI hơn tầng
 *   truy vấn — đây là thiết bị chống lệch có từ M2 và phải giữ.
 * - `isReleasedToPortal()` đọc thẳng ba cột trên bản ghi. Nó tồn tại vì một nhánh policy chỉ
 *   gồm "chạy lại tầng truy vấn" thì không phải một tầng riêng: nó sụp xuống thành chính tầng
 *   kia. Với hai luật SPEC gọi là tuyệt đối — nhóm D không bao giờ, chưa `published` thì chưa —
 *   muốn mở lỗ hổng phải sửa hai tệp bằng hai thứ ngôn ngữ khác nhau (một câu `where`, một câu
 *   so sánh thuộc tính) chứ không phải quên một `where`.
 */
class DocumentPolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, Document $document): bool
    {
        if ($user instanceof ClientUser) {
            return $document->isReleasedToPortal()
                && $this->visibleToPortal($user, $document)
                && $this->canSeeMatter($user, $document->matter);
        }

        if ($document->group->isInternal() && ! $user->can(Permission::DocumentViewInternal->value)) {
            return false;
        }

        return $this->canSeeMatter($user, $document->matter);
    }

    /** Tải tệp: khách phải được bật thêm client_can_download (SPEC §5). */
    public function download(User|ClientUser $user, Document $document): bool
    {
        if (! $this->view($user, $document)) {
            return false;
        }

        return $user instanceof ClientUser ? $document->client_can_download : true;
    }

    /**
     * $context là tham số ngữ cảnh tuỳ chọn theo quy ước Laravel
     * (`Gate::authorize('create', [Document::class, $item])`); Filament và giao diện gọi ability
     * này KHÔNG kèm ngữ cảnh khi chỉ quyết định có hiện nút "Tải lên" hay không.
     *
     * Khách: SPEC §5 phần Portal cho đúng một việc — "nộp tài liệu vào `matter_checklist_items`
     * thuộc matter hợp lệ". Đó là cái quyền không có tên trong bảng §5, và nó được diễn đạt ở
     * đây chứ không thêm vào `Permission` (guard `client` không dùng spatie). Một `Matter` trần
     * KHÔNG đủ: khách không bao giờ tạo được tài liệu rời ngoài danh mục hồ sơ. Nhánh không có
     * ngữ cảnh chỉ trả lời câu hỏi giao diện ("khách nói chung có nộp tệp được không"); chặn
     * thật nằm ở nhánh có ngữ cảnh, nên `SubmitClientDocument` (Task 4) phải gọi kèm đầu mục.
     *
     * Nhân sự: uỷ cho `MatterPolicy::update` khi đã biết vụ việc, để điều kiện "chưa xoá mềm,
     * có matter.update, thấy được vụ việc" chỉ tồn tại một chỗ.
     */
    public function create(User|ClientUser $user, Matter|MatterChecklistItem|null $context = null): bool
    {
        $matter = $context instanceof MatterChecklistItem ? $context->matter : $context;

        if ($user instanceof ClientUser) {
            if ($context === null) {
                return true;
            }

            return $context instanceof MatterChecklistItem
                && $this->visibleToPortal($user, $context)
                && $this->canSeeMatter($user, $matter);
        }

        return $context === null
            ? $user->can(Permission::MatterUpdate->value)
            : $this->canUpdateMatter($user, $matter);
    }

    public function publish(User|ClientUser $user, Document $document): bool
    {
        return $user instanceof User
            && $user->can(Permission::DocumentPublish->value)
            && $this->canSeeMatter($user, $document->matter);
    }

    /** Sửa tài liệu là công việc hồ sơ thường ngày: cả trợ lý trong đội ngũ cũng làm. */
    public function update(User|ClientUser $user, Document $document): bool
    {
        return $user instanceof User
            && $this->canUpdateMatter($user, $document->matter)
            && $this->view($user, $document);
    }

    /**
     * Xoá thì không. Việc mang sang từ rà soát M2/M3 nói đúng triệu chứng — "một trợ lý trong
     * đội ngũ xoá được tài liệu" — nhưng thuốc mà kế hoạch kê (`matter.update`) không chữa được
     * nó: theo bảng SPEC §5, đúng bốn vai trò có `matter.view` cũng có `matter.update`, nên
     * thêm mình `matter.update` không loại ai mà tầm nhìn vụ việc chưa loại. Cái phân biệt được
     * "làm hồ sơ" với "quyết định số phận một tài liệu" là `document.publish` — cùng nhóm vai
     * trò mà SPEC §5 giao quyền đưa tài liệu ra tới khách, và đúng nhóm không gồm trợ lý.
     *
     * Đi qua `update()` nên cũng thừa hưởng điều kiện đọc được: không ai xoá được một tài liệu
     * nhóm D mà chính họ không có quyền nhìn.
     */
    public function delete(User|ClientUser $user, Document $document): bool
    {
        return $this->update($user, $document)
            && $user->can(Permission::DocumentPublish->value);
    }
}
