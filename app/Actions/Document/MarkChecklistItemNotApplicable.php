<?php

namespace App\Actions\Document;

use App\Actions\Document\Concerns\OpensChecklistItem;
use App\Actions\Document\Concerns\RefusesWhileAwaitingReview;
use App\Enums\ChecklistItemStatus;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Văn phòng đánh dấu một đầu mục danh mục là **không cần nộp** — `not_applicable` ở SPEC §4.10.
 *
 * **Vì sao Action này tồn tại.** `not_applicable` có trong bảng trạng thái của SPEC §4.10,
 * `MatterSeeder` dựng ra nó, và luật đếm thanh tiến độ `X/Y` của kế hoạch M4 Task 6 tính nó vào
 * tử số. Vậy mà cho tới hôm nay không có một đường nào trong hệ thống ĐẶT được giá trị đó:
 * `SubmitClientDocument` chỉ viết `pending_review`, `UploadStaffDocument` chỉ viết `accepted`,
 * `ReviewChecklistItem` chỉ viết `accepted`/`rejected`. Một trạng thái chỉ có người đọc mà không
 * có người ghi là một trạng thái seeder dựng ra được còn văn phòng thì không.
 *
 * (SPEC §4.10 tự nó KHÔNG nói `not_applicable` được tính vào tử số — §4.10 chỉ định nghĩa mẫu
 * số `Y`. Luật đếm đầy đủ, gồm cả ràng buộc `X ⊆ Y` mà thiếu nó thanh tiến độ hiện "Đã nộp 5/3",
 * nằm ở kế hoạch M4 Task 6.)
 *
 * **Đây KHÔNG phải một kết quả duyệt, và đó là lý do nó không nằm trong `ReviewChecklistItem`.**
 * Duyệt là xem xét cái tệp khách vừa gửi lên; đánh dấu không cần nộp là một quyết định về PHẠM VI
 * hồ sơ — "vụ này không cần giấy uỷ quyền vì khách tự đứng tên". Hai việc khác nhau đến mức
 * `rejection_reason` không có nghĩa gì ở đây, và trộn chúng vào một Action sẽ cho ra một tham số
 * `$decision` mà một nửa giá trị đòi lý do còn nửa kia thì không.
 *
 * **Từ chối khi đang `pending_review`.** Có một tệp khách vừa gửi lên đang nằm chờ ai đó mở ra
 * xem. Gạt đầu mục sang "không cần nộp" lúc đó là vứt lần nộp ấy vào im lặng: khách thấy mục của
 * mình đổi trạng thái mà không ai nói gì về cái họ đã gửi, và dòng `document_submitted` trỏ tới
 * một đầu mục không còn chờ gì. Văn phòng duyệt hoặc từ chối cái đang chờ trước đã — rồi mới
 * quyết định nó có cần nộp hay không. Luật đó KHÔNG còn riêng của Action này: một lần
 * `UploadStaffDocument` nộp thay ở nhóm A cũng đóng đầu mục lại, nên cả hai hỏi chung
 * {@see RefusesWhileAwaitingReview}.
 *
 * **Dọn `rejection_reason`.** Câu đó hiện nguyên văn cho khách (SPEC §6.7, §8.3 mục 4). Một đầu
 * mục đã "không cần nộp" mà còn treo câu "ảnh bị mờ ở góc trên" là một dòng nói dối — cùng lý lẽ
 * với `ReviewChecklistItem` và `UploadStaffDocument::settleChecklistItem()`.
 *
 * `reviewed_by`/`reviewed_at` thì được GHI, không xoá: chúng trả lời "ai quyết định, lúc nào", và
 * ở đây có một người vừa ra một quyết định về hồ sơ của khách.
 */
class MarkChecklistItemNotApplicable
{
    use OpensChecklistItem;
    use RefusesWhileAwaitingReview;

    public function handle(MatterChecklistItem $checklistItem, User $actor): MatterChecklistItem
    {
        return DB::transaction(function () use ($checklistItem, $actor): MatterChecklistItem {
            // Bốn bước (năm điều kiện từ chối) dùng chung với `ReviewChecklistItem`, kể cả cổng
            // quyền `checklist.review`: xem `OpensChecklistItem`. Quyền dùng chung là có chủ đích
            // — SPEC §5 không có mục riêng cho thao tác này, và người được giao quyết định một
            // giấy tờ khách nộp có đạt hay không cũng chính là người quyết định nó có cần nộp hay
            // không.
            [$fresh, $matter] = $this->openChecklistItem($checklistItem, $actor);

            // Hỏi trên `$fresh` (bản đọc lại dưới khoá), không trên đối tượng caller đưa vào.
            $this->refuseWhileAwaitingReview($fresh);

            // Giữ lại TRƯỚC khi `update()` ghi đè cột, và lấy từ `$fresh` chứ không từ đối tượng
            // caller đưa vào — đối tượng đó là thứ ai cũng gán thuộc tính được.
            $previousStatus = $fresh->status;

            $fresh->update([
                'status' => ChecklistItemStatus::NotApplicable,
                'rejection_reason' => null,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ]);

            // Bên TRONG transaction, cùng lý do với `ReviewChecklistItem`. Và dòng này là dấu vết
            // DUY NHẤT của quyết định: `matter_checklist_items` chưa dùng `LogsActivity`, nên
            // không có dòng `updated` nào ghi lại việc một đầu mục vừa rời khỏi danh sách giấy tờ
            // khách phải nộp.
            //
            // `previous_status` được chép vào vì nó là thứ không đọc lại được từ đâu khác sau khi
            // cột đã bị ghi đè, và nó chính là câu trả lời cho "đầu mục này từng bị từ chối rồi
            // được cho qua, hay chưa ai đụng tới nó".
            Audit::record('checklist_item_marked_not_applicable', $fresh, [
                'matter_id' => $fresh->matter_id,
                'client_id' => $matter->client_id,
                'previous_status' => $previousStatus->value,
            ], $actor);

            return $fresh;
        });
    }
}
