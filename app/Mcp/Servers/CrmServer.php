<?php

namespace App\Mcp\Servers;

use App\Mcp\Methods\CallCrmTool;
use App\Mcp\Tools\CreateDeadlineTool;
use App\Mcp\Tools\DraftProgressUpdateTool;
use App\Mcp\Tools\DraftRequestReplyTool;
use App\Mcp\Tools\FetchTool;
use App\Mcp\Tools\GetChecklistTool;
use App\Mcp\Tools\GetClientRequestTool;
use App\Mcp\Tools\GetMatterTool;
use App\Mcp\Tools\ListClientRequestsTool;
use App\Mcp\Tools\ListDeadlinesTool;
use App\Mcp\Tools\ListDocumentsTool;
use App\Mcp\Tools\ListMatterUpdatesTool;
use App\Mcp\Tools\LogCommunicationTool;
use App\Mcp\Tools\SearchMattersTool;
use App\Mcp\Tools\SearchTool;
use App\Mcp\Tools\WhoAmITool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

/**
 * Máy chủ MCP duy nhất của VK-CRM (kế hoạch M11): một endpoint Streamable HTTP `POST /mcp`, chỉ
 * cho nhân sự, sau token Passport (`routes/ai.php`).
 *
 * Dual-era nhờ laravel/mcp 1.0.1: client kiểu `initialize` (2025-11-25, 2025-06-18) và client
 * stateless 2026-07-28 (`server/discover`, header `Mcp-Method`) dùng chung lớp này [DC:626]. Không
 * phiên, không SSE: không tool nào trả Generator [PL:108], [PL:127].
 *
 * Tool đăng ký theo THỨ TỰ CỐ ĐỊNH của bảng tool trong kế hoạch (R13, [DC:649]): Task 10 đăng ký
 * năm tool đọc đầu (`whoami`, `search`, `fetch`, `search_matters`, `get_matter`); Task 11 nối tiếp
 * sáu tool đọc còn lại (`list_matter_updates`, `list_deadlines`, `get_checklist`, `list_documents`,
 * `list_client_requests`, `get_client_request`); Task 13 bốn tool ghi (`draft_progress_update`,
 * `draft_request_reply`, `create_deadline`, `log_communication`) — chỉ đăng ký cho người ghi được
 * qua MCP lúc này (`CrmTool::shouldRegister()`, R13).
 */
class CrmServer extends Server
{
    /** Đường dẫn của endpoint, dùng chung cho `routes/ai.php` và cách render lỗi ở `bootstrap/app.php`. */
    public const PATH = 'mcp';

    protected string $name = 'VK-CRM';

    protected string $version = '1.0.0';

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        WhoAmITool::class,
        SearchTool::class,
        FetchTool::class,
        SearchMattersTool::class,
        GetMatterTool::class,
        ListMatterUpdatesTool::class,
        ListDeadlinesTool::class,
        GetChecklistTool::class,
        ListDocumentsTool::class,
        ListClientRequestsTool::class,
        GetClientRequestTool::class,
        DraftProgressUpdateTool::class,
        DraftRequestReplyTool::class,
        CreateDeadlineTool::class,
        LogCommunicationTool::class,
    ];

    /**
     * `instructions` tiếng Việt qua `lang/vi/mcp.php` (R11). Thuộc tính của lớp cha không gọi được
     * `__()`, nên chuỗi được nạp ở `boot()`. `Server::start()` gọi `boot()` trước khi dựng
     * `ServerContext`, và `createContext()` đọc `$this->instructions` ở mỗi lần xử lý một thông điệp.
     *
     * `tools/call` đi qua {@see CallCrmTool} (Task 6), không qua `CallTool` của gói: chỗ duy nhất mọi
     * lần gọi tool đi qua (kiểm lại quyền ghi R13; audit và rate limit của Task 8).
     */
    protected function boot(): void
    {
        $this->instructions = __('mcp.server.instructions');

        $this->addMethod('tools/call', CallCrmTool::class);
    }
}
