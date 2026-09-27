<?php

namespace App\Actions\Backup;

use App\Exceptions\RcloneCommandFailed;
use App\Support\Backup\RcloneArchives;
use App\Support\Backup\RcloneProcess;
use RuntimeException;
use Spatie\Backup\Events\BackupHasFailed;

/**
 * Giám sát độ TƯƠI của bản sao trên đích rclone (SPEC §10 mục 8; fix I4, lượt rà soát cuối M8a).
 * Chạy lúc 08:00 cùng `backup:monitor` (`routes/console.php`, tác vụ `backup.monitor`, qua
 * `->then()` — chạy cả khi `backup:monitor` thất bại).
 *
 * `backup:monitor` của gói chỉ giám sát các đĩa Laravel trong `BACKUP_DISKS`; nó không biết đích
 * rclone tồn tại. Nếu lượt đẩy lên Google Drive hỏng lặng lẽ nhiều đêm liền (thư báo lỗi bị bỏ
 * qua, tiến trình bị giết trước khi kịp báo), Google Drive dừng ở một bản cũ mà không ai biết. Lớp
 * này đòi bản MỚI NHẤT trong thư mục của môi trường ({@see RcloneArchives::folder()}) dưới
 * `vkcrm.backup.rclone.max_age_hours` giờ tuổi (36 — lượt 02:00 của đêm qua cộng một biên), tính
 * theo mốc thời gian trong TÊN tệp, không theo `ModTime` (một bản cũ tải lên lại không được coi là
 * tươi — cùng lý lẽ với {@see PruneRcloneRemoteBackups}).
 *
 * Mọi trục trặc — không liệt kê được remote, thư mục không có archive nào của môi trường này, bản
 * mới nhất quá cũ — đi CÙNG ĐƯỜNG báo lỗi với mọi lỗi rclone khác: `BackupHasFailed` nêu
 * `rclone:{remote}` ({@see PushBackupArchiveToRclone}). Không ném lỗi ra scheduler.
 *
 * Remote trống: không làm gì — việc "không có bản sao ngoài máy chủ" do
 * {@see GuardOffServerBackupDestination} báo mỗi đêm ở production.
 */
class CheckRcloneRemoteFreshness
{
    public function handle(): void
    {
        $remote = config('vkcrm.backup.rclone.remote');

        if (blank($remote)) {
            return;
        }

        $backupName = (string) config('backup.backup.name');
        $prefix = (string) config('backup.backup.destination.filename_prefix');
        $folder = RcloneArchives::folder($remote, $backupName);

        try {
            $archives = RcloneArchives::newestFirst(RcloneProcess::listJson($folder), $prefix);
        } catch (RcloneCommandFailed $exception) {
            $this->report($remote, $backupName, $exception->getMessage());

            return;
        }

        if ($archives === []) {
            $this->report($remote, $backupName, __('backup.errors.rclone_remote_empty', ['folder' => $folder]));

            return;
        }

        $maxAgeHours = (int) config('vkcrm.backup.rclone.max_age_hours', 36);
        $newest = $archives[0]['name'];
        $createdAt = RcloneArchives::createdAt($newest, $prefix);

        if ($createdAt->greaterThan(now()->subHours($maxAgeHours))) {
            return;
        }

        $this->report($remote, $backupName, __('backup.errors.rclone_remote_stale', [
            'file' => $newest,
            'hours' => (int) $createdAt->diffInHours(now(), absolute: true),
            'max' => $maxAgeHours,
        ]));
    }

    private function report(string $remote, string $backupName, string $message): void
    {
        event(new BackupHasFailed(new RuntimeException($message), "rclone:{$remote}", $backupName));
    }
}
