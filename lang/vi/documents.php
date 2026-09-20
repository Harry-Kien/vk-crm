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
        'checklist_item_deleted' => 'Đầu mục danh mục này đã bị xoá khỏi hồ sơ nên không gắn tài liệu vào đó được. Anh/chị tải lại trang rồi chọn một đầu mục còn trong danh mục, hoặc để trống ô này.',
        'title_required' => 'Tài liệu cần một tên gọi để anh/chị và khách nhận ra nó trong danh sách. Anh/chị nhập tên tài liệu rồi tải lên lại.',
        'title_too_long' => 'Tên tài liệu dài quá :max ký tự nên không lưu được. Anh/chị rút gọn lại còn phần chính (ví dụ "Quyết định 123/QĐ-UBND ngày 01/03/2026") rồi tải lên lại.',
        'issued_at_invalid' => 'Ngày ban hành chưa đúng định dạng nên hệ thống không đọc được. Anh/chị nhập theo dạng ngày/tháng/năm (ví dụ 01/03/2026) hoặc chọn từ lịch, hoặc để trống nếu tài liệu không có ngày ban hành.',
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
        'internal_group' => 'Tài liệu thuộc nhóm D (hồ sơ công việc nội bộ) nên không công bố cho khách được, kể cả chỉ cho xem. Nếu anh/chị cho rằng tài liệu này bị xếp nhầm nhóm, hãy báo luật sư phụ trách: việc chuyển một tài liệu ra khỏi nhóm D là một quyết định riêng, được ghi lại đầy đủ, và chỉ người có quyền công bố tài liệu mới làm được.',
        'not_signed_and_filed' => 'Văn bản do văn phòng phát hành phải ở trạng thái "Đã ký và nộp" thì mới công bố cho khách được; tài liệu này đang ở trạng thái ":status". Anh/chị hoàn tất việc trình duyệt và nộp, cập nhật trạng thái tài liệu, rồi công bố lại.',
        'without_client_view' => 'Công bố mà không cho khách xem thì không có tác dụng gì: nếu chưa muốn khách thấy tài liệu này, anh/chị cứ để nguyên, đừng công bố. Nếu chỉ muốn khách biết là đã có mà chưa cho tải về, hãy bật "Cho khách xem" và tắt "Cho khách tải về".',
        'without_file' => 'Tài liệu này chưa có tệp đính kèm nên chưa công bố được: khách sẽ thấy một dòng trong danh sách mà bấm vào không mở được gì. Anh/chị tải tệp lên cho tài liệu này trước, rồi công bố.',
        'matter_unavailable' => 'Hồ sơ chứa tài liệu này đã bị xoá nên không công bố được. Anh/chị khôi phục hồ sơ trước, rồi công bố lại tài liệu.',
        'trashed' => 'Tài liệu này đã bị xoá nên không công bố được. Anh/chị khôi phục tài liệu trước, hoặc tải lên lại bản mới rồi công bố.',
        'missing' => 'Không tìm thấy tài liệu này nữa — có thể ai đó vừa xoá nó trong lúc anh/chị đang mở trang. Anh/chị tải lại trang để xem danh sách tài liệu hiện tại.',
    ],

    /*
     * `RegroupDocument` và hàng rào tương ứng ở `Document::booted()`. Câu dưới đây nói về ĐƯỜNG
     * ĐI, không nói về quyền: người gặp nó thường là người có đủ quyền nhưng đang thao tác ở một
     * màn hình đi vòng qua Action, và một câu "anh/chị không có quyền" sẽ vừa sai vừa vô ích.
     */
    'regroup' => [
        'leaving_internal_group' => 'Tài liệu nhóm D (hồ sơ công việc nội bộ) chỉ chuyển sang nhóm khác bằng thao tác "Chuyển nhóm tài liệu" — thao tác đó ghi lại ai chuyển và chuyển từ nhóm nào sang nhóm nào. Anh/chị dùng thao tác đó thay vì sửa nhóm trực tiếp trên biểu mẫu.',
    ],

    /*
     * Tab "Tài liệu" trên trang chi tiết vụ việc (SPEC §7.2). Người đọc là nhân sự nội bộ.
     *
     * `internal_marker` chép NGUYÊN VĂN câu SPEC §7.2 in đậm ("Chỉ nội bộ — không bao giờ hiện
     * cho khách"). Nó không phải một lời nhắc chung chung: nhóm D là nơi ghi chú công việc, đánh
     * giá khả năng thắng kiện và trao đổi nội bộ nằm, và cái giá của một lần nhầm nhóm ở đây
     * không lấy lại được. Không diễn đạt lại, không rút gọn.
     */
    'tab' => [
        'internal_marker' => 'Chỉ nội bộ — không bao giờ hiện cho khách',
        'columns' => [
            'group' => 'Nhóm',
            'title' => 'Tên tài liệu',
            'status' => 'Trạng thái',
            'version' => 'Bản',
            'checklist_item' => 'Đầu mục danh mục',
            'client_access' => 'Khách xem/tải',
            'uploaded_at' => 'Đưa vào hồ sơ',
            'issued_at' => 'Ngày ban hành',
        ],
        'client_access' => [
            'none' => 'Khách chưa thấy',
            'view_only' => 'Khách xem được, chưa tải được',
            'view_and_download' => 'Khách xem và tải được',
            // Nhóm D không có ô nào để bật: SPEC §4.11 gọi đây là ranh giới tuyệt đối, nên dòng
            // này nói ra điều đó thay vì hiện "Khách chưa thấy" — một câu đọc như thể chỉ cần
            // bật lên là xong.
            'never' => 'Không bao giờ ra tới khách',
        ],
        'actions' => [
            'upload' => 'Đưa tài liệu vào hồ sơ',
            'upload_heading' => 'Đưa một tài liệu vào hồ sơ',
            'upload_success' => 'Đã lưu tài liệu vào hồ sơ.',
            'publish' => 'Công bố cho khách',
            'publish_heading' => 'Công bố tài liệu này cho khách',
            'publish_success' => 'Đã công bố tài liệu cho khách.',
            'regroup' => 'Chuyển nhóm',
            'regroup_heading' => 'Chuyển tài liệu này sang nhóm khác',
            'regroup_success' => 'Đã chuyển tài liệu sang nhóm mới và ghi lại thay đổi.',
            'download' => 'Tải tệp',
        ],
        'fields' => [
            'file' => 'Tệp',
            'file_help' => 'Nhận PDF, ảnh (JPG, JPEG, PNG) hoặc tệp Word/Excel (DOC, DOCX, XLS, XLSX), tối đa :max MB mỗi tệp.',
            'title' => 'Tên tài liệu',
            'title_help' => 'Tên này hiện trong danh sách của văn phòng, và với tài liệu đã công bố thì hiện cả cho khách. Viết đủ để nhận ra tài liệu mà không cần mở tệp.',
            'group' => 'Nhóm tài liệu',
            // Bốn nhóm quyết định ai đọc được tệp, nên ô này là ô quan trọng nhất của biểu mẫu.
            'group_help' => 'Nhóm quyết định khách có thấy tài liệu này hay không. Nhóm A ra tới khách ngay khi lưu; nhóm B và C nằm trong hồ sơ cho tới khi có người bấm công bố; nhóm D không bao giờ ra tới khách.',
            'checklist_item' => 'Gắn vào đầu mục danh mục',
            'checklist_item_help' => 'Để trống nếu tài liệu này không thuộc đầu mục nào trong danh mục hồ sơ.',
            'checklist_item_none' => 'Không gắn vào đầu mục nào',
            'issued_at' => 'Ngày ban hành hoặc ngày nộp thực tế',
            'client_can_view' => 'Cho khách xem',
            'client_can_view_help' => 'Khách thấy tài liệu này trong hồ sơ của họ trên trang khách hàng.',
            'client_can_download' => 'Cho khách tải về',
            'client_can_download_help' => 'Tắt ô này nếu muốn khách biết đã có tài liệu nhưng chưa cho giữ bản sao.',
            'target_group' => 'Chuyển sang nhóm',
        ],
    ],
];
