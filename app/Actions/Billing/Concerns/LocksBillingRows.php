<?php

namespace App\Actions\Billing\Concerns;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;
use Throwable;

/**
 * **Thứ tự khoá hàng DUY NHẤT của mọi Action tiền** — phán quyết toàn dự án (controller M6.5,
 * 2026-09-27): **`matters` TRƯỚC, rồi `contracts` → `instalments` → `payments`.** Hàng nào khác mà
 * một Action cần khoá (bản scan `documents` và `contract_amendments` của `AmendContract`, bản scan
 * của `RecordPayment`, `code_sequences` của `DraftContract`) khoá SAU chuỗi này, không bao giờ xen
 * giữa.
 *
 * Vì sao `matters` đứng đầu: các Action vụ việc (`AddTeamMember`, `RemoveTeamMember`, mở mốc
 * thời hạn, `OpenMatter`) khoá hàng `matters` TRƯỚC mọi hàng con của nó. Một Action tiền khoá
 * `contracts` trước rồi mới tới `matters` (như `RecordPayment` bản trước) chạy song song với một
 * Action vụ việc khoá `matters` rồi đọc-khoá tiền của vụ đó (hook dư nợ khi huỷ vụ việc, lúc merge
 * với M6.5) là đúng hình dạng deadlock A-giữ-X-chờ-Y / B-giữ-Y-chờ-X. Cùng một thứ tự ở mọi nơi
 * thì hai transaction trên cùng một vụ chỉ XẾP HÀNG ở hàng `matters`, không khoá chéo nhau.
 *
 * **LUẬT: trong một transaction tiền, KHÔNG có lần đọc thường nào trước khoá đầu tiên** (lượt sửa
 * thứ hai sau rà soát cuối M9, N1 — Critical). InnoDB ở REPEATABLE READ (mặc định của MariaDB)
 * chụp ảnh dữ liệu ở LẦN ĐỌC THƯỜNG ĐẦU TIÊN của transaction, và mọi lần đọc thường sau đó đọc
 * lại đúng ảnh chụp ấy. Một lần thăm dò không khoá đặt BÊN TRONG transaction, trước lúc đợi khoá
 * `matters`, vì thế đóng băng ảnh chụp ở thời điểm TRƯỚC khi phiên kia commit: bản trước của
 * trait này làm đúng như vậy, và hai `RecordPayment` 6 triệu đồng thời trên một đợt 10 triệu đều
 * qua được chốt chặn thu vượt (phiên đợi khoá cộng tổng đã thu trên ảnh chụp cũ, thấy 0) — 12 triệu
 * trên một đợt 10 triệu. Với `innodb_snapshot_isolation=1` (mặc định từ MariaDB 11.6) cùng lỗi đó
 * còn hiện ra thành `ERROR 1020 Record has changed since last read` ở một lần đọc có khoá — trang
 * 500. Ba lớp chặn, cả ba ở đây:
 *
 *  1. **Thăm dò NGOÀI transaction.** Người gọi chỉ cầm id của hàng SÂU nhất (một đợt, một khoản
 *     thu); để khoá `matters` trước, phải biết nó là vụ nào — nên đi NGƯỢC lên bằng những lần đọc
 *     thường (`payments.instalment_id` → `instalments.contract_id` → `contracts.matter_id`) TRƯỚC
 *     KHI `DB::transaction` mở, rồi khoá XUÔI từ trên xuống. An toàn vì ba cột khoá ngoại đó không
 *     Action nào đổi sau khi tạo hàng. Câu đầu tiên bên trong mọi transaction tiền vì thế là lần
 *     đọc CÓ KHOÁ hàng `matters` — `BillingLockOrderTest` đo điều đó cho cả mười Action. Chỉ dùng
 *     ba cửa {@see self::inContractTransaction()}, {@see self::inInstalmentTransaction()},
 *     {@see self::inPaymentTransaction()}; không tự viết `DB::transaction` rồi thăm dò bên trong.
 *     Ảnh chụp của transaction vì thế chỉ mở SAU khi khoá `matters` đã về tay, và mọi Action tiền
 *     ghi dưới đúng khoá đó — dữ liệu tiền trong ảnh chụp là dữ liệu mới nhất và đứng yên tới lúc
 *     commit.
 *  2. **Mọi con số QUYẾT ĐỊNH đọc bằng lần đọc CÓ KHOÁ**, hoặc từ hàng đã khoá trong cùng
 *     transaction: tổng đã thu ({@see self::lockedCollectedAmounts()}), tổng lịch thu
 *     (`ScheduleTotal::lockedOf()`), và trạng thái đợt khi hoàn tất hợp đồng. Lần đọc có khoá luôn
 *     đọc bản commit mới nhất, không đọc ảnh chụp — lớp thứ hai, phòng khi lớp 1 bị một thay đổi
 *     sau này làm hỏng.
 *  3. **`1020` (hàng vừa đổi) và `1213` (deadlock) thành một câu tiếng Việt "thử lại"** —
 *     {@see self::moneyTransaction()} — không thành trang 500.
 *
 * Luật này đúng khi Action tiền mở transaction NGOÀI CÙNG (mọi màn hình gọi chúng như vậy). Gọi
 * một Action tiền bên trong transaction của người khác thì lần thăm dò chạy bên trong transaction
 * bao ngoài và luật không còn đứng — đừng làm vậy.
 *
 * **Vụ việc khoá kèm `withTrashed()`.** Một vụ đã xoá mềm vẫn khoá được — để POLICY (cổng
 * `ChecksBillingAccess::canSeeBilling()`, vốn đóng đúng trên `trashed()`) là nơi từ chối, bằng một
 * `AuthorizationException`, thay vì một lần đọc không thấy hàng biến thành 404 ở một Action và
 * thành 403 ở Action khác.
 *
 * **Quan hệ được gắn sẵn bằng đúng các hàng đã khoá** (`contract->matter`, `instalment->contract`,
 * `payment->instalment`), để policy hỏi qua `Gate::forUser($actor)` đọc CHÍNH các hàng đó — không
 * phải một lần nạp lười (không khoá) khác của cùng hàng.
 *
 * Lớp dùng trait này phải dùng cả {@see ReadsWithoutPortalScope} (đọc không qua
 * `ClientPortalScope`: một phiên cổng khách mở song song không được làm hàng tiền thành "không
 * có").
 */
trait LocksBillingRows
{
    /**
     * Mã lỗi của MariaDB/MySQL nghĩa là "có người vừa thay đổi hàng này — làm lại": `1020`
     * (ER_CHECKREAD, `Record has changed since last read`) và `1213` (ER_LOCK_DEADLOCK).
     */
    private const RETRYABLE_DRIVER_CODES = [1020, 1213];

    /**
     * Thăm dò vụ việc NGOÀI transaction, rồi trong MỘT transaction khoá `matters` → `contracts`
     * và chạy `$work($matter, $contract)` với hai hàng đã khoá.
     *
     * @template T
     *
     * @param  Closure(Matter, Contract): T  $work
     * @return T
     */
    protected function inContractTransaction(int $contractId, Closure $work): mixed
    {
        $matterId = $this->matterIdOfContract($contractId);

        return $this->moneyTransaction(function () use ($matterId, $contractId, $work): mixed {
            [$matter, $contract] = $this->lockMatterAndContract($matterId, $contractId);

            return $work($matter, $contract);
        });
    }

    /**
     * Thăm dò hợp đồng và vụ việc NGOÀI transaction, rồi trong MỘT transaction khoá `matters`,
     * `contracts`, rồi đúng hàng `instalments` này, và chạy `$work($matter, $contract, $instalment)`.
     *
     * @template T
     *
     * @param  Closure(Matter, Contract, Instalment): T  $work
     * @return T
     */
    protected function inInstalmentTransaction(int $instalmentId, Closure $work): mixed
    {
        $contractId = $this->contractIdOfInstalment($instalmentId);
        $matterId = $this->matterIdOfContract($contractId);

        return $this->moneyTransaction(function () use ($matterId, $contractId, $instalmentId, $work): mixed {
            [$matter, $contract, $instalment] = $this->lockThroughInstalment($matterId, $contractId, $instalmentId);

            return $work($matter, $contract, $instalment);
        });
    }

    /**
     * Thăm dò đợt, hợp đồng và vụ việc NGOÀI transaction, rồi trong MỘT transaction khoá
     * `matters`, `contracts`, `instalments`, rồi đúng hàng `payments` này, và chạy
     * `$work($matter, $contract, $instalment, $payment)`.
     *
     * @template T
     *
     * @param  Closure(Matter, Contract, Instalment, Payment): T  $work
     * @return T
     */
    protected function inPaymentTransaction(int $paymentId, Closure $work): mixed
    {
        $instalmentId = $this->instalmentIdOfPayment($paymentId);
        $contractId = $this->contractIdOfInstalment($instalmentId);
        $matterId = $this->matterIdOfContract($contractId);

        return $this->moneyTransaction(function () use ($matterId, $contractId, $instalmentId, $paymentId, $work): mixed {
            [$matter, $contract, $instalment] = $this->lockThroughInstalment($matterId, $contractId, $instalmentId);

            $payment = $this->scopelessly(Payment::query())->whereKey($paymentId)->lockForUpdate()->firstOrFail();
            $payment->setRelation('instalment', $instalment);

            return $work($matter, $contract, $instalment, $payment);
        });
    }

    /**
     * `DB::transaction($work)`, và một lỗi `1020`/`1213` bên trong nó thành MỘT câu tiếng Việt
     * "có người vừa thay đổi khoản này, thử lại" (`ValidationException`, khoá `concurrency` — không
     * ô nào của form mang tên đó, nên `ReportsActionFailures` đưa nó lên bằng một thông báo) thay
     * cho một trang 500. Transaction đã được rollback khi câu này tới tay người dùng: không gì được
     * ghi, và thử lại là an toàn.
     *
     * `$work` phải mở đầu bằng một lần đọc CÓ KHOÁ (xem luật ở docblock trait). Ba cửa
     * `in…Transaction` làm đúng điều đó; `DraftContract` gọi thẳng hàm này vì nó đã cầm sẵn id vụ
     * việc và khoá `matters` ngay câu đầu tiên.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    protected function moneyTransaction(Closure $work): mixed
    {
        try {
            return DB::transaction($work);
        } catch (Throwable $exception) {
            if (self::isRetryableConflict($exception)) {
                throw ValidationException::withMessages([
                    'concurrency' => [__('billing.validation.concurrent_change')],
                ]);
            }

            throw $exception;
        }
    }

    /**
     * Tổng khoản thu CHƯA HUỶ của từng đợt, đọc bằng MỘT lần đọc CÓ KHOÁ (`payments` — đúng chỗ
     * của nó trong thứ tự khoá, sau `instalments`). Đợt chưa có khoản thu nào không có mặt trong
     * mảng trả về — người gọi đọc `?? 0`.
     *
     * @param  list<int>  $instalmentIds
     * @return array<int, int> tổng theo `instalment_id`
     */
    protected function lockedCollectedAmounts(array $instalmentIds): array
    {
        if ($instalmentIds === []) {
            return [];
        }

        return $this->scopelessly(Payment::query())
            ->whereIn('instalment_id', $instalmentIds)
            ->whereNull('voided_at')
            ->groupBy('instalment_id')
            ->selectRaw('instalment_id, SUM(amount) as collected')
            ->lockForUpdate()
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->instalment_id => (int) $row->collected])
            ->all();
    }

    /** {@see self::lockedCollectedAmounts()} cho đúng một đợt. */
    protected function lockedCollectedAmount(int $instalmentId): int
    {
        return $this->lockedCollectedAmounts([$instalmentId])[$instalmentId] ?? 0;
    }

    /**
     * Khoá `matters` rồi `contracts`. Chỉ gọi BÊN TRONG transaction, với id đã thăm dò NGOÀI nó.
     *
     * @return array{0: Matter, 1: Contract}
     */
    private function lockMatterAndContract(int $matterId, int $contractId): array
    {
        $matter = $this->scopelessly(Matter::query())->withTrashed()->whereKey($matterId)->lockForUpdate()->firstOrFail();
        $contract = $this->scopelessly(Contract::query())->whereKey($contractId)->lockForUpdate()->firstOrFail();

        $contract->setRelation('matter', $matter);

        return [$matter, $contract];
    }

    /**
     * Khoá `matters`, `contracts`, rồi đúng hàng `instalments` này.
     *
     * @return array{0: Matter, 1: Contract, 2: Instalment}
     */
    private function lockThroughInstalment(int $matterId, int $contractId, int $instalmentId): array
    {
        [$matter, $contract] = $this->lockMatterAndContract($matterId, $contractId);

        $instalment = $this->scopelessly(Instalment::query())->whereKey($instalmentId)->lockForUpdate()->firstOrFail();
        $instalment->setRelation('contract', $contract);

        return [$matter, $contract, $instalment];
    }

    /** Thăm dò không khoá — chỉ gọi NGOÀI transaction (xem luật ở docblock trait). */
    private function matterIdOfContract(int $contractId): int
    {
        $matterId = $this->scopelessly(Contract::query())->whereKey($contractId)->value('matter_id');

        if ($matterId === null) {
            throw (new ModelNotFoundException)->setModel(Contract::class, [$contractId]);
        }

        return (int) $matterId;
    }

    /** Thăm dò không khoá — chỉ gọi NGOÀI transaction (xem luật ở docblock trait). */
    private function contractIdOfInstalment(int $instalmentId): int
    {
        $contractId = $this->scopelessly(Instalment::query())->whereKey($instalmentId)->value('contract_id');

        if ($contractId === null) {
            throw (new ModelNotFoundException)->setModel(Instalment::class, [$instalmentId]);
        }

        return (int) $contractId;
    }

    /** Thăm dò không khoá — chỉ gọi NGOÀI transaction (xem luật ở docblock trait). */
    private function instalmentIdOfPayment(int $paymentId): int
    {
        $instalmentId = $this->scopelessly(Payment::query())->whereKey($paymentId)->value('instalment_id');

        if ($instalmentId === null) {
            throw (new ModelNotFoundException)->setModel(Payment::class, [$paymentId]);
        }

        return (int) $instalmentId;
    }

    /**
     * Lỗi `1020`/`1213` ở đâu đó trong chuỗi exception. Đi cả chuỗi `getPrevious()`: khi transaction
     * này lồng trong một transaction khác (bộ test, `RefreshDatabase`), Laravel bọc lỗi của driver
     * trong một `DeadlockException` không mang `errorInfo`; `QueryException` gốc nằm ở `previous`.
     */
    private static function isRetryableConflict(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && in_array($current->errorInfo[1] ?? null, self::RETRYABLE_DRIVER_CODES, true)) {
                return true;
            }
        }

        return false;
    }
}
