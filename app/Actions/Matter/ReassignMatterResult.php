<?php

namespace App\Actions\Matter;

use App\Models\StageLog;

/**
 * Kết quả một lần gọi `ReassignMatter::handle()` (fix round 1, finding 2 — trước đó `handle()`
 * chỉ trả về `$stageLog`, và không có cách nào khác để caller biết ĐÚNG những `deadlines`/
 * `client_requests` nào lần gọi NÀY vừa chuyển).
 *
 * **Vì sao cần, và vì sao KHÔNG re-query sau đó.** M7 Task 2 (bàn giao hàng loạt, chưa tới lượt ở
 * milestone này) gọi `ReassignMatter::handle()` lặp lại cho nhiều vụ việc rồi cần gộp một payload
 * `matter_id => {deadline_ids, client_request_ids, reason}` cho MỘT `SendReassignmentDigest` duy
 * nhất — xem docblock lớp `ReassignMatter`. Task 2 KHÔNG được phép dựng payload đó bằng cách hỏi
 * lại CSDL sau khi cả lô đã chạy xong ("mốc chưa hoàn thành và hiện do lead mới phụ trách"): câu
 * hỏi đó sẽ vô tình vét luôn cả những mốc lead mới ĐÃ giữ TỪ TRƯỚC lần bàn giao này (ví dụ với vai
 * `associate` trên một vụ khác, hoặc một mốc được giao thẳng cho họ ngoài luồng bàn giao) — phá
 * đúng câu "Nội dung = đúng những gì lần bàn giao NÀY chuyển" mà controller Task 1 đã ràng buộc.
 * `$movedDeadlineIds`/`$movedRequestIds` là ẢNH CHỤP id lấy NGAY LÚC bàn giao, dưới khoá, trong
 * transaction của chính `handle()` — đúng cách `SendReassignmentDigest` (job của MỘT vụ, Task 1)
 * đã dùng, chỉ khác là Task 2 gom nhiều ảnh chụp đó lại theo `matter_id` thay vì dispatch riêng
 * từng vụ.
 */
final readonly class ReassignMatterResult
{
    /**
     * @param  array<int, int>  $movedDeadlineIds  Id các `deadlines` CHƯA hoàn thành vừa chuyển
     *                                             sang lead mới trong CHÍNH lần gọi này (bước 3
     *                                             của `ReassignMatter::handle()`).
     * @param  array<int, int>  $movedRequestIds  Id các `client_requests` CHƯA đóng vừa chuyển
     *                                            trong CHÍNH lần gọi này (bước 4).
     */
    public function __construct(
        public StageLog $stageLog,
        public array $movedDeadlineIds,
        public array $movedRequestIds,
    ) {}
}
