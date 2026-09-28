<?php

namespace App\Support\Billing;

use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Exceptions\ContractTotalMismatch;
use App\Models\Contract;
use App\Models\Instalment;
use App\Support\Scopes\ClientPortalScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Bất biến tổng của M9 — định nghĩa MỘT chỗ, dùng bởi cả bốn tầng giữ nó:
 *
 *     SUM(amount) của các đợt CHƯA HUỶ của một hợp đồng === contracts.total_amount
 *
 * số nguyên đồng, không dung sai. Đợt `cancelled` không tính: huỷ một đợt của hợp đồng `active`
 * chỉ đi qua phụ lục (`AmendContract`), và phụ lục đổi `total_amount` cùng lúc. Đợt `waived` VẪN
 * tính: miễn là bớt số khách phải trả trên một hợp đồng không đổi giá trị (Task 5 trừ phần đã miễn
 * khi tính "còn phải thu"), không phải một phụ lục. `paid`/`pending` hiển nhiên tính.
 *
 * Bốn tầng (kế hoạch M9 Task 4):
 *  1. `ActivateContract` — lệch thì không rời được `draft`.
 *  2. Hook `saving` của `Instalment` và `updating` của `Contract` — {@see self::assertInstalmentWriteKeepsBalance()},
 *     {@see self::assertContractWriteKeepsBalance()}: từ chối mọi lần ghi qua model làm lệch tổng
 *     trên hợp đồng `active`. Chúng chặn đúng đường đi vòng qua Action.
 *  3. `AmendContract` — ghi giá trị mới và lịch mới trong một transaction, bên trong
 *     {@see self::whileAmending()}, rồi kiểm lại tổng từ DB trước khi commit.
 *  4. `billing:check-invariants` — {@see self::mismatchedActiveContracts()}.
 *
 * **Tầng 2 chỉ canh đường Eloquent có sự kiện.** `DB::table('instalments')->update(...)`,
 * `Instalment::query()->update(...)`, SQL thô, và cả `saveQuietly()` / `Model::withoutEvents()`
 * (tắt sự kiện model nên hook không chạy) đi vòng qua hook — cùng giới hạn đã ghi ở
 * `ContractAmendment` và `Client::booted()`. Tầng 4 là thứ bắt được chúng, sau khi đã xảy ra.
 * Hook cũng không khoá: hai lần ghi qua model đồng thời trên cùng một hợp đồng có thể cùng đọc một
 * tổng cũ. Đường ghi hợp lệ duy nhất đổi số tiền (phụ lục) khoá hàng `contracts` trước.
 *
 * **Tạo thẳng một hợp đồng ở trạng thái `active` không bị canh** (`Contract::create([... 'status'
 * => 'active'])`): hook của `Contract` là `updating`, không phải `saving`. Không Action nào làm
 * vậy — `DraftContract` luôn tạo `draft` — và đó là cách factory dựng fixture. Tầng 4 bắt được một
 * hợp đồng như thế nếu nó lọt vào dữ liệu thật.
 */
final class ScheduleTotal
{
    /**
     * Số lần `whileAmending()` đang mở cho từng hợp đồng (đếm, không phải cờ, để một lần gọi lồng
     * không mở khoá sớm cho lần bao ngoài).
     *
     * @var array<int, int>
     */
    private static array $amending = [];

    /**
     * Các đợt được tính vào bất biến: mọi đợt trừ `cancelled`.
     *
     * Mọi truy vấn của lớp này bỏ `ClientPortalScope`: dưới một phiên cổng khách đang mở (hai
     * panel dùng chung cookie), bốn model tiền trả KHÔNG hàng nào, và một lần đọc lại có scope sẽ
     * thấy "không có hợp đồng" rồi âm thầm bỏ qua kiểm tra — một cái cổng mở vì một phiên của
     * người khác. Bất biến là sự thật của dữ liệu, không phụ thuộc ai đang xem.
     */
    public static function counted(): Builder
    {
        return Instalment::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('status', '!=', InstalmentStatus::Cancelled->value);
    }

    private static function contracts(): Builder
    {
        return Contract::query()->withoutGlobalScope(ClientPortalScope::class);
    }

    /** Tổng các đợt được tính của một hợp đồng, đọc từ DB (không từ quan hệ đã nạp). */
    public static function of(int $contractId): int
    {
        return (int) self::counted()->where('contract_id', $contractId)->sum('amount');
    }

    /**
     * {@see self::of()}, đọc bằng một lần đọc CÓ KHOÁ (`… for update` trên `instalments`) — cho
     * Action tiền dùng khi con số này QUYẾT ĐỊNH (tầng 1 `ActivateContract`, tầng 3
     * `AmendContract`). Lần đọc có khoá luôn thấy bản commit mới nhất, không phải ảnh chụp
     * REPEATABLE READ của transaction (luật ở docblock `App\Actions\Billing\Concerns\LocksBillingRows`).
     * Chỉ gọi bên trong một transaction đã khoá `matters` → `contracts` của hợp đồng này: `instalments`
     * đứng sau hai bảng đó trong thứ tự khoá.
     *
     * Hook tầng 2 dùng {@see self::of()} (không khoá): nó chạy cả ngoài Action, và docblock lớp đã
     * nói thẳng hook không khoá.
     */
    public static function lockedOf(int $contractId): int
    {
        return (int) self::counted()->where('contract_id', $contractId)->lockForUpdate()->sum('amount');
    }

    /**
     * Chạy `$callback` với tầng 2 tạm tắt cho ĐÚNG hợp đồng này, và chỉ trong lúc callback chạy.
     * Chỉ `AmendContract` dùng: một phụ lục đổi giá trị và nhiều đợt, và giữa các lần ghi đó tổng
     * lệch là chuyện tất yếu. Người gọi PHẢI tự kiểm lại tổng sau callback, trước khi commit — tầng
     * 3 làm đúng việc đó.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function whileAmending(Contract $contract, Closure $callback): mixed
    {
        $id = (int) $contract->getKey();
        self::$amending[$id] = (self::$amending[$id] ?? 0) + 1;

        try {
            return $callback();
        } finally {
            if (--self::$amending[$id] === 0) {
                unset(self::$amending[$id]);
            }
        }
    }

    public static function isBeingAmended(int $contractId): bool
    {
        return isset(self::$amending[$contractId]);
    }

    /**
     * Tầng 2, phía đợt (hook `saving` của `Instalment`). Chỉ khi đợt mới được tạo, hoặc một trong
     * ba cột quyết định tổng đổi (`amount`, `status` — vào/ra `cancelled` —, `contract_id`): một
     * lần ghi chỉ đổi `due_date` hay `note` không đổi tổng, và không bị chặn kể cả khi hợp đồng đã
     * lệch sẵn (bởi một lần ghi thô mà tầng 4 sẽ báo).
     *
     * Kiểm CẢ hợp đồng cũ và mới khi `contract_id` đổi: dời một đợt khỏi hợp đồng `active` làm hụt
     * tổng của hợp đồng đó, dù hợp đồng nhận là bản nháp.
     *
     * Hợp đồng đọc lại từ DB, không từ quan hệ `$instalment->contract` đã nạp: quan hệ đó có thể
     * cũ hơn trạng thái thật (vừa kích hoạt, vừa đổi giá trị).
     */
    public static function assertInstalmentWriteKeepsBalance(Instalment $instalment): void
    {
        if ($instalment->exists && ! $instalment->isDirty(['amount', 'status', 'contract_id'])) {
            return;
        }

        $contractIds = array_unique(array_filter([
            (int) $instalment->getOriginal('contract_id'),
            (int) $instalment->contract_id,
        ]));

        foreach ($contractIds as $contractId) {
            $contract = self::contracts()->find($contractId);

            if ($contract === null || $contract->status !== ContractStatus::Active || self::isBeingAmended($contractId)) {
                continue;
            }

            $others = (int) self::counted()
                ->where('contract_id', $contractId)
                ->when($instalment->exists, fn (Builder $query) => $query->whereKeyNot($instalment->getKey()))
                ->sum('amount');

            $own = (int) $instalment->contract_id === $contractId && $instalment->status !== InstalmentStatus::Cancelled
                ? (int) $instalment->amount
                : 0;

            if ($others + $own !== $contract->total_amount) {
                throw ContractTotalMismatch::onWrite($contract, $others + $own);
            }
        }
    }

    /**
     * Tầng 2, phía hợp đồng (hook `updating` của `Contract`): một lần ghi mà SAU nó hợp đồng ở
     * `active`, và đổi `status` (tức vừa được đưa vào `active`) hoặc `total_amount`, phải để lại
     * một hợp đồng khớp tổng. Chặn `$contract->update(['total_amount' => ...])` đi vòng qua phụ lục,
     * và `$contract->update(['status' => 'active'])` đi vòng qua `ActivateContract`. Rời `active`
     * (hoàn tất, huỷ) không bị hỏi: sau lần ghi đó hợp đồng không còn ở `active`.
     */
    public static function assertContractWriteKeepsBalance(Contract $contract): void
    {
        if ($contract->status !== ContractStatus::Active
            || ! $contract->isDirty(['status', 'total_amount'])
            || self::isBeingAmended((int) $contract->getKey())) {
            return;
        }

        $scheduleTotal = self::of((int) $contract->getKey());

        if ($scheduleTotal !== $contract->total_amount) {
            throw ContractTotalMismatch::onWrite($contract, $scheduleTotal);
        }
    }

    /**
     * Tầng 4: mọi hợp đồng `active` mà tổng các đợt được tính khác `total_amount`, kèm
     * `schedule_total` đã đọc. Một truy vấn (tổng tính bằng truy vấn con), so sánh ở PHP để không
     * phụ thuộc cách từng CSDL trả kiểu của `SUM()`. Hợp đồng không có đợt nào được tính thì
     * `SUM()` là `NULL`, và `(int) null === 0` — đúng con số cần so.
     *
     * @return Collection<int, Contract>
     */
    public static function mismatchedActiveContracts(): Collection
    {
        return self::contracts()
            ->where('status', ContractStatus::Active->value)
            ->select('contracts.*')
            ->selectSub(
                self::counted()->selectRaw('SUM(amount)')->whereColumn('instalments.contract_id', 'contracts.id'),
                'schedule_total',
            )
            ->orderBy('code')
            ->get()
            ->filter(fn (Contract $contract) => (int) $contract->getAttribute('schedule_total') !== $contract->total_amount)
            ->values();
    }

    /** Số hợp đồng `active` đã quét — để lệnh nói "sạch trên N hợp đồng", không chỉ "sạch". */
    public static function activeContractCount(): int
    {
        return self::contracts()->where('status', ContractStatus::Active->value)->count();
    }
}
