<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Schedule\ReconcileStageTriggeredInstalments;
use App\Enums\ContractStatus;
use App\Listeners\ReleaseStageTriggeredInstalments;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\StageLog;
use App\Support\Audit;
use App\Support\Scopes\ClientPortalScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Kích hoạt đợt thanh toán theo giai đoạn (M9 Task 6): một đợt `trigger_type = stage` đến hạn khi vụ
 * việc CHẠM giai đoạn `trigger_stage_key` của nó — "thanh toán đợt 2 khi nộp đơn khởi kiện".
 * `TransitionMatterStage` không biết gì về tiền: nó chỉ phát `App\Events\MatterStageChanged`, và
 * listener {@see ReleaseStageTriggeredInstalments} gọi Action này.
 *
 * **MỘT logic, ba lối vào** (kế hoạch + phán quyết controller 6, vì Action tiền không được lồng
 * transaction — docblock {@see LocksBillingRows}):
 *  - {@see self::handle()} — cho nơi gọi ĐỨNG NGOÀI mọi transaction (listener sau commit, đối chiếu
 *    hằng ngày {@see ReconcileStageTriggeredInstalments}): thăm dò NGOÀI transaction, rồi tự mở MỘT
 *    transaction tiền khoá `matters` (câu đầu tiên) → `contracts`, và chạy lõi bên dưới.
 *  - {@see self::releaseLocked()} — LÕI, không mở transaction nào: cho nơi gọi ĐÃ cầm khoá `matters`
 *    → `contracts` trong transaction tiền của chính nó (`ActivateContract`: kích hoạt hợp đồng khi vụ
 *    đã đi qua giai đoạn của vài đợt — luật sư thường nộp đơn trước khi hợp đồng giấy về).
 *  - {@see self::releaseAddedByAmendment()} — cùng lõi, cùng điều kiện tiên quyết, cho `AmendContract`
 *    dưới khoá của chính nó: chỉ các đợt phụ lục VỪA thêm, với thêm một sàn ngày (ngày ký phụ lục —
 *    lượt rà soát Task 6, I1). Không có nó, một đợt phụ lục thêm cho giai đoạn vụ đã qua hiện "chờ"
 *    tới lượt đối chiếu 07:00, rồi ra đời với hạn tính từ lần chạm đầu — có thể nhiều tháng trước
 *    ngày khách đồng ý khoản đó.
 *
 * **Điều kiện để một đợt được kích hoạt** — tất cả đọc dưới khoá:
 *  1. Hợp đồng của vụ đang `active` (không `draft` — chưa ai phải trả gì; không `completed`/
 *     `cancelled` — không còn gì để đòi), và vụ chưa xoá mềm.
 *  2. Đợt đang chờ đúng giai đoạn đó: `Instalment::awaitingStage()` — `trigger_type = stage`,
 *     `status = pending`, **`triggered_at IS NULL`** (cổng chạy MỘT lần, không phải "giai đoạn hiện
 *     tại bằng giai đoạn kích hoạt": `allowed_next` có chu trình và `on_hold` ra vào được).
 *  3. Vụ đã thật sự VÀO giai đoạn đó: có một dòng `StageLog::entries()` (không tính dòng cùng giai
 *     đoạn §6.3). **"Đã chạm giai đoạn X" là dòng ĐẦU TIÊN vào X** (phán quyết controller 2) theo
 *     `occurred_at` rồi `id` — {@see self::firstEntryInto()}, đọc DƯỚI khoá `matters` (không lần chuyển
 *     giai đoạn nào của vụ này chen được: `TransitionMatterStage` khoá đúng hàng đó trước). Vào lại X
 *     lần hai không kích hoạt lại gì (cổng 2), và một đợt thêm sau lần chạm đầu được kích hoạt bằng
 *     — và tính ngày từ — lần chạm đầu, dù lối vào là listener của lần vào lại.
 *
 * **Ghi gì:** `due_date` = NGÀY gốc + `due_days_after_trigger`; NGÀY gốc là ngày (múi giờ ứng dụng)
 * của `occurred_at` trên dòng kích hoạt — ngày giai đoạn THẬT SỰ xảy ra, không phải hôm nay (kế hoạch
 * điểm 2: một lần chuyển ghi lùi ngày sinh ra một đợt đã quá hạn ngay khi ra đời, và đó là sự thật) —
 * nhưng không sớm hơn `signed_at` của hợp đồng (phán quyết controller 3: kẹp vào ngày muộn hơn; một
 * giai đoạn vụ chạm TRƯỚC khi khách ký không làm khoản tiền đến hạn trước ngày ký), và — chỉ với đợt
 * một phụ lục vừa thêm, ở lối vào {@see self::releaseAddedByAmendment()} — không sớm hơn ngày ký phụ
 * lục đó, cùng lý lẽ. Cả hai chỉ là sàn ({@see self::triggerDay()}).
 * `triggered_at = now()`; `triggered_by_stage_log_id` = dòng kích hoạt (bằng chứng "vì sao đợt này
 * đến hạn"). Đi qua model, không `DB::table()`, để mọi hook của `Instalment` còn đứng (hook `saving`
 * của bất biến tổng chỉ hỏi khi `amount`/`status`/`contract_id` đổi — ba cột ghi ở đây không đụng).
 *
 * **Không actor** (kế hoạch, Interfaces; phán quyết controller 1): kích hoạt là hệ quả của một sự
 * kiện, không phải quyết định của ai. Mỗi đợt một dòng `Audit::record('instalment_triggered', …,
 * bySystem: true)` KHÔNG causer, nguồn gốc là `stage_log_id`; `blameOnSystem()` giữ nguyên
 * `updated_by` của đợt. Cả hai đứng như nhau ở MỌI lối vào — kể cả khi listener chạy trong request của
 * luật sư vừa bấm "Chuyển giai đoạn" (không bao giờ rơi về người đang đăng nhập), và kể cả trong lần
 * kích hoạt hợp đồng hay ký phụ lục (người kích hoạt/ký đứng tên trên dòng `contract_activated`/
 * `contract_amended`, không trên đợt này).
 * Người đã chuyển giai đoạn truy ngược được qua `stage_log_id` → `stage_logs.created_by`.
 */
class TriggerInstalmentsForStage
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;

    /**
     * Lối vào tự mở transaction — xem docblock lớp. `$stageLog` là một dòng đưa ĐÚNG `$matter` VÀO
     * ĐÚNG `$stageKey` (listener: dòng của sự kiện; đối chiếu: lần chạm đầu); không thì lỗi lập trình
     * của nơi gọi — `LogicException`, fail closed, không gì được ghi. Đợt được gắn vào lần chạm ĐẦU
     * của vụ vào giai đoạn đó, đọc lại dưới khoá — trên đường thường ngày, đó chính là `$stageLog`.
     *
     * MỘT lần thăm dò NGOÀI transaction (luật "không đọc thường trước khoá đầu tiên" của
     * {@see LocksBillingRows}): hợp đồng của vụ có đợt nào đang chờ ĐÚNG giai đoạn này không. Không có
     * (vụ không có hợp đồng, hay không đợt nào chờ giai đoạn đó) thì trả `0` mà không mở transaction
     * tiền và không khoá hàng `matters` nào — mỗi lần chuyển giai đoạn của văn phòng không phải xếp
     * hàng sau một khoá tiền vô ích. Mọi điều kiện quyết định được hỏi LẠI dưới khoá ở
     * {@see self::releaseLocked()}. Chỉ giai đoạn `$stageKey`: đợt chờ một giai đoạn KHÁC mà vụ cũng
     * đã chạm (chưa được kích hoạt vì một lần hỏng trước đó) để lại cho lối vào của chính giai đoạn
     * đó — đối chiếu hằng ngày.
     *
     * @return int số đợt đã kích hoạt
     */
    public function handle(Matter $matter, string $stageKey, StageLog $stageLog): int
    {
        self::assertEntryInto((int) $matter->getKey(), $stageKey, $stageLog);

        $contractId = $this->scopelessly(Instalment::query())
            ->awaitingStage($stageKey)
            ->whereIn('contract_id', $this->scopelessly(Contract::query())->where('matter_id', $matter->getKey())->select('id'))
            ->value('contract_id');

        if ($contractId === null) {
            return 0;
        }

        return $this->inContractTransaction(
            (int) $contractId,
            fn (Matter $lockedMatter, Contract $lockedContract): int => $this->releaseLocked($lockedMatter, $lockedContract, $stageKey),
        );
    }

    /**
     * LÕI — chỉ gọi BÊN TRONG một transaction tiền đã khoá `matters` rồi `contracts` (theo thứ tự
     * DUY NHẤT của {@see LocksBillingRows}), với đúng hai hàng đã khoá đó; không tự mở transaction.
     * Khoá tiếp các đợt đang chờ (`instalments`, đúng chỗ của nó sau `contracts`), rồi kích hoạt
     * những đợt mà vụ đã chạm giai đoạn — xem docblock lớp cho điều kiện và cột ghi.
     *
     * `$stageKey` rỗng = MỌI giai đoạn mà hợp đồng có đợt đang chờ (lối vào của `ActivateContract`);
     * có giá trị = chỉ giai đoạn đó (lối vào của {@see self::handle()}).
     *
     * @return int số đợt đã kích hoạt
     */
    public function releaseLocked(Matter $lockedMatter, Contract $lockedContract, ?string $stageKey = null): int
    {
        return $this->release($lockedMatter, $lockedContract, $stageKey);
    }

    /**
     * Lối vào của `AmendContract` — cùng LÕI, cùng điều kiện tiên quyết với {@see self::releaseLocked()}
     * (bên trong transaction tiền của phụ lục, với hai hàng `matters` → `contracts` nó đã khoá; không
     * tự mở transaction). Chỉ xét ĐÚNG các đợt `$instalmentIds` mà phụ lục vừa thêm: đợt nào chờ một
     * giai đoạn vụ ĐÃ chạm thì kích hoạt ngay, gắn vào lần chạm ĐẦU như mọi lối vào khác, nhưng NGÀY
     * gốc của hạn còn không sớm hơn `$amendmentSignedOn` (lượt rà soát Task 6, I1 — cùng lý lẽ với kẹp
     * vào `signed_at` của hợp đồng: khách chưa ký phụ lục thì khoản đó chưa thể đến hạn; và cùng tiền
     * lệ với đợt `on_signing` thêm bằng phụ lục, tính từ ngày ký phụ lục). Đợt chờ giai đoạn vụ CHƯA
     * chạm vẫn chờ (listener kích hoạt nó khi vụ tới đó). Đợt CŨ của hợp đồng không bao giờ mang sàn
     * này — một đợt cũ đã lỡ lần kích hoạt để lại cho đối chiếu hằng ngày. `$instalmentIds` rỗng thì
     * trả `0` mà không đọc gì: phụ lục không thêm đợt `stage` nào thì không khoá thêm hàng nào.
     *
     * @param  list<int>  $instalmentIds
     * @return int số đợt đã kích hoạt
     */
    public function releaseAddedByAmendment(Matter $lockedMatter, Contract $lockedContract, array $instalmentIds, CarbonInterface $amendmentSignedOn): int
    {
        if ($instalmentIds === []) {
            return 0;
        }

        return $this->release($lockedMatter, $lockedContract, null, $instalmentIds, $amendmentSignedOn);
    }

    /**
     * Thân chung của mọi lối vào. `$onlyInstalmentIds` (khác `null`) giới hạn vào đúng các đợt đó;
     * `$notBefore` (khác `null`) là một sàn nữa cho NGÀY gốc của hạn — {@see self::triggerDay()}.
     *
     * @param  list<int>|null  $onlyInstalmentIds
     */
    private function release(
        Matter $lockedMatter,
        Contract $lockedContract,
        ?string $stageKey,
        ?array $onlyInstalmentIds = null,
        ?CarbonInterface $notBefore = null,
    ): int {
        if ((int) $lockedContract->matter_id !== (int) $lockedMatter->getKey()) {
            throw new LogicException(
                "Hợp đồng #{$lockedContract->getKey()} không thuộc vụ việc #{$lockedMatter->getKey()} đã khoá — dừng, không kích hoạt đợt nào dưới khoá của vụ khác.",
            );
        }

        if ($lockedMatter->trashed() || $lockedContract->status !== ContractStatus::Active) {
            return 0;
        }

        $waiting = $this->scopelessly(Instalment::query())
            ->where('contract_id', $lockedContract->getKey())
            ->awaitingStage($stageKey)
            ->when($onlyInstalmentIds !== null, fn (Builder $query): Builder => $query->whereKey($onlyInstalmentIds))
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get();

        $released = 0;

        foreach ($waiting->groupBy('trigger_stage_key') as $key => $instalments) {
            $entry = self::firstEntryInto((int) $lockedMatter->getKey(), (string) $key);

            if ($entry === null) {
                continue;
            }

            $triggerDay = self::triggerDay($entry, $lockedContract, $notBefore);

            foreach ($instalments as $instalment) {
                $instalment->fill([
                    'due_date' => $triggerDay->copy()->addDays($instalment->due_days_after_trigger)->toDateString(),
                    'triggered_at' => now(),
                    'triggered_by_stage_log_id' => $entry->getKey(),
                ]);
                $instalment->blameOnSystem()->save();

                Audit::record('instalment_triggered', $instalment, [
                    'stage_log_id' => $entry->getKey(),
                    'stage' => (string) $key,
                    'due_date' => $instalment->due_date->toDateString(),
                ], bySystem: true);

                $released++;
            }
        }

        return $released;
    }

    /**
     * **MỘT định nghĩa "vụ đã chạm giai đoạn X"** (phán quyết controller 2): dòng `stage_logs` ĐẦU
     * TIÊN đưa vụ VÀO X ({@see StageLog::scopeEntries()} — không tính dòng cùng giai đoạn §6.3), sắp
     * theo `occurred_at` rồi `id` (cùng tiêu chí với `Matter::stageLogs()`): một dòng ghi SAU nhưng
     * khai ngày xảy ra SỚM hơn là lần chạm đầu. `null` = vụ chưa từng vào X. Không qua
     * `ClientPortalScope` (một phiên cổng khách mở song song không được làm dòng tiến độ "không có").
     *
     * Lần vào giai đoạn ĐẦU lúc mở vụ không để lại dòng nào (`Matter::creating` đặt nó mà không ghi
     * `stage_logs`) — lý do `DraftContract` cấm lấy giai đoạn đầu làm giai đoạn kích hoạt. Dòng mở đầu
     * `from_stage = NULL` mà dữ liệu mẫu ghi thẳng vẫn được tính là một lần vào.
     */
    public static function firstEntryInto(int $matterId, string $stageKey): ?StageLog
    {
        return StageLog::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('matter_id', $matterId)
            ->where('to_stage', $stageKey)
            ->entries()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Ngày gốc của hạn: NGÀY muộn nhất trong ngày của `occurred_at`, `signed_at` của hợp đồng, và
     * `$notBefore` (ngày ký phụ lục, chỉ ở lối vào {@see self::releaseAddedByAmendment()}) — xem
     * docblock lớp. Hai ngày sau chỉ là SÀN: một giai đoạn xảy ra muộn hơn cả hai vẫn tính từ ngày
     * của nó.
     */
    private static function triggerDay(StageLog $entry, Contract $contract, ?CarbonInterface $notBefore): CarbonInterface
    {
        $day = $entry->occurred_at->copy()->startOfDay();

        foreach ([$contract->signed_at, $notBefore] as $floor) {
            if ($floor !== null && $floor->copy()->startOfDay()->gt($day)) {
                $day = $floor->copy()->startOfDay();
            }
        }

        return $day;
    }

    /**
     * Fail closed: `$stageLog` phải đưa đúng vụ `$matterId` VÀO đúng `$stageKey` — cùng vụ, `to_stage`
     * khớp, và không phải một dòng cùng giai đoạn. Một dòng khác là lỗi lập trình của nơi gọi, không
     * phải "không có gì để làm".
     */
    private static function assertEntryInto(int $matterId, string $stageKey, StageLog $stageLog): void
    {
        if ((int) $stageLog->matter_id !== $matterId
            || $stageLog->to_stage !== $stageKey
            || $stageLog->from_stage === $stageLog->to_stage) {
            throw new LogicException(
                "Dòng tiến độ #{$stageLog->getKey()} không đưa vụ việc #{$matterId} vào giai đoạn \"{$stageKey}\" — dừng, không kích hoạt đợt nào.",
            );
        }
    }
}
