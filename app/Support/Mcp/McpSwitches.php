<?php

namespace App\Support\Mcp;

use App\Models\Setting;

/**
 * Hai công tắc TOÀN HỆ THỐNG của máy chủ MCP (M11 R2), lưu trong bảng `settings` của M7 Task 10:
 *
 *  - `mcp.enabled`: tắt thì mọi request `/mcp` nhận 401 ngay ở request kế tiếp
 *    (`EnsureMcpAccess`), đăng ký client động từ chối (`RegisterClientController`) và CIMD tắt
 *    (`ResolveClientIdMetadataDocument::enabled()`);
 *  - `mcp.write_enabled`: tắt thì bốn tool ghi không được đăng ký và không chạy cho ai, kể cả người
 *    `read_write` (R13, {@see McpAccess::canWrite()}). Chỉ có nghĩa khi `mcp.enabled` cũng bật.
 *
 * **Mặc định TẮT cả hai** [DC:142]: một công tắc chỉ bật khi dòng của nó mang ĐÚNG chuỗi
 * {@see self::ON}. Dòng vắng mặt, `null`, `'0'`, `'true'`, `' 1'` — mọi thứ khác — là tắt: một giá
 * trị gõ sai phải đóng cửa, không mở cửa.
 *
 * Ghi qua `WriteSettings` (bước ghi chung) dưới một Action hỏi quyền và ghi audit — Action đó và màn
 * hình "Kết nối AI" là của Task 15 (test hôm nay ghi thẳng qua `WriteSettings`). Lớp này chỉ ĐỌC, và
 * đọc lại CSDL ở mỗi lần hỏi — không nhớ gì qua hai request, nên tắt công tắc có hiệu lực ngay ở
 * request kế tiếp.
 */
final class McpSwitches
{
    public const ENABLED = 'mcp.enabled';

    public const WRITE_ENABLED = 'mcp.write_enabled';

    /** Giá trị DUY NHẤT nghĩa là "bật". */
    public const ON = '1';

    /** Giá trị "tắt" mà màn hình lưu (`WriteSettings` coi chuỗi rỗng là "chưa đặt", nên dùng `'0'`). */
    public const OFF = '0';

    public static function enabled(): bool
    {
        return self::stored()[self::ENABLED] ?? false;
    }

    /** Ghi được qua MCP: cả `mcp.enabled` lẫn `mcp.write_enabled` đều bật. */
    public static function writeEnabled(): bool
    {
        $stored = self::stored();

        return ($stored[self::ENABLED] ?? false) && ($stored[self::WRITE_ENABLED] ?? false);
    }

    /** @return array<string, bool> khoá → bật hay không; khoá vắng mặt là tắt */
    private static function stored(): array
    {
        return Setting::query()
            ->whereIn('key', [self::ENABLED, self::WRITE_ENABLED])
            ->pluck('value', 'key')
            ->map(fn (mixed $value): bool => $value === self::ON)
            ->all();
    }
}
