<?php

/**
 * Chuỗi giao diện và thông báo lỗi của tiếp nhận khách tiềm năng (M10).
 */
return [
    /*
     * Câu thông báo người nhập đọc cho người gọi (R7a, Nghị định 356/2025/NĐ-CP: đồng ý phải lưu
     * lại và kiểm chứng được, cấm đánh dấu sẵn). `version` được lưu cùng thời điểm ghi nhận
     * (`intake_requests.privacy_notice_version`): đổi chữ ở `text` thì PHẢI đổi `version`, để biết
     * mỗi người liên hệ đã nghe bản nào.
     *
     * ĐÂY LÀ BẢN NHÁP: nội dung câu thông báo và căn cứ pháp lý lưu phần danh tính khi người gọi chưa
     * đồng ý là mục CHỜ LUẬT SƯ XÁC NHẬN (kế hoạch M10, "Còn cần chủ văn phòng hoặc luật sư xác
     * nhận", mục 1). Hậu tố `-nhap` trong `version` nhắc điều đó; bỏ hậu tố khi luật sư đã duyệt.
     */
    'privacy_notice' => [
        'version' => '2026-09-nhap',
        'text' => 'Văn phòng sẽ ghi lại họ tên, số điện thoại và nội dung anh/chị trình bày để tư vấn và để kiểm tra xung đột lợi ích trước khi nhận vụ việc. Thông tin này được bảo mật theo quy định của Luật Luật sư, được lưu tối đa 24 tháng nếu anh/chị không trở thành khách hàng, sau đó được ẩn danh. Anh/chị có quyền yêu cầu xoá thông tin bất cứ lúc nào.',
    ],

    'attributes' => [
        'contact_name' => 'tên người liên hệ',
        'contact_phone' => 'số điện thoại',
        'contact_email' => 'email',
        'contact_id_number' => 'số căn cước',
        'contact_role' => 'vai dự kiến',
        'source' => 'nguồn liên hệ',
        'referred_by' => 'người giới thiệu',
        'matter_type_id' => 'lĩnh vực',
        'quoted_amount' => 'phí đã báo',
        'assigned_to' => 'người phụ trách',
        'received_at' => 'thời điểm nhận',
        'parties' => 'bên đối lập',
        'parties.*.name' => 'tên bên đối lập',
        'parties.*.role' => 'vai của bên đối lập',
        'parties.*.phone' => 'số điện thoại của bên đối lập',
        'parties.*.id_number' => 'số căn cước của bên đối lập',
        'summary' => 'nội dung câu chuyện',
        'reason' => 'lý do',
    ],

    'errors' => [
        'contact_role_opposing_counsel' => 'Người liên hệ không thể mang vai luật sư đối phương.',
        'assignee_cannot_see' => 'Người được giao phải là nhân sự đang hoạt động và có quyền ghi nhận tiếp nhận.',
        'quoted_amount_invalid' => 'Phí đã báo không hợp lệ.',
        'privacy_notice_not_agreed' => 'Chỉ ghi nhận thông báo khi người liên hệ đã nghe và đồng ý.',
        'acknowledgement_not_needed' => 'Lần kiểm tra hiện tại không có gì cần xác nhận.',
        'override_not_red' => 'Bản ghi này không có xung đột mức đỏ nào đang chờ xử lý.',
        'override_reason_required' => 'Phải nhập lý do khi ghi đè xung đột mức đỏ.',
        'override_reason_too_long' => 'Lý do quá dài.',
        'summary_too_long' => 'Nội dung câu chuyện quá dài.',
        'summary_locked' => 'Chưa thể ghi nội dung câu chuyện: :blockers',
        'record_closed' => 'Bản ghi này đã được ẩn danh hoặc gộp vào bản khác nên không ghi thêm được nội dung.',
        // M10 Task 3: các Action của màn hình tiếp nhận.
        'record_final' => 'Bản ghi này đã chuyển thành vụ việc, đã gộp hoặc đã ẩn danh nên không sửa được nữa.',
        'party_not_on_record' => 'Một dòng bên đối lập không thuộc bản ghi này. Hãy tải lại trang rồi thử lại.',
        'status_not_allowed' => 'Không chuyển được bản ghi từ trạng thái ":from" sang ":to" bằng thao tác này.',
        'decline_reason_required' => 'Phải nhập lý do từ chối.',
        'decline_reason_too_long' => 'Lý do từ chối quá dài.',
        'decline_not_allowed' => 'Không từ chối được một bản ghi ở trạng thái ":status".',
        'merge_into_itself' => 'Không gộp được một bản ghi vào chính nó.',
        'merge_target_closed' => 'Không gộp được vào bản ghi đã chuyển thành vụ việc, đã gộp hoặc đã ẩn danh.',
        'merge_too_many_parties' => 'Gộp sẽ làm bản ghi được chọn có hơn :max bên đối lập. Gỡ các bên trùng hoặc không cần ở một trong hai bản ghi rồi gộp lại.',
        'phone_invalid' => 'Số điện thoại không hợp lệ.',
        'phone_too_long' => 'Số điện thoại quá dài: tính cả mã nước 84 thì vượt 20 chữ số.',
        // M10 Task 3, fix vòng 1: khoá người gọi lại không được rửa bằng gộp hay sửa danh tính. Câu
        // về bản đã từ chối giống nhau cho mọi lý do từ chối (R8).
        'identity_declined' => 'Bản ghi này đã bị từ chối nên phần danh tính không sửa được nữa.',
        'caller_keys_locked' => 'Bản ghi này còn xung đột mức đỏ chờ trưởng phòng hoặc quản trị xử lý: chỉ họ đổi hoặc xoá được số điện thoại, số căn cước và vai đã ghi của người liên hệ, vì văn phòng nhận ra người này gọi lại theo đúng ba thông tin đó.',
        'merge_drops_caller' => 'Bản ghi này chỉ gộp được vào một bản ghi cùng vai, cùng số điện thoại và số căn cước của người liên hệ, để văn phòng vẫn nhận ra khi người này gọi lại. Muốn gộp vào bản ghi khác, nhờ trưởng phòng hoặc quản trị.',
        // M10 Task 4 (`ConvertIntakeToMatter`). Câu về trạng thái dùng nhãn trạng thái — bản từ chối vì
        // xung đột nói đúng như bản từ chối thường (R8).
        'convert_already' => 'Bản ghi này đã được chuyển thành vụ việc.',
        'convert_status' => 'Không chuyển thành vụ việc được một bản ghi ở trạng thái ":status".',
        'convert_red_pending' => 'Bản ghi còn xung đột mức đỏ chờ trưởng phòng hoặc quản trị xử lý. Xử lý xong mới chuyển thành vụ việc được.',
        'description_too_long' => 'Ghi chú nội bộ quá dài.',
        'id_number_invalid' => 'Số căn cước không hợp lệ: phải có chữ số.',
        'convert_id_number_mismatch' => 'Số căn cước này không khớp số đã ghi lúc tiếp nhận. Kiểm tra lại với người liên hệ; nếu số lúc tiếp nhận sai thì sửa ở trang bản ghi trước.',
    ],

    /*
     * Màn hình tiếp nhận trên panel admin (M10 Task 3). Câu nào hiện cho người không có
     * `intake.viewAny` thì không bao giờ nói lý do xung đột (R8) hay tên của bản ghi họ không xem
     * được (R4).
     */
    'resource' => [
        'label' => 'lần liên hệ',
        'plural_label' => 'Tiếp nhận',
    ],

    'fields' => [
        'code' => 'Mã',
        'contact_name' => 'Tên người liên hệ',
        'contact_phone' => 'Số điện thoại',
        'contact_phone_help' => 'Bắt buộc, trừ khi người liên hệ chỉ để lại email. Số được dùng để kiểm tra xung đột lợi ích.',
        'contact_email' => 'Email',
        'contact_id_number' => 'Số căn cước',
        'contact_id_number_help' => 'Không bắt buộc. Hệ thống chỉ lưu dạng mã hoá để kiểm tra xung đột, không lưu số gốc.',
        'contact_id_number_help_edit' => 'Để trống để giữ dạng mã hoá đã lưu (nếu có). Nhập số mới để thay.',
        'contact_role' => 'Vai dự kiến của người liên hệ',
        'contact_role_help' => 'Người liên hệ định là nguyên đơn, bị đơn hay người liên quan. Vai quyết định bên nào là bên đối lập.',
        'caller_keys_locked_help' => 'Bản ghi còn xung đột mức đỏ chờ xử lý: chỉ trưởng phòng hoặc quản trị đổi được ô này.',
        'source' => 'Nguồn liên hệ',
        'referred_by' => 'Người giới thiệu',
        'matter_type_id' => 'Lĩnh vực dự kiến',
        'quoted_amount' => 'Phí đã báo (đồng)',
        'assigned_to' => 'Người phụ trách',
        'received_at' => 'Thời điểm nhận',
        'received_at_help' => 'Để trống nếu là bây giờ.',
        'status' => 'Trạng thái',
        'conflict_level' => 'Kết quả kiểm tra',
        'conflict_checked_at' => 'Lần kiểm tra gần nhất',
        'parties' => 'Bên đối lập',
        'parties_help' => 'Hỏi tên bên kia, và nếu người liên hệ biết thì số điện thoại hoặc số căn cước. Không có số nào thì kết quả chỉ là "thiếu định danh" và ô câu chuyện phải qua bước xác nhận.',
        'add_party' => 'Thêm bên đối lập',
        'party_role' => 'Vai của bên đó',
        'party_name' => 'Tên',
        'party_phone' => 'Số điện thoại',
        'party_id_number' => 'Số căn cước',
        'party_identity_help_edit' => 'Để trống để giữ số đã lưu (nếu có). Muốn bỏ số đã lưu thì xoá dòng rồi thêm lại.',
        'privacy_notice' => 'Người liên hệ đã nghe thông báo và đồng ý',
        'privacy_notice_help' => 'Đọc câu thông báo cho người liên hệ. Chỉ tích khi họ đã nghe và đồng ý — không tích thay họ.',
        'summary' => 'Câu chuyện',
        'summary_help_open' => 'Ghi lại người liên hệ kể gì, rồi bấm "Lưu câu chuyện".',
        'decline_reason' => 'Lý do từ chối',
        'decline_for_conflict' => 'Từ chối vì xung đột lợi ích',
        'decline_for_conflict_help' => 'Chỉ trưởng phòng và quản trị thấy lý do của một lần từ chối vì xung đột. Người khác chỉ thấy "Văn phòng từ chối".',
        'override_reason' => 'Lý do ghi đè mức đỏ',
        'merge_target' => 'Gộp vào bản ghi',
        'merge_target_help' => 'Bản ghi này sẽ thành "Đã gộp"; các bên đối lập của nó chuyển sang bản ghi được chọn, và bản ghi đó được kiểm tra lại.',
        'new_status' => 'Trạng thái mới',
    ],

    'sections' => [
        'identity' => 'Phần danh tính',
        'identity_description' => 'Nhập trong lúc nghe máy: tên, số điện thoại, vai dự kiến. Kiểm tra xung đột lợi ích chạy ngay khi lưu phần này — trước khi nghe câu chuyện.',
        'parties' => 'Bên đối lập',
        'privacy' => 'Thông báo xử lý dữ liệu cá nhân',
        'check' => 'Kiểm tra xung đột lợi ích',
        'story' => 'Câu chuyện',
        'duplicates' => 'Có thể trùng với lần liên hệ trước',
        'decision' => 'Kết quả xử lý',
    ],

    'check' => [
        'checked_at' => 'Lần kiểm tra gần nhất: :at',
        'never_checked' => 'Chưa có lần kiểm tra nào.',
        'stale_note' => 'Kết quả kiểm tra có hạn dùng: dữ liệu của văn phòng thay đổi hằng ngày. Bấm "Kiểm tra lại" trước khi quyết định; hệ thống cũng chạy lại khi chuyển thành vụ việc.',
        'heading_red' => 'Mức đỏ — ô câu chuyện bị khoá',
        'heading_red_overridden' => 'Mức đỏ — trưởng phòng hoặc quản trị đã ghi đè kèm lý do',
        'heading_attention' => 'Cần xem xét trước khi nghe câu chuyện',
        'heading_clear' => 'Không tìm thấy xung đột lợi ích',
        'intro' => 'Các hồ sơ và lần liên hệ dưới đây có bên trùng với người liên hệ hoặc bên đối lập vừa khai:',
        'carried_note' => 'Có dòng mang "bên phía mình" là bên đối lập mà CÙNG người liên hệ đã khai ở một lần gọi trước — hệ thống tự mang sang, người nhập không gõ lại.',
    ],

    'gate' => [
        'open' => 'Ô câu chuyện đang mở.',
        'locked' => 'Ô câu chuyện đang khoá vì:',
        'locked_create' => 'Ô câu chuyện mở sau khi lưu phần danh tính: hệ thống kiểm tra xung đột lợi ích trước, rồi mới cho ghi câu chuyện.',
        'hint_privacy_notice' => 'Đọc câu thông báo cho người liên hệ rồi bấm "Ghi nhận thông báo".',
        'hint_conflict_unchecked' => 'Bấm "Kiểm tra lại" để chạy kiểm tra cho danh tính hiện tại.',
        'hint_conflict_acknowledgement' => 'Xem bảng kết quả rồi bấm "Xác nhận đã xem các khớp".',
        'hint_conflict_red' => 'Dừng, không nghe tiếp câu chuyện. Báo trưởng phòng hoặc quản trị: chỉ họ xử lý được (từ chối, hoặc ghi đè kèm lý do).',
        'hint_declined' => 'Văn phòng đã từ chối bản ghi này; câu chuyện không ghi thêm được.',
        'red_was_shown' => 'Bản ghi này từng ra mức đỏ ở một lần kiểm tra trước, hoặc là lần gọi lại của một người có lần gọi trước đang chờ xử lý. Chỉ ghi đè khi anh/chị đã xem và quyết định vẫn nghe câu chuyện.',
    ],

    'duplicates' => [
        'same_identity' => 'Cùng số điện thoại hoặc số căn cước với:',
        'hidden_same_identity' => 'Số này đã từng liên hệ văn phòng ở một bản ghi anh/chị không xem được. Hỏi trưởng phòng nếu cần gộp.',
        'same_name' => 'Trùng tên (chỉ trùng tên, chưa chắc là cùng người):',
        'existing_client' => 'Số này đã là khách của văn phòng.',
        'lookup_unavailable' => 'Chưa tra được số này có phải khách của văn phòng không (đã chạm giới hạn tra cứu trong giờ). Hỏi trưởng phòng nếu cần.',
        'merge_hint' => 'Nếu là cùng một lần liên hệ, dùng "Gộp vào bản ghi khác".',
    ],

    'privacy' => [
        'recorded' => 'Đã ghi nhận người liên hệ nghe thông báo (bản :version) lúc :at.',
        'not_recorded' => 'Chưa ghi nhận người liên hệ đã nghe thông báo.',
    ],

    'decision' => [
        'declined_for_conflict' => 'Từ chối vì xung đột lợi ích',
        'outward_answer' => 'Câu trả lời cho người liên hệ: "Văn phòng xin phép không nhận vụ việc này." Không giải thích thêm.',
        'merged_into' => 'Đã gộp vào :code.',
        'converted_into' => 'Đã chuyển thành vụ việc :code.',
    ],

    'actions' => [
        'save_summary' => 'Lưu câu chuyện',
        'summary_saved' => 'Đã lưu câu chuyện.',
        'record_privacy_notice' => 'Ghi nhận thông báo',
        'privacy_notice_recorded' => 'Đã ghi nhận thông báo.',
        'acknowledge' => 'Xác nhận đã xem các khớp',
        'acknowledge_description' => 'Xác nhận anh/chị đã xem đúng những khớp đang hiện trong bảng. Hệ thống chạy lại kiểm tra ngay lúc bấm; nếu kết quả đã đổi, anh/chị phải xem lại.',
        'acknowledged' => 'Đã ghi nhận xác nhận.',
        'resolve_red' => 'Xử lý mức đỏ',
        'resolve_red_description' => 'Ghi đè mức đỏ kèm lý do. Lý do được ghi vào nhật ký và không xoá được. Muốn không nhận việc thì dùng "Từ chối" thay vì ghi đè.',
        'red_resolved' => 'Đã ghi đè mức đỏ.',
        'rerun' => 'Kiểm tra lại',
        'rerun_done' => 'Đã chạy lại kiểm tra xung đột lợi ích.',
        'change_status' => 'Đổi trạng thái',
        'status_changed' => 'Đã đổi trạng thái.',
        'decline' => 'Từ chối',
        'declined' => 'Đã từ chối bản ghi.',
        'merge' => 'Gộp vào bản ghi khác',
        'merged' => 'Đã gộp bản ghi.',
        'convert' => 'Chuyển thành vụ việc',
    ],

    /*
     * Trang "Chuyển thành vụ việc" (M10 Task 4, R3). Mọi thứ điền sẵn từ bản ghi; chỉ số căn cước thô
     * là phải hỏi lại (lúc tiếp nhận chỉ lưu dấu băm, R7).
     */
    'convert' => [
        'title' => 'Chuyển :code thành vụ việc',
        'breadcrumb' => 'Chuyển thành vụ việc',
        'submit' => 'Chuyển thành vụ việc',
        'cancel' => 'Quay lại bản ghi',
        'default_title' => ':name — :type',
        'sections' => [
            'carried' => 'Chuyển sang từ bản ghi',
            'carried_description' => 'Không cần gõ lại: người liên hệ thành khách hàng của vụ, các bên đối lập sang danh sách các bên kèm định danh đã ghi, câu chuyện sang ghi chú nội bộ (sửa được ở dưới), phí đã báo hiện sẵn khi soạn hợp đồng.',
            'client' => 'Khách hàng',
            'client_description' => 'Hệ thống tra khách theo số căn cước (nếu nhập) rồi theo số điện thoại đã ghi. Trùng đúng số thì gắn vào khách đó; không trùng thì tạo hồ sơ khách mới. Không bao giờ gắn theo tên.',
            'matter' => 'Vụ việc',
        ],
        'contact_line' => 'Người liên hệ: :name',
        'contact_phone_line' => 'Số điện thoại: :phone',
        'contact_email_line' => 'Email: :email',
        'contact_id_line' => 'Số căn cước: đã ghi lúc tiếp nhận (chỉ lưu dạng mã hoá).',
        'contact_no_id_line' => 'Số căn cước: không ghi lúc tiếp nhận.',
        'party_line' => 'Bên đối lập — :role: :name',
        'no_parties' => 'Không có bên đối lập nào được khai lúc tiếp nhận.',
        'client_type' => 'Loại khách hàng',
        'id_number' => 'Số căn cước của khách hàng',
        'id_number_help' => 'Không bắt buộc. Nhập nếu văn phòng cần số này trên hồ sơ khách; hệ thống cũng dùng nó để tra khách đã có.',
        'id_number_recorded_help' => 'Lúc tiếp nhận đã ghi số căn cước của người liên hệ (chỉ lưu dạng mã hoá, không lưu số gốc). Nhập lại đúng số đó nếu văn phòng cần nó trên hồ sơ khách; bỏ trống thì hồ sơ khách không có số căn cước.',
        'done' => 'Đã chuyển :intake thành vụ việc :matter.',
        'done_existing_client' => 'Người liên hệ được gắn vào khách hàng đã có của văn phòng.',
        'done_new_client' => 'Đã tạo hồ sơ khách hàng mới cho người liên hệ.',
    ],
];
