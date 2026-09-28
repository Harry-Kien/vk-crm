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
     * Một đầu mục đang `pending_review`: có một tệp khách vừa gửi lên đang nằm chờ ai đó mở ra
     * xem, và cả hai thao tác đóng đầu mục lại lúc đó đều vứt lần nộp ấy vào im lặng —
     * `MarkChecklistItemNotApplicable` gạt nó sang "không cần nộp", còn `UploadStaffDocument` ở
     * nhóm A ghi thẳng `accepted` kèm tên người vừa bấm nút tải lên. Điều kiện chung nằm ở
     * `App\Actions\Document\Concerns\RefusesWhileAwaitingReview`, nên hai nơi không thể lệch nhau.
     *
     * Đứng SAU `Gate`, cùng hạng với `nothingToReject()`: một câu về trạng thái bản ghi, chỉ
     * người đã có quyền trên hồ sơ mới được nghe.
     */
    public static function awaitingReview(MatterChecklistItem $checklistItem): self
    {
        return new self(__('checklist.not_applicable.awaiting_review'), $checklistItem);
    }

    /**
     * Hồ sơ chủ quản đã bị xoá mềm. Đứng SAU `Gate` trong `OpensChecklistItem`, và đó là một thứ
     * tự đã được SỬA một lần: bản đầu trả lời câu này TRƯỚC cổng quyền với lập luận rằng nó giống
     * hệt nhau cho mọi người hỏi nên không rò rỉ gì. Lập luận đó đúng cho câu "không tìm thấy đầu
     * mục" và SAI cho câu này — tình huống "hồ sơ đã bị xoá" chỉ với tới được khi đầu mục CÓ
     * THẬT, nên trả lời nó cho một người không có quyền nào là xác nhận rằng cái id họ vừa gõ là
     * một id thật (SPEC §10.10). Từ chỗ nó đứng bây giờ, người đọc nó chắc chắn đã có quyền trên
     * hồ sơ, nên câu này được phép nói ra chuyện gì đã xảy ra và cách sửa.
     *
     * Nó vẫn là một câu về BẤT BIẾN DỮ LIỆU chứ không phải một quyết định phân quyền, và vì vậy
     * nó tồn tại riêng thay vì tan vào `unavailable()`: `MatterChecklistItemPolicy::review` đi
     * qua `canSeeMatter`, thứ CỐ Ý cho quản trị viên thấy cả hồ sơ đã xoá mềm — và chính họ là
     * người cần đọc câu "khôi phục hồ sơ trước đã".
     */
    public static function matterUnavailable(MatterChecklistItem $checklistItem): self
    {
        return new self(__('checklist.review.matter_unavailable'), $checklistItem);
    }

    /**
     * R11 (M6.5 Task 17, checklist-04): tập tài liệu mà hộp duyệt đã hiện khi mở ra KHÁC tập hiện
     * tại — khách vừa gửi thêm (hoặc gửi lại) trong lúc người duyệt đang mở hộp, và quyết định
     * sắp lưu (đã nhận / cần nộp lại) đang gắn vào một tệp không còn là tệp mới nhất.
     *
     * Đứng SAU `Gate` và SAU cổng trạng thái, cùng hạng với `nothingToReject()`: một câu về DỮ
     * LIỆU đã đổi giữa lúc mở hộp và lúc bấm lưu, chỉ người đã có quyền trên hồ sơ mới được nghe.
     *
     * Không liệt kê tên tệp hay id trong câu — người đọc là nhân sự đang nhìn thẳng vào hộp vừa
     * cũ đi, và việc CẦN LÀM là mở lại, không phải đọc một danh sách id vô nghĩa với họ. Id đầy
     * đủ nằm trong dòng audit, cho một lần rà soát sau này cần tới nó.
     */
    public static function documentsChanged(): self
    {
        return new self(__('checklist.review.documents_changed'));
    }
}
