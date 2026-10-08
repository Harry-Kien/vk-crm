<?php

/**
 * M11 Task 6 — quyền truy cập qua AI theo người (R2), quyền ghi qua AI (R13), cam kết chính sách dùng
 * AI (R12). Tệp riêng (không trộn vào `mcp.php`) để làn tool song song không đụng cùng một khối.
 *
 * - `validation.*`: lỗi của `App\Actions\Mcp\SetUserAiAccess` (ô `ai_access`) và
 *   `App\Actions\Mcp\AcknowledgeAiPolicy` (ô `acknowledged`), hiện trên màn hình của Task 15.
 * - `tools.*`: kết quả `isError` mà `App\Mcp\Methods\CrmToolInvoker` trả cho AI khi từ chối chạy một
 *   tool. AI đọc nguyên câu này rồi kể lại cho nhân sự, nên câu nói điều nhân sự làm được tiếp theo.
 */
return [
    'validation' => [
        'needs_matter_view' => 'Không bật được truy cập qua AI cho người không có quyền xem nội dung vụ việc (ví dụ Kế toán): mọi công cụ AI đều đọc nội dung vụ việc.',
        'inactive' => 'Không bật được truy cập qua AI cho một tài khoản đang bị vô hiệu hoá.',
        'acknowledgement_required' => 'Bạn cần tích ô cam kết sau khi đọc chính sách dùng AI.',
    ],

    'tools' => [
        // Task 13 (brief, [DC:191]): nói rõ ai chưa bật, và rằng gọi lại cũng bị từ chối — AI đọc câu
        // này rồi thôi thử, thay vì lặp lại lời gọi.
        'write_refused' => 'Quản trị chưa bật quyền ghi cho anh/chị qua AI (tài khoản ở chế độ "Chỉ đọc", hoặc quyền ghi qua AI đang tắt cho cả văn phòng), nên không có gì được ghi. Đừng gọi lại: lần gọi sau cũng bị từ chối, không thử lại cho tới khi quản trị bật quyền ghi; hỏi quản trị nếu anh/chị cần.',
        'unavailable' => 'Công cụ này không dùng được qua máy chủ AI của văn phòng.',
    ],
];
