<?php

namespace App\Support\Performance;

use App\Actions\Performance\BuildPerformanceReport;

/**
 * Một dòng của trang "Hiệu suất theo kỳ" (M13, cột P1–P7, P9, P10, "Lĩnh vực chính"): số TRONG MỘT KỲ
 * của MỘT người được theo dõi — hoặc của dòng tham chiếu "Chung" (`$userId = null`, R8) — như MỘT người
 * xem đọc được: mọi con số đã tính trên `Matter::listableBy($viewer)` (R4). Dựng bởi
 * {@see BuildPerformanceReport}; định nghĩa từng cột ở bảng "Định nghĩa các con số" của kế hoạch M13 và
 * ở khoá `performance.explain.*`.
 *
 * **`null` = "Không áp dụng", không bao giờ "không có việc".** `$stageEntries`, `$mattersMoved`,
 * `$mattersClosed`, `$revenueCollected` là cột chỉ dành cho người phụ trách vụ (R6, dấu (L)): `null` khi
 * và chỉ khi `TeamRoster::leadsMatters()` sai. `$revenueCollected` còn `null` khi người xem không được
 * thấy cột doanh thu — lý do đó nằm ở `PerformanceReport::$revenueVisible`, không ở dòng. Dòng "Chung"
 * không phải một người, nên không có "Không áp dụng" nào ngoài cột doanh thu.
 *
 * `$responseMedianHours`/`$responseMeanHours` rỗng nghĩa là chưa luồng nào được trả lời tới hết kỳ.
 *
 * P8 (Task 7, `$staleStart` … `$overdueEnd`) đọc từ ảnh chụp hằng ngày (`BuildPerformanceTrend::endpoints()`):
 * `null` nghĩa là ngày đó KHÔNG có ảnh chụp (tác vụ lỡ, ngày trước khi triển khai, hay kỳ chưa có ngày nào
 * đã qua) — không bao giờ "Không áp dụng". Ảnh chụp ghi 0 cho N4 của người không đứng tên phụ trách vụ; trang
 * in "Không áp dụng" cho phần N4 theo `$leadsMatters`, như mọi cột (L). Dòng "Chung" luôn `null`: ảnh chụp
 * là số của từng người, còn dòng "Chung" là mọi việc trong các vụ người xem thấy — cộng ảnh chụp của những
 * người trên trang không ra số đó (R8).
 */
final readonly class PerformanceRow
{
    public function __construct(
        /** Người được xem; `null` là dòng "Chung" (R8). */
        public ?int $userId,
        public string $name,
        public bool $isActive,
        public bool $leadsMatters,
        /** P1 — mốc đến hạn trong kỳ, xong không muộn hơn hết ngày đến hạn. */
        public int $deadlinesOnTime,
        /** P1 — xong sau ngày đến hạn, không muộn hơn mốc cắt của kỳ. */
        public int $deadlinesLate,
        /** P1 — chưa xong (hoặc xong sau mốc cắt) khi đã hết ngày đến hạn. */
        public int $deadlinesMissed,
        /** P2 — mốc đã gỡ trong kỳ; không vào tỉ lệ. */
        public int $deadlinesRemoved,
        /** P1 — đúng hạn / (đúng hạn + trễ + lỡ). */
        public Ratio $onTimeRatio,
        /** P3 — yêu cầu khách gửi trong kỳ, TRỪ yêu cầu đóng không trả lời (mẫu số của P3, phần yêu cầu của P9). */
        public int $requestsReceived,
        /** P3 — trong số đó, đã trả lời tới mốc cắt. */
        public int $requestsAnswered,
        /** P10 — yêu cầu khách gửi trong kỳ mà văn phòng đóng không trả lời; không vào tỉ lệ. */
        public int $requestsClosedUnanswered,
        /** P3 — trung vị giờ LÀM VIỆC (R17, `BusinessHours`) từ lúc khách gửi tới lần trả lời đầu, trên luồng đã trả lời tới mốc cắt. */
        public ?float $responseMedianHours,
        /** P3 — trung bình của cùng tập. */
        public ?float $responseMeanHours,
        /** P4 (L) — số dòng đưa một vụ VÀO giai đoạn mới, theo ngày ghi trên dòng. */
        public ?int $stageEntries,
        /** P4 (L) — số vụ khác nhau của các dòng đó. */
        public ?int $mattersMoved,
        /** P5 (L) — vụ kết thúc trong kỳ, tính cho người phụ trách lúc vụ kết thúc. */
        public ?int $mattersClosed,
        /** P6 — số lần duyệt hoặc từ chối một đầu mục trong kỳ, theo nhật ký. */
        public int $itemsReviewed,
        /** P7 (L) — tiền đã thu trong kỳ, theo `payments.attributed_lawyer_id`; xem docblock lớp. */
        public ?int $revenueCollected,
        /** P9 — (đúng hạn + trễ + đã trả lời) / (mốc của kỳ + yêu cầu nhận trong kỳ). */
        public Ratio $completionRatio,
        /** @var list<array{name: string, matters: int}> hai lĩnh vực có nhiều vụ nhất trong số vụ có việc của kỳ */
        public array $mainPracticeAreas,
        /** P8 (Task 7) — N4 trong ảnh chụp ngày đầu kỳ; `null` = ngày đó không có ảnh chụp. Xem docblock lớp. */
        public ?int $staleStart,
        /** P8 — N4 trong ảnh chụp ngày cuối kỳ, không muộn hơn hôm qua. */
        public ?int $staleEnd,
        /** P8 — N5 trong ảnh chụp ngày đầu kỳ. */
        public ?int $overdueStart,
        /** P8 — N5 trong ảnh chụp ngày cuối kỳ, không muộn hơn hôm qua. */
        public ?int $overdueEnd,
    ) {}
}
