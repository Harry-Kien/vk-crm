<?php

namespace App\Actions\Schedule;

use App\Actions\Billing\TriggerInstalmentsForStage;
use App\Enums\ContractStatus;
use App\Listeners\ReleaseStageTriggeredInstalments;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\StageLog;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * M9 Task 6 — LƯỚI AN TOÀN hằng ngày (07:00, trước lượt nhắc quá hạn 08:00) cho đợt thanh toán theo
 * giai đoạn. Listener {@see ReleaseStageTriggeredInstalments} kích hoạt đợt NGAY khi vụ vào giai
 * đoạn; tác vụ này phủ ba đường listener không phủ:
 *  1. một đợt THÊM vào lịch sau khi vụ đã qua giai đoạn kích hoạt (phụ lục `AmendContract`);
 *  2. một lần kích hoạt hỏng ở listener (khoá hết giờ, "thử lại"… — listener `report()` rồi nuốt để
 *     lần chuyển giai đoạn đã commit không hiện ra như thất bại);
 *  3. `stage_logs` ghi thẳng không phát sự kiện (dữ liệu mẫu `MatterSeeder`, lệnh console).
 * (Đường thứ tư kế hoạch nêu — hợp đồng KÍCH HOẠT khi vụ đã ở giữa chừng — `ActivateContract` tự kích
 * hoạt trong cùng transaction; tác vụ này chỉ còn là lưới cho nó.)
 *
 * **Gọi ĐÚNG {@see TriggerInstalmentsForStage::handle()}, không bản sao logic.** Tác vụ chỉ TÌM các
 * cặp (vụ, giai đoạn) đáng hỏi; mọi điều kiện quyết định (hợp đồng `active`, vụ chưa xoá mềm, đợt
 * còn chờ, lần chạm ĐẦU, ngày đến hạn, nhật ký không causer) được Action hỏi lại dưới khoá. Chạy hai
 * lần chỉ kích hoạt một lần: cổng là `triggered_at IS NULL`.
 *
 * **Một truy vấn ứng viên** ({@see self::candidates()}): đợt đang chờ (`Instalment::awaitingStage()`)
 * của hợp đồng `active` trên vụ chưa xoá mềm, mà vụ ĐÃ có một dòng VÀO đúng giai đoạn đó
 * (`StageLog::entries()`, EXISTS tương quan) — gộp thành các cặp (vụ, giai đoạn) khác nhau. Ngày
 * thường của văn phòng (mọi đợt còn chờ những giai đoạn vụ chưa tới) là đúng MỘT truy vấn và không
 * transaction nào. Mỗi cặp tìm được tốn thêm: một lần nạp vụ cho cả lượt, một lần đọc lần chạm đầu,
 * rồi một transaction tiền của Action — các cặp đó hiếm (một lần kích hoạt hỏng, một phụ lục), và
 * mỗi cặp cần transaction riêng của nó dù sao.
 *
 * **Mỗi cặp một `try/catch`**: một cặp hỏng (khoá hết giờ sau ba lần chạy) không chặn các cặp khác của
 * lượt; lỗi được `report()`, và lượt ngày mai thử lại (đợt vẫn chờ). Không thư nào, không hàng đợi nào.
 *
 * **Mọi truy vấn gỡ `ClientPortalScope` tường minh**, cùng lý do `ExpireClientAccess`: tác vụ chạy từ
 * scheduler (không phiên nào), nhưng một lời gọi trong một tiến trình có phiên cổng đang mở không
 * được để scope của khách đó cắt tập ứng viên.
 */
class ReconcileStageTriggeredInstalments
{
    /**
     * @return array{triggered: int, failed: int} `triggered` — số đợt đã kích hoạt; `failed` — số cặp
     *                                            (vụ, giai đoạn) mà lần kích hoạt ném lỗi.
     */
    public function __invoke(): array
    {
        return $this->handle();
    }

    /**
     * @return array{triggered: int, failed: int}
     */
    public function handle(): array
    {
        $triggered = 0;
        $failed = 0;

        $pairs = self::candidates()->get();

        if ($pairs->isEmpty()) {
            return ['triggered' => 0, 'failed' => 0];
        }

        $trigger = app(TriggerInstalmentsForStage::class);

        // Truy vấn ứng viên đã loại vụ xoá mềm; một vụ bị xoá mềm SAU truy vấn đó vẫn được nạp ở đây,
        // và Action (khoá vụ kèm `withTrashed()`, hỏi lại dưới khoá) trả 0 cho nó — không lọc lần nữa.
        $matters = Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->whereIn('id', $pairs->pluck('matter_id')->unique()->values())
            ->get()
            ->keyBy('id');

        foreach ($pairs as $pair) {
            try {
                // `stage_logs` chỉ-thêm: dòng VÀO mà truy vấn ứng viên đã thấy không biến mất.
                $entry = TriggerInstalmentsForStage::firstEntryInto((int) $pair->matter_id, (string) $pair->trigger_stage_key);

                $triggered += $trigger->handle($matters[(int) $pair->matter_id], (string) $pair->trigger_stage_key, $entry);
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return ['triggered' => $triggered, 'failed' => $failed];
    }

    /**
     * Các cặp (`matter_id`, `trigger_stage_key`) khác nhau mà một lần kích hoạt CÓ THỂ có việc: đợt
     * đang chờ của hợp đồng `active`, vụ chưa xoá mềm, và vụ đã có ít nhất một dòng VÀO giai đoạn đó.
     * Không thay cho các điều kiện của Action — chỉ để không hỏi Action những cặp chắc chắn không có
     * việc.
     *
     * @return Builder<Instalment>
     */
    public static function candidates(): Builder
    {
        return Instalment::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->awaitingStage()
            ->join('contracts', 'contracts.id', '=', 'instalments.contract_id')
            ->join('matters', 'matters.id', '=', 'contracts.matter_id')
            ->where('contracts.status', ContractStatus::Active->value)
            ->whereNull('matters.deleted_at')
            ->whereExists(
                StageLog::query()
                    ->withoutGlobalScope(ClientPortalScope::class)
                    ->entries()
                    ->whereColumn('stage_logs.matter_id', 'contracts.matter_id')
                    ->whereColumn('stage_logs.to_stage', 'instalments.trigger_stage_key')
                    ->select('stage_logs.id')
                    ->toBase(),
            )
            ->select('contracts.matter_id', 'instalments.trigger_stage_key')
            ->distinct()
            ->orderBy('contracts.matter_id')
            ->orderBy('instalments.trigger_stage_key');
    }
}
