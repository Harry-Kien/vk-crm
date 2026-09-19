<?php

namespace App\Actions\Document;

use App\Enums\ChecklistItemStatus;
use App\Events\ChecklistItemRejected;
use App\Exceptions\ChecklistItemNotReviewable;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Văn phòng duyệt hoặc từ chối một giấy tờ khách đã nộp — SPEC §6.7.
 *
 * **Cái được viết ra ở đây, khách sẽ đọc.** `rejection_reason` không phải một ghi chú nội bộ: nó
 * hiện nguyên văn trên portal (SPEC §6.7, §8.3 mục 4), cho một người đang lo lắng về hồ sơ của
 * chính mình. Ba thứ đi ra từ nhận xét đó:
 *
 *  - **Ngưỡng 20 ký tự là một ràng buộc về CHẤT LƯỢNG câu chữ, không phải một phép đo kỹ thuật.**
 *    SPEC §6.7 nói thẳng lý do nó tồn tại: không có nó thì trợ lý viết "không hợp lệ", và khách
 *    đọc xong vẫn không biết phải làm gì nên nộp lại đúng cái vừa bị từ chối. Đếm bằng
 *    `mb_strlen` — một câu 20 ký tự tiếng Việt có dấu dài 40 byte, và một ngưỡng đếm byte sẽ cho
 *    qua đúng những câu cụt lủn mà ngưỡng này sinh ra để chặn (bài học M3).
 *  - **Action không ghép thêm một chữ nào vào câu đó.** Không mã hồ sơ, không id bản ghi, không
 *    tên người duyệt, không tên cột. Thứ duy nhất nó làm với chuỗi người dùng nhập là `trim()`.
 *  - **Ba mẫu ở SPEC §6.7 nằm nguyên văn trong `lang/vi/checklist.php`** để giao diện điền một
 *    chạm, và cả ba đều dài hơn ngưỡng — có test ghim, vì một cái nút điền sẵn một câu rồi bị
 *    chính hệ thống từ chối là cái nút không ai bấm lần thứ hai.
 *
 * **Thứ tự các cổng, chép theo `PublishDocument` và vì cùng một lý do.** Hai cổng trạng thái (đầu
 * mục còn đó không, hồ sơ còn đó không) đứng TRƯỚC `Gate`: câu trả lời của chúng y hệt nhau cho
 * quản trị viên và cho người vừa bị thu hồi quyền, nên chúng không phân biệt được ai với ai và
 * không rò rỉ gì về phân quyền. Cổng "hồ sơ đã xoá mềm" phải nằm ở Action chứ không thể trông vào
 * policy: `MatterChecklistItemPolicy::review` đi qua `canSeeMatter()`, thứ CỐ Ý cho quản trị viên
 * nhìn thấy cả hồ sơ đã xoá mềm để còn khôi phục được.
 *
 * **Xác thực ô nhập đứng SAU `Gate`.** Người không có quyền trên hồ sơ này không đáng được biết
 * lý do họ nhập dài hay ngắn; họ chỉ đáng được biết là không.
 *
 * **Bản ghi được ĐỌC LẠI trong transaction**, và `$checklistItem` mà caller đưa vào chỉ dùng để
 * lấy khoá chính. Một đối tượng Eloquent là thứ ai cũng gán thuộc tính được, và `matter_id` của
 * nó chính là thứ quyết định `Gate` hỏi về hồ sơ nào.
 *
 * **Ba loại exception, không có loại thứ tư.** `ValidationException` cho ô nhập sai (gắn đúng tên
 * ô để Filament hiện tại chỗ), `ChecklistItemNotReviewable` — một `DomainException` — cho trạng
 * thái bản ghi, và `AuthorizationException` từ `Gate` như mọi Action khác. Màn hình Task 6 bắt
 * `DomainException` là đủ cho nhánh thứ hai.
 */
class ReviewChecklistItem
{
    /**
     * SPEC §6.7 và SPEC §4.10 ("`status = rejected` thì `rejection_reason` bắt buộc, tối thiểu
     * 20 ký tự"). Cùng ngưỡng mà đặc tả `RetractDocument` ở M6 sẽ dùng lại.
     */
    private const MIN_REJECTION_REASON_LENGTH = 20;

    public function handle(
        MatterChecklistItem $checklistItem,
        User $actor,
        ChecklistItemStatus $decision,
        ?string $rejectionReason = null,
    ): MatterChecklistItem {
        return DB::transaction(function () use ($checklistItem, $actor, $decision, $rejectionReason): MatterChecklistItem {
            // `lockForUpdate()` nối tiếp hai lần duyệt song song trên cùng một đầu mục trên
            // MariaDB. Bộ test chạy SQLite, nơi nó không sinh ra khoá nào, nên phần KHOÁ của câu
            // này không có test chứng minh; phần ĐỌC LẠI thì có.
            $fresh = MatterChecklistItem::query()->lockForUpdate()->find($checklistItem->getKey());

            if ($fresh === null) {
                throw ChecklistItemNotReviewable::missing();
            }

            // `belongsTo` của một model có SoftDeletes trả `null` khi hồ sơ đã bị xoá mềm, nên
            // MỘT phép kiểm `null` phủ được cả "hồ sơ đã xoá" lẫn "khoá ngoại hỏng". Không dùng
            // `withTrashed()` rồi hỏi `trashed()` như `PublishDocument`: ở đó hai nhánh cũng dẫn
            // tới cùng một thông điệp, nên tách ra chỉ thêm một nhánh mà không thông điệp nào
            // phân biệt được — và một nhánh không phân biệt được là một nhánh không test được.
            $matter = $fresh->matter()->first();

            if ($matter === null) {
                throw ChecklistItemNotReviewable::matterUnavailable($fresh);
            }

            // Hỏi trên `$fresh`, không trên đối tượng caller đưa vào: policy đọc `matter` của đối
            // tượng được hỏi, nên một `matter_id` bị sửa trong bộ nhớ sẽ trả lời thay cho dòng dữ
            // liệu thật.
            Gate::forUser($actor)->authorize('review', $fresh);

            $reason = $this->resolveRejectionReason($decision, $rejectionReason);

            $fresh->update([
                'status' => $decision,
                // Lý do chỉ tồn tại ở nhánh từ chối. Một đầu mục đã nhận đủ mà còn treo câu "ảnh
                // bị mờ" là một dòng nói dối, và người đọc nó là khách hàng — cùng lý lẽ với
                // `UploadStaffDocument::settleChecklistItem()`.
                'rejection_reason' => $reason,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ]);

            // Từ chối là một việc khách phải được biết ngay (SPEC §9, mẫu `client.document_
            // rejected`). Sự kiện là `ShouldDispatchAfterCommit` nên dispatch trong transaction
            // là an toàn; listener thuộc M6.
            if ($decision === ChecklistItemStatus::Rejected) {
                event(new ChecklistItemRejected($fresh));
            }

            // Bên TRONG transaction, cùng lý do với `PublishDocument` và `UploadStaffDocument`:
            // ngoài transaction thì có một khoảng — ngắn, nhưng có — mà bản ghi đã commit còn
            // dòng nhật ký thì chưa ghi. Nói cho đủ: KHÔNG mutation nào phân biệt được hai chỗ
            // đặt, vì thứ nó đóng là một tiến trình chết đúng giữa hai câu lệnh. Câu này là một
            // lập luận, không phải một điều kiện có test đứng sau.
            //
            // `rejection_reason` được chép vào dòng nhật ký: `matter_checklist_items` chưa dùng
            // `LogsActivity`, nên đây là dấu vết DUY NHẤT của câu văn phòng đã nói với khách, và
            // một lần sửa lý do về sau ghi đè cột chứ không ghi đè dòng này. Chép nó vào đây
            // không làm lộ gì thêm: chính khách đã đọc câu đó.
            Audit::record('checklist_item_reviewed', $fresh, [
                'matter_id' => $fresh->matter_id,
                'client_id' => $matter->client_id,
                'status' => $decision->value,
                'rejection_reason' => $reason,
            ], $actor);

            return $fresh;
        });
    }

    /**
     * Câu sẽ hiện cho khách, hoặc `null` khi đầu mục được nhận đủ.
     *
     * Kết quả duyệt chỉ có hai giá trị (SPEC §6.7). `not_applicable` ở SPEC §4.10 là một trạng
     * thái có thật của đầu mục nhưng KHÔNG phải một kết quả duyệt — đánh dấu một giấy tờ là không
     * cần nộp là một quyết định về phạm vi hồ sơ, không phải một lần xem xét tệp khách gửi lên, và
     * SPEC chưa đặc tả thao tác đó. Từ chối ở đây thay vì âm thầm nhận: một `match` vét cạn trên
     * enum khiến mọi giá trị ngoài hai cái này dừng lại ở một câu người dùng đọc được, chứ không
     * đi tiếp thành một trạng thái không ai định nghĩa.
     */
    private function resolveRejectionReason(ChecklistItemStatus $decision, ?string $rejectionReason): ?string
    {
        if ($decision === ChecklistItemStatus::Accepted) {
            return null;
        }

        if ($decision !== ChecklistItemStatus::Rejected) {
            throw ValidationException::withMessages([
                'status' => [__('checklist.review.decision_not_allowed', [
                    'accepted' => ChecklistItemStatus::Accepted->label(),
                    'rejected' => ChecklistItemStatus::Rejected->label(),
                ])],
            ]);
        }

        $reason = trim((string) $rejectionReason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => [__('checklist.review.reason_required', [
                    'min' => self::MIN_REJECTION_REASON_LENGTH,
                ])],
            ]);
        }

        // `mb_strlen`, không `strlen` — xem docblock lớp.
        $length = mb_strlen($reason);

        if ($length < self::MIN_REJECTION_REASON_LENGTH) {
            throw ValidationException::withMessages([
                'rejection_reason' => [__('checklist.review.reason_too_short', [
                    'length' => $length,
                    'min' => self::MIN_REJECTION_REASON_LENGTH,
                ])],
            ]);
        }

        return $reason;
    }
}
