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
        /** P3 — trung vị giờ lịch từ lúc khách gửi tới lần trả lời đầu, trên luồng đã trả lời tới mốc cắt. */
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
    ) {}
}
