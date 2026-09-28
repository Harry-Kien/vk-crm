<?php

namespace App\Support;

use App\Enums\ClientRequestStatus;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * "Có trả lời mới" — SPEC §9 đính chính 2026-09-27 (`requests/REQ-4`): huy hiệu trên thẻ hồ sơ ở
 * cổng khách hàng (`MyMatters`) và trên trang tiến độ (`MatterProgress`), báo khách có câu trả
 * lời của văn phòng mà họ CHƯA đọc.
 *
 * **Không có bảng/cột nào ghi "khách đã đọc luồng", và R3 (M6) cấm thêm cột "đã nhắc lúc nào".**
 * Định nghĩa vì vậy không cần migration: một luồng CHƯA `closed` có câu của văn phòng đứng SAU
 * lần viết cuối cùng của khách. Tách ra khỏi `App\Filament\Portal\Pages\MyRequests` (nơi hàm này
 * sống từ M5, riêng cho việc chọn câu "answered_by_phone") thành một chỗ DÙNG CHUNG — phán quyết
 * controller, task-4-brief.md: "không gọi một trang Filament từ trang khác" — để `MyRequests`,
 * `MyMatters`, `MatterProgress` nói cùng một câu về cùng một luồng.
 *
 * **Huy hiệu tắt khi khách viết tiếp hoặc luồng đóng — cả hai đều "miễn phí" từ chính định
 * nghĩa, không cần một điều kiện riêng cho từng vế:**
 *  - khách viết tiếp ⇒ câu MỚI NHẤT của luồng là của khách, nên "câu của văn phòng đứng sau lần
 *    viết cuối của khách" tự động sai;
 *  - luồng đóng ⇒ {@see self::hasUnseenStaffReply()} hỏi thẳng `status !== Closed`.
 */
final class ClientRequestActivity
{
    /**
     * Có câu của NHÂN SỰ đứng sau lần viết cuối cùng của KHÁCH trong luồng này hay không — không
     * xét trạng thái `closed`. Dùng ở `MyRequests::statusLine()` để chọn câu "answered_by_phone"
     * (REQ-5): một luồng `answered` không có gì đứng sau lần khách viết cuối (kể cả khi đã đóng
     * lại rồi mở ra) nghĩa là câu trả lời không được VIẾT RA qua form, mà được đánh dấu tay.
     *
     * `$request->replies` phải đã được NẠP SẴN (không tự truy vấn ở đây): mọi nơi gọi hàm này đều
     * đã có `ClientRequest::replies()` trong tay (từ `MyRequests::threads()`,
     * `MyMatters::buildCards()`, `MatterProgress`), và một Support tự chạy N+1 truy vấn cho từng
     * luồng trên một trang liệt kê nhiều luồng/hồ sơ là đúng cái giá mà `ChecklistProgress`
     * (`App\Actions\Document\ChecklistProgress`) đã tránh bằng cách nhận một tập đã nạp.
     */
    public static function hasStaffReplyAfterClientsLastEntry(ClientRequest $request): bool
    {
        $staffMorph = (new User)->getMorphClass();

        $lastClientEntryId = $request->replies
            ->filter(fn (ClientRequestReply $reply): bool => $reply->author_type !== $staffMorph)
            ->max('id') ?? 0;

        return $request->replies->contains(
            fn (ClientRequestReply $reply): bool => $reply->author_type === $staffMorph
                && (int) $reply->getKey() > (int) $lastClientEntryId,
        );
    }

    /**
     * Huy hiệu "có trả lời mới" của MỘT luồng: chưa đóng, và có câu trả lời chưa đọc theo định
     * nghĩa ở trên.
     */
    public static function hasUnseenStaffReply(ClientRequest $request): bool
    {
        return $request->status !== ClientRequestStatus::Closed
            && self::hasStaffReplyAfterClientsLastEntry($request);
    }

    /**
     * Huy hiệu ở TẦM HỒ SƠ: ít nhất một luồng của `$matter` có "trả lời mới" — dùng cho thẻ hồ sơ
     * ở `MyMatters` và trang tiến độ `MatterProgress`.
     *
     * `$requests` phải đã nạp `replies` (cùng lý do {@see self::hasStaffReplyAfterClientsLastEntry()}).
     * Nhận một `Collection<ClientRequest>` thay vì `Matter` để caller tự quyết định cách nạp —
     * `MyMatters::buildCards()` đã nạp toàn bộ thẻ trong MỘT truy vấn cố định (không một truy vấn
     * thêm cho mỗi thẻ, đúng ràng buộc docblock lớp đó), và ép hàm này tự đi qua `$matter->
     * clientRequests` sẽ phá đúng ràng buộc ấy.
     *
     * @param  Collection<int, ClientRequest>  $requests
     */
    public static function matterHasUnseenStaffReply(Collection $requests): bool
    {
        return $requests->contains(fn (ClientRequest $request): bool => self::hasUnseenStaffReply($request));
    }
}
