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
 *
 * {@see self::localOnlyDisks()} là điều kiện, tách khỏi việc phát sự kiện, để `vkcrm:preflight`
 * (dòng `backup_off_server`) và `vkcrm:backup-check` hỏi ĐÚNG câu mà guard này hỏi mỗi đêm (làn fc,
 * kiểm tra nghiệp vụ 2026-10-09).
 */
class GuardOffServerBackupDestination
{
    public function handle(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $diskList = self::localOnlyDisks();

        if ($diskList === null) {
            return;
        }

        event(new BackupHasFailed(
            new RuntimeException(__('backup.errors.no_off_server_copy', ['disks' => $diskList])),
            $diskList,
            (string) config('backup.backup.name'),
        ));
    }

    /**
     * Danh sách đĩa (phân tách dấu phẩy) khi KHÔNG có đích nào ngoài máy chủ — `BACKUP_RCLONE_REMOTE`
     * trống và mọi đĩa trong `BACKUP_DISKS` có driver `local` — hoặc `null` khi có ít nhất một đích
     * ngoài máy chủ. Không xét môi trường (người gọi quyết). Một đĩa không có cấu hình không được
     * coi là local (lý do ở docblock lớp).
     */
    public static function localOnlyDisks(): ?string
    {
        if (filled(config('vkcrm.backup.rclone.remote'))) {
            return null;
        }

        $disks = config('backup.backup.destination.disks', []);

        foreach ($disks as $disk) {
            if (config("filesystems.disks.{$disk}.driver") !== 'local') {
                return null;
            }
        }

        return implode(', ', $disks);
    }
}
