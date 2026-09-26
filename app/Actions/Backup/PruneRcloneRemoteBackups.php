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
 * KHÔNG bọc `try/catch` ở đây: một lệnh `deletefile` hỏng giữa chừng (remote treo, hết quota) ném
 * {@see RcloneCommandFailed} thẳng ra ngoài — {@see PushBackupArchiveToRclone} là
 * nơi quyết định báo lỗi thế nào, lớp này chỉ có MỘT việc: tính đúng tập cần xoá và gọi xoá.
 */
class PruneRcloneRemoteBackups
{
    public function handle(string $remote): void
    {
        $entries = RcloneProcess::listJson($remote);

        $keep = (int) config('vkcrm.backup.rclone.keep', 30);

        if (count($entries) <= $keep) {
            return;
        }

        $stale = array_slice($entries, $keep);

        foreach ($stale as $entry) {
            RcloneProcess::deleteFile(rtrim($remote, '/').'/'.$entry['name']);
        }
    }
}
