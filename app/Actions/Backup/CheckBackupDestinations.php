<?php

namespace App\Actions\Backup;

use App\Support\Backup\BackupDisks;
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
                ? $this->checkRclone($remote, $disks)
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

    /**
     * @param  list<string>  $disks  `config('backup.backup.destination.disks')` — tham số, không
     *                               đọc lại `config()` ở đây, để `handle()` là nơi DUY NHẤT quyết
     *                               định danh sách disk cho MỘT lượt gọi (tránh đọc hai giá trị
     *                               lệch nhau nếu ai đó gọi lại `checkRclone()` sau khi cấu hình
     *                               đã đổi giữa chừng).
     *
     * # Fix I2, vòng rà soát 1 — phát hiện SỚM cấu hình "đẩy sẽ không bao giờ chạy"
     *
     * {@see PushBackupArchiveToRclone} CHỈ phản ứng với sự kiện của disk
     * {@see BackupDisks::DEFAULT_DISK} (`local_backups`). Nếu văn phòng bật
     * `BACKUP_RCLONE_REMOTE` nhưng lại bỏ `local_backups` ra khỏi `BACKUP_DISKS` (đổi ý, gõ nhầm,
     * hoặc dọn dẹp `.env` không cẩn thận), lượt đẩy lên Google Drive mỗi đêm KHÔNG BAO GIỜ chạy —
     * và không lỗi nào xuất hiện, vì bản thân `rclone` chưa từng được gọi. Không có dòng kiểm
     * TRƯỚC bên dưới, `vkcrm:backup-check rclone` vẫn tự đẩy/tự xác nhận được MỘT TỆP THỬ (không
     * đi qua `PushBackupArchiveToRclone`) và báo "OK" — đúng kiểu báo cáo sai mà brief cảnh báo:
     * "vkcrm:backup-check still reports the remote OK". Kiểm điều kiện này TRƯỚC, không tốn một
     * lệnh `rclone` nào nếu đã sai cấu hình.
     * @return array{ok: bool, message: string}
     */
    private function checkRclone(string $remote, array $disks): array
    {
        if (! in_array(BackupDisks::DEFAULT_DISK, $disks, true)) {
            return [
                'ok' => false,
                'message' => __('backup.check.rclone_failed', [
                    'remote' => $remote,
                    'detail' => __('backup.errors.rclone_push_never_runs', ['disk' => BackupDisks::DEFAULT_DISK]),
                ]),
            ];
        }

        $filename = 'vkcrm-backup-check-'.Str::random(16).'.txt';
        $localPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$filename;

        /*
         * Fix I1, vòng rà soát 1 — tệp thử đi vào một THƯ MỤC CON RIÊNG (`.backup-check`), KHÔNG
         * BAO GIỜ vào gốc remote nơi archive thật nằm. `checkDisk()` ở trên đã làm đúng việc này
         * cho disk local (`'.backup-check/'.Str::uuid()...`) — trước fix này, `checkRclone()` lại
         * đẩy thẳng vào gốc, cùng chỗ với archive: `PruneRcloneRemoteBackups::handle()` liệt kê
         * MỌI tệp phẳng trong gốc, nên một tệp thử kẹt lại (dọn hỏng, tiến trình bị ngắt giữa
         * chừng) chiếm một trong 30 suất giữ lại. Tách thư mục xong, `PruneRcloneRemoteBackups`
         * gọi `lsjson` trên GỐC (không đệ quy vào `.backup-check`) nên không bao giờ thấy tệp thử,
         * và bộ lọc tên archive ở đó là lớp phòng thủ THỨ HAI, độc lập với việc tách thư mục này.
         */
        $remoteProbeDir = rtrim($remote, '/').'/.backup-check';

        try {
            file_put_contents($localPath, 'vkcrm-backup-check');

            RcloneProcess::copy($localPath, $remoteProbeDir);

            $found = false;

            foreach (RcloneProcess::listJson($remoteProbeDir) as $entry) {
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
                RcloneProcess::deleteFile($remoteProbeDir.'/'.$filename);
            } catch (Throwable) {
                // Bỏ qua có chủ đích — xem lý do ngay trên.
            }
        }
    }
}
