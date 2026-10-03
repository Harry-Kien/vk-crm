<?php

namespace App\Exceptions;

use App\Actions\Document\AddChecklistItem;
use App\Actions\Document\MarkChecklistItemNotApplicable;
use App\Actions\Document\ReviewChecklistItem;
use DomainException;

/**
 * M7 Task 3 (sửa 2026-09-27, đoạn "Danh mục hồ sơ của vụ đã đóng" trong brief kế hoạch M7 — việc
 * hoãn lại của M6.5 Task 15, ruling sổ M6.5 dòng 113).
 *
 * **Danh mục hồ sơ của một vụ việc đã kết thúc là CHỈ ĐỌC.** Thêm đầu mục
 * ({@see AddChecklistItem}), duyệt/từ chối
 * ({@see ReviewChecklistItem}) và đánh dấu "không cần nộp"
 * ({@see MarkChecklistItemNotApplicable}) đều là những quyết định về việc
 * VĂN PHÒNG còn đang cần gì từ khách — một câu hỏi không còn ý nghĩa sau khi hồ sơ đã đóng. Không
 * chặn ở đây thì một vụ vừa đóng vẫn nhận thêm đầu mục, hoặc một đầu mục vẫn đổi trạng thái, trong
 * khi gói bàn giao (Task 4/11) đã hoặc sắp được sinh ra từ đúng danh mục này — một danh mục còn
 * đổi được sau khi đóng là một danh mục không bao giờ ổn định để đóng gói.
 *
 * **MỘT lớp, MỘT câu, dùng chung cho cả BA Action** — cùng lý lẽ đã dựng nên
 * {@see ChecklistItemNotReviewable}: một bất biến áp cho nhiều Action mà viết ở nhiều chỗ thì chỉ
 * đúng tới lần sửa đầu tiên. Không dùng lại `ChecklistItemNotReviewable` vì lớp đó nói về TRẠNG
 * THÁI CỦA MỘT ĐẦU MỤC đã có (và về việc không mở được nó); lớp này nói về TRẠNG THÁI CỦA CHÍNH
 * VỤ VIỆC, một câu hỏi mà `AddChecklistItem` — Action không thao tác trên một đầu mục có sẵn nào
 * — cũng phải trả lời được.
 *
 * **Không phải một `AuthorizationException`.** Đây không phải một câu hỏi về QUYỀN (ai được thấy
 * gì) mà là một câu hỏi về TRẠNG THÁI bản ghi (vụ việc đã đóng hay chưa) — đúng cùng một loại với
 * `ChecklistItemNotReviewable`, `DocumentNotPublishable`. Cùng quy ước, nó là một `DomainException`
 * thuần, và `ReportsActionFailures` (Filament) đổi nó thành một `Notification` bền vững cho người
 * dùng, không phải một trang 403.
 *
 * Câu chữ chỉ thẳng đường sửa: mở lại vụ việc qua "Chuyển giai đoạn" — đúng và chỉ đúng đường mở
 * lại mà hệ thống có (R8, M6.5 Task 5: đường bỏ qua của admin xoá `closed_at`).
 */
class MatterChecklistReadOnly extends DomainException
{
    public static function make(): self
    {
        return new self(__('exceptions.matter_checklist_read_only'));
    }
}
