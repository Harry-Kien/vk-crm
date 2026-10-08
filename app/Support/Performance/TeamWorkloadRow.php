<?php

namespace App\Support\Performance;

use App\Actions\Performance\BuildTeamWorkload;
use Carbon\CarbonImmutable;

/**
 * Một dòng của trang "Theo dõi đội ngũ" (M13, cột N1–N10; N11 chỉ trên trang của một người): số "bây giờ" của MỘT người được theo
 * dõi, như MỘT người xem đọc được — mọi con số đã tính trên `Matter::listableBy($viewer)` (R4). Dựng
 * bởi {@see BuildTeamWorkload}; định nghĩa từng cột ở bảng "Định nghĩa các con số" của kế hoạch M13
 * và ở khoá `performance.explain.n1` … `n11`.
 *
 * **`null` = "Không áp dụng", không bao giờ "không có vụ".** Các trường `?int` dưới đây là cột chỉ
 * dành cho người phụ trách vụ (R6, dấu (L) của kế hoạch): `null` khi và chỉ khi
 * `TeamRoster::leadsMatters()` sai (người đó không có quyền đứng tên phụ trách vụ, ví dụ trợ lý). Người
 * phụ trách được không có vụ nào — hay chỉ có vụ người xem không thấy — nhận `0`. `$leadsMatters` mang
 * lại đúng câu trả lời đó cho màn hình. `$teamOpen`, `$overdueDeadlines`, `$deadlinesDueSoon`,
 * `$awaitingOfficeRequests` áp dụng cho mọi người.
 *
 * `$lastMatterActivityAt` (N11) rỗng nghĩa khác, áp dụng cho mọi người: hoặc người đó chưa ghi một
 * thay đổi nào vào một vụ người xem thấy được, hoặc `BuildTeamWorkload` không được hỏi N11 — trang
 * "Theo dõi đội ngũ" không hỏi, chỉ trang của một người hỏi (phán quyết N11 của Task 4, docblock
 * `BuildTeamWorkload`).
 */
final readonly class TeamWorkloadRow
{
    public function __construct(
        public int $userId,
        public string $name,
        public bool $isActive,
        public bool $leadsMatters,
        /** N1 (L) — vụ đang mở người này phụ trách. */
        public ?int $leadOpen,
        /** N2 — vụ đang mở người này giữ ghế luật sư cộng sự hoặc trợ lý. */
        public int $teamOpen,
        /** N3 (L) — vụ đã kết thúc đang đứng tên người này. */
        public ?int $leadClosed,
        /** N4 (L) — vụ quá hạn cập nhật cho khách. */
        public ?int $stale,
        /** N4 (L), số đi kèm — vụ đang mở chưa bật cổng khách, luật quá hạn cập nhật không đo được. */
        public ?int $notMeasurable,
        /** N5 — mốc người này giữ đã quá hạn, trên vụ đang mở. */
        public int $overdueDeadlines,
        /** N6 — mốc người này giữ đến hạn từ hôm nay tới hết ngày +7, trên vụ đang mở. */
        public int $deadlinesDueSoon,
        /** N7 (L) — vụ chờ giấy tờ của khách. */
        public ?int $awaitingClientMatters,
        /** N7 (L), số trong ngoặc — vụ đã chờ quá `ChecklistProgress::STUCK_AFTER_DAYS` ngày. */
        public ?int $awaitingClientStuck,
        /** N8 (L) — đầu mục khách đã nộp chờ văn phòng duyệt, trên vụ người này phụ trách. */
        public ?int $awaitingReviewItems,
        /** N9 — yêu cầu của khách người này đang giữ, chờ văn phòng trả lời. */
        public int $awaitingOfficeRequests,
        /** N10 (L), `X` — đầu mục đã xong trên các vụ đang phụ trách. */
        public ?int $checklistSettled,
        /** N10 (L), `Y` — đầu mục phải có trên các vụ đang phụ trách. */
        public ?int $checklistTotal,
        /** N11 — lần gần nhất người này ghi một thay đổi vào một vụ người xem thấy được; chỉ khi được hỏi. */
        public ?CarbonImmutable $lastMatterActivityAt,
    ) {}
}
