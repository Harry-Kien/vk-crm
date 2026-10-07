<?php

namespace App\Jobs;

use App\Actions\Matter\BuildHandoverPackage;
use App\Actions\Matter\RecordHandoverPackageFailure;
use App\Actions\Matter\RequestHandoverPackage;
use App\Exceptions\HandoverPackageFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * M7 Task 4 (R9) — sinh gói bàn giao hồ sơ (SPEC §6.12) trên hàng đợi RIÊNG. Job mỏng có chủ đích:
 * nghiệp vụ nằm trong {@see BuildHandoverPackage} (dựng và lưu) và {@see RecordHandoverPackageFailure}
 * (ghi lỗi); xếp hàng nó là việc của {@see RequestHandoverPackage}.
 *
 * # Hàng đợi riêng — vì sao
 *
 * Mục lịch `queue.drain` dùng chung một lượt `withoutOverlapping()` cho mọi job; một job nén vài
 * trăm MB giữ lượt đó và thư nhắc mốc thời hạn phải chờ. Job này chạy trên kết nối `handover`
 * (`config/queue.php`) và hàng `handover`, do mục lịch `queue.handover` (`routes/console.php`) rút.
 *
 * # `$timeout`, `$tries`, `retry_after`
 *
 *  - `$timeout` = {@see self::TIMEOUT_SECONDS} giây: đủ cho gói vài trăm MB trên shared hosting.
 *    `$failOnTimeout` = true: hết giờ là THẤT BẠI (gọi `failed()`, luật sư được báo), không phải
 *    thử lại vô hạn.
 *  - `retry_after` của kết nối `handover` = 900 giây > `$timeout`: một job đang chạy không bị worker
 *    khác nhặt lại và chạy song song (kết nối `database` chung chỉ có 90 giây — lý do có kết nối
 *    riêng). `tests/Feature/Schedule/QueueHandoverScheduleTest.php` ghim quan hệ này.
 *  - `$tries` = 2: một lần thử lại cho lỗi nhất thời (khoá DB, mất kết nối DB). Lỗi CÓ TÊN
 *    ({@see HandoverPackageFailed} — thiếu tệp, không nén được, không dựng được mục lục, thư mục
 *    tạm không ghi được, gói vượt trần một tệp của kho, kho không lưu được gói) là lỗi tất định
 *    hoặc cần người sửa trước: thử lại chỉ phí thêm một lượt nén, nên bị bắt ngay ở `handle()` và
 *    ghi luôn, với câu nói người vận hành phải làm gì.
 *
 * # `$requestedAt` — dấu của lần yêu cầu
 *
 * Job mang theo `handover_requested_at` (giây UNIX) của lần yêu cầu đã xếp nó. Cả
 * {@see BuildHandoverPackage} lẫn {@see RecordHandoverPackageFailure} chỉ ghi kết quả khi dấu đó
 * còn KHỚP dòng lưu trữ: một job cũ (nhấc dậy muộn sau khi nút được mở khoá vì kẹt) không ghi đè
 * kết quả của lần yêu cầu mới hơn.
 */
class GenerateHandoverPackage implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT_SECONDS = 600;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 2;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $matterId,
        public readonly int $requestedAt,
    ) {
        $this->onConnection('handover');
        $this->onQueue('handover');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [120];
    }

    public function handle(BuildHandoverPackage $build, RecordHandoverPackageFailure $record): void
    {
        try {
            $build->handle($this->matterId, $this->requestedAt);
        } catch (HandoverPackageFailed $exception) {
            $record->handle($this->matterId, $this->requestedAt, $exception);
        }
    }

    /**
     * Hết lượt thử lại (hoặc hết giờ) với một lỗi lạ. Laravel gọi hàm này đúng một lần.
     */
    public function failed(?Throwable $exception): void
    {
        app(RecordHandoverPackageFailure::class)->handle(
            $this->matterId,
            $this->requestedAt,
            $exception ?? new \RuntimeException('handover job failed without an exception'),
        );
    }
}
