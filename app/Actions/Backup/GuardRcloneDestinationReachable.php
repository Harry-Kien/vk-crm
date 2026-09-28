<?php

namespace App\Actions\Backup;

use App\Support\Backup\BackupDisks;
use RuntimeException;
use Spatie\Backup\Events\BackupHasFailed;

/**
 * M8a Task 2, vòng rà soát 1, fix I2 (phần "consider" của brief) — bắt cấu hình "đẩy sẽ không bao
 * giờ chạy" NGAY TỪ ĐÊM ĐẦU TIÊN, không chỉ khi có người chạy `vkcrm:backup-check` tình cờ.
 *
 * {@see PushBackupArchiveToRclone} chỉ phản ứng với sự kiện `BackupWasSuccessful` của đúng disk
 * {@see BackupDisks::DEFAULT_DISK} (`local_backups`, xem docblock ở đó). Nếu `BACKUP_RCLONE_REMOTE`
 * đã cấu hình nhưng `local_backups` bị bỏ ra khỏi `BACKUP_DISKS`, sự kiện đó KHÔNG BAO GIỜ bắn với
 * đúng tên disk — lượt đẩy lên Google Drive lặng lẽ không chạy, và không ai biết cho tới khi tình
 * cờ chạy `vkcrm:backup-check` (fix I2 phần bắt buộc, ở `CheckBackupDestinations::checkRclone()`).
 *
 * Đăng ký ở `AppServiceProvider::boot()` nghe `Spatie\Backup\Events\BackupManifestWasCreated` —
 * CÙNG sự kiện với `GuardBackupEncryption` (fires một lần, gần đầu `BackupJob::run()`) — nhưng
 * KHÔNG NÉM LỖI: đây là một cấu hình đáng báo, không phải một lý do để chặn bản sao lưu CỤC BỘ
 * đêm nay (bản đó vẫn hoàn toàn dùng được, chỉ riêng bản sao ngoài văn phòng là chưa có). Gọi
 * thẳng `event(new BackupHasFailed(...))` — CÙNG ĐƯỜNG báo lỗi với mọi lỗi rclone khác (nêu tên
 * remote, tiền tố `rclone:`, xem {@see PushBackupArchiveToRclone::reportFailure()}) — để văn
 * phòng nhận đúng MỘT loại thư cho mọi trục trặc liên quan tới Google Drive, không phải hai loại
 * khác nhau tuỳ lỗi xảy ra ở đâu.
 *
 * Lặp lại MỖI ĐÊM cho tới khi văn phòng sửa `BACKUP_DISKS` — có chủ đích, cùng triết lý với
 * `backup:monitor` (giám sát sức khoẻ) đã có: một cấu hình sai chưa sửa xứng đáng một lời nhắc
 * mỗi ngày, không phải một lần rồi im lặng.
 */
class GuardRcloneDestinationReachable
{
    public function handle(): void
    {
        $remote = config('vkcrm.backup.rclone.remote');

        if (blank($remote)) {
            return;
        }

        $disks = config('backup.backup.destination.disks', []);

        if (in_array(BackupDisks::DEFAULT_DISK, $disks, true)) {
            return;
        }

        event(new BackupHasFailed(
            new RuntimeException(__('backup.errors.rclone_push_never_runs', [
                'disk' => BackupDisks::DEFAULT_DISK,
            ])),
            "rclone:{$remote}",
            (string) config('backup.backup.name'),
        ));
    }
}
