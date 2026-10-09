<?php

namespace App\Support\Storage;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Nơi DUY NHẤT trả lời "kho tài liệu đang ở chế độ nào" (kế hoạch M14, R2, R7) và nơi lấy hai đĩa
 * của nó: đĩa kho `documents_remote` (Google Drive) và vùng đệm `private` (đĩa tệp hồ sơ của máy
 * chủ, nơi mọi tệp mới vẫn được ghi trước).
 *
 * # Cho phép khác bật
 *
 * - Công tắc `DOCUMENT_STORAGE` (`vkcrm.storage.driver`, đọc qua `config()` để `config:cache` không
 *   biến nó thành `null`) chỉ CHO PHÉP: `google_drive` thì {@see self::usesRemote()} đúng.
 * - Mốc `settings.storage.remote_enabled_at` mới BẬT: {@see self::pushesNewFiles()} đúng khi CẢ HAI
 *   điều có. Không có mốc thì đổi biến môi trường xong là tác vụ quét đẩy cả kho tệp cũ trong giờ
 *   làm việc, và lệnh chuyển ngoài giờ, chạy thử, cửa sổ quay lui đều vô nghĩa.
 *
 * # Gõ sai không bao giờ là "kho"
 *
 * Mọi giá trị khác đúng chuỗi `local` hay `google_drive` (kể cả viết hoa, thừa khoảng trắng) là
 * KHÔNG dùng kho: tệp ở lại máy chủ, không mất. Và {@see self::driverIsValid()} sai, để kiểm tra
 * sẵn sàng báo ĐỎ — người vận hành gõ sai đang tin rằng tệp ở trên kho. Không chuẩn hoá giá trị: một
 * lỗi gõ được "sửa" lặng lẽ là một lỗi không ai thấy.
 */
final class DocumentStore
{
    public const REMOTE_DISK = 'documents_remote';

    public const STAGING_DISK = 'private';

    public const DRIVER_LOCAL = 'local';

    public const DRIVER_GOOGLE_DRIVE = 'google_drive';

    /** Khoá `settings` của mốc bật kho. Ai ghi, ai xoá: kế hoạch M14, "Mô hình dữ liệu". */
    public const REMOTE_ENABLED_AT_KEY = 'storage.remote_enabled_at';

    /** Công tắc là đúng chuỗi `google_drive`. */
    public static function usesRemote(): bool
    {
        return config('vkcrm.storage.driver') === self::DRIVER_GOOGLE_DRIVE;
    }

    /** Công tắc là một trong hai giá trị đã biết. Sai nghĩa là gõ sai: báo ĐỎ, không đoán. */
    public static function driverIsValid(): bool
    {
        return in_array(config('vkcrm.storage.driver'), [self::DRIVER_LOCAL, self::DRIVER_GOOGLE_DRIVE], true);
    }

    /**
     * Mốc bật kho (ISO-8601 trong `settings`), hoặc `null` khi chưa bật. Giá trị không đọc được thì
     * cũng `null` kèm một dòng log `warning`: coi như chưa bật là chiều an toàn (tệp ở lại máy chủ),
     * và lệnh bật ghi lại được mốc đúng.
     */
    public static function remoteEnabledAt(): ?CarbonImmutable
    {
        $value = Setting::query()->where('key', self::REMOTE_ENABLED_AT_KEY)->value('value');

        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            Log::warning('Mốc bật kho tài liệu trong settings không đọc được; coi như kho chưa bật.', [
                'key' => self::REMOTE_ENABLED_AT_KEY,
            ]);

            return null;
        }
    }

    /**
     * Tệp mới có được tự đẩy lên kho không: công tắc `google_drive` VÀ đã có mốc bật. Hỏi công tắc
     * trước: ở chế độ `local` (mặc định) câu trả lời có ngay, không tốn một truy vấn `settings` cho
     * mỗi tệp được tạo.
     */
    public static function pushesNewFiles(): bool
    {
        return self::usesRemote() && self::remoteEnabledAt() !== null;
    }

    /**
     * Khoá đẩy của MỘT media, `document-push:{id}`. Mọi đường đổi đĩa hay xoá bản cục bộ của media đó
     * phải giữ đúng khoá này (luật của kế hoạch M14, R2, R10, R11).
     *
     * Store `vkcrm.storage.lock_store` (`database`, bảng `cache_locks`) chứ không store mặc định: khoá
     * chỉ có nghĩa khi mọi tiến trình (web, cron, worker) cùng thấy nó. Hạn
     * `vkcrm.storage.lock_ttl_seconds` (2100) phải lớn hơn `$timeout` của job đẩy (1800): khoá hết
     * hạn giữa một lượt tải gói 2 GB thì lượt thứ hai chạy song song với nó.
     */
    public static function pushLock(int $mediaId): Lock
    {
        return Cache::store(config('vkcrm.storage.lock_store'))
            ->lock('document-push:'.$mediaId, (int) config('vkcrm.storage.lock_ttl_seconds'));
    }

    public static function remote(): Filesystem
    {
        return Storage::disk(self::REMOTE_DISK);
    }

    public static function staging(): Filesystem
    {
        return Storage::disk(self::STAGING_DISK);
    }
}
