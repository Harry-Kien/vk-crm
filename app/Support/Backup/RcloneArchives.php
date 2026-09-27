<?php

namespace App\Support\Backup;

use App\Actions\Backup\CheckRcloneRemoteFreshness;
use App\Actions\Backup\PruneRcloneRemoteBackups;
use App\Actions\Backup\PushBackupArchiveToRclone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Spatie\Backup\Tasks\Backup\BackupJob;

/**
 * Hai quy ước về archive trên đích rclone (fix I3, lượt rà soát cuối M8a), đặt ở MỘT chỗ để lượt
 * đẩy ({@see PushBackupArchiveToRclone}), lượt dọn
 * ({@see PruneRcloneRemoteBackups}) và lượt giám sát 08:00
 * ({@see CheckRcloneRemoteFreshness}) không thể hiểu khác nhau.
 *
 * # Một thư mục cho MỖI môi trường: `{remote}/{slug(backup.name)}`
 *
 * Trước fix này mọi môi trường (production, staging) đẩy PHẲNG vào cùng `BACKUP_RCLONE_REMOTE`, và
 * lượt dọn lọc theo "bắt đầu bằng tiền tố": tiền tố `vk-crm-` của production khớp luôn
 * `vk-crm-staging-…zip`, nên archive staging bị đếm vào 30 bản của production và bị xoá. Mỗi môi
 * trường nay có thư mục riêng, đặt tên bằng `Str::slug()` của `backup.name` — cùng nguồn với
 * `filename_prefix` (`config/backup.php`), nên thư mục và tiền tố tên luôn đi cùng nhau.
 *
 * # Archive là ĐÚNG `{prefix}Y-m-d-H-i-s.zip`, và thứ tự lấy từ TÊN
 *
 * `BackupJob::FILENAME_FORMAT` (`Y-m-d-H-i-s.\z\i\p`) đặt tên archive; {@see self::PATTERN_SUFFIX}
 * khớp đúng định dạng đó sau tiền tố, không hơn — `vk-crm-staging-2026-…zip` không khớp tiền tố
 * `vk-crm-` vì `staging-` không phải bốn chữ số năm. Thứ tự "mới nhất" lấy từ mốc thời gian trong
 * tên, KHÔNG từ `ModTime` của Google Drive: một archive cũ được tải lên lại (khôi phục từ thùng
 * rác, chép tay giữa hai thư mục) mang `ModTime` mới tinh nhưng vẫn là bản cũ. Mốc trong tên có độ
 * rộng cố định, nên so sánh CHUỖI tên (cùng tiền tố) cũng là so sánh thời gian.
 *
 * Mốc trong tên theo giờ `APP_TIMEZONE` (gói dựng tên bằng `Carbon::now()`), nên
 * {@see self::createdAt()} đọc lại theo đúng múi giờ đó.
 *
 * `tests/Unit/Support/Backup/RcloneArchivesTest.php` ghim `BackupJob::FILENAME_FORMAT` — một bản
 * nâng cấp gói đổi định dạng tên làm test đó đỏ trước khi lượt dọn lặng lẽ không còn khớp gì.
 */
class RcloneArchives
{
    /** Khớp phần sau tiền tố của `BackupJob::FILENAME_FORMAT`. */
    public const PATTERN_SUFFIX = '\d{4}(-\d{2}){5}\.zip';

    private const TIMESTAMP_FORMAT = 'Y-m-d-H-i-s';

    public static function folder(string $remote, string $backupName): string
    {
        return rtrim($remote, '/').'/'.Str::slug($backupName);
    }

    public static function matches(string $name, string $prefix): bool
    {
        return preg_match('/^'.preg_quote($prefix, '/').self::PATTERN_SUFFIX.'$/', $name) === 1;
    }

    /**
     * Lọc còn đúng archive của tiền tố này, MỚI NHẤT trước theo mốc trong tên.
     *
     * @param  list<array{name: string, size: int, modTime: string}>  $entries  từ `RcloneProcess::listJson()`
     * @return list<array{name: string, size: int, modTime: string}>
     */
    public static function newestFirst(array $entries, string $prefix): array
    {
        $archives = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => self::matches($entry['name'], $prefix),
        ));

        usort($archives, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $archives;
    }

    public static function createdAt(string $name, string $prefix): CarbonImmutable
    {
        $timestamp = substr($name, strlen($prefix), strlen('0000-00-00-00-00-00'));

        return CarbonImmutable::createFromFormat(self::TIMESTAMP_FORMAT, $timestamp, config('app.timezone'));
    }
}
