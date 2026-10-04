<?php

namespace App\Listeners;

use App\Actions\Billing\TriggerInstalmentsForStage;
use App\Actions\Schedule\ReconcileStageTriggeredInstalments;
use App\Events\MatterStageChanged;
use App\Models\Matter;
use App\Support\Scopes\ClientPortalScope;
use Throwable;

/**
 * M9 Task 6 — nối `MatterStageChanged` (M7 Task 3, dùng lại nguyên hình dạng; không sự kiện thứ hai)
 * với {@see TriggerInstalmentsForStage}: vụ việc vừa VÀO một giai đoạn thì các đợt thanh toán chờ
 * giai đoạn đó đến hạn. Mỏng có chủ đích — nghiệp vụ nằm trong Action, listener chỉ là sợi dây
 * (CLAUDE.md). Sự kiện chỉ phát khi giai đoạn THẬT SỰ đổi (`TransitionMatterStage`, `! $isSameStage`),
 * nên một dòng cập nhật cùng giai đoạn (§6.3) không bao giờ tới đây.
 *
 * ĐĂNG KÝ BẰNG TỰ DÒ (event discovery), như `SyncMatterArchiveOnStageChange` và
 * `SendStageUpdateNotification`: một `handle(MatterStageChanged $event)` là đủ.
 *
 * **ĐỒNG BỘ, không `ShouldQueue`** — cùng lý lẽ `SyncMatterArchiveOnStageChange`:
 *  1. Sự kiện đã là `ShouldDispatchAfterCommit`: listener chỉ chạy SAU KHI lần chuyển giai đoạn
 *     (StageLog + `matters.stage`) commit xong; transaction đó rollback thì listener không bao giờ
 *     chạy — một đợt "đến hạn" vì một lần chuyển giai đoạn không tồn tại là một khoản văn phòng đi
 *     đòi mà lý do không có. Và listener chạy NGOÀI mọi transaction, nên Action tự mở được
 *     transaction tiền của nó (không lồng).
 *  2. Việc rẻ, không I/O ngoài (không thư, không mạng) — và một đợt KHÔNG được kích hoạt là một
 *     khoản nợ biến mất âm thầm khỏi "Công nợ", nên làm ngay, không để một job nằm chờ hàng đợi.
 *
 * **Lỗi được `report()` rồi NUỐT** (khác bước đồng bộ lưu trữ, giống bước gói bàn giao của listener
 * kia): lần chuyển giai đoạn ĐÃ commit không được hiện ra như thất bại cho người vừa bấm nút vì bước
 * tiền hỏng (khoá hết giờ, "thử lại"…). Lưới an toàn là {@see ReconcileStageTriggeredInstalments}
 * (07:00 hằng ngày, trước lượt nhắc quá hạn 08:00): ở lượt kế tiếp nó kích hoạt đúng đợt đó, qua đúng
 * Action này, gắn đúng lần chạm đầu và tính đúng ngày — hạn không lệch, chỉ ngày "đến hạn" hiện ra
 * trễ tới sáng hôm sau.
 *
 * **Nạp vụ bằng `matter_id`, không qua `ClientPortalScope`, kể cả vụ đã xoá mềm** (bài học
 * `SyncMatterArchiveOnStageChange`): một phiên cổng khách mở song song không được làm vụ "không có".
 * Vụ đã xoá mềm trong khe giữa commit và listener được để Action quyết (nó không kích hoạt gì cho vụ
 * đã xoá mềm) — một định nghĩa, ở một chỗ.
 */
class ReleaseStageTriggeredInstalments
{
    public function __construct(private TriggerInstalmentsForStage $trigger) {}

    public function handle(MatterStageChanged $event): void
    {
        $stageLog = $event->stageLog;

        try {
            $matter = Matter::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->withTrashed()
                ->findOrFail($stageLog->matter_id);

            $this->trigger->handle($matter, (string) $stageLog->to_stage, $stageLog);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
