<?php

namespace App\Support\Storage\GoogleDrive;

use App\Exceptions\DocumentStorageUnavailable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;

/**
 * Ngắt mạch cho lệnh gọi Google Drive (kế hoạch M14, R9): 3 lỗi tạm thời trong 60 giây thì mở mạch
 * 60 giây; trong lúc mở, {@see self::assertClosed()} ném {@see DocumentStorageUnavailable} ngay, không
 * chờ mạng. Trên shared hosting chỉ có vài tiến trình PHP-FPM: mười lượt tải cùng chờ hết thời gian
 * chờ là treo cả hai panel.
 *
 * - **Hai phạm vi riêng**, `web` (request HTTP) và `job` (lệnh Artisan, hàng đợi), chọn theo
 *   `app()->runningInConsole()` khi không truyền phạm vi. Một job tải lên gặp lỗi không được biến
 *   mọi lượt tải xuống trên web thành 503, và ngược lại.
 * - **Trạng thái** nằm ở store `vkcrm.storage.google_drive.breaker_store` (`file`): chung mọi tiến
 *   trình PHP trên máy, không phụ thuộc CSDL đang chậm. Khoá `drive-breaker:web`, `drive-breaker:job`.
 *   Đọc–sửa–ghi không nguyên tử: hai tiến trình cùng ghi thì có thể mất một lần đếm. Chấp nhận — đây
 *   là van an toàn, không phải bộ đếm chính xác.
 * - **Ai đếm**: {@see DriveClient} gọi {@see self::recordFailure()} cho một THAO TÁC đọc hay metadata
 *   đã hết lượt thử lại (một thao tác hỏng đếm một lần, dù nó đã thử mấy lần), và cho lỗi tạm thời
 *   của endpoint token. Lỗi của khối tải lên được thử lại trong phiên resumable và KHÔNG đếm.
 * - Hết 60 giây thì mạch đóng lại với bộ đếm trống: lời gọi kế tiếp đi ra mạng bình thường.
 */
final class DriveCircuitBreaker
{
    public const WEB = 'web';

    public const JOB = 'job';

    public const FAILURE_THRESHOLD = 3;

    public const WINDOW_SECONDS = 60;

    public const OPEN_SECONDS = 60;

    private const EMPTY_STATE = ['failures' => [], 'open_until' => null];

    /** @param  ?string  $scope  {@see self::WEB}/{@see self::JOB}; `null` = chọn theo cách ứng dụng đang chạy */
    public function __construct(
        private readonly Repository $store,
        private readonly ?string $scope = null,
    ) {}

    public function scope(): string
    {
        return $this->scope ?? (app()->runningInConsole() ? self::JOB : self::WEB);
    }

    public function isOpen(): bool
    {
        $openUntil = $this->state()['open_until'];

        return $openUntil !== null && $openUntil > now()->getTimestamp();
    }

    /** @throws DocumentStorageUnavailable khi mạch của phạm vi này đang mở */
    public function assertClosed(): void
    {
        if ($this->isOpen()) {
            throw DocumentStorageUnavailable::temporarily();
        }
    }

    public function recordFailure(): void
    {
        $now = now()->getTimestamp();
        $state = $this->state();

        $failures = array_values(array_filter(
            $state['failures'],
            fn (int $at): bool => $at > $now - self::WINDOW_SECONDS,
        ));
        $failures[] = $now;

        if (count($failures) >= self::FAILURE_THRESHOLD) {
            $state = ['failures' => [], 'open_until' => $now + self::OPEN_SECONDS];

            Log::error(__('storage.drive.log.breaker_opened'), ['scope' => $this->scope(), 'seconds' => self::OPEN_SECONDS]);
        } else {
            $state = ['failures' => $failures, 'open_until' => $state['open_until']];
        }

        $this->store->put($this->key(), $state, self::WINDOW_SECONDS + self::OPEN_SECONDS);
    }

    /** @return array{failures: list<int>, open_until: ?int} */
    private function state(): array
    {
        $state = $this->store->get($this->key());

        return is_array($state) ? $state + self::EMPTY_STATE : self::EMPTY_STATE;
    }

    private function key(): string
    {
        return 'drive-breaker:'.$this->scope();
    }
}
