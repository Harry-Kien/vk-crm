<?php

namespace Tests\Support;

use App\Support\Storage\GoogleDrive\DriveObjectName;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * M14 Task 7 — biên nhận bản thứ hai của máy văn phòng (kế hoạch R10) và một `rclone` giả.
 *
 * Lớp tĩnh chứ không hàm Pest toàn cục (cùng lý do với {@see DocumentStoreFixtures}): hai tệp test
 * cùng khai một hàm toàn cục thì bộ test song song vỡ ở tệp nạp sau.
 *
 * `rclone` giả trả lời đúng hai lệnh mà lượt nhập biên nhận được phép dùng — `lsjson` thư mục biên
 * nhận và `cat` một tệp — và ghi lại argv của MỌI lời gọi vào {@see self::$commands}, để test cấu
 * trúc đọc lại được (không lời gọi nào mang `sync`, `move`, `purge`, `deletefile`, …). Lệnh khác thì
 * thất bại, nên một lời gọi ngoài dự kiến làm test đỏ chứ không lặng lẽ "thành công".
 */
final class OfficeReceiptFixtures
{
    public const RECEIPTS_PATH = 'gdrive:VK-CRM-backups/office-receipts/vk-crm-test';

    /** @var list<list<string>> argv của mọi lời gọi `rclone` từ lúc {@see self::fakeRclone()} */
    public static array $commands = [];

    /** Cấu hình đủ để lượt nhập chạy: remote biên nhận + Shared Drive và thư mục gốc giả. */
    public static function configure(): void
    {
        config([
            'vkcrm.storage.office.receipts_path' => self::RECEIPTS_PATH,
            'vkcrm.storage.google_drive.shared_drive_id' => FakeGoogleDrive::DRIVE_ID,
            'vkcrm.storage.google_drive.root_folder_id' => FakeGoogleDrive::ROOT_FOLDER_ID,
        ]);
    }

    /**
     * @param  array<string, string>  $files  tên tệp trên remote => nội dung
     * @param  array<string, int>  $sizes  cỡ mà `lsjson` khai, ghi đè cỡ thật của nội dung
     * @param  list<string>  $failCat  tên tệp mà `rclone cat` thất bại
     */
    public static function fakeRclone(array $files, array $sizes = [], bool $failList = false, array $failCat = []): void
    {
        self::$commands = [];

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($files, $sizes, $failList, $failCat) {
            $command = array_values((array) $process->command);
            self::$commands[] = $command;

            if (in_array('lsjson', $command, true)) {
                if ($failList) {
                    return Process::result(errorOutput: 'Failed to lsjson: directory not found', exitCode: 3);
                }

                $entries = [['Path' => 'cu', 'Name' => 'cu', 'Size' => -1, 'ModTime' => '2026-10-01T00:00:00Z', 'IsDir' => true]];

                foreach ($files as $name => $content) {
                    $entries[] = [
                        'Path' => $name,
                        'Name' => $name,
                        'Size' => $sizes[$name] ?? strlen($content),
                        'ModTime' => '2026-10-07T00:00:00Z',
                        'IsDir' => false,
                    ];
                }

                return Process::result(output: json_encode($entries));
            }

            if (in_array('cat', $command, true)) {
                $name = basename((string) end($command));

                if (in_array($name, $failCat, true) || ! array_key_exists($name, $files)) {
                    return Process::result(errorOutput: 'Failed to cat: object not found', exitCode: 3);
                }

                return Process::result(output: $files[$name]);
            }

            return Process::result(errorOutput: 'lệnh rclone ngoài dự kiến', exitCode: 99);
        });
    }

    /** @return list<string> tên tệp mà `rclone cat` đã đọc, theo thứ tự */
    public static function catted(): array
    {
        return array_values(array_map(
            fn (array $command) => basename((string) end($command)),
            array_filter(self::$commands, fn (array $command) => in_array('cat', $command, true)),
        ));
    }

    /** Tên tệp biên nhận đúng khuôn của `office-pull.sh` (`receipt-<UTC>.json`). */
    public static function fileName(string $utc = '20261007T010000Z'): string
    {
        return "receipt-{$utc}.json";
    }

    /** Khoá thư viện media hợp lệ (khuôn R4). */
    public static function key(int $mediaId = 1834, string $extension = '.pdf'): string
    {
        return $mediaId.'/'.strtolower((string) Str::ulid()).$extension;
    }

    /** @return array{name: string, md5: string, size: int} một dòng của danh sách `files` */
    public static function line(string $key, string $md5, int $size = 1024, int $generation = 1): array
    {
        return ['name' => DriveObjectName::fromKey($key, $generation), 'md5' => $md5, 'size' => $size];
    }

    /**
     * Một biên nhận JSON hợp lệ của Shared Drive và thư mục gốc giả; `$overrides` ghi đè từng khoá
     * cấp đầu (`kho` thay cả khối).
     *
     * @param  list<array<string, mixed>>  $files
     */
    public static function receipt(array $files, array $overrides = []): string
    {
        return json_encode(array_merge([
            'format' => 1,
            'kho' => [
                'team_drive' => FakeGoogleDrive::DRIVE_ID,
                'root_folder_id' => FakeGoogleDrive::ROOT_FOLDER_ID,
            ],
            'started_at' => now()->subHours(6)->utc()->format('Y-m-d\TH:i:s\Z'),
            'finished_at' => now()->subHours(5)->utc()->format('Y-m-d\TH:i:s\Z'),
            'errors' => 0,
            'files' => $files,
        ], $overrides), JSON_UNESCAPED_SLASHES);
    }
}
