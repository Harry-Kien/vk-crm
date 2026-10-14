<?php

namespace App\Actions\Document;

use App\Actions\Document\Concerns\OpensChecklistItem;
use App\Enums\ChecklistItemStatus;
use App\Exceptions\DocumentCorrectionRefused;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục A5) — đưa một đầu mục danh mục VỀ "Còn thiếu". Trước
 * Action này không đường nào ghi lại `missing` sau lúc tạo: một lần lỡ bấm "Không cần nộp" trên đầu mục
 * bắt buộc làm thanh tiến độ tính là xong và thư nhắc thứ Hai/Tư/Sáu thôi nhắc mãi
 * (`ChecklistProgress::outstandingRequired()` chỉ lấy `missing`/`rejected`); trên mục đó chỉ còn nút
 * "Đã nhận" — bấm vào là nói sai sự thật.
 *
 * **Chỉ từ `not_applicable` hoặc `accepted`.** `missing`/`rejected` đã là "khách còn phải nộp" — không có
 * gì để mở lại; `pending_review` có một lần nộp đang chờ: duyệt hoặc từ chối nó trước (cùng lý lẽ
 * `MarkChecklistItemNotApplicable`). Từ chối khác: `DocumentCorrectionRefused::notReopenable()`.
 *
 * Mở đầu mục qua {@see OpensChecklistItem} — cùng bốn bước và cùng cổng quyền `checklist.review` với
 * `ReviewChecklistItem`/`MarkChecklistItemNotApplicable` (người quyết định một mục có cần nộp hay
 * không cũng là người mở lại nó), khoá `matters` trước, hồ sơ đã kết thúc thì chỉ đọc.
 *
 * Ghi: `status = missing`, `rejection_reason = null` (không có lý do từ chối nào để hiện cho khách),
 * `accepted_version = null` (không còn bản nào đứng là "đã nhận"), `reviewed_by`/`reviewed_at` = người và
 * lúc ra quyết định này. KHÔNG thư, không sự kiện `ChecklistItemRejected`: đây không phải một lần từ chối
 * tệp khách gửi. Tài liệu đã nộp (nếu có) ở nguyên chỗ. Dòng `checklist_item_reopened` mang trạng thái
 * trước đó — thứ không đọc lại được từ đâu khác sau khi cột bị ghi đè.
 */
class ReopenChecklistItem
{
    use OpensChecklistItem;

    /** Các trạng thái mở lại được — một định nghĩa cho Action và cho nút trên màn hình. */
    public const REOPENABLE = [ChecklistItemStatus::NotApplicable, ChecklistItemStatus::Accepted];

    public function handle(MatterChecklistItem $checklistItem, User $actor): MatterChecklistItem
    {
        $matterId = $this->checklistItemMatterId($checklistItem);

        return DB::transaction(function () use ($checklistItem, $actor, $matterId): MatterChecklistItem {
            [$fresh, $matter] = $this->openChecklistItem($checklistItem, $actor, $matterId);

            if (! in_array($fresh->status, self::REOPENABLE, true)) {
                throw DocumentCorrectionRefused::notReopenable();
            }

            $previousStatus = $fresh->status;

            $fresh->update([
                'status' => ChecklistItemStatus::Missing,
                'rejection_reason' => null,
                'accepted_version' => null,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ]);

            Audit::record('checklist_item_reopened', $fresh, [
                'matter_id' => $fresh->matter_id,
                'client_id' => $matter->client_id,
                'previous_status' => $previousStatus->value,
            ], $actor);

            return $fresh;
        });
    }
}
