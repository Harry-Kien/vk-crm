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
use Illuminate\Auth\Access\Response;

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
 *   kia. Hai luật SPEC gọi là tuyệt đối — nhóm D không bao giờ, chưa `published` thì chưa — nhờ
 *   vậy được phát biểu HAI LẦN bằng hai dạng câu khác nhau: một chuỗi `where` trong
 *   `Document::applyClientPortalConstraints()`, một chuỗi so sánh thuộc tính trong
 *   `Document::isReleasedToPortal()`. Hai hàm nằm cùng một tệp và ngay cạnh nhau — sự gần nhau
 *   đó là lời nhắc, không phải hàng rào. Hàng rào là chúng không chung một câu lệnh nào:
 *   quên một `where` không gỡ được điều kiện tương ứng ở đây, và mỗi điều kiện của mỗi hàm đều
 *   có một bản ghi riêng ghim nó trong `tests/Feature/Authorization/DocumentAccessTest.php`
 *   (xoá một điều kiện bất kỳ là một dòng đỏ, đã dựng lại bằng mutation).
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
     *
     * Kiểu `mixed` là cố ý, không phải lười: khai báo hẹp biến một lần gọi sai ngữ cảnh thành
     * `TypeError` — tức lỗi 500 — mà một hệ phân quyền chỉ được phép trả lời "có" hoặc "không".
     * Thứ gì không phải `Matter` cũng không phải `MatterChecklistItem` đều rơi xuống `$matter =
     * null` và bị từ chối ở cả hai nhánh, KHÔNG rơi xuống nhánh "không có ngữ cảnh": một câu
     * hỏi có ngữ cảnh mà ngữ cảnh sai vẫn là một câu hỏi có ngữ cảnh.
     *
     * @param  Matter|MatterChecklistItem|null  $context
     */
    public function create(User|ClientUser $user, mixed $context = null): bool
    {
        $matter = match (true) {
            $context instanceof MatterChecklistItem => $context->matter,
            $context instanceof Matter => $context,
            default => null,
        };

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

    /**
     * Công bố là quyết định về số phận một tài liệu, nên nó đứng cùng cổng với `delete`:
     * `document.publish` CỘNG điều kiện ghi được vào vụ việc. Đi qua `update()` để thừa hưởng
     * nguyên ba thứ ở đó — vụ việc chưa xoá mềm, có `matter.update`, và ĐỌC ĐƯỢC chính tài liệu
     * này (`view()`), nên không ai công bố được một tài liệu nhóm D mà họ không có quyền nhìn.
     *
     * Thân hàm trùng `delete()` là cố ý và được viết rời ra chứ không gọi lẫn nhau: hai quyền
     * hôm nay có cùng một điều kiện, nhưng chúng là hai câu hỏi khác nhau và một ngày siết
     * `publish` (ví dụ thêm "chưa published thì mới publish được") không được âm thầm siết luôn
     * quyền xoá. Mọi điều kiện "trạng thái nào thì công bố được" thuộc về `PublishDocument`
     * (SPEC §6.5), không thuộc policy.
     */
    public function publish(User|ClientUser $user, Document $document): bool
    {
        return $this->update($user, $document)
            && $user->can(Permission::DocumentPublish->value);
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
     *
     * **Gộp M6.5 + M9 (xung đột 5):** tệp đang được một bản ghi tiền trỏ tới (bản scan phụ lục,
     * biên lai) thì không ai xoá được, kể cả người đủ quyền — trả `Response::deny()` kèm lý do để
     * nút xoá nói ra vì sao. Hỏi SAU cổng quyền: người không được xoá tài liệu này nói chung không
     * cần biết nó có đang làm bằng chứng cho một khoản tiền hay không. Hook `Document::deleting`
     * chặn cùng điều kiện trên mọi đường không hỏi policy.
     */
    public function delete(User|ClientUser $user, Document $document): bool|Response
    {
        if (! ($this->update($user, $document) && $user->can(Permission::DocumentPublish->value))) {
            return false;
        }

        return $document->isReferencedByBillingRecord()
            ? Response::deny(__('documents.delete_blocked_billing_reference'))
            : true;
    }
}
