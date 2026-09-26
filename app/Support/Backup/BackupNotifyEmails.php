<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\Log;

/**
 * Phân tích `BACKUP_NOTIFY_EMAIL` (SPEC §10 mục 8; M8a Task 1, fix I1) — danh sách email phân
 * tách dấu phẩy, mỗi phần tử được validate qua `filter_var(..., FILTER_VALIDATE_EMAIL)`. Phần tử
 * KHÔNG hợp lệ bị loại và ghi log cảnh báo, KHÔNG BAO GIỜ ném lỗi.
 *
 * Việc phân tích và validate sống Ở ĐÂY, hoàn toàn tách khỏi `config('backup.notifications.
 * mail.to')` của gói — trường đó bị `Spatie\Backup\Config\NotificationMailConfig::fromArray()`
 * validate CHẶT và VÔ ĐIỀU KIỆN mỗi khi `Spatie\Backup\Config\Config` được dựng (tức là MỌI lần
 * chạy MỘT lệnh artisan bất kỳ — xem docblock ở `config/backup.php`). Nếu giá trị thô của
 * `BACKUP_NOTIFY_EMAIL` (một danh sách phẩy, hoặc một lỗi gõ tay) lọt vào trường đó, nó ném
 * `InvalidConfig` và làm hỏng `schedule:run`, `queue:work`, mọi lệnh — không riêng sao lưu. Đây
 * là lý do lớp này tồn tại: validate ở TẦNG CỦA DỰ ÁN, khoan dung với lỗi gõ tay (bỏ qua + log),
 * không bao giờ chạm vào trường validate chặt của gói.
 */
class BackupNotifyEmails
{
    /** @return list<string> */
    public static function parse(?string $raw): array
    {
        if (blank($raw)) {
            return [];
        }

        $candidates = array_values(array_filter(
            array_map(static fn (string $email): string => trim($email), explode(',', $raw)),
            static fn (string $email): bool => $email !== '',
        ));

        $valid = [];

        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $valid[] = $candidate;

                continue;
            }

            Log::warning("BACKUP_NOTIFY_EMAIL chứa một địa chỉ không hợp lệ, đã bỏ qua: \"{$candidate}\".");
        }

        return $valid;
    }
}
