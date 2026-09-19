<?php

/**
 * Thông điệp về tài liệu và tệp. `file_guard.*` ứng với từng lý do từ chối của `FileGuard`
 * (SPEC §6.6 bước 2-4) và `VirusScanner` (bước 5) — xem `App\Exceptions\FileRejected`. Mỗi thông
 * điệp phải nói rõ việc cần làm tiếp theo (SPEC §8.4), không được dừng ở "tệp không hợp lệ".
 *
 * **Không thông điệp nào nói cho người tải lên biết hệ thống ĐÃ ĐỌC RA gì.** MIME thật mà `finfo`
 * đọc được từng nằm trong `content_mismatch`; với một khách đang chụp ảnh trên điện thoại đó là
 * chữ vô nghĩa, còn với một người đang dò danh sách trắng đó là một cái máy trả lời miễn phí —
 * đổi vài byte đầu tệp, đọc MIME hệ thống trả về, lặp lại tới khi tìm ra thứ lọt qua. MIME thật
 * đi vào log (`FileGuard` ghi `Log::warning('file_guard.content_mismatch')`), không đi ra màn
 * hình. Cùng lý do đó, `:extension` luôn được `FileRejected` cắt ngắn trước khi chèn vào câu:
 * chuỗi đó do client đặt tên tệp nên dài bao nhiêu cũng được.
 */
return [
    'file_guard' => [
        'extension_not_allowed' => 'Định dạng ":extension" không được chấp nhận. Chỉ nhận PDF, ảnh (JPG, JPEG, PNG) hoặc tệp Word/Excel (DOC, DOCX, XLS, XLSX). Nếu đây là ảnh chụp, hãy lưu lại dưới định dạng JPG hoặc PNG rồi tải lên lại.',
        'extension_missing' => 'Tên tệp không có phần mở rộng nên hệ thống không biết đây là loại tệp gì. Đặt lại tên tệp kèm đuôi (ví dụ "ho-so.pdf") rồi tải lên lại.',
        'content_mismatch' => 'Nội dung tệp không khớp với đuôi ":extension" mà tệp khai báo. Tệp có thể đã bị đổi tên sai định dạng hoặc hỏng trong lúc tải lên. Hãy mở lại tệp gốc, lưu đúng định dạng ":extension" rồi tải lên lại.',
        'not_office_package' => 'Tệp ":extension" này không phải một tài liệu Word/Excel thật: bên trong thiếu phần cấu trúc bắt buộc của định dạng. Hãy mở tệp gốc bằng Word hoặc Excel, chọn "Lưu thành" đúng định dạng ":extension" rồi tải lên lại.',
        'macro_content' => 'Tệp này có chứa macro (đoạn mã tự chạy khi mở tệp) nên không được nhận. Hãy mở tệp bằng Word hoặc Excel, chọn "Lưu thành" định dạng không macro (DOCX hoặc XLSX) rồi tải lên lại.',
        'package_unreadable' => 'Hệ thống không kiểm tra được phần bên trong của tệp Word/Excel này nên tạm từ chối để đảm bảo an toàn. Hãy lưu tệp sang PDF rồi tải lên lại, hoặc báo cho quản trị hệ thống.',
        'too_large' => 'Tệp vượt quá :max MB. Anh/chị thử chụp lại ở chế độ ảnh thường thay vì HDR, hoặc gửi từng trang một, hoặc nén tệp trước khi gửi.',
        'empty' => 'Tệp không có nội dung (0 KB). Có thể quá trình tải lên bị gián đoạn — hãy chọn lại tệp và tải lên lại.',
        'unreadable' => 'Hệ thống không đọc được tệp này. Hãy chọn lại tệp và tải lên lại; nếu vẫn lỗi, liên hệ văn phòng để được hỗ trợ.',
        'invalid_name' => 'Tên tệp chứa ký tự không hợp lệ. Đổi tên tệp (chỉ dùng chữ, số, dấu gạch ngang hoặc gạch dưới) rồi tải lên lại.',
        'upload_failed' => 'Quá trình tải tệp lên bị gián đoạn. Hãy kiểm tra kết nối mạng và thử tải lên lại.',
        'virus_detected' => 'Tệp bị nghi có mã độc nên không được lưu. Nếu chắc chắn tệp an toàn, hãy quét lại bằng phần mềm diệt virus trên máy trước khi gửi lại, hoặc liên hệ văn phòng.',
        'scanner_unavailable' => 'Không thể quét virus cho tệp này lúc này nên hệ thống tạm từ chối để đảm bảo an toàn. Hãy thử lại sau ít phút; nếu vẫn lỗi, báo cho quản trị hệ thống.',
    ],

    // Tên dự phòng khi tên tệp client gửi lên không còn ký tự nào dùng được (xem FileGuard::safeName).
    'fallback_file_name' => 'tep-tai-len',

    /*
     * `UploadStaffDocument` — nhân sự nộp tệp thay hoặc đưa văn bản vào hồ sơ.
     */
    'upload' => [
        'checklist_item_other_matter' => 'Đầu mục danh mục này thuộc một hồ sơ khác nên không gắn tài liệu vào đó được. Anh/chị chọn lại một đầu mục trong danh mục của chính hồ sơ đang mở, hoặc để trống ô này nếu tài liệu không thuộc đầu mục nào.',
    ],

    /*
     * `PublishDocument` (SPEC §6.5). Người đọc những câu này là trợ lý hoặc luật sư đang thao tác,
     * nên mỗi câu nói ra VIỆC CẦN LÀM TIẾP THEO, không dừng ở "không công bố được" — cùng luật với
     * `file_guard.*` ở trên (SPEC §8.4).
     *
     * Không câu nào nhắc tới quyền hay vai trò: từ chối vì trạng thái bản ghi và từ chối vì thiếu
     * quyền là hai chuyện khác nhau, và trộn chúng lại sẽ biến thông điệp thành một cách dò xem ai
     * có quyền gì.
     */
    'publish' => [
        'internal_group' => 'Tài liệu thuộc nhóm D (hồ sơ công việc nội bộ) nên không bao giờ công bố cho khách được, kể cả chỉ cho xem. Nếu đây thật sự là văn bản cần gửi khách, anh/chị sửa nhóm tài liệu sang đúng nhóm của nó (A, B hoặc C) rồi công bố lại.',
        'not_signed_and_filed' => 'Văn bản do văn phòng phát hành phải ở trạng thái "Đã ký và nộp" thì mới công bố cho khách được; tài liệu này đang ở trạng thái ":status". Anh/chị hoàn tất việc trình duyệt và nộp, cập nhật trạng thái tài liệu, rồi công bố lại.',
        'without_client_view' => 'Công bố mà không cho khách xem thì không có tác dụng gì: nếu chưa muốn khách thấy tài liệu này, anh/chị cứ để nguyên, đừng công bố. Nếu chỉ muốn khách biết là đã có mà chưa cho tải về, hãy bật "Cho khách xem" và tắt "Cho khách tải về".',
        'matter_unavailable' => 'Hồ sơ chứa tài liệu này đã bị xoá nên không công bố được. Anh/chị khôi phục hồ sơ trước, rồi công bố lại tài liệu.',
        'trashed' => 'Tài liệu này đã bị xoá nên không công bố được. Anh/chị khôi phục tài liệu trước, hoặc tải lên lại bản mới rồi công bố.',
        'missing' => 'Không tìm thấy tài liệu này nữa — có thể ai đó vừa xoá nó trong lúc anh/chị đang mở trang. Anh/chị tải lại trang để xem danh sách tài liệu hiện tại.',
    ],
];
