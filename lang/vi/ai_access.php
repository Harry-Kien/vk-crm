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
        'write_refused' => 'Tài khoản của bạn hiện chỉ được đọc qua AI (chế độ "Chỉ đọc", hoặc quản trị đã tắt quyền ghi qua AI), nên không có gì được ghi. Hỏi quản trị nếu bạn cần quyền ghi.',
        'unavailable' => 'Công cụ này không dùng được qua máy chủ AI của văn phòng.',
    ],
];
