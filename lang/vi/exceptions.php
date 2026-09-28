<?php

return [
    'stage_log_immutable' => 'Nhật ký tiến độ không thể sửa nội dung hoặc xoá sau khi đã ghi.',
    'stage_log_view_immutable' => 'Biên bản khách đã xem tiến độ không thể sửa hoặc xoá sau khi đã ghi.',
    'matter_not_destroyable' => 'Không thể xoá vĩnh viễn vụ việc: nhật ký tiến độ và nhật ký tải về phải được giữ theo chính sách lưu trữ.',
    'stage_not_configured' => 'Loại vụ việc ":name" chưa có giai đoạn nào. Vào Loại vụ việc để thêm giai đoạn trước khi mở vụ việc mới.',
    'duplicate_matter_type_code' => 'Đã có một loại vụ việc còn dùng mang mã ":code". Mã loại nằm trong mã hồ sơ (SPEC §6.1) nên không được trùng; đổi mã, hoặc mở lại loại vụ việc cũ nếu nó đã bị xoá.',
    'duplicate_stage_key' => 'Loại vụ việc ":name" đã có một giai đoạn còn dùng với định danh ":key". Xoá hoặc đổi định danh giai đoạn cũ trước khi tạo lại.',
    'stage_key_in_use' => 'Không thể đổi định danh ":key" của loại vụ việc ":name": còn hồ sơ, dòng tiến độ, hoặc một giai đoạn khác (mục "Được chuyển tới") đang dùng định danh này. Tạo một giai đoạn mới nếu cần một định danh khác.',
    'stage_terminal_flag_in_use' => 'Không đổi được "Giai đoạn kết thúc" của giai đoạn ":key": còn :count hồ sơ đang đứng ở giai đoạn này.',
    'invalid_stage_transition' => 'Vụ việc :code không thể chuyển sang giai đoạn ":to": giai đoạn này không nằm trong danh sách giai đoạn kế tiếp được phép của ":from", hoặc không tồn tại trong cấu hình loại vụ việc.',
    'matter_stage_changed' => 'Giai đoạn của vụ việc :code đã đổi trong lúc anh/chị thao tác. Hãy tải lại trang để xem giai đoạn mới nhất rồi thử lại.',
    'conflict_blocked' => 'Không thể lưu vụ việc: phát hiện xung đột lợi ích mức đỏ với hồ sơ :codes. Chỉ trưởng phòng hoặc quản trị mới được ghi đè, và phải nhập lý do.',
    'conflict_acknowledgement_required' => 'Phát hiện cảnh báo xung đột lợi ích mức vàng: hãy xem lại danh sách bản ghi trùng và tích xác nhận trước khi lưu vụ việc.',
    'our_client_party_needs_client' => 'Bên ":name" được đánh dấu là khách hàng của văn phòng nhưng chưa chọn hồ sơ khách hàng. Hãy chọn đúng hồ sơ ở ô "Khách hàng", hoặc tắt công tắc "Là khách hàng của văn phòng" — tên và số căn cước của một bên như vậy phải lấy từ hồ sơ thật thì lần kiểm tra xung đột sau mới nhìn thấy bên này.',
    'unnamed_party' => 'chưa nhập tên',
    'matter_not_published_to_portal' => 'Không thể công bố dòng tiến độ cho vụ việc :code: vụ việc chưa bật "Công bố portal". Bật công tắc này trước khi công bố tiến độ cho khách.',
    'deadline_matter_not_published_to_portal' => 'Không thể gửi mốc thời hạn cho khách ở vụ việc :code: vụ việc chưa bật "Công bố portal". Bật công tắc này ở tab Tổng quan trước, rồi gửi lại mốc.',
    'team_member_has_open_work' => 'Không thể gỡ :name khỏi đội ngũ vụ việc :code: người này còn — :items. Hãy chuyển các việc này cho người khác trước khi gỡ khỏi đội ngũ.',
    'conflict_check_busy' => 'Hệ thống đang kiểm tra xung đột cho một yêu cầu khác, thử lại sau.',
    // M6.5 Task 6 (R4, `intake-07`): App\Actions\Client\CreateClient.
    'duplicate_client_detected' => 'Đã có một khách hàng khác mang cùng số điện thoại hoặc số CCCD vừa nhập — mã :code. Xem lại hồ sơ đó ở liên kết bên trên trước khi quyết định.',
    // M6.5 Task 6, fix round 1 (C1): câu trung lập, không nêu tên hay mã hồ sơ của ai.
    'duplicate_client_not_visible' => 'Có thể khách hàng này đã có hồ sơ ở văn phòng. Nhờ trưởng phòng hoặc quản trị viên mở vụ.',
    // M6.5 Task 6, fix round 1 (I1).
    'client_lookup_throttled' => 'Đã tra hoặc thử quá nhiều lần trong một giờ. Vui lòng thử lại sau, hoặc nhờ trưởng phòng/quản trị viên mở vụ giúp.',

    'contract_not_destroyable_not_draft' => 'Chỉ xoá được hợp đồng khi còn ở trạng thái "Nháp". Hợp đồng đã ký hoặc đã kết thúc thì đóng lại bằng "Hoàn tất" hoặc "Huỷ", không xoá.',
    'contract_not_destroyable_has_payments' => 'Không thể xoá hợp đồng: đã có khoản thu ghi nhận trên hợp đồng này.',
    'instalment_not_destroyable' => 'Chỉ xoá được đợt thanh toán khi hợp đồng còn ở trạng thái "Nháp". Đợt của hợp đồng đã ký thì huỷ hoặc miễn, không xoá.',
    'payment_not_destroyable' => 'Không thể xoá khoản thu đã ghi nhận. Ghi nhầm thì huỷ khoản thu kèm lý do, khoản thu vẫn được giữ lại.',
    'contract_amendment_immutable' => 'Phụ lục hợp đồng chỉ được thêm mới, không được sửa hoặc xoá sau khi đã ghi.',
    'matter_has_outstanding_balance' => 'Không thể xoá vụ việc :code: còn dư nợ :amount trên :count đợt thanh toán của hợp đồng đang có hiệu lực. Thu nốt hoặc miễn các đợt còn lại (kèm lý do) trước khi xoá.',
];
