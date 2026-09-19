<?php

namespace App\Exceptions;

use App\Models\MatterChecklistItem;
use DomainException;

/**
 * Các Action thao tác lên danh mục hồ sơ (`ReviewChecklistItem` — SPEC §6.7, và
 * `MarkChecklistItemNotApplicable` — SPEC §4.10) từ chối vì TRẠNG THÁI của bản ghi, hoặc vì
 * người hỏi không được mở dòng đó ra ({@see self::unavailable()}). Cùng hình dạng và
 * cùng lý lẽ với `DocumentNotPublishable`: một `DomainException` chứ không `ValidationException`,
 * vì không câu nào ở đây nói về một ô nhập sai — lý do từ chối quá ngắn hay kết quả duyệt không
 * hợp lệ mới là ô nhập sai, và chúng đi ra bằng `ValidationException` gắn đúng tên ô.
 *
 * Việc mang sang từ rà soát M2/M3 buộc mọi màn hình M4 gọi Action phải bắt `DomainException` và
 * đổi thành lỗi trên form, nên Task 6 bắt lớp cha này là đủ cho cả BỐN nhánh dưới đây — kể cả
 * {@see self::unavailable()}, nhánh mà một Action anh em (`SubmitClientDocument`) trả lời bằng
 * `AuthorizationException`. Nói thẳng cái giá của việc đó: một caller quên bắt `DomainException`
 * biến một lời từ chối vì thiếu quyền thành lỗi 500 chứ không phải trang 403. Lý do chọn như
 * vậy nằm ở docblock `ReviewChecklistItem`.
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
     * **Ba tình huống, MỘT câu.** Đầu mục không còn tồn tại, đã bị xoá mềm khỏi danh mục, và
     * thuộc một hồ sơ người đang hỏi không được thấy — cả ba đi ra từ đây, cùng lớp, cùng câu,
     * cùng kết cục HTTP.
     *
     * Bản đầu chỉ gộp HAI tình huống đầu và để tình huống thứ ba ném `AuthorizationException`
     * với thông điệp mặc định tiếng Anh của Laravel. Ba thứ khác nhau cùng lúc — lớp, câu chữ,
     * mã HTTP — nên bộ ba câu trả lời đó là một cái máy dò sự tồn tại của bản ghi, cho đúng
     * người SPEC §5 không cấp quyền nào trên hồ sơ ấy. SPEC §10.10 không chừa ngoại lệ cho người
     * trong văn phòng, và M3 đã áp đúng luật này cho cả panel
     * (`AnswerDeniedPanelRequestsWithNotFound`, với cùng ví dụ: kế toán).
     *
     * Giá phải trả, nói thẳng: một lần từ chối vì THIẾU QUYỀN bây giờ đi ra bằng một
     * `DomainException` chứ không `AuthorizationException`, nên nó hiện thành một câu lỗi trên
     * form thay vì một trang 403. Đổi lại đúng thứ vòng rà soát này tồn tại để giữ: câu tiếng
     * Việt đến được người đọc. Trang panel vốn đã bị `canAccess()` chặn từ trước, nên lời gọi
     * chạm tới đây mà không có quyền là một trang đã cũ hoặc một id bị sửa tay, không phải một
     * lối vào.
     */
    public static function unavailable(): self
    {
        return new self(__('checklist.review.item_unavailable'));
    }

    /**
     * Có tệp thì mới từ chối được. Một đầu mục `missing` hay `not_applicable` chưa có gì của
     * khách đang chờ xem, và `rejection_reason` là một câu nói THẲNG với khách về thứ họ đã gửi
     * (SPEC §6.7, §8.3 mục 4) — cộng thêm một email `client.document_rejected` ở SPEC §9.
     *
     * Đứng SAU `Gate`: đây là một câu về trạng thái bản ghi, chỉ người đã có quyền trên hồ sơ
     * mới được nghe. Nhãn trạng thái đi vào câu qua tham số, lấy từ `ChecklistItemStatus
     * ::label()` — cùng chuỗi mà màn hình đang hiện, chứ không phải một chuỗi chép tay.
     */
    public static function nothingToReject(MatterChecklistItem $checklistItem): self
    {
        return new self(
            __('checklist.review.nothing_to_reject', ['status' => $checklistItem->status->label()]),
            $checklistItem,
        );
    }

    /**
     * Hồ sơ chủ quản đã bị xoá mềm. Đứng TRƯỚC `Gate` trong Action, cùng lý do với
     * `DocumentNotPublishable::matterUnavailable()`: câu trả lời giống hệt nhau cho mọi người hỏi
     * nên nó không phân biệt được ai với ai, và nó là một bất biến dữ liệu chứ không phải một
     * quyết định phân quyền — `MatterChecklistItemPolicy::review` hôm nay đi qua `canSeeMatter`,
     * thứ CỐ Ý cho quản trị viên thấy cả hồ sơ đã xoá mềm.
     */
    /**
     * `MarkChecklistItemNotApplicable` gặp một đầu mục đang `pending_review`: có một tệp khách
     * vừa gửi lên đang nằm chờ ai đó mở ra xem, và gạt đầu mục sang "không cần nộp" lúc đó là
     * vứt lần nộp ấy vào im lặng.
     *
     * Đứng SAU `Gate`, cùng hạng với `nothingToReject()`: một câu về trạng thái bản ghi, chỉ
     * người đã có quyền trên hồ sơ mới được nghe.
     */
    public static function awaitingReview(MatterChecklistItem $checklistItem): self
    {
        return new self(__('checklist.not_applicable.awaiting_review'), $checklistItem);
    }

    public static function matterUnavailable(MatterChecklistItem $checklistItem): self
    {
        return new self(__('checklist.review.matter_unavailable'), $checklistItem);
    }
}
