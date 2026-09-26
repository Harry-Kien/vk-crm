<?php

namespace App\Actions\Backup;

use App\Support\Backup\RcloneProcess;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * `vkcrm:backup-check` (M8a Task 2) — kiểm từng đích sao lưu bằng một tác vụ thật, không chỉ đọc
 * cấu hình: ghi một tệp nhỏ, đọc lại so nội dung, xoá — với MỖI disk trong `BACKUP_DISKS`; và với
 * đích rclone (khi `BACKUP_RCLONE_REMOTE` đã cấu hình): đẩy một tệp nhỏ lên, `lsjson` xác nhận có
 * mặt, `deletefile` dọn lại.
 *
 * Nghiệp vụ nằm hẳn ở đây (CLAUDE.md: "Filament resource/controller/job chỉ gọi Action") —
 * `App\Console\Commands\BackupCheckCommand` chỉ định dạng và in kết quả.
 *
 * # Đích, không phải disk
 *
 * `{target?}` của lệnh nhận HOẶC tên một disk trong `BACKUP_DISKS`, HOẶC từ khoá cố định
 * {@see self::RCLONE_TARGET} ("rclone") cho đích Google Drive — vì đích rclone không phải một
 * Laravel filesystem disk, không có tên disk nào để gõ. Bỏ trống `{target}` kiểm HẾT các đích hợp
 * lệ (mọi disk, cộng rclone nếu đã bật).
 */
class CheckBackupDestinations
{
    public const RCLONE_TARGET = 'rclone';

    /**
     * @return array<string, array{ok: bool, message: string}> khoá theo tên đích, giữ THỨ TỰ đã
     *                                                         kiểm (disk trước, rclone sau)
     */
    public function handle(?string $target = null): array
    {
        $disks = config('backup.backup.destination.disks', []);
        $remote = config('vkcrm.backup.rclone.remote');

        $available = $disks;

        if (filled($remote)) {
            $available[] = self::RCLONE_TARGET;
        }

        if ($target !== null && ! in_array($target, $available, true)) {
            return [
                $target => [
                    'ok' => false,
                    'message' => __('backup.check.target_not_found', [
                        'target' => $target,
                        'available' => $available === [] ? '(không có đích nào)' : implode(', ', $available),
                    ]),
                ],
            ];
        }

        $toCheck = $target !== null ? [$target] : $available;

        $results = [];

        foreach ($toCheck as $name) {
            $results[$name] = $name === self::RCLONE_TARGET
                ? $this->checkRclone($remote)
                : $this->checkDisk($name);
        }

        return $results;
    }

    /**
     * So sánh NỘI DUNG đọc lại, không chỉ bắt ngoại lệ khi ghi/đọc — mọi disk local của dự án
     * (`local`, `private`, `local_backups`, xem `config/filesystems.php`) khai `'throw' => false`,
     * nên `Illuminate\Filesystem\FilesystemAdapter::put()`/`get()` NUỐT `UnableToWriteFile`/
     * `UnableToReadFile` và trả về `false`/`null` thay vì ném ra ngoài (xác nhận bằng mutation
     * probe: xoá so sánh nội dung khiến bài test "một disk hỏng khi ghi" — dùng đúng một adapter
     * giả luôn ném lỗi khi ghi — chuyển XANH GIẢ). So `$readBack !== $content` bắt được cả trường
     * hợp "ghi lặng lẽ thất bại" đó, không riêng trường hợp một exception thật sự lọt ra ngoài.
     *
     * @return array{ok: bool, message: string}
     */
    private function checkDisk(string $diskName): array
    {
        $path = '.backup-check/'.Str::uuid()->toString().'.txt';
        $content = 'vkcrm-backup-check-'.Str::random(16);

        try {
            $disk = Storage::disk($diskName);
            $disk->put($path, $content);

            try {
                $readBack = $disk->get($path);
            } finally {
                $disk->delete($path);
            }

            if ($readBack !== $content) {
                throw new \RuntimeException(__('backup.check.content_mismatch'));
            }

            return ['ok' => true, 'message' => __('backup.check.disk_ok', ['disk' => $diskName])];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => __('backup.check.disk_failed', ['disk' => $diskName, 'detail' => $exception->getMessage()]),
            ];
        }
    }

    /** @return array{ok: bool, message: string} */
    private function checkRclone(string $remote): array
    {
        $filename = 'vkcrm-backup-check-'.Str::random(16).'.txt';
        $localPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$filename;

        try {
            file_put_contents($localPath, 'vkcrm-backup-check');

            RcloneProcess::copy($localPath, $remote);

            $found = false;

            foreach (RcloneProcess::listJson($remote) as $entry) {
                if ($entry['name'] === $filename) {
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                throw new \RuntimeException(__('backup.check.rclone_not_found_after_copy'));
            }

            return ['ok' => true, 'message' => __('backup.check.rclone_ok', ['remote' => $remote])];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => __('backup.check.rclone_failed', ['remote' => $remote, 'detail' => $exception->getMessage()]),
            ];
        } finally {
            if (is_file($localPath)) {
                @unlink($localPath);
            }

            // Dọn tệp thử trên remote, cố hết sức — một lượt dọn hỏng không được phép che mất kết
            // quả kiểm tra CHÍNH (đã trả ở trên), nên lỗi ở đây bị nuốt có chủ đích.
            try {
                RcloneProcess::deleteFile(rtrim($remote, '/').'/'.$filename);
            } catch (Throwable) {
                // Bỏ qua có chủ đích — xem lý do ngay trên.
            }
        }
    }
}
