<?php

/**
 * Nhật ký và rate limit của máy chủ MCP (kế hoạch M11, R8, Task 8). Tệp riêng (không trong
 * `lang/vi/mcp.php`) để làn tool chạy song song không đụng cùng khối.
 */
return [
    // Thân phản hồi JSON-RPC lỗi khi một lần gọi tool bị chặn (HTTP 429), client AI đọc.
    'rate_limited' => 'Quá nhiều lần gọi trong thời gian ngắn. Đợi theo thời gian ở header Retry-After rồi gọi lại.',

    // HTTP 429 trước bước xác thực: cùng một token không dùng được bị gửi lại quá nhiều lần từ một địa chỉ.
    'too_many_failures' => 'Token này đã bị từ chối quá nhiều lần trong thời gian ngắn. Kết nối lại trợ lý AI hoặc thử lại sau ít phút.',

    // `App\Enums\McpToolOutcome::label()` — `properties.outcome` của dòng `mcp_tool_called`.
    'outcomes' => [
        'ok' => 'Thành công',
        'not_found' => 'Không tìm thấy',
        'denied' => 'Bị từ chối',
        'invalid' => 'Yêu cầu không hợp lệ',
        'rate_limited' => 'Vượt giới hạn số lần gọi',
        'error' => 'Lỗi hệ thống',
    ],

    // `App\Notifications\Staff\McpReadVolumeAlert` — thông báo trong hệ thống cho admin.
    'read_volume_alert' => [
        'title' => 'Một nhân sự đọc nhiều dữ liệu qua trợ lý AI',
        'body' => ':name đã nhận hơn :count bản ghi qua kết nối trợ lý AI trong một giờ. Xem trang Nhật ký hệ thống, lọc theo kênh "Trợ lý AI (MCP)", để biết từng lần gọi.',
    ],

    // `App\Filament\Admin\Pages\ActivityLogPage` — bộ lọc theo kênh và ghi chú về IP.
    'page' => [
        'channel_filter' => 'Kênh',
        'channels' => [
            'mcp' => 'Trợ lý AI (MCP)',
        ],
        'ip_note' => 'Với dòng của kênh Trợ lý AI (MCP), IP là địa chỉ máy chủ của nền tảng AI (Claude, ChatGPT…) gọi thay nhân sự, không phải nơi nhân sự đang ngồi. Chỉ ứng dụng chạy trên máy (Claude Code, Cursor, VS Code) mới gọi từ máy người dùng.',
    ],
];
