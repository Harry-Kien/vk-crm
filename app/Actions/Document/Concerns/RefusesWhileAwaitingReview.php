<?php

namespace App\Actions\Document\Concerns;

use App\Enums\ChecklistItemStatus;
use App\Exceptions\ChecklistItemNotReviewable;
use App\Models\MatterChecklistItem;

/**
 * **Một tệp của khách đang nằm chờ ai đó mở ra xem, nên thao tác này dừng lại.** Luật đó, một chỗ.
 *
 * Hai thao tác của văn phòng ĐÓNG một đầu mục danh mục lại mà không cần ai đọc tệp đang chờ:
 *
 *  - `MarkChecklistItemNotApplicable` (SPEC §4.10) gạt đầu mục sang "không cần nộp";
 *  - `UploadStaffDocument` ở nhóm A gọi `settleChecklistItem()`, thứ ghi thẳng `accepted` cộng
 *    `reviewed_by = người vừa bấm nút tải lên`.
 *
 * Cái thứ hai còn nặng hơn cái thứ nhất: nó không chỉ bỏ qua lần nộp của khách, nó còn khai vào
 * dữ liệu rằng đã có người DUYỆT nó, và nêu đích danh một người chưa hề mở tệp ra. Hai thao tác,
 * một cái hại, nên một câu từ chối — và nó được phát biểu ở ĐÚNG MỘT chỗ, vì một tính chất kiểu
 * "hai chỗ phải trả lời giống hệt nhau" mà cài ở hai chỗ thì chỉ đúng tới lần sửa đầu tiên (cùng
 * lập luận đã dựng nên {@see OpensChecklistItem}).
 *
 * **Bản ghi truyền vào phải là bản ĐỌC LẠI DƯỚI KHOÁ, không phải đối tượng caller cầm trong tay.**
 * Trạng thái đầu mục là thứ đổi được giữa lúc màn hình nạp trang và lúc Action ghi — ở
 * `UploadStaffDocument` khoảng đó chứa cả một lần quét virus dài tới 30 giây, thừa để chính khách
 * hàng gửi tệp lên đúng đầu mục ấy. Cả hai nơi gọi đều hỏi trên bản đọc lại vì lý do đó.
 *
 * Câu chữ dùng chung `checklist.not_applicable.awaiting_review`, viết cho người trong văn phòng và
 * nói ra việc cần làm tiếp theo (duyệt hoặc từ chối tệp đang chờ trước). Câu đó hôm nay còn nhắc
 * riêng thao tác "không cần nộp" ở vế giữa; nó là một câu đang được dùng cho hai thao tác nên vế
 * đó cần viết lại cho trung tính — một việc ở `lang/vi/checklist.php`, tệp mà Task 6 đang sửa
 * song song, nên nó được ghi lại thành việc mang sang chứ không sửa ở vòng này.
 */
trait RefusesWhileAwaitingReview
{
    /**
     * @throws ChecklistItemNotReviewable
     */
    protected function refuseWhileAwaitingReview(MatterChecklistItem $checklistItem): void
    {
        if ($checklistItem->status === ChecklistItemStatus::PendingReview) {
            throw ChecklistItemNotReviewable::awaitingReview($checklistItem);
        }
    }
}
