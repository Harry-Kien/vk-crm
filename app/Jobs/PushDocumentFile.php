<?php

namespace App\Jobs;

use App\Actions\Storage\PushDocumentFileToRemote;
use App\Enums\PushOutcome;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Support\Storage\PushBackoff;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Đẩy MỘT tệp từ vùng đệm lên kho tài liệu (kế hoạch M14, R2). Job chỉ gọi Action
 * {@see PushDocumentFileToRemote} rồi chọn số phận của chính nó theo kết quả:
 *
 * | Kết quả | Job |
 * |---|---|
 * | kho đang dừng ({@see PushBackoff::paused()}) | tự xoá, không chạm kho, không hỏng: `storage.push-pending` xếp lại khi hết dừng |
 * | `Locked` (lượt khác đang giữ khoá đẩy của media) | thả lại sau 120 giây |
 * | {@see DocumentStorageUnavailable} (kho tạm thời không trả lời, ngắt mạch mở) | dừng mọi lượt đẩy 15 phút, thả lại sau 60 giây (lượt đó thấy kho đang dừng và tự xoá) |
 * | {@see DocumentStorageMisconfigured} (thiếu khoá, hết dung lượng, mất quyền…) | dừng mọi lượt đẩy 60 phút, hỏng ngay (kế hoạch Task 3): thử lại không giúp gì; cảnh báo cho người vận hành đi qua kiểm tra sức khoẻ kho (Task 5) và tồn đọng, không qua job |
 * | lỗi khác (md5 lệch sau khi tải, vùng đệm mất tệp…) | để hàng đợi thử lại theo `backoff()` |
 * | `Pushed`, `AlreadyRemote`, `Gone`, `Disabled`, `Rejected` | xong |
 *
 * Mỗi lần thả lại cũng tính một lượt trong `$tries`; hết lượt thì job hỏng, và tác vụ quét
 * `storage.push-pending` (15 phút) xếp lại media còn ở vùng đệm.
 *
 * **Duy nhất theo media** (`ShouldBeUnique`, rà soát cuối vòng sửa 1, I4): push-pending xếp lại mọi
 * media còn ở vùng đệm mỗi 15 phút; không khử trùng thì kho hỏng lâu làm hàng `storage` và
 * `failed_jobs` phình theo số tệp chờ nhân số lượt quét. Khoá duy nhất nằm ở store của khoá đẩy
 * (`vkcrm.storage.lock_store`) và sống tối đa `retry_after` của kết nối `storage` (2400 giây): worker
 * chết giữa chừng thì khoá tự hết hạn và lượt quét sau xếp lại được. Thư viện nhả khoá khi job xong
 * hay hỏng, giữ khoá khi job thả lại. Khoá đẩy (`DocumentStore::pushLock()`) vẫn là thứ loại trừ hai
 * lượt cùng tải một media; khoá duy nhất chỉ chặn xếp trùng.
 *
 * - Kết nối và hàng `storage` (`config/queue.php`): `retry_after` 2400 > `$timeout` 1800, nên worker
 *   khác không nhặt lại một job còn đang tải gói 2 GB. Rút bằng mục lịch `queue.storage` (worker sống
 *   tới `--max-time=50`, `--timeout=1800`), tách khỏi hàng thư `default` và hàng `handover`.
 * - TTL khoá đẩy (`vkcrm.storage.lock_ttl_seconds`, 2100) > `$timeout`: khoá không hết hạn giữa một
 *   lượt tải. `DocumentStorageScheduleTest` ghim cả hai quan hệ.
 * - `$failOnTimeout`: một lượt bị giết vì quá giờ là hỏng, không chạy lại vô hạn.
 */
class PushDocumentFile implements ShouldBeUnique, ShouldQueue
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

    public function uniqueId(): string
    {
        return (string) $this->mediaId;
    }

    public function uniqueFor(): int
    {
        return (int) config('queue.connections.storage.retry_after');
    }

    public function uniqueVia(): Repository
    {
        return Cache::store(config('vkcrm.storage.lock_store'));
    }

    public function handle(PushDocumentFileToRemote $push): void
    {
        if (PushBackoff::paused()) {
            $this->delete();

            return;
        }

        try {
            $outcome = $push->handle($this->mediaId);
        } catch (DocumentStorageUnavailable $exception) {
            PushBackoff::pauseAfter($exception, $this->mediaId);
            $this->release(self::UNAVAILABLE_RELEASE_SECONDS);

            return;
        } catch (DocumentStorageMisconfigured $exception) {
            PushBackoff::pauseAfter($exception, $this->mediaId);
            $this->fail($exception);

            return;
        }

        if ($outcome === PushOutcome::Locked) {
            $this->release(self::LOCKED_RELEASE_SECONDS);
        }
    }
}
