<?php

namespace App\Actions\Backup;

use App\Exceptions\RcloneCommandFailed;
use App\Models\SystemHealth;
use App\Support\Backup\BackupDisks;
use App\Support\Backup\RcloneArchives;
use App\Support\Backup\RcloneProcess;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;

/**
 * Đẩy archive VỪA sao lưu xong lên Google Drive bằng `rclone copy` (M8a Task 2, Ruling 1 của
 * brief — dùng `rclone`, KHÔNG dùng adapter Flysystem; lý do ở
 * docs/research/2026-09-26-sao-luu.md, mục "Task 2"). Đăng ký làm listener của
 * `Spatie\Backup\Events\BackupWasSuccessful` ở `AppServiceProvider::boot()`, CÙNG THÀNH NGỮ với
 * `GuardBackupEncryption` (đọc docblock của lớp đó).
 *
 * # Vì sao chỉ phản ứng với đúng MỘT disk: `local_backups`
 *
 * `BackupWasSuccessful` bắn MỘT LẦN CHO MỖI DISK trong `BACKUP_DISKS` (xem
 * `Spatie\Backup\Tasks\Backup\BackupJob::copyToBackupDestinations()`). Ruling 2 của brief coi
 * `local_backups` là đĩa TRUNG CHUYỂN — nếu văn phòng cấu hình thêm disk khác trong `BACKUP_DISKS`
 * (ví dụ một disk SFTP văn phòng ở Task 3), sự kiện của NHỮNG disk đó bị bỏ qua ở đây: đẩy rclone
 * một lần duy nhất, không nhân lên theo số disk đã cấu hình, và {@see PruneLocalBackupsDisk} chỉ
 * dọn ĐÚNG disk mà nó vừa xác minh đã lên remote — dọn một disk khác dựa trên việc xác minh disk
 * này là dọn nhầm.
 *
 * # Cách tìm "archive vừa tạo"
 *
 * `BackupWasSuccessful` không mang đường dẫn tệp, chỉ mang `diskName`/`backupName` (thư mục con).
 * Lấy tệp MỚI NHẤT theo `lastModified()` trong thư mục đó — đúng lúc sự kiện này bắn, tệp vừa ghi
 * xong luôn là tệp mới nhất (đồng bộ, không tiến trình nào khác ghi xen vào cùng lúc).
 *
 * Không tìm thấy tệp nào (fix M4, lượt rà soát cuối M8a) là một lượt đẩy HỎNG, không phải "không có
 * gì để làm": gói vừa báo sao lưu thành công lên chính disk này, nên thiếu archive nghĩa là đêm nay
 * không có bản sao ngoài máy chủ. Báo lỗi theo cùng đường với một `rclone copy` thất bại — bản
 * trước `return` lặng lẽ ở đây.
 *
 * # Đích: thư mục RIÊNG của môi trường
 *
 * Archive đi vào `{BACKUP_RCLONE_REMOTE}/{slug(backupName)}` ({@see RcloneArchives::folder()}, fix
 * I3, lượt rà soát cuối M8a), không vào gốc remote: production và staging dùng chung một Google
 * Drive thì mỗi bên có thư mục của mình, và lượt xác minh + lượt dọn ở dưới làm việc trên ĐÚNG thư
 * mục vừa đẩy vào.
 *
 * # Thất bại: báo lỗi NÊU TÊN REMOTE, không phải tên disk
 *
 * Brief: "naming the remote rather than a disk". `BackupHasFailed` nhận một `diskName` tự do —
 * dùng tiền tố `rclone:` (`rclone:{$remote}`) để người đọc thư phân biệt ngay đây KHÔNG PHẢI một
 * trong các disk ở `BACKUP_DISKS`, mà là đích rclone. Không throw ra ngoài: đây là một sự kiện,
 * không callback nào chờ giá trị trả về của nó, và ném lỗi ở đây sẽ làm CHẾT CẢ TIẾN TRÌNH
 * `backup:run` — một lượt sao lưu cục bộ đã thành công không được phép biến thành một lỗi 500 của
 * scheduler chỉ vì Google Drive tạm thời không nối được (brief: "never throws out of the
 * scheduler").
 *
 * # Xác minh trước khi dọn — cả remote lẫn máy chủ
 *
 * `rclone copy` thoát mã 0 không đủ để tin: {@see verify()} đọc lại `rclone lsjson` và so khớp TÊN
 * VÀ DUNG LƯỢNG trước khi coi lượt đẩy là thật sự xong. Xác minh thất bại đi CÙNG ĐƯỜNG báo lỗi
 * với một lượt `copy` thất bại, và — brief Ruling 2 — "never prune locally when the push failed":
 * không {@see PruneRcloneRemoteBackups} lẫn {@see PruneLocalBackupsDisk} nào chạy.
 *
 * Dọn REMOTE thất bại (ví dụ `deletefile` một bản cũ bị treo) VẪN báo lỗi theo cùng đường, nhưng
 * KHÔNG chặn dọn máy chủ phía sau: bản MỚI đã lên remote và đã xác minh — tài sản quan trọng nhất
 * (bản mới) đã an toàn, dọn máy chủ (Ruling 2) không phụ thuộc việc dọn xong các bản CŨ trên remote
 * hay chưa. Lượt chạy `backup:clean`+`backup:run` hôm sau sẽ thử dọn lại phần remote còn sót.
 */
class PushBackupArchiveToRclone
{
    public function handle(BackupWasSuccessful $event): void
    {
        if ($event->diskName !== BackupDisks::DEFAULT_DISK) {
            return;
        }

        $remote = config('vkcrm.backup.rclone.remote');

        if (blank($remote)) {
            return;
        }

        $disk = Storage::disk($event->diskName);
        $archive = $this->newestArchive($disk, $event->backupName);

        if ($archive === null) {
            $this->reportFailure($remote, $event->backupName, __('backup.errors.rclone_archive_missing', [
                'disk' => $event->diskName,
                'folder' => $event->backupName,
            ]));

            return;
        }

        $absolutePath = $disk->path($archive);
        $remoteFolder = RcloneArchives::folder($remote, $event->backupName);

        try {
            RcloneProcess::copy($absolutePath, $remoteFolder);
            $this->verify($remoteFolder, basename($archive), $absolutePath);
        } catch (RcloneCommandFailed $exception) {
            $this->reportFailure($remote, $event->backupName, $exception->getMessage());

            return;
        }

        // Làn fc (kiểm tra nghiệp vụ 2026-10-09): mốc của bản ngoài máy chủ gần nhất ĐÃ XÁC MINH — dải
        // sức khoẻ trên trang chủ `/admin` báo đỏ khi nó quá cũ, kể cả khi thư báo lỗi không đi được.
        // Chỉ ghi ở đây, sau `verify()`: một `copy` thoát 0 mà remote không có tệp không phải bản sao.
        SystemHealth::current()->forceFill(['last_offsite_backup_at' => now()])->save();

        try {
            app(PruneRcloneRemoteBackups::class)->handle($remoteFolder);
        } catch (RcloneCommandFailed $exception) {
            $this->reportFailure($remote, $event->backupName, $exception->getMessage());
        }

        app(PruneLocalBackupsDisk::class)->handle($disk, $event->backupName);
    }

    private function newestArchive(Filesystem $disk, string $folder): ?string
    {
        $files = $disk->files($folder);

        if ($files === []) {
            return null;
        }

        usort($files, static fn (string $a, string $b): int => $disk->lastModified($b) <=> $disk->lastModified($a));

        return $files[0];
    }

    /** @throws RcloneCommandFailed */
    private function verify(string $remoteFolder, string $filename, string $absolutePath): void
    {
        $expectedSize = @filesize($absolutePath);

        if ($expectedSize === false) {
            throw RcloneCommandFailed::verificationFailed($filename);
        }

        foreach (RcloneProcess::listJson($remoteFolder) as $entry) {
            if ($entry['name'] === $filename && $entry['size'] === $expectedSize) {
                return;
            }
        }

        throw RcloneCommandFailed::verificationFailed($filename);
    }

    private function reportFailure(string $remote, string $backupName, string $message): void
    {
        event(new BackupHasFailed(new RuntimeException($message), "rclone:{$remote}", $backupName));
    }
}
