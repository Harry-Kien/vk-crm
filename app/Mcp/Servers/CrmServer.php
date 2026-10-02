<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;

/**
 * Máy chủ MCP duy nhất của VK-CRM (kế hoạch M11): một endpoint Streamable HTTP `POST /mcp`, chỉ
 * cho nhân sự, sau token Passport (`routes/ai.php`).
 *
 * Dual-era nhờ laravel/mcp 1.0.1: client kiểu `initialize` (2025-11-25, 2025-06-18) và client
 * stateless 2026-07-28 (`server/discover`, header `Mcp-Method`) dùng chung lớp này [DC:626]. Không
 * phiên, không SSE: không tool nào trả Generator [PL:108], [PL:127].
 *
 * Task 1 chưa đăng ký tool nào. `whoami` đến ở Task 10.
 */
class CrmServer extends Server
{
    /** Đường dẫn của endpoint, dùng chung cho `routes/ai.php` và cách render lỗi ở `bootstrap/app.php`. */
    public const PATH = 'mcp';

    protected string $name = 'VK-CRM';

    protected string $version = '1.0.0';

    /**
     * `instructions` tiếng Việt qua `lang/vi/mcp.php` (R11). Thuộc tính của lớp cha không gọi được
     * `__()`, nên chuỗi được nạp ở `boot()`. `Server::start()` gọi `boot()` trước khi dựng
     * `ServerContext`, và `createContext()` đọc `$this->instructions` ở mỗi lần xử lý một thông điệp.
     */
    protected function boot(): void
    {
        $this->instructions = __('mcp.server.instructions');
    }
}
