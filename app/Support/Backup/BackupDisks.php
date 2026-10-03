<?php

namespace App\Support\Backup;

/**
 * Phân tích `BACKUP_DISKS` (SPEC §10 mục 8; M8a Task 1, R3) — biến này THAY THẾ `BACKUP_DISK`
 * cũ. `BACKUP_DISK` không còn được đọc ở bất kỳ đâu trong mã nguồn (kiểm bằng
 * `grep -rn "BACKUP_DISK\b"`, ghi lại trong `docs/research/2026-09-26-sao-luu.md`); dòng mẫu của
 * nó (cùng khối AWS) đã xoá khỏi `.env.example` ở M8 Task 7, và
 * `tests/Feature/Deployment/EnvExampleTest.php` giữ cho không biến chết nào quay lại bản mẫu.
 *
 * Danh sách disk cấu hình bằng một chuỗi phân tách dấu phẩy, ví dụ `"google, ,office"` — có thể
 * có khoảng trắng quanh từng tên và phần tử rỗng (dấu phẩy cụt) do người vận hành gõ tay hoặc do
 * một biến môi trường bị nối chuỗi từ nhiều nguồn. Cả hai đều bị loại; chỉ còn `["google",
 * "office"]`.
 *
 * Khi kết quả rỗng — biến chưa khai báo, để trống, hoặc chỉ toàn dấu phẩy/khoảng trắng — trả về
 * {@see self::DEFAULT_DISK}, một disk local duy nhất luôn tồn tại (`config/filesystems.php`,
 * `storage/app/backups`), để `spatie/laravel-backup` luôn có ÍT NHẤT một đích thay vì ném lỗi
 * "no disks configured" hay lặng lẽ sao lưu vào hư không.
 */
class BackupDisks
{
    public const DEFAULT_DISK = 'local_backups';

    /** @return list<string> */
    public static function parse(?string $raw): array
    {
        $disks = array_values(array_filter(
            array_map(static fn (string $disk): string => trim($disk), explode(',', (string) $raw)),
            static fn (string $disk): bool => $disk !== '',
        ));

        return $disks === [] ? [self::DEFAULT_DISK] : $disks;
    }
}
