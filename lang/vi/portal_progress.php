<?php

/**
 * Chuỗi của trang chi tiết hồ sơ trên cổng khách hàng — SPEC §8.3.
 *
 * **Tệp riêng, không phải `lang/vi/portal.php`.** Bốn agent cùng làm M5 trên một nhánh; phán
 * quyết của người điều phối (19/09/2026) giao `portal.php` cho Task 1, `portal_matters.php` cho
 * Task 3 và tệp này cho Task 4, để ba người không cùng sửa một tệp — đúng chỗ M3 đã va nhau.
 *
 * **Người đọc là khách hàng, không phải nhân viên văn phòng.** SPEC §8 cấm thuật ngữ kỹ thuật và
 * từ viết tắt, nên mấy chỗ dưới đây cố ý KHÔNG dùng lại nhãn nội bộ ở `lang/vi/enums.php`:
 *
 *  - "Dòng thời gian" (chữ của SPEC §8.3 mục 3) là một từ của người làm giao diện, không phải
 *    của người đang lo vụ kiện của mình. Ở đây nó là **"Diễn biến vụ việc"**. Thứ tự và nội dung
 *    khối giữ nguyên như SPEC liệt kê; chỉ cái tên hiển thị đổi, và nó đổi theo đúng câu mở đầu
 *    §8 ("không dùng thuật ngữ kỹ thuật").
 *  - Trạng thái đầu mục ở `enums.checklist_item_status` được viết cho bảng của nhân sự ("Chờ
 *    kiểm tra", "Cần nộp lại"). Bản của khách nói thành câu đủ ý, vì với khách thì "Cần nộp lại"
 *    mà không kèm "vì sao" là một lời trách chứ không phải một hướng dẫn.
 *
 * Xưng hô giữ đúng giọng của `lang/vi/portal.php`: "anh/chị" cho người đọc, "chúng tôi" cho văn
 * phòng.
 */
return [

    'title' => 'Hồ sơ :code',
    'subheading' => 'Mã hồ sơ :code',

    'blocks' => [

        'status' => [
            'heading' => 'Tình trạng hiện tại',
            // Khi loại vụ việc chưa cấu hình giai đoạn này (dữ liệu cũ, hoặc quản trị viên vừa
            // xoá một giai đoạn còn hồ sơ đang đứng ở đó). Không để trống: một ô trống ở khối
            // đầu tiên là chỗ khách đọc ra "hệ thống hỏng".
            'unknown' => 'Văn phòng đang cập nhật tình trạng hồ sơ này.',
        ],

        'todo' => [
            'heading' => 'Việc anh/chị cần làm',
            'documents_lead' => 'Giấy tờ chúng tôi còn chờ ở anh/chị:',
            /*
             * Nhóm thứ hai của khối 2 — xem `MatterProgress::outstandingOptionalItems()`.
             *
             * Câu này tồn tại vì ô nổi bật nhất màn hình và thanh tiến độ ngay dưới nó phải nói
             * về cùng một tập dòng. Một đầu mục nằm NGOÀI mẫu số `Y` của SPEC §4.10 vẫn hiện ra,
             * vì khách vẫn cần biết văn phòng có thể dùng tới nó, nhưng nó phải được gọi đúng
             * tên: không bắt buộc. Nói "còn chờ ở anh/chị" về một tờ giấy chứng tử mà chính văn
             * phòng đã đánh dấu không bắt buộc là giao cho khách một việc không ai cần.
             */
            'documents_optional_lead' => 'Nếu anh/chị có sẵn thì gửi thêm giúp chúng tôi — không bắt buộc:',
            /*
             * Câu này KHÔNG BAO GIỜ được hiển thị, và nó có mặt ở đây đúng vì lý do đó: SPEC
             * §8.3 mục 2 nói khối này "chỉ hiện khi có", nên khi không có việc thì cả khối biến
             * mất chứ không hiện một dòng "không có việc gì". Test
             * `MatterProgressTest` khẳng định chuỗi này vắng mặt trong HTML — tức nó là một
             * NHÂN CHỨNG cho luật ấy, không phải một chuỗi bị quên. Xoá nó đi là bỏ mất phép đo.
             */
            'empty' => 'Hiện không có việc gì cần anh/chị làm.',
        ],

        'timeline' => [
            'heading' => 'Diễn biến vụ việc',
            'empty' => 'Chưa có cập nhật nào. Ngay khi có tin, chúng tôi sẽ ghi vào đây và báo cho anh/chị.',
        ],

        'checklist' => [
            'heading' => 'Hồ sơ giấy tờ',
            'empty' => 'Hồ sơ này chưa cần giấy tờ nào từ anh/chị. Nếu cần, chúng tôi sẽ ghi vào đây.',
        ],

        'documents' => [
            'heading' => 'Tài liệu',
            'empty' => 'Chưa có tài liệu nào được gửi cho anh/chị. Khi có, chúng tôi sẽ đưa lên đây.',
        ],

        'deadlines' => [
            'heading' => 'Mốc thời hạn sắp tới',
            'empty' => 'Hiện chưa có mốc thời hạn nào anh/chị cần nhớ.',
        ],

        /*
         * M9 Task 10 (P1) — khối "Hợp đồng và thanh toán", đứng sau "Mốc thời hạn sắp tới" và
         * trước "Gửi yêu cầu" (đính chính SPEC §8.3). Không có câu "trống": khối chỉ hiện khi vụ có
         * hợp đồng đã ký (`active` hoặc `completed`), và biến mất hẳn khi không có.
         */
        'billing' => [
            'heading' => 'Hợp đồng và thanh toán',
        ],

        'requests' => [
            'heading' => 'Gửi yêu cầu',
            'lead' => 'Anh/chị có điều gì chưa rõ về hồ sơ này?',
            'open' => 'Gửi yêu cầu cho văn phòng',

            /*
             * **Cả hai cùng hiện, và đó là một lần sửa chứ không phải một lựa chọn thẩm mỹ.**
             * Task 4 để khối 7 rẽ nhánh: có màn hình gửi yêu cầu thì vẽ cái nút, chưa có thì vẽ
             * số điện thoại. Task 6 dựng màn hình ấy, nên từ đó nhánh "gọi điện" không còn đường
             * nào chạy tới và số điện thoại văn phòng lặng lẽ biến mất khỏi trang.
             *
             * Một cái nút gửi yêu cầu không thay được một số gọi được: người bấm nút là người
             * chấp nhận chờ, còn người đang lo lắng lúc chín giờ tối thì không. Nên khối 7 nói cả
             * hai, và cái nút đứng trước vì nó là cách để lại dấu vết trong hồ sơ.
             *
             * `call` là NHÃN của một liên kết `tel:` nên nó ngắn và có số ở trong; `call_lead`
             * mang phần giải thích. Tách ra vì một câu dài làm nhãn liên kết là một mục tiêu bấm
             * trải dài ba dòng trên màn hình 375px.
             */
            'call_lead' => 'Hoặc anh/chị gọi thẳng cho văn phòng — số này dùng được cả trên Zalo:',
            'call' => 'Gọi :hotline',

            /*
             * M6 Task 4 (`requests/REQ-4`, đính chính SPEC §9 2026-09-27): huy hiệu "có trả lời
             * mới" — App\Filament\Portal\Pages\MatterProgress::hasNewReply(). Đứng trước cái nút
             * "Gửi yêu cầu cho văn phòng", cùng khối 7, vì đây là chính chỗ khách bấm vào để đọc.
             */
            'new_reply' => 'Văn phòng vừa trả lời một yêu cầu của anh/chị — bấm vào bên dưới để xem.',
        ],

    ],

    'timeline' => [
        'moved_to' => 'Hồ sơ chuyển sang giai đoạn: :stage',
        'next_step' => 'Tiếp theo sẽ là:',
        'client_action' => 'Anh/chị cần làm:',
        'expected' => 'Dự kiến có tin tiếp theo trước ngày :date',
        'occurred_at' => 'Ngày :date',
    ],

    'checklist' => [
        'progress' => 'Đã nộp :submitted / :total giấy tờ',
        'required' => 'Bắt buộc',
        'optional' => 'Không bắt buộc',
        'rejection_lead' => 'Vì sao chúng tôi chưa nhận được:',

        /*
         * Bản của khách, viết thành câu. Đối chiếu với `enums.checklist_item_status` (bản của
         * nhân sự) ở docblock đầu tệp.
         */
        'status' => [
            'missing' => 'Chúng tôi đang chờ anh/chị gửi',
            'pending_review' => 'Đang chờ văn phòng kiểm tra',
            'accepted' => 'Đã nhận đủ',
            'rejected' => 'Cần anh/chị gửi lại',
            'not_applicable' => 'Không cần cho hồ sơ này',
        ],
    ],

    'documents' => [
        'download' => 'Tải về',
        /*
         * Hai cờ độc lập (SPEC §6.5 bước 3): có tài liệu văn phòng cho khách BIẾT là đã có nhưng
         * chưa cho tải. Câu này nói ra điều đó, vì một dòng không có nút bấm mà không có lời giải
         * thích sẽ bị đọc là hỏng.
         */
        'view_only' => 'Tài liệu này anh/chị xem tại văn phòng; chúng tôi chưa mở tải về.',
        'issued_at' => 'Ngày ban hành :date',
    ],

    'deadlines' => [
        'due' => 'Hạn ngày :date',
        'overdue' => 'Đã quá hạn',
        'today' => 'Hạn hôm nay',
        'in_days' => 'Còn :count ngày',
    ],

    /*
     * M9 Task 10 (P1) — chữ của khối "Hợp đồng và thanh toán" VÀ của mục "Bảng kê thanh toán" trong
     * `MUC-LUC.pdf`: cả hai nhận cùng một hình chiếu (`App\Support\Billing\ClientBillingStatement`),
     * nên khách đọc ĐÚNG MỘT câu cho cùng một đợt ở cả hai nơi.
     *
     * **Không dùng lại nhãn nội bộ** ở `lang/vi/enums.php` (`instalment_state`, `payment_method`) —
     * cùng lý do với trạng thái giấy tờ ở đầu tệp: "Đã thu đủ", "Chưa lên lịch", "Cấn trừ" là chữ
     * của sổ sách văn phòng. Ở đây khách là người TRẢ tiền, nên câu nói "đã thanh toán", và đợt đã
     * miễn chỉ nói "Văn phòng đã miễn" — không lý do (lý do là nội bộ, P1).
     */
    'billing' => [
        'contract_code' => 'Số hợp đồng: :code',
        'signed_on' => 'Ngày ký: :date',
        'total' => 'Tổng giá trị hợp đồng: :amount',
        // `vat_rate_percent` khác null — kể cả 0 (hoá đơn thuế suất 0%). `null` thì không có dòng
        // này. Tổng giá trị LUÔN là số khách trả, đã gồm thuế (kế hoạch M9, "Kết luận về VAT").
        'vat' => 'Thuế suất thuế giá trị gia tăng: :rate% (đã gồm trong tổng giá trị)',
        'completed' => 'Hợp đồng đã hoàn tất ngày :date.',
        'instalments_heading' => 'Các đợt thanh toán',
        'amount' => 'Số tiền: :amount',
        'collected' => 'Đã thanh toán: :amount',
        'outstanding' => 'Còn lại: :amount',

        /*
         * "Đến hạn khi nào" của một đợt. Đợt theo tiến độ CHƯA tới bước của nó nói tên bước bằng
         * `client_label` (nhãn cho khách của giai đoạn), không bao giờ nhãn nội bộ hay khoá thô.
         */
        'due' => [
            'on' => 'Đến hạn ngày :date',
            'stage' => 'Đến hạn khi vụ việc tới bước: :stage',
            'stage_after' => 'Đến hạn :days ngày sau khi vụ việc tới bước: :stage',
            // Bước đó không còn tra được nhãn cho khách (loại vụ việc đổi cấu hình): nói chung
            // chung thay vì in khoá nội bộ.
            'stage_unnamed' => 'Đến hạn theo tiến độ vụ việc',
            'on_signing' => 'Đến hạn khi ký hợp đồng',
            // M9 Task 13 (minor m5 rà soát Task 10): không hứa "văn phòng sẽ báo" — M9 không gửi
            // thư tiền nào cho khách (P1).
            'unscheduled' => 'Chưa có ngày đến hạn',
        ],

        /*
         * Trạng thái của một đợt, suy ra ở `Instalment::state()` (một định nghĩa). `cancelled`
         * không có ở đây: đợt đã huỷ không bao giờ tới khách.
         */
        'state' => [
            'scheduled' => 'Chưa đến đợt thanh toán',
            'due' => 'Đến hạn thanh toán',
            'partially_paid' => 'Đã thanh toán một phần',
            'overdue' => 'Quá hạn thanh toán',
            'paid' => 'Đã thanh toán đủ',
            'waived' => 'Văn phòng đã miễn',
        ],

        'payments_heading' => 'Các khoản văn phòng đã nhận',
        'payments_empty' => 'Văn phòng chưa ghi nhận khoản thanh toán nào.',
        'paid_on' => 'Ngày :date',

        'method' => [
            'bank_transfer' => 'Chuyển khoản',
            'cash' => 'Tiền mặt',
            'card' => 'Thẻ ngân hàng',
            'offset' => 'Bù trừ với khoản khác giữa hai bên',
            'other' => 'Hình thức khác',
        ],
    ],

    /**
     * Lối quay lại danh sách hồ sơ, ở cuối trang chi tiết.
     *
     * Không phải "Quay lại" trống không: một nhãn chỉ nói hướng đi thì trên điện thoại không phân
     * biệt được với nút back của trình duyệt, còn khách thì đang tìm "chỗ có tất cả hồ sơ của
     * tôi".
     */
    'back_to_list' => 'Xem tất cả hồ sơ của tôi',

    /*
     * Chuỗi của `resources/views/errors/404.blade.php` — trang trả lời chung cho "không tồn tại"
     * và "không có quyền" (SPEC §10.10 đòi hai tình huống ấy một câu trả lời duy nhất).
     *
     * **Ba câu này cố ý không nói bất cứ điều gì về thứ vừa được hỏi tới.** Không tên hồ sơ,
     * không mã, không "bạn không có quyền" — bất kỳ khác biệt nào giữa hai tình huống cũng là một
     * máy dò sự tồn tại, và test ghim hai response phải giống nhau ĐÚNG TỪNG BYTE.
     *
     * **Chúng nằm ở tệp này vì hôm nay chưa có tệp chuỗi dùng chung cho trang lỗi**, và tệp này
     * là tệp chuỗi mà vòng sửa trang chi tiết sở hữu. Trang 404 phục vụ CẢ panel nội bộ, nên khi
     * có người dựng `lang/vi/errors.php` thì ba khoá dưới đây nên chuyển sang đó — ghi ra ở đây
     * thay vì để người sau tự đoán vì sao chúng ở chỗ này.
     */
    'not_found' => [
        'heading' => 'Không mở được trang này',
        'body' => 'Đường dẫn anh/chị vừa mở không còn dùng được, hoặc không thuộc tài khoản đang đăng nhập. Anh/chị thử mở lại từ trang chính, hoặc gọi cho văn phòng để chúng tôi tra giúp.',
        'home' => 'Về trang chính',
        'call_lead' => 'Gọi cho văn phòng — số này dùng được cả trên Zalo:',
        'call' => 'Gọi :hotline',
    ],

    /**
     * Trang 403 — `resources/views/errors/403.blade.php`.
     *
     * **Vì sao nó nói ra được lý do trong khi trang 404 thì không.** 403 ở cổng này chỉ đến từ
     * middleware `signed`, thứ trả lời TRƯỚC khi một bản ghi nào được đọc, nên câu "liên kết đã
     * hết hạn" nói về ĐƯỜNG DẪN chứ không về một tài liệu. Nó đúng y như nhau cho một tài liệu
     * có thật lẫn cho một id bịa — `DocumentDownloadTest` ghim hai response ấy phải giống nhau
     * đúng từng byte — nên nó không rò rỉ gì dưới SPEC §10.10. Mọi lời từ chối CÓ đọc bản ghi
     * đều là 404 và dùng nhóm khoá `not_found` bên trên.
     *
     * Cùng hoàn cảnh với `not_found`: chúng sẽ chuyển sang `lang/vi/errors.php` khi có tệp đó.
     */
    'link_expired' => [
        'heading' => 'Liên kết tải tệp đã hết hạn',
        'body' => 'Đường dẫn tải tệp chỉ dùng được trong ít phút sau khi trang được mở, để tệp của anh/chị không bị người khác lấy mất nếu đường dẫn lọt ra ngoài.',
        'retry' => 'Anh/chị quay lại trang hồ sơ, tải lại trang rồi bấm vào tệp một lần nữa là tải được.',
        'home' => 'Về trang chính',
        'call_lead' => 'Nếu vẫn không tải được, gọi cho văn phòng — số này dùng được cả trên Zalo:',
        'call' => 'Gọi :hotline',
    ],

];
