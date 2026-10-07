<?php

namespace App\Support\Backup;

use App\Exceptions\RcloneCommandFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Một lớp bọc mỏng quanh `Illuminate\Support\Facades\Process` cho ba lệnh `rclone` mà M8a Task 2
 * dùng: `copy`, `lsjson`, `deletefile`. Không mang quyết định nghiệp vụ nào (thứ đó nằm ở
 * `App\Actions\Backup\*`) — lớp này chỉ biết dựng đúng dòng lệnh và đọc kết quả, để cả Action ĐẨY
 * archive lẫn lệnh `vkcrm:backup-check` dùng chung MỘT chỗ gọi `rclone`, không lặp lại cách dựng
 * `--config`/hạn giờ ở hai nơi.
 *
 * Test bằng `Illuminate\Support\Facades\Process::fake()` — không cần binary `rclone` thật (brief
 * Task 2: "tests fake Process; no real rclone is needed").
 *
 * KHÔNG BAO GIỜ truyền `-v`/`-vv`: xem lý do ở docblock của {@see RcloneCommandFailed}.
 *
 * M14 Task 7 thêm `cat` (đọc một tệp biên nhận của máy văn phòng) và tham số `$timeout` tuỳ chọn cho
 * `listJson`/`cat`: lượt nhập biên nhận dùng 120 giây (`vkcrm.storage.office.rclone_timeout`), không
 * 1800 của sao lưu. Bỏ tham số thì giữ hạn {@see self::timeout()}, nên các nơi gọi của M8a không đổi.
 * Mã ngoài sao lưu M8a chỉ được gọi `listJson` và `cat` (test cấu trúc `OfficeCopyStructureTest`).
 */
class RcloneProcess
{
    public static function binary(): string
    {
        return (string) config('vkcrm.backup.rclone.binary', 'rclone');
    }

    public static function timeout(): int
    {
        return (int) config('vkcrm.backup.rclone.timeout', 1800);
    }

    public static function configPath(): ?string
    {
        return config('vkcrm.backup.rclone.config_path');
    }

    public static function copy(string $localPath, string $remoteDestination): void
    {
        self::run(['copy', $localPath, $remoteDestination]);
    }

    public static function deleteFile(string $remoteFilePath): void
    {
        self::run(['deletefile', $remoteFilePath]);
    }

    /**
     * Liệt kê một thư mục remote (`rclone lsjson`), bỏ qua thư mục con — Task 2 chỉ cần tệp
     * archive nằm phẳng trong remote. Sắp theo `ModTime` MỚI NHẤT trước, dùng
     * `Illuminate\Support\Carbon::parse()` (không so sánh CHUỖI trực tiếp): rclone không đảm bảo
     * cùng số chữ số thập phân giữa các backend, và so sánh chuỗi có thể xếp sai thứ tự khi độ dài
     * phần thập phân khác nhau.
     *
     * @return list<array{name: string, size: int, modTime: string}>
     *
     * @throws RcloneCommandFailed
     */
    public static function listJson(string $remote, ?int $timeout = null): array
    {
        $output = self::run(['lsjson', $remote], $timeout);

        $decoded = json_decode(trim($output) === '' ? '[]' : $output, true);

        if (! is_array($decoded)) {
            throw RcloneCommandFailed::invalidOutput();
        }

        $entries = [];

        foreach ($decoded as $entry) {
            if (! is_array($entry) || ($entry['IsDir'] ?? false) === true) {
                continue;
            }

            $name = (string) ($entry['Name'] ?? '');

            if ($name === '') {
                continue;
            }

            $entries[] = [
                'name' => $name,
                'size' => (int) ($entry['Size'] ?? -1),
                'modTime' => (string) ($entry['ModTime'] ?? ''),
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            return Carbon::parse($b['modTime'])->getTimestamp() <=> Carbon::parse($a['modTime'])->getTimestamp();
        });

        return $entries;
    }

    /**
     * Nội dung của MỘT tệp remote (`rclone cat`), nguyên văn. Cả nội dung nằm trong bộ nhớ: người gọi
     * phải tự chặn cỡ trước (lượt nhập biên nhận kiểm cỡ từ `listJson()` trước khi đọc).
     *
     * @throws RcloneCommandFailed
     */
    public static function cat(string $remoteFilePath, ?int $timeout = null): string
    {
        return self::run(['cat', $remoteFilePath], $timeout);
    }

    /** @param  list<string>  $arguments */
    private static function run(array $arguments, ?int $timeout = null): string
    {
        $configPath = self::configPath();
        $command = $configPath !== null
            ? [self::binary(), '--config', $configPath, ...$arguments]
            : [self::binary(), ...$arguments];

        try {
            $result = Process::timeout($timeout ?? self::timeout())->run($command);
        } catch (Throwable $exception) {
            throw RcloneCommandFailed::fromThrowable($exception);
        }

        if (! $result->successful()) {
            $errorOutput = method_exists($result, 'errorOutput') ? $result->errorOutput() : '';

            throw RcloneCommandFailed::fromExitCode($result->exitCode(), $errorOutput !== '' ? $errorOutput : $result->output());
        }

        return $result->output();
    }
}
