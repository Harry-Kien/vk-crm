<?php

namespace App\Support\Mcp;

use App\Actions\Mcp\UpdateAiSettings;
use App\Models\Setting;
use Carbon\CarbonImmutable;

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
 * Cùng bảng còn một dòng không phải công tắc: {@see self::TRANSFER_ASSESSMENT_FILED_ON}, ngày quản trị
 * ghi đã nộp hồ sơ đánh giá tác động chuyển dữ liệu xuyên biên giới (R12 mục 3). Nó chỉ tắt dải cảnh
 * báo của trang "Kết nối AI", không mở hay đóng gì của máy chủ.
 *
 * Ghi qua {@see UpdateAiSettings} (hỏi `settings.manage`, ghi audit, dựng trên bước ghi chung
 * `WriteSettings`); màn hình là trang "Kết nối AI" (Task 15). Lớp này chỉ ĐỌC, và đọc lại CSDL ở mỗi
 * lần hỏi — không nhớ gì qua hai request, nên tắt công tắc có hiệu lực ngay ở request kế tiếp.
 */
final class McpSwitches
{
    public const ENABLED = 'mcp.enabled';

    public const WRITE_ENABLED = 'mcp.write_enabled';

    /** Giá trị DUY NHẤT nghĩa là "bật". */
    public const ON = '1';

    /** Giá trị "tắt" mà màn hình lưu (`WriteSettings` coi chuỗi rỗng là "chưa đặt", nên dùng `'0'`). */
    public const OFF = '0';

    /** Ngày đã nộp hồ sơ đánh giá tác động (R12 mục 3), dạng `Y-m-d`; vắng mặt = chưa nộp. */
    public const TRANSFER_ASSESSMENT_FILED_ON = 'mcp.transfer_assessment_filed_on';

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

    /**
     * Giá trị ĐÃ LƯU của riêng công tắc `mcp.write_enabled`, không xét `mcp.enabled` — để màn hình
     * hiện đúng điều quản trị đã chọn. Hỏi "ghi được không" thì dùng {@see self::writeEnabled()}.
     */
    public static function writeSwitchOn(): bool
    {
        return self::stored()[self::WRITE_ENABLED] ?? false;
    }

    /**
     * Ngày quản trị ghi đã nộp hồ sơ đánh giá tác động (R12 mục 3), hoặc `null` khi chưa ghi — hay khi
     * giá trị lưu không phải đúng dạng `Y-m-d` của một ngày có thật: dải cảnh báo thà hiện thừa còn
     * hơn tắt vì một giá trị hỏng.
     */
    public static function transferAssessmentFiledOn(): ?CarbonImmutable
    {
        $value = Setting::query()->where('key', self::TRANSFER_ASSESSMENT_FILED_ON)->value('value');

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
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
