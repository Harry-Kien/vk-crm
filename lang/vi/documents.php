<?php

/**
 * Thông điệp về tài liệu và tệp. `file_guard.*` ứng với từng lý do từ chối của `FileGuard`
 * (SPEC §6.6 bước 2-4) và `VirusScanner` (bước 5) — xem `App\Exceptions\FileRejected`. Mỗi thông
 * điệp phải nói rõ việc cần làm tiếp theo (SPEC §8.4), không được dừng ở "tệp không hợp lệ".
 */
return [
    'file_guard' => [
        'extension_not_allowed' => 'Định dạng ":extension" không được chấp nhận. Chỉ nhận PDF, ảnh (JPG, JPEG, PNG) hoặc tệp Word/Excel (DOC, DOCX, XLS, XLSX). Nếu đây là ảnh chụp, hãy lưu lại dưới định dạng JPG hoặc PNG rồi tải lên lại.',
        'extension_missing' => 'Tên tệp không có phần mở rộng nên hệ thống không biết đây là loại tệp gì. Đặt lại tên tệp kèm đuôi (ví dụ "ho-so.pdf") rồi tải lên lại.',
        'content_mismatch' => 'Nội dung tệp không khớp với đuôi ":extension" mà tệp khai báo (hệ thống đọc được kiểu tệp thật là ":mime"). Tệp có thể đã bị đổi tên sai định dạng hoặc hỏng trong lúc tải lên. Hãy mở lại tệp gốc, lưu đúng định dạng ":extension" rồi tải lên lại.',
        'mime_unknown' => 'không xác định được',
        'too_large' => 'Tệp vượt quá :max MB. Anh/chị thử chụp lại ở chế độ ảnh thường thay vì HDR, hoặc gửi từng trang một, hoặc nén tệp trước khi gửi.',
        'empty' => 'Tệp không có nội dung (0 KB). Có thể quá trình tải lên bị gián đoạn — hãy chọn lại tệp và tải lên lại.',
        'unreadable' => 'Hệ thống không đọc được tệp này. Hãy chọn lại tệp và tải lên lại; nếu vẫn lỗi, liên hệ văn phòng để được hỗ trợ.',
        'invalid_name' => 'Tên tệp chứa ký tự không hợp lệ. Đổi tên tệp (chỉ dùng chữ, số, dấu gạch ngang hoặc gạch dưới) rồi tải lên lại.',
        'upload_failed' => 'Quá trình tải tệp lên bị gián đoạn. Hãy kiểm tra kết nối mạng và thử tải lên lại.',
        'virus_detected' => 'Tệp bị nghi có mã độc nên không được lưu. Nếu chắc chắn tệp an toàn, hãy quét lại bằng phần mềm diệt virus trên máy trước khi gửi lại, hoặc liên hệ văn phòng.',
        'scanner_unavailable' => 'Không thể quét virus cho tệp này lúc này nên hệ thống tạm từ chối để đảm bảo an toàn. Hãy thử lại sau ít phút; nếu vẫn lỗi, báo cho quản trị hệ thống.',
    ],
];
