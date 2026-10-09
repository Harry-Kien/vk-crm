<?php

namespace App\Support\Storage;

use App\Actions\Schedule\PushPendingDocumentFiles;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Jobs\PushDocumentFile;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Lùi lại lượt đẩy tệp khi kho hỏng (M14, rà soát cuối vòng sửa 1, I4). Hai dấu, cùng store với khoá
 * đẩy (`vkcrm.storage.lock_store`, `database`) để web, cron và worker cùng thấy:
 *
 * - **Dừng mọi lượt đẩy** (`document-push:paused-until`, mốc Unix): một job gặp
 *   {@see DocumentStorageMisconfigured} dừng {@see self::MISCONFIGURED_PAUSE_SECONDS} (60 phút — nhịp
 *   kiểm tra sức khoẻ kho, nơi thư cảnh báo đi ra), gặp lỗi kho không trả lời dừng
 *   {@see self::UNAVAILABLE_PAUSE_SECONDS} (15 phút — một nhịp `storage.push-pending`). Trong lúc dừng
 *   {@see PushPendingDocumentFiles} không xếp gì, và {@see PushDocumentFile} lấy ra khỏi hàng tự xoá mà
 *   không chạm kho. Mốc chỉ được kéo DÀI, không bao giờ rút ngắn: lỗi 15 phút ngay sau lỗi 60 phút
 *   không mở lại sớm.
 * - **Tệp vừa làm hỏng** (`document-push:failed:{media_id}`, {@see self::RECENT_FAILURE_SECONDS}): lượt
 *   quét sau xếp nó CUỐI. Không có dấu này thì một tệp "độc" (hỏng vì chính nó) có `id` nhỏ nhất đứng
 *   đầu mọi lượt, làm dừng kho, và các job đứng sau tự xoá — không tệp nào lên kho nữa. Xếp cuối chứ
 *   không bỏ qua: khi mọi tệp đều mang dấu (kho hỏng lâu), tệp đầu vẫn đi làm lượt thăm dò, nên kho
 *   được sửa thì lượt quét kế tiếp đẩy được ngay.
 *
 * Kết quả: kho hỏng vì cấu hình thì `failed_jobs` thêm khoảng một dòng mỗi giờ thay vì một dòng cho
 * mỗi tệp chờ mỗi 15 phút; kho không trả lời thì không thêm dòng nào. Tệp vẫn nằm an toàn ở vùng đệm
 * (`private`) và tải về được; tồn đọng quá `push_alert_minutes` vẫn báo VÀNG qua kiểm tra sức khoẻ.
 */
final class PushBackoff
{
    public const MISCONFIGURED_PAUSE_SECONDS = 3600;

    public const UNAVAILABLE_PAUSE_SECONDS = 900;

    public const RECENT_FAILURE_SECONDS = 86400;

    private const PAUSED_UNTIL_KEY = 'document-push:paused-until';

    public static function paused(): bool
    {
        return (int) self::store()->get(self::PAUSED_UNTIL_KEY, 0) > now()->getTimestamp();
    }

    /** Ghi dấu dừng theo loại lỗi và đánh dấu media vừa làm hỏng. */
    public static function pauseAfter(Throwable $failure, int $mediaId): void
    {
        $seconds = $failure instanceof DocumentStorageMisconfigured
            ? self::MISCONFIGURED_PAUSE_SECONDS
            : self::UNAVAILABLE_PAUSE_SECONDS;

        $until = now()->getTimestamp() + $seconds;
        $current = (int) self::store()->get(self::PAUSED_UNTIL_KEY, 0);

        if ($until > $current) {
            self::store()->put(self::PAUSED_UNTIL_KEY, $until, $seconds);
        }

        self::store()->put(self::failedKey($mediaId), 1, self::RECENT_FAILURE_SECONDS);
    }

    /**
     * Những media trong `$mediaIds` vừa làm hỏng một lượt đẩy.
     *
     * @param  list<int>  $mediaIds
     * @return list<int>
     */
    public static function recentlyFailed(array $mediaIds): array
    {
        if ($mediaIds === []) {
            return [];
        }

        $marks = self::store()->many(array_map(self::failedKey(...), $mediaIds));

        return array_values(array_filter(
            $mediaIds,
            fn (int $id): bool => ($marks[self::failedKey($id)] ?? null) !== null,
        ));
    }

    private static function failedKey(int $mediaId): string
    {
        return 'document-push:failed:'.$mediaId;
    }

    private static function store(): Repository
    {
        return Cache::store(config('vkcrm.storage.lock_store'));
    }
}
