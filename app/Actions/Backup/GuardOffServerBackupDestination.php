<?php

namespace App\Actions\Backup;

use RuntimeException;
use Spatie\Backup\Events\BackupHasFailed;

/**
 * SPEC §10 mục 8 — bản sao lưu phải ra KHỎI máy chủ. Fix I4, lượt rà soát cuối M8a: ở
 * `production`, khi `BACKUP_RCLONE_REMOTE` để trống VÀ mọi đĩa trong `BACKUP_DISKS` dùng driver
 * `local` (tức đều nằm trên chính ổ đĩa của máy chủ đang được bảo vệ), phát `BackupHasFailed` với
 * lý do "không có bản sao ngoài máy chủ" — MỖI ĐÊM, cho tới khi văn phòng bật một đích ngoài máy
 * chủ. Trước fix này cấu hình đó sao lưu "thành công" mỗi đêm và không ai được báo: một máy chủ
 * hỏng ổ là mất cả dữ liệu lẫn mọi bản sao lưu.
 *
 * Đăng ký ở `AppServiceProvider::boot()` nghe `Spatie\Backup\Events\BackupManifestWasCreated`,
 * cùng sự kiện và cùng thành ngữ với {@see GuardRcloneDestinationReachable}: KHÔNG NÉM LỖI — bản
 * sao cục bộ đêm nay vẫn được tạo (nó vẫn cứu được khi chỉ CSDL hỏng, không phải cả máy), chỉ có
 * thư báo lỗi đi theo cùng đường với mọi lỗi sao lưu khác (`BackupHasFailedNotification`).
 *
 * "Ngoài máy chủ" = một đĩa có driver KHÁC `local` (SFTP văn phòng, S3, …) hoặc một remote rclone.
 * Một đĩa có tên trong `BACKUP_DISKS` mà không có cấu hình trong `config/filesystems.php` không
 * được coi là local ở đây: gói sẽ tự báo lỗi cho chính đĩa đó khi ghi, và một thư "không có bản
 * sao ngoài máy chủ" chồng lên sẽ chỉ sai hướng người đọc.
 *
 * Chỉ `production`: máy dev và staging không bắt buộc có Google Drive.
 */
class GuardOffServerBackupDestination
{
    public function handle(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (filled(config('vkcrm.backup.rclone.remote'))) {
            return;
        }

        $disks = config('backup.backup.destination.disks', []);

        foreach ($disks as $disk) {
            if (config("filesystems.disks.{$disk}.driver") !== 'local') {
                return;
            }
        }

        $diskList = implode(', ', $disks);

        event(new BackupHasFailed(
            new RuntimeException(__('backup.errors.no_off_server_copy', ['disks' => $diskList])),
            $diskList,
            (string) config('backup.backup.name'),
        ));
    }
}
