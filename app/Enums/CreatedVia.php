<?php

namespace App\Enums;

/**
 * Bản ghi được tạo qua đường nào — cột `created_via` của `deadlines` và `communication_logs` (M11
 * R5). `Mcp` là bản ghi do tool ghi của máy chủ MCP tạo (`create_deadline`, `log_communication`,
 * Task 13), dưới tên người sở hữu token; trên `/admin` nó mang nhãn "Tạo qua AI" (Task 12). Server
 * ÉP giá trị này, không nhận nó từ tham số của tool.
 *
 * Mọi dòng có trước M11 và mọi dòng nhân sự nhập trên `/admin` là `Web` (mặc định của cột).
 */
enum CreatedVia: string
{
    case Web = 'web';
    case Mcp = 'mcp';

    public function label(): string
    {
        return __('enums.created_via.'.$this->value);
    }
}
