<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;

/**
 * Tập mã nguồn của máy chủ MCP (M11) mà các phép quét cấu trúc đọc, và cách đọc nó — dùng chung cho
 * hai ranh giới "không bao giờ qua MCP":
 *  - tiếp nhận (M10 R7d, `tests/Feature/Intake/IntakeMcpBoundaryTest.php`);
 *  - tiền của vụ việc (SPEC §5 "Mang sang M11" của M9, `tests/Feature/Mcp/MoneyMcpBoundaryTest.php`).
 *
 * Một danh sách duy nhất, để hai phép quét không thể lệch nhau: thư mục MCP mới chỉ cần thêm ở
 * {@see self::roots()} (cả hai test khẳng định danh sách và tiền đề "mỗi thư mục có tệp").
 *
 * {@see self::texts()} trả phần MÃ của một tệp, không chú thích: với PHP là các token khác chú thích,
 * docblock và khoảng trắng (mỗi token kèm loại của nó); với Blade là toàn bộ văn bản sau khi bỏ chú
 * thích `{{-- … --}}` — bộ tách token của PHP coi `{{ $x->y }}` là HTML, nên Blade quét cả HTML.
 */
final class McpSourceScan
{
    /** Loại "token" của văn bản Blade (không phải hằng `T_*` nào của PHP). */
    public const BLADE = -1;

    /** @return list<string> Thư mục chứa mã MCP của M11 (tool, presenter, Action đọc/ghi, HTTP, view). */
    public static function roots(): array
    {
        return [
            app_path('Mcp'),
            app_path('Support/Mcp'),
            app_path('Actions/Mcp'),
            app_path('Http/Middleware/Mcp'),
            app_path('Http/Controllers/Mcp'),
            app_path('Http/Responses/Mcp'),
            resource_path('views/mcp'),
        ];
    }

    /**
     * Tệp MCP nằm NGOÀI các thư mục trên: tệp đặt tên cụ thể, cộng mọi tệp PHP trong `app/` có `Mcp`
     * trong tên (enum, exception, model, policy, lệnh, thông báo…).
     *
     * @return list<string>
     */
    public static function files(): array
    {
        $files = [
            base_path('routes/ai.php'),
            config_path('mcp.php'),
            app_path('Filament/Admin/Pages/AiConnections.php'),
            app_path('Filament/Admin/Pages/MyAiConnections.php'),
            resource_path('views/filament/admin/pages/ai-connections.blade.php'),
            resource_path('views/filament/admin/pages/my-ai-connections.blade.php'),
            resource_path('views/filament/admin/ai-drafts.blade.php'),
        ];

        foreach ((new Filesystem)->allFiles(app_path()) as $file) {
            if ($file->getExtension() === 'php' && str_contains($file->getFilename(), 'Mcp')) {
                $files[] = $file->getPathname();
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Tệp được quét: mọi tệp `.php` (kể cả `.blade.php`) dưới các thư mục, cộng các tệp đặt tên.
     *
     * @param  list<string>  $roots
     * @param  list<string>  $files
     * @return list<string>
     */
    public static function scannedFiles(array $roots, array $files = []): array
    {
        $scanned = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach ((new Filesystem)->allFiles($root) as $file) {
                if ($file->getExtension() === 'php') {
                    $scanned[] = $file->getPathname();
                }
            }
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                $scanned[] = $file;
            }
        }

        return array_values(array_unique($scanned));
    }

    /**
     * Phần mã của một tệp, không chú thích.
     *
     * @return list<array{0: int, 1: string}> cặp (loại token `T_*`, hoặc {@see self::BLADE}; văn bản)
     */
    public static function texts(string $path): array
    {
        $source = (string) file_get_contents($path);

        if (str_ends_with($path, '.blade.php')) {
            return [[self::BLADE, (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source)]];
        }

        $texts = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                $texts[] = [$token[0], $token[1]];
            }
        }

        return $texts;
    }

    /** Đường dẫn tương đối với gốc dự án, dấu `/`, để in trong thông điệp của test. */
    public static function shown(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }
}
