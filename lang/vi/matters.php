<?php

return [
    'label' => 'Vụ việc',
    'plural_label' => 'Vụ việc',
    'fields' => [
        'code' => 'Mã hồ sơ',
        'client' => 'Khách hàng',
        'matter_type' => 'Loại vụ việc',
        'title' => 'Tiêu đề',
        'summary_for_client' => 'Tóm tắt cho khách',
        'stage' => 'Giai đoạn',
        'lead_lawyer' => 'Luật sư phụ trách',
        'last_client_update_at' => 'Cập nhật gần nhất cho khách',
        'is_published_to_portal' => 'Công bố portal',
        // Fix round 1, finding S2: Filament không tô màu lên một ô trống — một vụ quá hạn nhưng
        // CHƯA TỪNG cập nhật cho khách (last_client_update_at null) phải hiện chữ này, không phải
        // để trống, để màu đỏ có một dòng chữ đi kèm (tài liệu bộ công cụ §4: màu không bao giờ
        // là kênh thông tin duy nhất).
        'never_updated' => 'Chưa cập nhật lần nào',
    ],
    'filters' => [
        'stage' => 'Giai đoạn',
        'matter_type' => 'Loại vụ việc',
        'lead_lawyer' => 'Luật sư phụ trách',
        'is_published_to_portal' => 'Đã công bố portal',
    ],
    // Nhãn của MỘT dòng `matter_parties`, dùng cho nút "Tạo mới…" và tiêu đề modal của tab "Các
    // bên". Bỏ trống thì Filament tự sinh từ tên lớp: "Tạo mới matter party", "Tạo Matter Party".
    'party_label' => 'bên trong vụ việc',
    'party_plural_label' => 'các bên trong vụ việc',
    'tabs' => [
        'overview' => 'Tổng quan',
        'progress' => 'Tiến độ',
        // Hai tab của M4 (SPEC §7.2). Tên chép nguyên văn SPEC: "Danh mục hồ sơ" là danh sách
        // giấy tờ CẦN có, "Tài liệu" là những tệp đã thật sự nằm trong hồ sơ — hai thứ khác nhau
        // và người dùng phân biệt chúng bằng đúng hai cái tên này.
        'checklist' => 'Danh mục hồ sơ',
        'documents' => 'Tài liệu',
        'parties' => 'Các bên',
    ],
    'overview_sections' => [
        'details' => 'Thông tin vụ việc',
        'team' => 'Đội ngũ',
    ],
    // Ba nhãn của mục "Đội ngũ" ở tab Tổng quan. Chúng bị `hiddenLabel()` giấu khỏi mắt nhưng
    // Filament vẫn in ra DOM cho trình đọc màn hình — bỏ trống thì chỗ đó đọc tiếng Anh.
    'team_fields' => [
        'members' => 'Thành viên đội ngũ',
        'name' => 'Họ và tên nhân sự',
        'role_in_matter' => 'Vai trò trong vụ việc',
    ],
    'overview_fields' => [
        'confidentiality' => 'Độ mật',
        'opened_at' => 'Ngày mở',
        'closed_at' => 'Ngày đóng',
        'court_name' => 'Toà án',
        'case_number' => 'Số hồ sơ vụ án',
    ],
    'actions' => [
        'publish_to_portal' => 'Bật công bố portal',
        'unpublish_from_portal' => 'Tắt công bố portal',
        'portal_publication_toggled' => 'Đã cập nhật trạng thái công bố portal.',
        'portal_publication_changed' => 'Trạng thái công bố của vụ việc vừa được người khác đổi trong lúc anh/chị xác nhận — chưa thay đổi gì. Hãy xem lại trạng thái hiện tại rồi thử lại nếu vẫn cần.',
        'add_party' => 'Thêm một bên',
        'add_party_heading' => 'Thêm một bên vào vụ việc',
        // M6.5 Task 9 (`conflict-05`, brief R14).
        'edit_party' => 'Sửa',
        'edit_party_heading' => 'Sửa thông tin bên',
        'remove_party' => 'Gỡ',
        'remove_party_heading' => 'Gỡ bên khỏi vụ việc',
        'transition_stage' => 'Chuyển giai đoạn',
        'add_update' => 'Thêm cập nhật',
        'cancel_matter' => 'Huỷ hồ sơ mở nhầm',
    ],
    // M6.5 Task 5 — trang "Sửa vụ việc" (findings intake-06, spec-gap-06).
    'edit_form' => [
        'section' => 'Sửa thông tin vụ việc',
        // Fix round 1, finding I2: chỉ lead hoặc admin, không còn "không phải trợ lý".
        'confidentiality_denied' => 'Chỉ luật sư phụ trách của vụ việc này hoặc quản trị viên mới đổi được mức bảo mật.',
        // Fix round 1, finding I2: chuyển sang mức hạn chế trong khi đội ngũ còn thành viên khác
        // lead/admin — họ sẽ hết thấy được vụ việc ngay sau khi đổi.
        'confidentiality_blocked_by_team' => 'Không thể chuyển sang mức hạn chế khi còn người ở đội ngũ, hoặc còn giữ mốc hạn/yêu cầu khách chưa xong, mà sẽ không mở được vụ việc sau khi chuyển: :names. Hãy chuyển việc cho người khác và gỡ họ khỏi đội ngũ qua tab Đội ngũ trước.',
        // Fix round 1, finding I1: summary_for_client đòi quyền công bố cho khách (stageLog.publish).
        'summary_for_client_denied' => 'Chỉ ai có quyền công bố cho khách mới sửa được tóm tắt cho khách.',
    ],
    // M6.5 Task 5 — hộp thoại "Huỷ hồ sơ mở nhầm" trên trang Sửa vụ việc.
    'cancel_form' => [
        'reason' => 'Lý do huỷ',
        'reason_help' => 'Bắt buộc. Vụ gắn nhầm khách hàng hoặc nhầm loại vụ việc thì huỷ và mở lại đúng, thay vì sửa — lý do được ghi vĩnh viễn vào nhật ký.',
        'success' => 'Đã huỷ hồ sơ mở nhầm.',
    ],
    'transition_form' => [
        'to_stage' => 'Giai đoạn mới',
        // `stage/stage-04` (M6.5 Task 10): nhãn gắn thêm vào các giai đoạn NGOÀI allowed_next mà
        // chỉ admin thấy trong ô chọn — TransitionStageAction::stageOptions().
        'outside_allowed_next_suffix' => '(ngoài luồng thông thường)',
        'occurred_at' => 'Ngày xảy ra',
        'internal_note' => 'Ghi chú nội bộ',
        'internal_note_hint' => 'Chỉ nội bộ, khách không đọc được',
        'public_content' => 'Nội dung công bố cho khách',
        'next_step' => 'Tiếp theo sẽ là gì',
        'client_action' => 'Anh/chị cần làm gì',
        'client_action_hint' => 'Để trống nghĩa là không cần làm gì',
        'expected_next_update_at' => 'Dự kiến có tin tiếp theo trước ngày',
        'publish' => 'Công bố cho khách ngay',
        'public_content_publish_hint' => 'Công bố cho khách yêu cầu tối thiểu 30 ký tự.',
        'publish_disabled_hint' => 'Vụ việc chưa bật công bố portal nên dòng này chưa công bố được ngay — bật bằng nút "Bật công bố portal" ở đầu trang vụ việc (cần quyền công bố), rồi thêm cập nhật.',
        // Task 7 (R12, phát hiện `stage/stage-06`): vụ đã bật cổng nhưng khách không có tài khoản
        // cổng nào đang hoạt động VÀ đã kích hoạt (activated_at không null) — đúng điều kiện
        // NotifyClientOfStageUpdate::eligibleRecipientsQuery() dùng để chọn người nhận thư thật.
        'no_activated_account_warning' => 'Khách chưa có tài khoản cổng đang dùng — sẽ không ai nhận thư.',
        'transition_heading' => 'Chuyển giai đoạn vụ việc',
        'add_update_heading' => 'Thêm cập nhật (không đổi giai đoạn)',
        'transition_success' => 'Đã chuyển giai đoạn.',
        'add_update_success' => 'Đã thêm cập nhật.',
        'preview_heading' => 'Bản xem trước — đúng như khách sẽ thấy',
        'preview_not_publishing' => 'Sẽ KHÔNG công bố cho khách với lựa chọn hiện tại.',
        // `stage/stage-02` (M6.5 Task 10): dòng CẬP NHẬT không công bố, nhưng `matters.stage` vẫn
        // ghi vô điều kiện (SPEC §6.2 bước 5) — khách vẫn thấy NHÃN GIAI ĐOẠN mới ngay, dù dòng
        // tiến độ nói về nó thì không lên timeline. Chỉ dùng khi bản xem trước đang thật sự đổi
        // giai đoạn (TransitionStageAction, không phải AddUpdateAction — xem client-preview.blade.php).
        'preview_not_publishing_with_stage_change' => 'Dòng này không công bố. Nếu vụ việc đang bật công bố portal, khách vẫn thấy giai đoạn mới: :stage.',
        'preview_no_stage' => 'Chưa chọn giai đoạn',
        'preview_empty_public_content' => '(Chưa có nội dung công bố)',
        'preview_next_step' => 'Tiếp theo',
        'preview_client_action' => 'Anh/chị cần làm gì',
        'preview_no_client_action' => 'Không cần làm gì',
        'preview_expected_next_update' => 'Dự kiến có tin tiếp theo',
    ],
    // Câu khách hàng đọc khi cổng khách từ chối ghi biên bản "đã xem" (SPEC §4.18). Một câu duy
    // nhất cho cả bốn tình huống, theo SPEC §10.10 — xem App\Actions\Portal\RecordStageLogView.
    'stage_log_views' => [
        'unavailable' => 'Dòng cập nhật này không còn hiển thị trong hồ sơ của anh/chị nên hệ thống chưa ghi nhận được. Anh/chị tải lại trang hồ sơ để xem những cập nhật mới nhất; nếu vẫn không thấy, gọi cho văn phòng để được hướng dẫn.',
    ],
    'stage_log_fields' => [
        'occurred_at' => 'Ngày xảy ra',
        'to_stage' => 'Giai đoạn',
        'internal_note' => 'Ghi chú nội bộ',
        'internal_marker' => 'Nội bộ',
        'public_content' => 'Nội dung đã công bố',
        'viewed_at' => 'Khách đã xem lúc :time ngày :date',
        'not_viewed' => 'Khách chưa xem',
    ],
    'party_fields' => [
        'role' => 'Vai trò',
        'is_our_client' => 'Là khách hàng của văn phòng',
        'client' => 'Khách hàng',
        'name' => 'Tên',
        'id_number' => 'Số căn cước / mã số thuế',
        'phone' => 'Số điện thoại',
        'address' => 'Địa chỉ',
        'note' => 'Ghi chú',
        // M6.5 Task 9: chỉ hiện trên form SỬA — hai ô id_number/phone luôn bắt đầu trống ở đó
        // (số gốc không bao giờ được lưu, SPEC §10.5), khác form thêm bên.
        'id_number_edit_help' => 'Để trống nếu không đổi số căn cước đã lưu — hệ thống không lưu số gốc nên không hiện lại được ở đây.',
        'phone_edit_help' => 'Để trống nếu không đổi số điện thoại đã lưu.',
        // `conflict-10`: thay cho thông điệp "Định dạng số điện thoại không hợp lệ." mặc định của
        // ->tel(), giờ chỉ nổ khi Normalizer::phone() không tìm được chữ số nào trong ô.
        'phone_invalid' => 'Định dạng số điện thoại không hợp lệ.',
        'acknowledge_conflict' => 'Tôi đã xem xét kết quả kiểm tra xung đột lợi ích và xác nhận vẫn muốn thêm bên này',
        'acknowledge_conflict_help' => 'Chỉ cần tích khi thông báo kết quả kiểm tra yêu cầu xem xét trước khi lưu.',
        'override_reason' => 'Lý do ghi đè mức đỏ',
        'override_reason_help_allowed' => 'Chỉ điền khi kết quả ở mức đỏ và anh/chị quyết định vẫn thêm bên này. Lý do được ghi vào nhật ký và không xoá được.',
        'override_reason_help_denied' => 'Chỉ trưởng phòng hoặc quản trị mới ghi đè được mức đỏ, nên ô này bị khoá với vai trò hiện tại.',
    ],
    'create_form' => [
        'sections' => [
            'details' => 'Thông tin vụ việc',
            'details_description' => 'Mã hồ sơ và giai đoạn đầu tiên do hệ thống tự sinh theo loại vụ việc, không cần nhập.',
            'other_parties' => 'Các bên khác trong vụ việc',
            'other_parties_description' => 'Bị đơn, người có quyền lợi nghĩa vụ liên quan, bên thứ ba… Khách hàng của vụ việc đã được thêm tự động, không cần khai lại ở đây.',
        ],
        'client_role' => 'Vai của khách hàng trong vụ việc này',
        'client_role_help' => 'Bắt buộc, không có mặc định: vai này quyết định bên nào là bên đối lập khi đối chiếu xung đột lợi ích. Chọn sai vai sẽ hạ một xung đột lẽ ra mức đỏ xuống mức vàng.',
        'add_party' => 'Thêm một bên',
        'unnamed_party' => 'Bên chưa nhập tên',
        'id_number_help' => 'Nên nhập. Đây là tiêu chí đối chiếu chắc chắn nhất; bỏ trống thì hệ thống không thể phát hiện trùng số căn cước.',
        'phone_help' => 'Nên nhập. Đây là tiêu chí đối chiếu mạnh thứ hai, sau số căn cước.',
        'identity_missing_warning' => 'Bên này chưa có số căn cước lẫn số điện thoại nên chỉ đối chiếu được theo tên — mức tin cậy thấp nhất. Kết quả xanh với bên như vậy không có nghĩa là đã kiểm tra kỹ, và hệ thống sẽ bắt xác nhận trước khi lưu.',
        'create_heading' => 'Mở vụ việc mới',
        // M6.5 Task 6 (R4, findings `intake-03`/`roles-04`): luật sư không có client.manage nên
        // không thấy danh sách khách hàng của văn phòng — hai đường thay thế dưới đây.
        'client_lookup_intro' => 'Anh/chị không có quyền xem danh sách khách hàng của văn phòng. Tra đúng số điện thoại hoặc số CCCD nếu khách đã có hồ sơ, hoặc tạo khách hàng mới ngay bên dưới nếu chưa có.',
        'client_lookup_identifier' => 'Số điện thoại hoặc số CCCD của khách hàng (nếu đã có hồ sơ)',
        'client_lookup_identifier_help' => 'Phải khớp ĐÚNG số đã đăng ký. Hệ thống không gợi ý theo tên và không liệt kê hồ sơ gần đúng.',
        'client_lookup_found' => 'Đã tìm thấy hồ sơ khách hàng: :code — :name',
        'new_client_intro' => 'Không tìm thấy hồ sơ khớp — điền thông tin bên dưới để tạo khách hàng mới.',
    ],
    'conflict' => [
        'section' => 'Kiểm tra xung đột lợi ích',
        'section_description' => 'Hệ thống chạy kiểm tra ngay trước khi lưu và hiện kết quả tại mục này. Mọi lần chạy đều được ghi vào nhật ký, kể cả khi không tìm thấy gì.',
        'heading_red' => 'Mức đỏ — không được lưu vụ việc này',
        'heading_attention' => 'Cần xem xét trước khi lưu',
        'heading_clear' => 'Không tìm thấy xung đột lợi ích',
        'intro' => 'Các hồ sơ dưới đây có bên trùng với một bên của vụ việc đang mở:',
        'no_matches' => 'Không tìm thấy bản ghi trùng nào.',
        'boundary_note' => 'Tại đây chỉ hiện mã hồ sơ, loại vụ việc và vai của bên trùng — không hiện tiêu đề, nội dung hay tài liệu của hồ sơ đó, kể cả với người có quyền.',
        'incomplete' => 'Các bên chưa có số căn cước lẫn số điện thoại để đối chiếu: :names',
        'column_matter_code' => 'Mã hồ sơ',
        'column_matter_type' => 'Loại vụ việc',
        'column_party_role' => 'Vai của bên đó',
        'column_party_name' => 'Tên bên trùng',
        'column_tier' => 'Trùng theo',
        'column_level' => 'Mức',
        'column_our_party' => 'Bên phía mình',
        'same_matter_marker' => 'Vụ việc đang mở này',
        'already_confirmed' => 'Đã xem xét ở lần trước',
        'acknowledge' => 'Tôi đã xem xét kết quả kiểm tra xung đột lợi ích ở trên và xác nhận vẫn mở vụ việc này',
        'acknowledge_help' => 'Chỉ cần tích khi bảng kết quả ở trên yêu cầu xem xét.',
        'override_reason' => 'Lý do ghi đè mức đỏ',
        'override_reason_help_allowed' => 'Chỉ điền khi kết quả ở mức đỏ và anh/chị quyết định vẫn mở vụ việc. Lý do được ghi vào nhật ký và không xoá được.',
        'override_reason_help_denied' => 'Chỉ trưởng phòng hoặc quản trị mới ghi đè được mức đỏ, nên ô này bị khoá với vai trò hiện tại.',
        'blocked_retry' => 'Mức đỏ: không lưu được vụ việc này. Chỉ trưởng phòng hoặc quản trị mới ghi đè được, và bắt buộc nhập lý do vào ô này.',
        'blocked_retry_denied' => 'Mức đỏ: không lưu được vụ việc này. Vai trò hiện tại không ghi đè được — hãy đề nghị trưởng phòng mở vụ việc, hoặc sửa lại thông tin các bên.',
        'ack_retry' => 'Đọc kỹ bảng kết quả kiểm tra xung đột lợi ích ở trên, sau đó tích "Tôi đã xem xét…" rồi bấm lưu lại.',
        'saved_clear' => 'Đã kiểm tra xung đột lợi ích trước khi lưu: không tìm thấy bản ghi trùng nào.',
        // Fix round 1, C3 (`conflict-01`): không có khớp MỚI, nhưng có khớp đã xác nhận/ghi đè
        // trước đó (R13c) — KHÔNG được dùng saved_clear/màu success, vì thân thông báo vẫn liệt
        // kê những khớp đó (có thể ở mức Đỏ).
        'saved_clear_with_confirmed' => 'Không có xung đột MỚI; :count xung đột đã được xem xét/ghi đè trước đó.',
        'saved_after_review' => 'Đã mở vụ việc sau khi xem xét kết quả kiểm tra xung đột lợi ích.',
        'saved_overridden' => 'ĐÃ GHI ĐÈ XUNG ĐỘT MỨC ĐỎ — vụ việc vẫn được mở theo quyết định của anh/chị.',
        'saved_overridden_reason' => 'Lý do ghi đè đã ghi vĩnh viễn vào nhật ký: :reason',
    ],
    'parties' => [
        // Ba tiêu đề của giai đoạn CHƯA LƯU. Chỉ dùng cho hai nhánh bị chặn — một dòng đã lưu
        // xong không bao giờ được mô tả bằng câu "trước khi lưu" (C-1).
        'conflict_blocked_title' => 'Mức đỏ — chưa thêm bên này vào vụ việc',
        'conflict_check_title_attention' => 'Cần xem xét trước khi lưu',
        // Ba tiêu đề của giai đoạn ĐÃ LƯU.
        'saved_overridden' => 'ĐÃ GHI ĐÈ XUNG ĐỘT MỨC ĐỎ — bên này vẫn được thêm theo quyết định của anh/chị.',
        'saved_after_review' => 'Đã thêm bên sau khi xem xét kết quả kiểm tra xung đột lợi ích.',
        // Fix round 1, C3 (`conflict-01`): không có khớp MỚI, nhưng có khớp đã xác nhận/ghi đè
        // trước đó (R13c) — KHÔNG được dùng conflict_check_title_clear/màu success, vì thân
        // thông báo vẫn liệt kê những khớp đó (có thể ở mức Đỏ).
        'saved_clear_with_confirmed' => 'Không có xung đột MỚI; :count xung đột đã được xem xét/ghi đè trước đó.',
        'conflict_check_title_clear' => 'Không tìm thấy xung đột lợi ích',
        'conflict_check_clear' => 'Không tìm thấy bản ghi trùng.',
        'conflict_check_incomplete' => 'Các bên sau chưa có số căn cước/điện thoại để đối chiếu: :names',
        'conflict_blocked_retry' => 'Mức đỏ: chưa thêm bên này. Chỉ trưởng phòng hoặc quản trị mới ghi đè được, và bắt buộc nhập lý do vào ô này.',
        'conflict_blocked_retry_denied' => 'Mức đỏ: chưa thêm bên này. Vai trò hiện tại không ghi đè được — hãy đề nghị trưởng phòng thêm bên này, hoặc sửa lại thông tin bên vừa nhập.',
        'conflict_ack_retry' => 'Đọc kỹ thông báo kết quả kiểm tra xung đột lợi ích ở trên, sau đó tích "Tôi đã xem xét…" rồi gửi lại.',
        // M6.5 Task 9, fix round 1, C1: dùng chung cho CẢ sửa LẪN gỡ — hai tab cùng nhìn một bên,
        // một tab gỡ nó trước, tab kia gửi lại một modal đã mở từ trước đó.
        'already_removed' => 'Bên này đã được gỡ khỏi vụ việc. Anh/chị tải lại trang.',
    ],
    // M6.5 Task 9 — hộp thoại "Gỡ bên khỏi vụ việc" trên tab "Các bên" (brief R14).
    'remove_party_form' => [
        'reason' => 'Lý do gỡ',
        'reason_help' => 'Bắt buộc. Bên đã gỡ được xem là "nhập nhầm, chưa từng là bên" và không còn tham gia đối chiếu xung đột lợi ích — lý do được ghi vĩnh viễn vào nhật ký.',
        'reason_required' => 'Bắt buộc nhập lý do gỡ.',
        'success' => 'Đã gỡ bên khỏi vụ việc.',
        'own_client_denied' => 'Đây là khách hàng của chính vụ việc này — sửa qua hồ sơ khách hàng, không gỡ được ở đây.',
    ],
    // Final review B-M3: thư báo tiến độ cho khách đã hỏng hẳn
    // (NotifyClientOfStageUpdate::reportFailure()).
    'stage_update_failed_notification' => [
        'title' => 'Chưa gửi được thư báo tiến độ cho khách hàng',
        'body' => 'Thư báo cập nhật tiến độ hồ sơ :code đã thử gửi nhiều lần nhưng không tới được khách hàng. Hãy báo cho khách qua kênh khác và kiểm tra email của tài khoản cổng.',
    ],
    // M6 Task 3 — cùng hình dạng stage_update_failed_notification, cho hai listener mới
    // (NotifyClientOfDocumentPublished::reportFailure(), NotifyClientOfChecklistItemRejected::reportFailure()).
    'document_published_failed_notification' => [
        'title' => 'Chưa gửi được thư báo văn bản mới cho khách hàng',
        'body' => 'Thư báo có văn bản mới của hồ sơ :code đã thử gửi nhiều lần nhưng không tới được khách hàng. Hãy báo cho khách qua kênh khác và kiểm tra email của tài khoản cổng.',
    ],
    'document_rejected_failed_notification' => [
        'title' => 'Chưa gửi được thư báo từ chối giấy tờ cho khách hàng',
        'body' => 'Thư báo từ chối giấy tờ của hồ sơ :code đã thử gửi nhiều lần nhưng không tới được khách hàng. Hãy báo cho khách qua kênh khác và kiểm tra email của tài khoản cổng.',
    ],
    // M6 Task 4 — cùng hình dạng hai khoá trên, cho NotifyClientOfRequestAnswered::reportFailure().
    'request_answered_failed_notification' => [
        'title' => 'Chưa gửi được thư báo phản hồi yêu cầu cho khách hàng',
        'body' => 'Thư báo phản hồi yêu cầu của hồ sơ :code đã thử gửi nhiều lần nhưng không tới được khách hàng. Hãy báo cho khách qua kênh khác và kiểm tra email của tài khoản cổng.',
    ],
    // M6.5 Task 9 — ba tiêu đề/thông báo riêng của "sửa một bên" khác câu với "thêm một bên"
    // (`parties` ở trên). Hai khoá KHÔNG lặp lại ở đây (`saved_clear_with_confirmed`,
    // `conflict_check_title_clear`) không nhắc "thêm bên" nên dùng chung được với `notifySaved()`.
    'update_parties' => [
        'own_client_locked' => 'Đây là khách hàng của chính vụ việc này — không đổi được "là khách hàng của văn phòng" hay khách hàng liên kết ở đây. Vai trò, địa chỉ và ghi chú vẫn sửa được.',
        'conflict_blocked_title' => 'Mức đỏ — chưa lưu thay đổi này',
        'saved_overridden' => 'ĐÃ GHI ĐÈ XUNG ĐỘT MỨC ĐỎ — thay đổi vẫn được lưu theo quyết định của anh/chị.',
        'saved_after_review' => 'Đã lưu thay đổi sau khi xem xét kết quả kiểm tra xung đột lợi ích.',
        'conflict_blocked_retry' => 'Mức đỏ: chưa lưu thay đổi này. Chỉ trưởng phòng hoặc quản trị mới ghi đè được, và bắt buộc nhập lý do vào ô này.',
        'conflict_blocked_retry_denied' => 'Mức đỏ: chưa lưu thay đổi này. Vai trò hiện tại không ghi đè được — hãy đề nghị trưởng phòng sửa bên này, hoặc sửa lại thông tin vừa nhập.',
    ],
];
