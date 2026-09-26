<?php

/*
|--------------------------------------------------------------------------
| Yêu cầu từ khách — cả hai đầu của cuộc trao đổi (SPEC §4.14, §7.2, §8.3 mục 7)
|--------------------------------------------------------------------------
|
| Tệp này phục vụ HAI người đọc rất khác nhau, nên nó chia làm hai nửa và hai nửa KHÔNG dùng
| chung một chuỗi nào:
|
|  - `portal.*` — khách hàng đọc. SPEC §8 cấm thuật ngữ và từ viết tắt, và người đọc thường đang
|    lo về vụ việc của mình. Cách xưng hô là "anh/chị", câu ngắn, mỗi câu nói ra việc tiếp theo.
|  - `tab.*` — nhân sự văn phòng đọc, ở tab "Yêu cầu từ khách" của SPEC §7.2.
|
| **Vì sao bốn trạng thái được viết ra HAI LẦN.** `lang/vi/enums.php` đã có nhãn ngắn cho
| `ClientRequestStatus` ("Mới", "Đang xử lý", "Đã trả lời", "Đã đóng") và panel nội bộ dùng đúng
| chúng — chúng là từ vựng làm việc của văn phòng. Nhưng chữ "Mới" trên màn hình một khách hàng
| không nói được điều họ cần biết, tức là *đã có ai nhìn thấy cái tôi gửi chưa, và bao giờ tôi có
| câu trả lời*. Nên `portal.status.*` là một câu hoàn chỉnh cho từng trạng thái, không phải một
| nhãn. Đây KHÔNG phải một bản dịch thứ hai của cùng một thứ: hai bên bàn cần biết hai điều khác
| nhau về cùng một dòng dữ liệu.
*/

return [

    // =====================================================================================
    // Cổng khách hàng — SPEC §8.3 mục 7
    // =====================================================================================

    'portal' => [
        'title' => 'Trao đổi về hồ sơ :code',
        'heading' => 'Gửi yêu cầu và xem trả lời',
        'subheading' => 'Hồ sơ :code — :title',
        'back_to_matter' => 'Quay lại trang hồ sơ',

        /*
         * REQ-8: khách hàng có nhiều tài khoản portal (SPEC §4.3, ví dụ hai vợ chồng) đọc và viết
         * chung một cuộc trao đổi ({@see \App\Models\ClientRequest::applyClientPortalConstraints()}).
         * Trước bản sửa này, sự thật đó chỉ được ghi trong docblock — người gửi không có cách nào
         * biết trên chính màn hình mình đang gõ.
         */
        'shared_accounts_notice' => 'Các tài khoản khác của cùng khách hàng cũng đọc được các trao đổi này.',

        'new' => [
            'heading' => 'Gửi một yêu cầu mới',
            'lead' => 'Anh/chị có điều gì cần hỏi, hoặc cần văn phòng làm giúp việc gì, xin viết vào đây. Văn phòng sẽ trả lời ngay trên trang này.',
            'subject' => 'Anh/chị cần hỏi về việc gì?',
            'subject_placeholder' => 'Ví dụ: Xin hỏi về ngày hoà giải',
            'content' => 'Anh/chị viết cụ thể giúp văn phòng',
            'content_placeholder' => 'Anh/chị viết càng rõ thì văn phòng càng trả lời nhanh và đúng việc.',
            'submit' => 'Gửi cho văn phòng',
            'sent' => 'Văn phòng đã nhận được yêu cầu của anh/chị.',
        ],

        'history' => [
            /*
             * Đổi từ "Những điều anh/chị đã gửi" (fix round 1, minor, REQ-8). Câu cũ chỉ đúng khi
             * người xem là người DUY NHẤT đã viết vào luồng — sai ngay khi người nhà (cùng
             * `client_id`) đã viết tiếp, vì lúc đó tiêu đề này đứng trên cả những câu người xem
             * KHÔNG hề gửi. Câu mới trung lập, đúng với cả hai trường hợp.
             */
            'heading' => 'Những điều đã trao đổi về hồ sơ này',
            'empty' => 'Anh/chị chưa gửi yêu cầu nào cho hồ sơ này. Khi nào cần hỏi, anh/chị dùng ô bên trên.',
            'from_office' => 'Văn phòng trả lời',
            'from_client' => 'Anh/chị viết',
            'unknown_author' => 'Văn phòng',

            /*
             * Tên dự phòng cho một dòng của KHÁCH mà id không giải quyết được (dữ liệu hỏng: một
             * `author_id` trỏ ra ngoài phạm vi `client_id` của người đang xem — xem docblock
             * {@see \App\Filament\Portal\Pages\MyRequests::clientEntry()}). PHẢI khác `from_client`
             * ("Anh/chị viết"): dòng đó có thể là của người NHÀ KHÁC (`client_sibling`), và một
             * tên dự phòng mượn nhãn của chính người xem là đúng câu REQ-8 cấm.
             */
            'unknown_client_author' => 'Người cùng khách hàng',
        ],

        'reply' => [
            'label' => 'Anh/chị muốn nói thêm gì về việc này?',
            'placeholder' => 'Viết tiếp vào đây, văn phòng sẽ đọc cùng với câu hỏi ở trên.',
            'submit' => 'Gửi thêm',
            'sent' => 'Văn phòng đã nhận được.',
        ],

        /*
         * Mỗi câu nói ra HAI điều: chuyện gì đang xảy ra, và anh/chị có phải làm gì không. Không
         * câu nào nhắc tới tên trạng thái trong cơ sở dữ liệu.
         */
        'status' => [
            'new' => 'Văn phòng đã nhận, đang chờ người phụ trách xem',
            'in_progress' => 'Văn phòng đang xem và chuẩn bị trả lời anh/chị',
            'answered' => 'Văn phòng đã trả lời, anh/chị xem bên dưới',
            'closed' => 'Việc này đã xong. Nếu còn điều cần hỏi, anh/chị gửi một yêu cầu mới.',

            /*
             * REQ-5: `TriageClientRequest::setStatus()` cho phép đặt thẳng `answered` mà không
             * cần viết câu trả lời nào — ca có chủ đích, "luật sư trả lời qua điện thoại rồi đánh
             * dấu thẳng Đã trả lời" ({@see \App\Actions\Portal\TriageClientRequest::setStatus()}).
             * Câu `answered` ở trên mời khách "xem bên dưới", nhưng bên dưới khi đó trống — không
             * có mục nào của văn phòng. `MyRequests::statusLine()` chọn câu này thay vì câu đó
             * khi luồng `answered` không có lời trả lời nào viết ra.
             *
             * **Khoá PHẢI nằm TRONG mảng `status`, không phải cạnh nó (fix round 1, C1).**
             * `statusLine()` gọi `__('requests.portal.status.answered_by_phone')` — bản trước đặt
             * khoá này ở `portal.answered_by_phone`, một tầng NGOÀI `status`, nên lời gọi đó
             * không tìm thấy gì và Laravel trả về NGUYÊN VĂN chuỗi khoá — khách nhìn thấy
             * "requests.portal.status.answered_by_phone" trên màn hình thay vì một câu tiếng
             * Việt. Bài học: một khoá lệch tầng không tự báo lỗi ở đâu cả, kể cả khi test so
             * `__($key)` với `__($key)` — cả hai vế đều là cùng một khoá thô, và một khẳng định so
             * một thứ với chính nó luôn xanh. Test thật viết thẳng câu tiếng Việt kỳ vọng, không
             * gọi lại `__()`.
             */
            'answered_by_phone' => 'Văn phòng đã trả lời anh/chị qua điện thoại hoặc trực tiếp.',
        ],

        /*
         * Câu này nhắc tới "ô trên cùng" — cái ô "Gửi một yêu cầu mới" của CHÍNH trang này. Nó
         * chỉ tồn tại trên cổng khách, nên nửa `tab.*` có câu riêng của nó
         * (`tab.closed_notice`), không mượn câu này.
         */
        'closed_notice' => 'Cuộc trao đổi này đã kết thúc nên không viết thêm được nữa. Nếu còn điều cần hỏi, anh/chị gửi một yêu cầu mới ở ô trên cùng.',
    ],

    // =====================================================================================
    // Panel nội bộ — SPEC §7.2 tab "Yêu cầu từ khách"
    // =====================================================================================

    'tab' => [
        'title' => 'Yêu cầu từ khách',
        'empty_state' => 'Khách chưa gửi yêu cầu nào cho vụ việc này.',

        'columns' => [
            'subject' => 'Nội dung hỏi',
            'client_user' => 'Người gửi',
            'status' => 'Trạng thái',
            'assignee' => 'Người xử lý',
            'created_at' => 'Khách gửi lúc',
            'last_activity' => 'Trao đổi gần nhất',
            'replies_count' => 'Số lượt trả lời',
        ],

        'unassigned' => 'Chưa ai nhận',

        /*
         * REQ-3, phần hiển thị (phần CHẶN nghỉ việc đã ở Task 4). Cột "Người xử lý" vẫn phải
         * hiện đúng tên — cùng lý do `assignee` được nạp `withTrashed()` — nhưng một cái tên trơn
         * không nói được rằng người đó không còn xử lý được nữa.
         */
        'assignee_deactivated' => ':name (đã nghỉ việc)',

        /*
         * Cùng cổng trạng thái với `portal.closed_notice`, hai người đọc khác nhau. Câu của
         * khách mời họ "gửi một yêu cầu mới ở ô trên cùng"; ở panel nội bộ cái ô đó không tồn
         * tại, và văn phòng KHÔNG mở yêu cầu thay khách (xem docblock
         * `ClientRequestsRelationManager`). Việc cần làm ở đầu này là nút "Đổi trạng thái" ngay
         * cạnh, nên câu này chỉ vào đúng nó.
         */
        'closed_notice' => 'Yêu cầu này đã đóng nên không gửi thêm câu trả lời được. Nếu cần trả lời tiếp, hãy dùng nút "Đổi trạng thái" để mở lại yêu cầu.',

        'actions' => [
            'reply' => 'Trả lời',
            'reply_heading' => 'Trả lời khách hàng',
            'reply_submit' => 'Gửi câu trả lời',
            'reply_success' => 'Đã gửi câu trả lời. Khách đọc được ngay trên cổng khách hàng.',

            /*
             * REQ-6: hồ sơ chưa công bố lên cổng (`is_published_to_portal = false`) vẫn nhận câu
             * trả lời — không điều kiện nào trong `ReplyToClientRequest`/`ClientRequestReplyPolicy`
             * xét cột đó — nhưng khách nhận 404 khi mở trang (`MyRequests::resolveMatter()`).
             * Câu báo thành công phải nói đúng sự thật đó thay vì hứa một điều không xảy ra.
             */
            'reply_success_hidden' => 'Đã gửi câu trả lời. Khách chưa xem được trên cổng vì hồ sơ đang ẩn.',

            'assign' => 'Giao việc',
            'assign_heading' => 'Ai xử lý yêu cầu này?',
            'assign_submit' => 'Lưu',
            'assign_success' => 'Đã ghi người xử lý.',

            'change_status' => 'Đổi trạng thái',
            'change_status_heading' => 'Yêu cầu này đang ở đâu?',
            'change_status_submit' => 'Lưu',
            'change_status_success' => 'Đã đổi trạng thái.',

            /*
             * Mang sang từ vòng rà soát Task 3: mở lại một luồng đã đóng mà người đang giữ không
             * còn mở nổi hồ sơ (đã rời đội ngũ, bị vô hiệu hoá, xoá mềm) thì
             * `TriageClientRequest::setStatus()` tự gỡ họ ra thay vì âm thầm mở lại một luồng
             * không ai xử lý được. Người thao tác phải biết việc đó vừa xảy ra và phải giao lại
             * cho người khác.
             */
            'change_status_unassigned' => 'Đã mở lại yêu cầu. :name không còn mở được vụ việc này nên đã được gỡ khỏi vai trò người xử lý — hãy giao lại cho người khác.',
        ],

        'fields' => [
            'content' => 'Câu trả lời gửi cho khách',
            'content_help' => 'Khách hàng đọc nguyên văn câu này trên điện thoại. Viết đủ câu, không viết tắt.',
            'assignee' => 'Người xử lý',
            'assignee_help' => 'Chỉ chọn được người đang có tên trong đội ngũ vụ việc.',
            'status' => 'Trạng thái',
        ],

        'thread' => [
            'heading' => 'Cuộc trao đổi cho tới lúc này',
            'client_said' => 'Khách hàng :name viết lúc :at',
            'office_said' => ':name (văn phòng) trả lời lúc :at',
            'office_unknown' => 'Văn phòng',
        ],
    ],

    // =====================================================================================
    // Từ chối
    // =====================================================================================

    /*
     * MỘT câu cho mọi lý do — SPEC §10.10. Không tồn tại, thuộc hồ sơ của khách hàng khác, hồ sơ
     * đã bị gỡ khỏi cổng, tài khoản đã bị vô hiệu hoá: cả bốn dùng chung câu này, nên không ai
     * đọc lời từ chối mà suy ra được yêu cầu kia có thật hay không.
     */
    'unavailable' => 'Không mở được yêu cầu này. Anh/chị thử mở lại từ trang hồ sơ, hoặc gọi văn phòng.',

    'validation' => [
        'subject_required' => 'Anh/chị viết giúp một dòng ngắn nói về việc cần hỏi.',
        'subject_max' => 'Dòng này dài quá (tối đa :max chữ). Anh/chị viết ngắn lại, phần chi tiết để ở ô bên dưới.',
        'content_required' => 'Anh/chị viết giúp nội dung cần hỏi.',
        'content_max' => 'Nội dung dài quá (tối đa :max chữ). Anh/chị gửi làm hai lần giúp văn phòng.',

        /*
         * Câu này chỉ nhân sự đọc. Nó cố ý KHÔNG nêu tên người được chọn: với một vụ việc
         * `restricted` (SPEC §4.6), nhắc lại tên người vừa chọn cạnh tên hồ sơ là một chỗ rò rỉ
         * nhỏ, và người đọc đang nhìn thẳng vào ô chọn nên không cần được nhắc.
         */
        'assignee_cannot_open' => 'Người này không mở được vụ việc, nên giao xong thì họ cũng không xử lý được. Hãy thêm họ vào đội ngũ vụ việc trước, hoặc chọn một người khác.',

        /*
         * Cũng chỉ nhân sự đọc. "Mới" không phải một bước trong quy trình mà là một lời khẳng
         * định về thế giới — chưa ai trong văn phòng nhìn thấy — nên câu này nói ra đúng điều đó
         * và chỉ sang lựa chọn đúng, thay vì chỉ báo "không được".
         */
        'cannot_return_to_new' => 'Không đặt lại thành "Mới" được: "Mới" nghĩa là chưa ai trong văn phòng nhìn thấy, mà yêu cầu này thì đã có người xem rồi. Nếu chưa xử lý xong, chọn "Đang xử lý".',
    ],

    // =====================================================================================
    // Widget SPEC §7.1 mục 5 — "Khách chưa xem cập nhật"
    // =====================================================================================

    'unseen_widget' => [
        'heading' => 'Khách chưa xem cập nhật',
        'description' => 'Những dòng tiến độ đã công bố quá 5 ngày mà chưa có khách nào mở ra xem. Nghĩa là khách không nhận được thư báo, hoặc không biết dùng cổng khách hàng — nên gọi điện.',
        'empty_state' => 'Mọi cập nhật đã công bố đều đã có khách xem. Không phải gọi ai.',
        'columns' => [
            'code' => 'Mã hồ sơ',
            'client' => 'Khách hàng',
            'published_at' => 'Công bố lúc',
            'content' => 'Nội dung đã công bố',
        ],
        'open' => 'Mở hồ sơ',
    ],
];
