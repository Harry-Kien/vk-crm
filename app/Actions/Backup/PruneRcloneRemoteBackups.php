<?php

namespace App\Actions\Backup;

use App\Exceptions\RcloneCommandFailed;
use App\Support\Backup\RcloneProcess;

/**
 * Dọn remote rclone xuống còn N bản MỚI NHẤT (SPEC §10 mục 8: "giữ 30 bản"; M8a Task 2, Ruling 1).
 * Dọn theo SỐ LƯỢNG, không theo tuổi — brief Task 2: "Pruning is count-based, not age-based".
 *
 * Chỉ gọi SAU một lượt `rclone copy` đã được {@see PushBackupArchiveToRclone} XÁC MINH (tệp mới
 * đã có mặt trên remote, đúng dung lượng) — dọn dựa trên một danh sách remote không có bản mới
 * nhất là dọn nhầm.
 *
 * `RcloneProcess::listJson()` đã sắp remote theo `ModTime` MỚI NHẤT trước, nên phần "N bản mới
 * nhất" luôn là N phần tử ĐẦU của mảng — {@see array_slice()} lấy phần CÒN LẠI (cũ hơn) để xoá.
 *
 * # Chỉ tính ĐÚNG tệp mang hình dạng archive (fix I1, vòng rà soát 1)
 *
 * `RcloneProcess::listJson($remote)` liệt kê MỌI tệp nằm phẳng trong thư mục remote — không chỉ
 * archive. Văn phòng hoàn toàn có thể tự tay bỏ một tệp khác (một bản scan, một ghi chú) vào
 * ĐÚNG thư mục Google Drive đó. Trước khi đếm/xoá, lọc còn lại ĐÚNG những tên khớp
 * `{filename_prefix}...zip` — `filename_prefix` đọc từ
 * `config('backup.backup.destination.filename_prefix')`, CÙNG MỘT nguồn mà
 * `Spatie\Backup\Tasks\Backup\BackupJob::createZipContainingEveryFileInManifest()` dùng để đặt
 * tên archive thật (xem docblock `config/backup.php`). Một tệp không khớp — dù là ghi chú của
 * văn phòng hay một tệp thử của `CheckBackupDestinations` sót lại — KHÔNG BAO GIỜ được đếm vào N
 * bản giữ lại, và KHÔNG BAO GIỜ bị coi là "cũ hơn N bản đầu" để xoá.
 *
 * KHÔNG bọc `try/catch` ở đây: một lệnh `deletefile` hỏng giữa chừng (remote treo, hết quota) ném
 * {@see RcloneCommandFailed} thẳng ra ngoài — {@see PushBackupArchiveToRclone} là
 * nơi quyết định báo lỗi thế nào, lớp này chỉ có MỘT việc: tính đúng tập cần xoá và gọi xoá.
 */
class PruneRcloneRemoteBackups
{
    public function handle(string $remote): void
    {
        $prefix = (string) config('backup.backup.destination.filename_prefix');

        $archives = array_values(array_filter(
            RcloneProcess::listJson($remote),
            static fn (array $entry): bool => $prefix !== ''
                && str_starts_with($entry['name'], $prefix)
                && str_ends_with($entry['name'], '.zip'),
        ));

        $keep = (int) config('vkcrm.backup.rclone.keep', 30);

        if (count($archives) <= $keep) {
            return;
        }

        $stale = array_slice($archives, $keep);

        foreach ($stale as $entry) {
            RcloneProcess::deleteFile(rtrim($remote, '/').'/'.$entry['name']);
        }
    }
}
