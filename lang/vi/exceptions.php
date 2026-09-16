<?php

return [
    'stage_log_immutable' => 'Nhật ký tiến độ không thể sửa nội dung hoặc xoá sau khi đã ghi.',
    'matter_not_destroyable' => 'Không thể xoá vĩnh viễn vụ việc: nhật ký tiến độ và nhật ký tải về phải được giữ theo chính sách lưu trữ.',
    'stage_not_configured' => 'Loại vụ việc ":name" chưa có giai đoạn nào. Vào Loại vụ việc để thêm giai đoạn trước khi mở vụ việc mới.',
    'duplicate_stage_key' => 'Loại vụ việc ":name" đã có một giai đoạn còn dùng với định danh ":key". Xoá hoặc đổi định danh giai đoạn cũ trước khi tạo lại.',
    'invalid_stage_transition' => 'Vụ việc :code không thể chuyển sang giai đoạn ":to": giai đoạn này không nằm trong danh sách giai đoạn kế tiếp được phép của ":from", hoặc không tồn tại trong cấu hình loại vụ việc.',
    'conflict_blocked' => 'Không thể lưu vụ việc: phát hiện xung đột lợi ích mức đỏ với hồ sơ :codes. Chỉ trưởng phòng hoặc quản trị mới được ghi đè, và phải nhập lý do.',
    'conflict_acknowledgement_required' => 'Phát hiện cảnh báo xung đột lợi ích mức vàng: hãy xem lại danh sách bản ghi trùng và tích xác nhận trước khi lưu vụ việc.',
    'our_client_party_needs_client' => 'Bên ":name" được đánh dấu là khách hàng của văn phòng nhưng chưa chọn hồ sơ khách hàng. Hãy chọn đúng hồ sơ ở ô "Khách hàng", hoặc tắt công tắc "Là khách hàng của văn phòng" — tên và số căn cước của một bên như vậy phải lấy từ hồ sơ thật thì lần kiểm tra xung đột sau mới nhìn thấy bên này.',
    'unnamed_party' => 'chưa nhập tên',
    'matter_not_published_to_portal' => 'Không thể công bố dòng tiến độ cho vụ việc :code: vụ việc chưa bật "Công bố portal". Bật công tắc này trước khi công bố tiến độ cho khách.',
];
