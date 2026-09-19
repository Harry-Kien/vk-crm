<?php

/**
 * Danh mục hồ sơ: khách nộp giấy tờ (SPEC §6.6) và văn phòng duyệt hoặc từ chối (SPEC §6.7).
 *
 * **Hai loại người đọc, hai giọng khác nhau — và một trong hai KHÔNG phải nhân viên.** Các câu
 * dưới `review.*` là lỗi hiện trên màn hình của trợ lý đang thao tác. Các câu dưới `submit.*`
 * hiện cho KHÁCH HÀNG trên portal: SPEC §8 dặn "không dùng thuật ngữ kỹ thuật, không dùng từ viết
 * tắt", và §8.4 cấm những câu dừng ở "Upload failed" — mỗi câu phải nói ra việc cần làm tiếp
 * theo. Người đọc chúng có thể đang đứng ở sân uỷ ban phường với một cái điện thoại.
 *
 * **`rejection_templates` chép NGUYÊN VĂN từ SPEC §6.7, không diễn đạt lại.** Ba mẫu này tồn tại
 * vì lý do nêu thẳng trong SPEC: không có chúng thì trợ lý viết "không hợp lệ", và câu đó hiện
 * thẳng cho khách mà không nói được khách phải làm gì. Cặp ngoặc vuông trong mẫu thứ ba
 * (`[tên tài liệu đã nộp]`, `[tên đầu mục]`) cũng là nguyên văn SPEC: chúng là chỗ trống để người
 * duyệt điền tay, không phải tham số thay thế của `__()` — một tham số `__()` phải viết dạng
 * `:name`, nên hai thứ này không lẫn vào nhau được.
 *
 * Không câu nào ở đây nhắc tới quyền, vai trò, id bản ghi hay tên cột: từ chối vì trạng thái bản
 * ghi và từ chối vì thiếu quyền là hai chuyện khác nhau, và trộn chúng lại biến thông điệp thành
 * một cách dò xem ai có quyền gì (cùng luật với `lang/vi/documents.php`).
 */
return [
    /*
     * `SubmitClientDocument` (SPEC §6.6). Khách đọc.
     */
    'submit' => [
        // Một đầu mục không còn tồn tại, đã bị xoá khỏi danh mục, hoặc không thuộc hồ sơ của
        // người đang đăng nhập — BA tình huống, MỘT câu trả lời. SPEC §10.10: không tồn tại và
        // không có quyền phải trả lời giống hệt nhau, nếu không thì chính cặp thông điệp khác
        // nhau đó là cách dò xem một đầu mục có thật hay không.
        'item_unavailable' => 'Mục giấy tờ này không còn trong danh mục hồ sơ của anh/chị nên chưa gửi tệp lên được. Anh/chị tải lại trang hồ sơ rồi chọn lại mục cần nộp; nếu vẫn không thấy, gọi cho văn phòng để được hướng dẫn.',
    ],

    /*
     * `ReviewChecklistItem` (SPEC §6.7). Trợ lý hoặc luật sư đọc.
     */
    'review' => [
        // Hai nhãn đi vào câu này qua tham số, lấy từ `ChecklistItemStatus::label()` — cùng chuỗi
        // mà hai cái nút trên màn hình mang. Viết thẳng chữ vào đây một lần đã sai một lần rồi
        // ("Đã nhận đủ" trong khi nhãn thật là "Đã nhận"), và một câu lỗi chỉ sang một cái nút
        // không tồn tại thì tệ hơn một câu lỗi chung chung.
        'decision_not_allowed' => 'Kết quả duyệt chỉ có thể là ":accepted" hoặc ":rejected". Anh/chị chọn lại một trong hai rồi lưu.',
        'reason_required' => 'Từ chối một giấy tờ thì phải nói cho khách biết vì sao và phải làm gì tiếp theo — câu này hiện thẳng trên màn hình của khách. Anh/chị nhập lý do (ít nhất :min ký tự), hoặc bấm một trong các mẫu có sẵn rồi sửa lại cho đúng trường hợp.',
        'reason_too_short' => 'Lý do từ chối mới có :length ký tự, chưa đủ :min. Khách đọc câu này để biết phải làm gì, nên một câu cụt như "không hợp lệ" sẽ khiến anh/chị nhận lại đúng cái giấy tờ đó lần nữa. Anh/chị viết rõ chỗ nào chưa đạt và cần nộp lại thế nào, hoặc bấm một trong các mẫu có sẵn.',
        'item_missing' => 'Không tìm thấy mục giấy tờ này nữa — có thể ai đó vừa xoá nó trong lúc anh/chị đang mở trang. Anh/chị tải lại trang để xem danh mục hồ sơ hiện tại.',
        'matter_unavailable' => 'Hồ sơ chứa mục giấy tờ này đã bị xoá nên không duyệt được. Anh/chị khôi phục hồ sơ trước, rồi duyệt lại.',
    ],

    /*
     * Ba mẫu lý do từ chối ở SPEC §6.7, nguyên văn. Giao diện Task 6 gắn mỗi mẫu vào một nút,
     * bấm một cái là điền vào ô lý do.
     */
    'rejection_templates' => [
        'blurred' => 'Ảnh bị mờ ở góc trên nên không đọc được số thửa. Nhờ anh/chị chụp lại dưới ánh sáng tự nhiên, lấy trọn cả bốn góc trang.',
        'uncertified_copy' => 'Bản này là bản photo chưa chứng thực. Toà yêu cầu bản sao có chứng thực, anh/chị mang bản gốc ra Uỷ ban phường hoặc phòng công chứng để chứng thực giúp em.',
        'wrong_document' => 'File này là [tên tài liệu đã nộp], còn mục đang cần là [tên đầu mục]. Anh/chị kiểm tra lại giúp em nhé.',
    ],
];
