<?php

namespace App\Actions\Backup;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Dọn disk `local_backups` (đã trở thành đĩa TRUNG CHUYỂN khi đích rclone bật, M8a Task 2, Ruling
 * 2 của brief) xuống còn `BACKUP_LOCAL_KEEP` bản MỚI NHẤT (mặc định 7). Chỉ gọi SAU một lượt đẩy
 * rclone đã được {@see PushBackupArchiveToRclone} XÁC MINH THÀNH CÔNG — brief: "never prune
 * locally when the push failed". Không disk hỏng nào ở đây được phép ăn mất bản DUY NHẤT còn lại.
 *
 * HAI lớp độc lập chặn một giá trị dưới 1 — không phải một: `config/vkcrm.php` chặn giá trị ĐỌC
 * TỪ `.env` (`max(1, (int) (env('BACKUP_LOCAL_KEEP') ?: 7))` — trống hay `0` rơi về 7, số âm thành
 * 1), còn dòng `max(1, ...)` NGAY TRONG
 * `handle()` bên dưới chặn cả trường hợp một đoạn code khác gọi thẳng
 * `config(['vkcrm.backup.local_keep' => 0])` lúc chạy, bỏ qua tệp cấu hình (test giả lập đúng tình
 * huống đó). Nếu bỏ dòng `max(1, ...)` này, `$keep = 0` khiến {@see array_slice()} coi TOÀN BỘ
 * mảng (kể cả bản mới nhất) là "cũ hơn N bản đầu" — `array_slice($files, 0)` trả về CHÍNH mảng gốc,
 * không bỏ qua phần tử nào. `count($files) <= $keep` ở dưới KHÔNG bảo vệ được trường hợp này (chỉ
 * đúng khi không có tệp nào); lớp bảo vệ thật sự là `max(1, ...)`.
 */
class PruneLocalBackupsDisk
{
    public function handle(Filesystem $disk, string $folder): void
    {
        $files = $disk->files($folder);

        $keep = max(1, (int) config('vkcrm.backup.local_keep', 7));

        if (count($files) <= $keep) {
            return;
        }

        usort($files, static fn (string $a, string $b): int => $disk->lastModified($b) <=> $disk->lastModified($a));

        $stale = array_slice($files, $keep);

        foreach ($stale as $file) {
            $disk->delete($file);
        }
    }
}
