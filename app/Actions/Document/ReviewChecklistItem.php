<?php

namespace App\Actions\Document;

use App\Actions\Document\Concerns\OpensChecklistItem;
use App\Enums\ChecklistItemStatus;
use App\Events\ChecklistItemRejected;
use App\Exceptions\ChecklistItemNotReviewable;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
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
 * **Thứ tự các cổng.** Bốn cổng đầu — đọc lại bản ghi, giải hồ sơ, tài khoản còn hiệu lực,
 * `Gate`, rồi hồ sơ đã xoá mềm — nằm ở `OpensChecklistItem` vì `MarkChecklistItemNotApplicable`
 * phải trả lời giống hệt; docblock của trait đó giải thích cả thứ tự lẫn lần sửa thứ tự. Còn lại
 * ở Action này là hai cổng riêng của việc duyệt, theo đúng thứ tự:
 *
 *  1. cổng trạng thái của kết quả duyệt ({@see self::guardDecisionAgainstState()});
 *  2. xác thực ô nhập.
 *
 * **Xác thực ô nhập đứng SAU tất cả.** Người không có quyền trên hồ sơ này không đáng được biết
 * lý do họ nhập dài hay ngắn; họ chỉ đáng được biết là không.
 *
 * **Bản ghi được ĐỌC LẠI trong transaction**, và `$checklistItem` mà caller đưa vào chỉ dùng để
 * lấy khoá chính. Một đối tượng Eloquent là thứ ai cũng gán thuộc tính được, và `matter_id` của
 * nó chính là thứ quyết định `Gate` hỏi về hồ sơ nào.
 *
 * **HAI loại exception, không có loại thứ ba.** `ValidationException` cho ô nhập sai (gắn đúng
 * tên ô để Filament hiện tại chỗ), và `ChecklistItemNotReviewable` — một `DomainException` — cho
 * mọi thứ về BẢN GHI, kể cả lời từ chối vì thiếu quyền. Action này KHÔNG ném
 * `AuthorizationException`, và đó là một thay đổi có chủ đích khác với Action anh em
 * `SubmitClientDocument`: ở đó người đọc là khách trên portal và một mã 403/404 là kết cục đúng;
 * ở đây người đọc là nhân sự đang mở một màn hình mà `canAccess()` đã gác từ trước, và nguyên
 * nhân thật sự hay gặp nhất là một trang đã cũ. Một trang 403 ở tình huống đó vứt mất đúng câu
 * tiếng Việt vừa được viết ra để nói cho họ biết phải làm gì.
 *
 * Giá phải trả, nói thẳng: mọi caller của Action này BẮT BUỘC phải bắt `DomainException` (ràng
 * buộc toàn cục của kế hoạch M4), vì một `DomainException` không được bắt là một lỗi 500, không
 * phải một trang 403.
 */
class ReviewChecklistItem
{
    use OpensChecklistItem;

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
            // Đọc lại bản ghi, giải hồ sơ, hỏi quyền — bốn cổng, một chỗ, dùng chung với
            // `MarkChecklistItemNotApplicable`: xem `OpensChecklistItem`, nơi SPEC §10.10 cho
            // danh mục hồ sơ được phát biểu. `$checklistItem` mà caller đưa vào chỉ dùng để lấy
            // khoá chính.
            [$fresh, $matter] = $this->openChecklistItem($checklistItem, $actor);

            $this->guardDecisionAgainstState($fresh, $decision);

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
     * Cổng trạng thái của SPEC §6.7, và nó BẤT ĐỐI XỨNG — hai nhánh duyệt không đối xứng vì hai
     * hậu quả không đối xứng.
     *
     * **`accepted` đi được từ mọi trạng thái.** Khách mang giấy tờ ra tận văn phòng đưa tay là
     * chuyện xảy ra hằng ngày, và lúc đó đầu mục vẫn đang `missing`. Chặn nhánh này sẽ buộc trợ
     * lý bịa ra một lần nộp trên portal để rồi tự duyệt lần nộp đó.
     *
     * **`rejected` thì phải có cái để từ chối.** `rejection_reason` không phải một ghi chú nội
     * bộ: nó hiện nguyên văn cho khách (SPEC §6.7, §8.3 mục 4) và kéo theo một email
     * `client.document_rejected` (SPEC §9). Từ chối một đầu mục `missing` là gửi cho khách câu
     * "ảnh anh/chị gửi bị mờ ở góc trên" về một tấm ảnh họ chưa từng gửi; `not_applicable` còn
     * tệ hơn một bậc, vì chính văn phòng vừa nói với họ rằng giấy tờ đó không cần nộp.
     *
     * Ba trạng thái còn lại đều đi được: `pending_review` là đường thường; `rejected` là lần sửa
     * lại một câu lý do viết chưa rõ — câu đó khách đang đọc nên phải sửa được; `accepted` là
     * lần văn phòng nhận ra mình duyệt nhầm, và đó là đường DUY NHẤT quay lại, vì SPEC không có
     * thao tác "bỏ duyệt".
     *
     * Đọc theo CỘT `status` chứ không theo "đầu mục này có dòng `documents` nào không": cột đó
     * là bản ghi của chính văn phòng về việc có gì đang chờ xem hay không, còn một dòng nhóm A
     * vẫn nằm đó sau khi một đầu mục được mở lại — và câu hỏi ở đây là "có gì đang chờ", không
     * phải "đã từng có gì".
     */
    private function guardDecisionAgainstState(MatterChecklistItem $item, ChecklistItemStatus $decision): void
    {
        if ($decision !== ChecklistItemStatus::Rejected) {
            return;
        }

        $nothingWaiting = [ChecklistItemStatus::Missing, ChecklistItemStatus::NotApplicable];

        if (in_array($item->status, $nothingWaiting, true)) {
            throw ChecklistItemNotReviewable::nothingToReject($item);
        }
    }

    /**
     * Câu sẽ hiện cho khách, hoặc `null` khi đầu mục được nhận đủ.
     *
     * Kết quả duyệt chỉ có hai giá trị (SPEC §6.7). `not_applicable` ở SPEC §4.10 là một trạng
     * thái có thật của đầu mục nhưng KHÔNG phải một kết quả duyệt — đánh dấu một giấy tờ là không
     * cần nộp là một quyết định về phạm vi hồ sơ, không phải một lần xem xét tệp khách gửi lên.
     * Thao tác đó có Action riêng: `MarkChecklistItemNotApplicable`.
     *
     * Hai câu `if` chứ không một `match` vét cạn — bản đầu của docblock này mô tả một `match`
     * chưa từng tồn tại. Hệ quả thực tế thì giống nhau (mọi giá trị ngoài hai cái kia dừng ở một
     * câu người dùng đọc được), nhưng khác ở một điểm có thật: thêm một giá trị mới vào
     * `ChecklistItemStatus` sẽ KHÔNG làm chỗ này nổ ra `UnhandledMatchError`, nó lặng lẽ rơi vào
     * nhánh "không phải kết quả duyệt". Đó là hành vi đúng ở đây — một trạng thái mới của đầu
     * mục không mặc nhiên là một kết quả duyệt mới — nhưng nó phải được viết ra đúng như nó là.
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
