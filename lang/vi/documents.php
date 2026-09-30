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
        // Hai câu dưới đây bắt lời từ chối của CHÍNH Ô CHỌN TỆP (luật `mimetypes`/`required` của
        // Filament), tức khi tệp bị chặn TRƯỚC khi `FileGuard` kịp chạy — xem docblock tại chỗ
        // `->validationMessages()` được gọi trong `uploadAction()` (`docs/docs-7`).
        'file_required' => 'Anh/chị chưa chọn tệp. Chọn một tệp rồi tải lên lại.',
        'file_type' => 'Định dạng tệp này không được chấp nhận. Chỉ nhận PDF, ảnh (JPG, JPEG, PNG) hoặc tệp Word/Excel (DOC, DOCX, XLS, XLSX). Nếu đây là ảnh chụp, hãy lưu lại dưới định dạng JPG hoặc PNG rồi tải lên lại.',
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
        'not_signed_and_filed' => 'Văn bản do văn phòng phát hành phải ở trạng thái "Đã ký, đã nộp" thì mới công bố cho khách được; tài liệu này đang ở trạng thái ":status". Anh/chị bấm "Trình duyệt" (nếu còn là bản thảo), rồi "Đánh dấu đã ký, đã nộp" khi đã có bản ký và đã nộp, sau đó công bố lại.',
        'without_client_view' => 'Công bố mà không cho khách xem thì không có tác dụng gì: nếu chưa muốn khách thấy tài liệu này, anh/chị cứ để nguyên, đừng công bố. Nếu chỉ muốn khách biết là đã có mà chưa cho tải về, hãy bật "Cho khách xem" và tắt "Cho khách tải về".',
        'without_file' => 'Tài liệu này chưa có tệp đính kèm nên chưa công bố được: khách sẽ thấy một dòng trong danh sách mà bấm vào không mở được gì. Anh/chị tải tệp lên cho tài liệu này trước, rồi công bố.',
        'matter_unavailable' => 'Hồ sơ chứa tài liệu này đã bị huỷ hoặc xoá nên không công bố được tài liệu.',
        'trashed' => 'Tài liệu này đã bị xoá nên không công bố được. Nếu cần, hãy tải lên lại bản mới rồi công bố.',
        'missing' => 'Không tìm thấy tài liệu này nữa — có thể ai đó vừa xoá nó trong lúc anh/chị đang mở trang. Anh/chị tải lại trang để xem danh sách tài liệu hiện tại.',
        // Vòng sửa 1 Task 16: kiểm tra optimistic — ai đó đã đổi cờ xem/tải của tài liệu này sau
        // khi hộp thoại được mở. Nói ra NGUYÊN NHÂN thật (hai tab, hay một người khác vừa công bố
        // lại) chứ không phải một lỗi chung chung, và bảo đúng một việc: tải lại.
        'stale_form' => 'Có người khác (hoặc chính anh/chị ở một tab khác) vừa đổi quyền xem/tải của tài liệu này sau khi hộp thoại này mở ra. Để không lỡ ghi đè thay đổi đó, anh/chị hãy đóng hộp thoại, tải lại trang, rồi công bố lại với đúng lựa chọn hiện tại.',
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
     * Vòng đời văn bản nhóm B (SPEC §4.11, phán quyết R9): `internal_draft` → `pending_approval` →
     * `signed_filed` → `published`. `SubmitDocumentForApproval`, `MarkDocumentSignedFiled` và cổng
     * mới của `RegroupDocument` (rời nhóm B) đều dùng chung nhóm câu này — xem
     * `App\Exceptions\DocumentLifecycleNotAllowed`. Cùng luật SPEC §8.4 với `publish.*`: nói rõ
     * trạng thái hiện tại và việc cần làm tiếp theo, không nhắc tới quyền hay vai trò.
     */
    'lifecycle' => [
        'not_group_b' => 'Chỉ văn bản do văn phòng phát hành (nhóm B) mới đi qua vòng trình duyệt và ký, nộp này. Văn bản của cơ quan nhà nước (nhóm C) không do văn phòng soạn nên không có gì để trình duyệt.',
        'not_internal_draft' => 'Tài liệu này đang ở trạng thái ":status" nên không trình duyệt được nữa — chỉ bản thảo nội bộ mới trình duyệt được. Nếu cần sửa lại nội dung đã trình duyệt, anh/chị tải lên một bản mới.',
        // Vòng sửa 2: nhánh RIÊNG cho `pending_approval` — từ vòng sửa 1, "Trả về bản nháp" là
        // đường quay lại `internal_draft` thật sự cho đúng trạng thái này. Câu `not_internal_draft`
        // phía trên (đẩy đi tải bản mới) chỉ còn đúng cho `signed_filed`/`published`, nơi không có
        // đường quay lại nào — xem `DocumentLifecycleNotAllowed::notInternalDraft()` cho nhánh chọn.
        'not_internal_draft_pending' => 'Tài liệu này đang ở trạng thái ":status" nên không trình duyệt được nữa. Anh/chị bấm "Trả về bản nháp" để đưa nó về lại bản thảo nội bộ, rồi trình duyệt lại.',
        'not_pending_approval' => 'Tài liệu này đang ở trạng thái ":status" nên chưa đánh dấu "Đã ký, đã nộp" được. Anh/chị trình duyệt bản thảo trước, rồi đánh dấu sau khi đã có bản ký và đã nộp.',
        // Vòng sửa 1 (phán quyết R9 mở rộng): giờ có HAI đường rời nhóm B — đã ký/đã nộp/đã công
        // bố, HOẶC một lý do sửa nhầm nhóm ghi rõ. Câu từ chối phải nói ra cả hai, vì người đọc
        // câu này có thể đang ở đúng tình huống thứ hai (nộp nhầm nhóm, chưa từng có gì để ký).
        'not_ready_to_leave_group_b' => 'Văn bản do văn phòng phát hành (nhóm B) đang ở trạng thái ":status" nên chưa chuyển sang nhóm khác được. Anh/chị trình duyệt và đánh dấu "Đã ký, đã nộp" trước khi chuyển, HOẶC nếu đây là một lần nộp nhầm nhóm (tài liệu chưa từng cần ký), hãy nhập lý do (ít nhất 10 ký tự) vào ô "Lý do chuyển nhóm" rồi chuyển lại — lý do đó được ghi vào nhật ký như một lần sửa nhầm nhóm.',
        'misfiling_reason_too_short' => 'Lý do chuyển nhóm cần ít nhất 10 ký tự để người rà soát sau này hiểu vì sao đây là một lần nộp nhầm nhóm, không phải một lần "giặt" bản nháp. Anh/chị viết rõ hơn rồi thử lại.',
        // "Trả về bản nháp" — ruling vòng sửa 1: đưa một văn bản đang chờ duyệt về lại bản thảo.
        'not_pending_approval_to_return' => 'Tài liệu này đang ở trạng thái ":status" nên không trả về bản nháp được — chỉ một văn bản đang "Chờ duyệt" mới trả về được.',
        'trashed' => 'Tài liệu này đã bị xoá nên không thao tác được nữa.',
        'matter_unavailable' => 'Hồ sơ chứa tài liệu này đã bị huỷ hoặc xoá nên không thao tác được với tài liệu.',
        'missing' => 'Không tìm thấy tài liệu này nữa — có thể ai đó vừa xoá nó trong lúc anh/chị đang mở trang. Anh/chị tải lại trang để xem danh sách tài liệu hiện tại.',
    ],

    /*
     * Lời từ chối của CHÍNH endpoint tải tệp (không phải của `FileGuard`) đối với nhân sự — xem
     * `App\Filament\Admin\Concerns\ExplainsStaffUploadRefusal`. Không có số điện thoại văn phòng:
     * nhân sự chờ hoặc nhờ quản trị hệ thống, không gọi văn phòng để được tải tiếp.
     */
    'errors' => [
        'staff_upload_rate_limited' => 'Tài khoản của anh/chị đã chạm mức tối đa :limit tệp tải lên trong một giờ (hoặc lô tệp vừa chọn sẽ làm vượt mức đó), nên hệ thống chưa nhận thêm. Anh/chị chờ khoảng :minutes phút rồi tải tiếp; nếu cần tải gấp một bộ hồ sơ lớn hơn, hãy báo cho quản trị hệ thống.',
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
            'submit_for_approval' => 'Trình duyệt',
            'submit_for_approval_heading' => 'Trình bản thảo này để duyệt',
            'submit_for_approval_description' => 'Tài liệu chuyển sang trạng thái "Chờ duyệt". Bước này chưa đưa gì ra tới khách — khách chỉ thấy tài liệu sau khi văn bản được đánh dấu "Đã ký, đã nộp" rồi công bố.',
            'submit_for_approval_success' => 'Đã chuyển tài liệu sang trạng thái chờ duyệt.',
            'mark_signed_filed' => 'Đánh dấu đã ký, đã nộp',
            'mark_signed_filed_heading' => 'Đánh dấu văn bản này đã ký và đã nộp',
            'mark_signed_filed_description' => 'Chỉ đánh dấu khi văn bản đã thật sự có chữ ký và đã nộp cho cơ quan có thẩm quyền. Sau bước này tài liệu mới công bố được cho khách.',
            'mark_signed_filed_success' => 'Đã đánh dấu văn bản là đã ký, đã nộp.',
            'return_to_draft' => 'Trả về bản nháp',
            'return_to_draft_heading' => 'Trả văn bản này về bản nháp',
            'return_to_draft_description' => 'Tài liệu quay lại trạng thái "Bản thảo nội bộ" và phải trình duyệt lại từ đầu. Dùng khi bản đang chờ duyệt cần sửa lại nội dung.',
            'return_to_draft_success' => 'Đã trả tài liệu về bản nháp.',
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
            // Ô chọn nhóm của thao tác CHUYỂN NHÓM có câu riêng: nó phải nói ra cái giá không
            // tự phục hồi của việc đi vào nhóm D (xem hook `saving` của `App\Models\Document`).
            'target_group_help' => 'Nhóm quyết định khách có thấy tài liệu này hay không. Chuyển VÀO nhóm D thu hồi ngay quyền xem và quyền tải của khách; chuyển RA khỏi nhóm D không trả lại hai quyền đó — muốn tài liệu về lại tay khách thì phải bấm "Công bố cho khách" một lần nữa.',
            'checklist_item' => 'Gắn vào đầu mục danh mục',
            'checklist_item_help' => 'Để trống nếu tài liệu này không thuộc đầu mục nào trong danh mục hồ sơ.',
            'checklist_item_none' => 'Không gắn vào đầu mục nào',
            'issued_at' => 'Ngày ban hành hoặc ngày nộp thực tế',
            'client_can_view' => 'Cho khách xem',
            'client_can_view_help' => 'Khách thấy tài liệu này trong hồ sơ của họ trên trang khách hàng.',
            'client_can_download' => 'Cho khách tải về',
            'client_can_download_help' => 'Tắt ô này nếu muốn khách biết đã có tài liệu nhưng chưa cho giữ bản sao.',
            'target_group' => 'Chuyển sang nhóm',
            // Vòng sửa 1, phán quyết R9 mở rộng: chỉ hiện khi tài liệu đang nhóm B và nhóm ĐÍCH là
            // A/C mà chưa "Đã ký, đã nộp" — xem `RegroupDocument` và `regroupAction()`.
            'regroup_reason' => 'Lý do chuyển nhóm',
            'regroup_reason_help' => 'Bắt buộc khi văn bản chưa "Đã ký, đã nộp": giải thích đây là một lần nộp nhầm nhóm (ví dụ "Nộp nhầm — đây là bản ghi chú nội bộ, không phải văn bản phát hành"), không phải một cách né vòng trình duyệt. Ít nhất 10 ký tự, được ghi vào nhật ký.',
        ],
    ],
];
