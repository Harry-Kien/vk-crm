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
        // BA tình huống, MỘT câu: mục không còn tồn tại, mục đã bị xoá khỏi danh mục, và mục
        // thuộc một hồ sơ người đang hỏi không được thấy. SPEC §10.10 không chừa ngoại lệ cho
        // người trong văn phòng — kế toán không được cấp quyền nào về hồ sơ, và một cặp câu trả
        // lời khác nhau là cách họ dò xem một id có thật hay không. Cùng luật mà
        // `AnswerDeniedPanelRequestsWithNotFound` đã áp cho cả panel ở M3.
        //
        // Câu này nêu CẢ HAI khả năng mà không nói là khả năng nào: nói ra danh sách không tiết
        // lộ gì, nói ra kết luận thì có.
        'item_unavailable' => 'Không mở được mục giấy tờ này để duyệt: có thể ai đó vừa xoá nó khỏi danh mục, hoặc nó thuộc một hồ sơ anh/chị không phụ trách. Anh/chị tải lại trang để xem danh mục hiện tại; nếu vẫn cần duyệt mục này thì nhờ người phụ trách hồ sơ hoặc quản trị viên.',
        // Từ chối là một câu nói với khách về thứ họ đã gửi lên. Không có gì trên bàn thì không
        // có gì để nói — xem `ReviewChecklistItem::guardDecisionAgainstState()`.
        'nothing_to_reject' => 'Mục này đang ở trạng thái ":status", tức chưa có tệp nào của khách đang chờ xem. Từ chối lúc này sẽ gửi cho khách một lời chê về thứ họ chưa gửi. Anh/chị chờ khách nộp rồi duyệt, hoặc gọi nhắc khách nộp bổ sung.',
        'matter_unavailable' => 'Hồ sơ chứa mục giấy tờ này đã bị xoá nên không duyệt được. Anh/chị khôi phục hồ sơ trước, rồi duyệt lại.',
    ],

    /*
     * `MarkChecklistItemNotApplicable` (SPEC §4.10) VÀ `UploadStaffDocument` ở nhóm A — hai thao
     * tác, một câu, vì chúng dùng chung điều kiện ở `App\Actions\Document\Concerns     * RefusesWhileAwaitingReview`. Trợ lý hoặc luật sư đọc.
     *
     * Câu này từng chỉ nói về thao tác "không cần nộp"; nó đã được viết lại cho TRUNG TÍNH khi
     * `UploadStaffDocument` bắt đầu dùng chung điều kiện đó (việc mang sang từ vòng sửa Task 4,
     * ghi trong docblock của trait ấy). Một câu dùng cho hai thao tác mà chỉ gọi tên một thao tác
     * là một câu nói sai với một nửa số người đọc nó.
     */
    'not_applicable' => [
        'awaiting_review' => 'Mục này đang có một tệp khách vừa gửi lên và chưa ai xem. Đóng mục lại lúc này — dù bằng cách đánh dấu "không cần nộp", hay bằng cách nộp thay một tệp khác vào đúng mục đó — là bỏ qua tệp của khách mà không nói gì với họ; riêng lần nộp thay còn ghi vào hồ sơ rằng đã có người duyệt. Anh/chị duyệt hoặc từ chối tệp đang chờ trước, rồi quay lại làm tiếp.',
    ],

    /*
     * Tab "Danh mục hồ sơ" trên trang chi tiết vụ việc (SPEC §7.2). Người đọc là trợ lý hoặc luật
     * sư; các câu ở đây nói về thao tác, còn những câu KHÁCH đọc (lý do từ chối) nằm ở
     * `rejection_templates` bên dưới và ở cột `rejection_reason` của chính bản ghi.
     */
    'tab' => [
        // Thanh tiến độ của SPEC §7.2. `:submitted`/`:total` là X/Y theo SPEC §4.10 — xem
        // `ChecklistRelationManager::progressFor()` cho định nghĩa của tập Y và vì sao X đếm
        // bên trong nó.
        'progress' => 'Đã nộp :submitted/:total giấy tờ cần cho hồ sơ này',
        // Câu đi kèm khi mẫu số bằng 0: một hồ sơ chưa có đầu mục bắt buộc nào và chưa ai nộp gì
        // thì "0/0" không nói được điều gì, còn một thanh rỗng 0% thì trông như một hồ sơ đang
        // tắc. Hai tình huống khác hẳn nhau nên chúng có hai câu khác nhau.
        'progress_empty' => 'Hồ sơ này chưa có giấy tờ nào cần theo dõi: danh mục chưa có mục bắt buộc, và chưa có tài liệu nào của khách gắn vào mục không bắt buộc.',
        'columns' => [
            'name' => 'Đầu mục giấy tờ',
            'is_required' => 'Bắt buộc',
            'status' => 'Trạng thái',
            'rejection_reason' => 'Lý do đã nói với khách',
            'reviewer' => 'Người duyệt',
            'reviewed_at' => 'Duyệt lúc',
            'documents_count' => 'Số tệp đã nộp',
        ],
        'actions' => [
            // "Thêm đầu mục" — M6.5 Task 15 (finding intake-02/checklist-02/roles-06/spec-gap-04):
            // trước đây không có nút nào thêm được một giấy tờ riêng cho một vụ việc đã mở.
            'add_item' => 'Thêm đầu mục',
            'add_item_heading' => 'Thêm đầu mục giấy tờ cho vụ việc này',
            'add_item_submit' => 'Thêm',
            'add_item_success' => 'Đã thêm đầu mục vào danh mục hồ sơ.',
            'accept' => 'Đã nhận',
            'accept_heading' => 'Xác nhận đã nhận đủ giấy tờ của đầu mục này',
            'accept_description' => 'Khách sẽ thấy mục này chuyển sang "Đã nhận" và không còn bị nhắc nộp nữa.',
            'accept_success' => 'Đã ghi nhận đầu mục này là đã nhận đủ.',
            'reject' => 'Cần nộp lại',
            'reject_heading' => 'Từ chối giấy tờ và báo cho khách biết phải làm gì',
            'reject_success' => 'Đã gửi yêu cầu nộp lại kèm lý do cho khách.',
            'not_applicable' => 'Không cần nộp',
            'not_applicable_heading' => 'Đánh dấu đầu mục này là không cần nộp',
            'not_applicable_description' => 'Mục này sẽ không còn nằm trong danh sách giấy tờ khách phải nộp, và thanh tiến độ tính lại theo đó.',
            'not_applicable_success' => 'Đã đánh dấu đầu mục này là không cần nộp.',
        ],
        'fields' => [
            'rejection_reason' => 'Lý do, viết cho khách đọc',
            // Nhắc thẳng rằng câu này ra khỏi văn phòng. SPEC §6.7 tồn tại vì trợ lý hay viết
            // "không hợp lệ", và một dòng nhắc ngay dưới ô nhập rẻ hơn một vòng nộp lại.
            'rejection_reason_help' => 'Câu này hiện nguyên văn trên màn hình của khách và được gửi kèm email, nên hãy viết như đang nói chuyện với họ: chỗ nào chưa đạt, và cần làm gì để nộp lại cho đúng. Bấm một mẫu bên dưới rồi sửa lại cho đúng trường hợp.',
            'templates' => 'Mẫu có sẵn — bấm một cái là điền',
            // Ba ô của modal "Thêm đầu mục" — M6.5 Task 15.
            'item_name' => 'Tên đầu mục',
            'item_description' => 'Mô tả cho khách',
            'item_is_required' => 'Bắt buộc',
        ],
        // Nhãn ngắn của ba cái nút điền mẫu. Nội dung ĐẦY ĐỦ của mỗi mẫu nằm ở
        // `rejection_templates` bên dưới, nguyên văn SPEC §6.7; ba nhãn này chỉ để người duyệt
        // nhận ra mẫu nào là mẫu nào mà không phải đọc hết cả đoạn.
        'template_labels' => [
            'blurred' => 'Ảnh mờ, chụp lại',
            'uncertified_copy' => 'Bản photo chưa chứng thực',
            'wrong_document' => 'Nộp nhầm tài liệu',
        ],
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
