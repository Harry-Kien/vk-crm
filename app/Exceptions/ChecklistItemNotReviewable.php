<?php

namespace App\Exceptions;

use App\Models\MatterChecklistItem;
use DomainException;

/**
 * `ReviewChecklistItem` từ chối duyệt vì TRẠNG THÁI của bản ghi (SPEC §6.7). Cùng hình dạng và
 * cùng lý lẽ với `DocumentNotPublishable`: một `DomainException` chứ không `ValidationException`,
 * vì không câu nào ở đây nói về một ô nhập sai — lý do từ chối quá ngắn hay kết quả duyệt không
 * hợp lệ mới là ô nhập sai, và chúng đi ra bằng `ValidationException` gắn đúng tên ô.
 *
 * Việc mang sang từ rà soát M2/M3 buộc mọi màn hình M4 gọi Action phải bắt `DomainException` và
 * đổi thành lỗi trên form, nên Task 6 bắt lớp cha này là đủ cho cả hai nhánh dưới đây.
 *
 * Thông điệp không nhắc tới id, tên đầu mục hay tên hồ sơ: người đọc đang đứng trước đúng bản ghi
 * đó trên màn hình, và nhắc lại tên nó không thêm được gì ngoài một chỗ nữa để rò rỉ.
 */
class ChecklistItemNotReviewable extends DomainException
{
    private function __construct(string $message, public readonly ?MatterChecklistItem $checklistItem = null)
    {
        parent::__construct($message);
    }

    /**
     * Đầu mục không còn đọc ra được khi Action đọc lại nó trong transaction: đã bị xoá mềm khỏi
     * danh mục, hoặc đã bị xoá hẳn, giữa lúc màn hình mở và lúc bấm nút.
     *
     * Hai trường hợp gộp làm một câu vì việc cần làm tiếp theo giống hệt nhau (tải lại trang), và
     * vì người đọc là nhân sự đã có quyền trên hồ sơ này — không có gì để giấu, chỉ có một trang
     * đã cũ cần làm mới.
     */
    public static function missing(): self
    {
        return new self(__('checklist.review.item_missing'));
    }

    /**
     * Hồ sơ chủ quản đã bị xoá mềm. Đứng TRƯỚC `Gate` trong Action, cùng lý do với
     * `DocumentNotPublishable::matterUnavailable()`: câu trả lời giống hệt nhau cho mọi người hỏi
     * nên nó không phân biệt được ai với ai, và nó là một bất biến dữ liệu chứ không phải một
     * quyết định phân quyền — `MatterChecklistItemPolicy::review` hôm nay đi qua `canSeeMatter`,
     * thứ CỐ Ý cho quản trị viên thấy cả hồ sơ đã xoá mềm.
     */
    public static function matterUnavailable(MatterChecklistItem $checklistItem): self
    {
        return new self(__('checklist.review.matter_unavailable'), $checklistItem);
    }
}
