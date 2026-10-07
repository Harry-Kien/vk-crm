<?php

namespace App\Enums;

use App\Support\Mcp\ConsentRequest;

/**
 * Nền tảng AI của một kết nối MCP, SUY TỪ HOST của redirect URI (kế hoạch M11, Task 4 "tên nền tảng
 * suy từ host redirect"; R8 cho nhật ký). Không bao giờ từ `client_name`: tên đó do client tự khai
 * lúc đăng ký động (Task 3), ai cũng đặt được "Claude chính chủ" [DC:139], [DC:635]. Host của
 * redirect thì không giả được: mã uỷ quyền chỉ đi tới đó, và URI phải khớp allowlist chính xác
 * (`App\Support\Mcp\RedirectUriAllowlist`).
 *
 * So host CHÍNH XÁC (không theo hậu tố, nên `evil-claude.ai` hay `claude.ai.evil.example` không phải
 * Claude), sau khi đưa về chữ thường, và chỉ qua `https` — trừ loopback:
 *
 *  - `http` + `localhost` / `127.0.0.1` / `[::1]` → {@see self::LocalApp}. Cổng không nói gì về ứng dụng
 *    (RFC 8252 bỏ qua cổng; Claude Code, VS Code `127.0.0.1:33418`, Cursor `localhost:8787` đều là
 *    loopback, và một chương trình bất kỳ trên máy chọn được cổng đó), nên không đoán tên ứng dụng;
 *  - mọi URI khác (kể cả URI thêm qua `MCP_EXTRA_REDIRECT_URIS`, `http` không loopback, chuỗi không
 *    phân tích được) → {@see self::Other}.
 *
 * Danh sách host theo `config('vkcrm.mcp.redirect_uris')` (R7). Màn hình đồng ý hiện nhãn này CÙNG
 * với chính host ({@see ConsentRequest::displayHost()}), để người đọc tự đối chiếu.
 */
enum McpPlatform: string
{
    case Claude = 'claude';
    case ChatGpt = 'chatgpt';
    case LocalApp = 'local_app';
    case VsCode = 'vscode';
    case Cursor = 'cursor';
    case Antigravity = 'antigravity';
    case Other = 'other';

    /** Ba host loopback của RFC 8252 §7.3, đúng như `RedirectUriAllowlist` nhận. */
    public const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public static function fromRedirectUri(string $uri): self
    {
        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return self::Other;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if ($scheme === 'http' && in_array($host, self::LOOPBACK_HOSTS, true)) {
            return self::LocalApp;
        }

        if ($scheme !== 'https') {
            return self::Other;
        }

        return match ($host) {
            'claude.ai' => self::Claude,
            'chatgpt.com' => self::ChatGpt,
            'vscode.dev' => self::VsCode,
            'www.cursor.com' => self::Cursor,
            'antigravity.google' => self::Antigravity,
            default => self::Other,
        };
    }

    /** Mã uỷ quyền đi tới một cổng trên chính máy đang mở màn hình đồng ý (cảnh báo riêng, [PL:225]). */
    public function isLocal(): bool
    {
        return $this === self::LocalApp;
    }

    public function label(): string
    {
        return __('enums.mcp_platform.'.$this->value);
    }
}
