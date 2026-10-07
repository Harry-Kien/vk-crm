<?php

/**
 * M11 Task 4 — màn hình đồng ý OAuth (`resources/views/mcp/authorize.blade.php`), nơi nhân sự cho một
 * trợ lý AI (Claude, ChatGPT, …) kết nối vào máy chủ MCP dưới tài khoản của mình. Tệp riêng (không
 * trộn vào `mcp.php`) để làn tool song song không đụng cùng một khối.
 *
 * Nhãn nền tảng: `enums.mcp_platform`; câu lý do từ chối: `enums.mcp_access_refusal`.
 */
return [
    'title' => 'Kết nối trợ lý AI',
    'heading' => 'Cho phép :platform truy cập dữ liệu văn phòng dưới tài khoản của anh/chị?',
    'refused_heading' => 'Chưa kết nối được trợ lý AI',

    'platform' => 'Nền tảng',
    'redirect_host' => 'Mã kết nối sẽ được gửi tới',
    'account' => 'Tài khoản',
    'mode' => 'Chế độ hiện tại',
    'write_switch_off' => 'quyền ghi qua AI đang tắt cho toàn văn phòng',

    // [DC:139]: câu đầu là câu kế hoạch đòi nguyên văn.
    'acts_as_you' => 'AI sẽ hành động với danh nghĩa và quyền của anh/chị.',
    'acts_as_you_detail' => 'Nó đọc được những vụ việc anh/chị xem được trên hệ thống (trừ vụ hạn chế, vụ chưa cho phép AI, tài liệu nhóm D, ghi chú nội bộ và số định danh), không gửi hay công bố gì cho khách, và mọi lần gọi đều được ghi nhật ký.',

    // [PL:225]: cổng loopback — mọi chương trình trên máy đều mở được trang này với cổng của nó.
    'loopback_warning' => 'Mã kết nối sẽ được gửi tới một cổng trên chính máy tính này (:host), không tới một trang web. Chỉ đồng ý nếu anh/chị vừa tự mở một ứng dụng AI trên máy này (ví dụ Claude Code, VS Code, Cursor) và đang chờ nó kết nối: bất kỳ chương trình nào trên máy cũng mở được trang này.',
    'other_warning' => 'Địa chỉ này không thuộc nền tảng AI nào văn phòng đã biết; quản trị đã thêm nó vào danh sách được phép. Chỉ đồng ý nếu anh/chị biết ứng dụng này.',

    'policy_link' => 'Đọc chính sách dùng AI của văn phòng',
    'approve' => 'Đồng ý kết nối',
    'deny' => 'Từ chối',
    'refused_deny_hint' => 'Bấm "Từ chối" để báo cho ứng dụng AI rằng lần kết nối này không thành, rồi đóng trang.',
];
