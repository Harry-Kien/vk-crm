<?php

namespace App\Listeners;

use App\Actions\Matter\SyncMatterArchive;
use App\Events\MatterStageChanged;

/**
 * Nối `MatterStageChanged` (M7 Task 3) với {@see SyncMatterArchive}. Mỏng có chủ đích, cùng lý lẽ
 * với `SendStageUpdateNotification`: nghiệp vụ nằm trong Action, listener chỉ là sợi dây — CLAUDE.md.
 *
 * ĐĂNG KÝ BẰNG TỰ DÒ, không đăng ký tường minh — cùng cơ chế và cùng bài học đã ghi ở
 * `SendStageUpdateNotification` (đặt tên `handle` đúng chữ ký là đủ, Laravel tự tìm và đăng ký).
 *
 * **KHÔNG `ShouldQueue`, và đây là một lựa chọn tường minh giữa hai phương án phán quyết của chủ
 * nhiệm cho phép ("chọn một, ghi lý do").** Ba lý do:
 *
 *  1. `SyncMatterArchive::handle()` là một upsert rẻ, không I/O ngoài (không gửi thư, không quét
 *     virus, không gọi mạng ngoài) — không có lý do hiệu năng nào để đẩy nó ra khỏi request.
 *  2. **Một bản ghi lưu trữ không đồng bộ được là một lỗ hổng ÂM THẦM có hậu quả bảo mật**, khác
 *     hẳn một email chậm vài phút: `client_access_until` sai (hoặc thiếu hẳn) làm
 *     `ExpireClientAccess` (Task 5) tính sai ngày khách mất quyền xem — một vụ đã đóng có thể vẫn
 *     hiện trên portal quá hạn lưu, hoặc ngược lại mất quyền xem quá sớm. Rủi ro đó đáng để đổi
 *     lấy một phản hồi có lỗi hiện ra NGAY cho người vừa bấm "Chuyển giai đoạn", còn hơn một job
 *     nằm trong `failed_jobs` mà không ai biết tra.
 *  3. Vì `MatterStageChanged` đã là `ShouldDispatchAfterCommit`, chạy đồng bộ ở đây nghĩa là:
 *     lần CHUYỂN GIAI ĐOẠN (StageLog + `matters.stage`/`closed_at`) đã commit XONG trước khi
 *     listener này chạy — một lần đồng bộ lưu trữ thất bại không bao giờ kéo theo việc mất một
 *     `StageLog` đã ghi. Cái giá phải trả, nói thẳng: nếu `SyncMatterArchive::handle()` ném lỗi,
 *     màn hình "Chuyển giai đoạn" báo lỗi cho người dùng dù CHÍNH lần chuyển giai đoạn đã thành
 *     công — một thông điệp có thể gây hiểu lầm "chưa xong", nhưng `SyncMatterArchive` idempotent
 *     nên bất kỳ lần chuyển giai đoạn hợp lệ TIẾP THEO trên vụ việc này (mở lại rồi đóng lại,
 *     hoặc chuyển tiếp sang một giai đoạn terminal khác) sẽ tự đồng bộ lại — không có bản ghi nào
 *     kẹt sai vĩnh viễn, và không cần một cơ chế chống gọi trùng hay một lệnh bảo trì riêng.
 *
 * Không `failed()`: không `ShouldQueue` thì không có hàng đợi nào để job "thất bại hẳn" trên đó.
 */
class SyncMatterArchiveOnStageChange
{
    public function __construct(private SyncMatterArchive $sync) {}

    public function handle(MatterStageChanged $event): void
    {
        $stageLog = $event->stageLog;

        // `matter_id`, không phải quan hệ `matter` — xem docblock `SyncMatterArchive`, mục
        // "Nhận int $matterId": tải quan hệ ở ĐÂY có thể rơi vào đúng cái bẫy `ClientPortalScope`
        // mà Action kia được viết ra để không phụ thuộc vào.
        $this->sync->handle($stageLog->matter_id, $stageLog->author);
    }
}
