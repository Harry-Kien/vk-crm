<?php

namespace App\Actions\Backup;

use App\Exceptions\RcloneCommandFailed;
use App\Support\Backup\RcloneArchives;
use App\Support\Backup\RcloneProcess;

/**
 * Dọn THƯ MỤC MÔI TRƯỜNG trên remote rclone xuống còn N bản MỚI NHẤT (SPEC §10 mục 8: "giữ 30
 * bản"; M8a Task 2, Ruling 1). Dọn theo SỐ LƯỢNG, không theo tuổi — brief Task 2: "Pruning is
 * count-based, not age-based".
 *
 * Chỉ gọi SAU một lượt `rclone copy` đã được {@see PushBackupArchiveToRclone} XÁC MINH (tệp mới
 * đã có mặt trên remote, đúng dung lượng) — dọn dựa trên một danh sách remote không có bản mới
 * nhất là dọn nhầm.
 *
 * `$remoteFolder` là `{BACKUP_RCLONE_REMOTE}/{slug(backup.name)}` — MỘT thư mục cho MỘT môi
 * trường ({@see RcloneArchives::folder()}, fix I3, lượt rà soát cuối M8a). Lớp này không tự dựng
 * đường đó: nơi gọi đã đẩy archive vào đúng thư mục ấy, và dọn phải làm ở CHÍNH chỗ vừa xác minh.
 *
 * # Chỉ tính ĐÚNG tệp mang tên archive của môi trường này, xếp theo mốc trong TÊN
 *
 * `RcloneProcess::listJson()` liệt kê MỌI tệp nằm phẳng trong thư mục — văn phòng có thể tự tay bỏ
 * một tệp khác vào đó, và một bố trí phẳng cũ có thể còn archive của môi trường khác. Trước khi
 * đếm/xoá, {@see RcloneArchives::newestFirst()} giữ lại ĐÚNG những tên khớp
 * `{filename_prefix}Y-m-d-H-i-s.zip` — `filename_prefix` đọc từ
 * `config('backup.backup.destination.filename_prefix')`, cùng nguồn mà gói dùng để đặt tên archive
 * thật — rồi xếp mới nhất trước theo mốc thời gian TRONG TÊN, không theo `ModTime` của remote (fix
 * I3: `vk-crm-staging-…zip` khớp "bắt đầu bằng `vk-crm-`" nên từng bị đếm vào production và bị
 * xoá; một archive cũ tải lên lại mang `ModTime` mới nên từng được giữ thay một bản thật sự mới).
 * Một tệp không khớp KHÔNG BAO GIỜ được đếm vào N bản giữ lại, và KHÔNG BAO GIỜ bị xoá.
 *
 * KHÔNG bọc `try/catch` ở đây: một lệnh `deletefile` hỏng giữa chừng (remote treo, hết quota) ném
 * {@see RcloneCommandFailed} thẳng ra ngoài — {@see PushBackupArchiveToRclone} là
 * nơi quyết định báo lỗi thế nào, lớp này chỉ có MỘT việc: tính đúng tập cần xoá và gọi xoá.
 */
class PruneRcloneRemoteBackups
{
    public function handle(string $remoteFolder): void
    {
        $archives = RcloneArchives::newestFirst(
            RcloneProcess::listJson($remoteFolder),
            (string) config('backup.backup.destination.filename_prefix'),
        );

        $keep = (int) config('vkcrm.backup.rclone.keep', 30);

        if (count($archives) <= $keep) {
            return;
        }

        foreach (array_slice($archives, $keep) as $entry) {
            RcloneProcess::deleteFile(rtrim($remoteFolder, '/').'/'.$entry['name']);
        }
    }
}
