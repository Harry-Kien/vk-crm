<?php

namespace App\Mcp\Tools\Concerns;

/**
 * Lớp cơ sở của mười một tool ĐỌC (kế hoạch M11, bảng tool 1–11; Task 10 dựng năm tool đầu). Đứng
 * trên {@see CrmTool} (hint R14, tiêu đề/mô tả tiếng Việt), cộng:
 *
 * - **`writes()` cố định `false`.** Một tool đọc không tự khai mình là tool ghi được.
 * - Bốn việc dùng chung với tool ghi ({@see InteractsWithCrmRequests}): người gọi tường minh từ guard
 *   `mcp`, một thông điệp "Không tìm thấy" duy nhất, `inputSchema`/`outputSchema` đóng và kiểm ở
 *   server, kết quả có cấu trúc.
 *
 * Audit và rate limit (R8) là việc của lớp cơ sở/middleware ở Task 8, không của tool con.
 */
abstract class CrmReadTool extends CrmTool
{
    use InteractsWithCrmRequests;

    final protected function writes(): bool
    {
        return false;
    }
}
