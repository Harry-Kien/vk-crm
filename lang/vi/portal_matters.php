<?php

/**
 * Danh sách hồ sơ của cổng khách hàng (SPEC §8.2).
 *
 * **Tệp riêng, không nối thêm vào `lang/vi/portal.php`.** Ba agent đang viết song song trên
 * nhánh M5 và `portal.php` thuộc về task đăng nhập; một tệp chung là một lần va chạm chờ sẵn
 * (bài học M3, học bằng cách làm hỏng). Chia theo màn hình cũng đúng hơn về mặt nội dung: những
 * câu ở đây chỉ có nghĩa trên đúng một trang.
 *
 * Xưng hô giữ nguyên quy ước của `portal.php`: "anh/chị" cho người đọc, "chúng tôi" cho văn
 * phòng. SPEC §8 cấm thuật ngữ kỹ thuật và từ viết tắt — người đọc là khách hàng đang lo về vụ
 * việc của mình, ở mọi lứa tuổi, trên một màn hình điện thoại. Không có chữ "hệ thống", không có
 * chữ "trạng thái", không có "N/A".
 */
return [

    'title' => 'Hồ sơ của anh/chị',
    'navigation_label' => 'Hồ sơ của tôi',
    'heading' => 'Hồ sơ của anh/chị',
    'subheading' => 'Đây là những hồ sơ văn phòng đang làm cho anh/chị.',

    'card' => [
        /*
         * `client_label` của giai đoạn, KHÔNG bao giờ `label` nội bộ (SPEC §8.2). Khi vụ việc
         * đang ở một giai đoạn không còn trong cấu hình của loại vụ việc — loại bị xoá mềm, hoặc
         * giai đoạn bị gỡ sau khi hồ sơ đã bước vào — thì không có nhãn nào cho khách đọc. Câu
         * dưới đây là thứ hiện ra ở đó: nó nói thật mà không đổ một chi tiết nội bộ ra ngoài, và
         * tuyệt đối không được thay bằng `label`.
         */
        'stage_unknown' => 'Văn phòng đang cập nhật giai đoạn của hồ sơ này.',

        'updated_at' => 'Cập nhật gần nhất: :date',
        'never_updated' => 'Chúng tôi chưa gửi cập nhật nào cho anh/chị về hồ sơ này.',

        // SPEC §4.10 và đính chính 2026-09-16: X và Y do App\Actions\Document\ChecklistProgress
        // tính, màn hình chỉ đọc ra.
        'progress' => 'Đã nộp :submitted/:total giấy tờ',
        'progress_empty' => 'Hồ sơ này chưa có giấy tờ nào anh/chị cần nộp.',

        /*
         * M6 Task 4 (`requests/REQ-4`, đính chính SPEC §9 2026-09-27): huy hiệu "có trả lời
         * mới" — App\Support\ClientRequestActivity::matterHasUnseenStaffReply(). Đứng riêng, độc
         * lập với ba màu tiến độ ở dưới ('status'): một hồ sơ đã "đủ giấy tờ" vẫn có thể có một
         * câu hỏi văn phòng vừa trả lời mà khách chưa đọc.
         */
        'new_reply' => 'Văn phòng vừa trả lời một yêu cầu của anh/chị',
    ],

    /*
     * Ba màu, mỗi màu một câu — và câu mới là thứ mang nghĩa. Tài liệu bộ công cụ §4 đòi màu
     * không bao giờ là kênh thông tin duy nhất, vì một phần người đọc không phân biệt được đỏ với
     * xanh, và vì một trình đọc màn hình không đọc được màu.
     */
    'status' => [
        'outstanding' => 'Còn :count giấy tờ anh/chị cần nộp',
        'waiting_office' => 'Chúng tôi đang kiểm tra giấy tờ anh/chị đã nộp',
        'settled' => 'Giấy tờ của hồ sơ này đã đủ',
    ],

    /*
     * Trạng thái trống là một câu, không phải một khoảng trắng (tài liệu bộ công cụ §4: "không
     * hiện bảng rỗng; trạng thái trống phải kèm hướng dẫn bước tiếp theo"). Một khách vừa đăng
     * nhập lần đầu mà thấy một trang trắng sẽ nghĩ mình làm sai điều gì đó — nên câu này vừa nói
     * chuyện gì đang xảy ra, vừa cho họ một con đường không đi qua màn hình này.
     */
    'empty' => [
        'heading' => 'Chưa có hồ sơ nào hiện ở đây',
        'body' => 'Khi văn phòng mở hồ sơ cho anh/chị, hồ sơ sẽ hiện ngay tại trang này. Nếu anh/chị đang chờ một hồ sơ mà chưa thấy, xin gọi hoặc nhắn cho văn phòng để chúng tôi kiểm tra giúp.',
        'hotline' => 'Gọi văn phòng: :phone',
        'zalo' => 'Nhắn Zalo cho văn phòng',
    ],
];
