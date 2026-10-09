<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Methods\CrmToolInvoker;

/**
 * Lớp cơ sở của bốn tool GHI (kế hoạch M11, R5; Task 13): `draft_progress_update`,
 * `draft_request_reply`, `create_deadline`, `log_communication`. Đứng trên {@see CrmTool}, cộng:
 *
 * - **`writes()` cố định `true`.** Từ đó, không cần dòng mã nào trong tool con:
 *   - annotation trung thực của tool ghi (`readOnlyHint: false`, `destructiveHint: false`,
 *     `idempotentHint: true`, `openWorldHint: false` — R14);
 *   - chỉ được đăng ký cho người ghi được qua MCP lúc này (`CrmTool::shouldRegister()`, R13), và bước
 *     gọi tool ({@see CrmToolInvoker}) kiểm lại ở MỖI lần gọi;
 *   - giới hạn riêng của tool ghi: 10 lần một phút, 100 lần một ngày (R8, `McpRateLimits`);
 *   - một dòng `mcp_tool_called` cho mỗi lần gọi, văn bản tự do chỉ còn độ dài (Task 8).
 * - Bốn việc dùng chung với tool đọc ({@see InteractsWithCrmRequests}).
 *
 * Tool con chỉ đọc tham số, gọi MỘT Action ở `App\Actions\Mcp\Write`, và trình bày kết quả qua
 * presenter ở `App\Support\Mcp\Presenters`. Không truy vấn, không ghi Eloquent, không tự kiểm quyền:
 * Action hỏi đúng ability của màn hình web tương ứng (R3).
 *
 * {@see self::attributeNames()}: thông điệp kiểm tra tham số gọi tham số bằng ĐÚNG tên của nó
 * (`due_date`, không "due date"), để AI biết sửa tham số nào.
 */
abstract class CrmWriteTool extends CrmTool
{
    use InteractsWithCrmRequests;

    final protected function writes(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, string>
     */
    protected function attributeNames(array $rules): array
    {
        $names = array_keys($rules);

        return array_combine($names, $names);
    }
}
