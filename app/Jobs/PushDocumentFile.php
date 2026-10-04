<?php

namespace App\Jobs;

use App\Actions\Storage\PushDocumentFileToRemote;
use App\Enums\PushOutcome;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Đẩy MỘT tệp từ vùng đệm lên kho tài liệu (kế hoạch M14, R2). Job chỉ gọi Action
 * {@see PushDocumentFileToRemote} rồi chọn số phận của chính nó theo kết quả:
 *
 * | Kết quả | Job |
 * |---|---|
 * | `Locked` (lượt khác đang giữ khoá đẩy của media) | thả lại sau 120 giây |
 * | {@see DocumentStorageUnavailable} (kho tạm thời không trả lời, ngắt mạch mở) | thả lại sau 60 giây |
 * | {@see DocumentStorageMisconfigured} (thiếu khoá, hết dung lượng, mất quyền…) | hỏng ngay: thử lại không giúp gì; cảnh báo cho người vận hành đi qua kiểm tra sức khoẻ kho (Task 5) và tồn đọng, không qua job |
 * | lỗi khác (md5 lệch sau khi tải, vùng đệm mất tệp…) | để hàng đợi thử lại theo `backoff()` |
 * | `Pushed`, `AlreadyRemote`, `Gone`, `Disabled`, `Rejected` | xong |
 *
 * Mỗi lần thả lại cũng tính một lượt trong `$tries`; hết lượt thì job hỏng, và tác vụ quét
 * `storage.push-pending` (15 phút) xếp lại media còn ở vùng đệm.
 *
 * - Kết nối và hàng `storage` (`config/queue.php`): `retry_after` 2400 > `$timeout` 1800, nên worker
 *   khác không nhặt lại một job còn đang tải gói 2 GB. Rút bằng mục lịch `queue.storage` (worker sống
 *   tới `--max-time=50`, `--timeout=1800`), tách khỏi hàng thư `default` và hàng `handover`.
 * - TTL khoá đẩy (`vkcrm.storage.lock_ttl_seconds`, 2100) > `$timeout`: khoá không hết hạn giữa một
 *   lượt tải. `DocumentStorageScheduleTest` ghim cả hai quan hệ.
 * - `$failOnTimeout`: một lượt bị giết vì quá giờ là hỏng, không chạy lại vô hạn.
 */
class PushDocumentFile implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT_SECONDS = 1800;

    public const LOCKED_RELEASE_SECONDS = 120;

    public const UNAVAILABLE_RELEASE_SECONDS = 60;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 4;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $mediaId)
    {
        $this->onConnection('storage');
        $this->onQueue('storage');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(PushDocumentFileToRemote $push): void
    {
        try {
            $outcome = $push->handle($this->mediaId);
        } catch (DocumentStorageUnavailable) {
            $this->release(self::UNAVAILABLE_RELEASE_SECONDS);

            return;
        } catch (DocumentStorageMisconfigured $exception) {
            $this->fail($exception);

            return;
        }

        if ($outcome === PushOutcome::Locked) {
            $this->release(self::LOCKED_RELEASE_SECONDS);
        }
    }
}
