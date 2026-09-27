<?php

namespace App\Support\Billing;

use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Models\Instalment;
use App\Models\Payment;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Còn phải thu" của MỘT vụ việc — MỘT định nghĩa, dùng ở mọi chỗ cần chặn hay báo cáo dư nợ (M9
 * Task 5). Không phải nguồn sự thật về tiền (đó vẫn là {@see Payment}, chưa huỷ); đây
 * chỉ là một phép TÍNH LẠI từ nó, giống {@see ScheduleTotal}.
 *
 * **Chỉ hợp đồng `active`** (constraint (a) mang từ Task 4, xem docblock
 * `App\Actions\Billing\CancelContract`): một đợt `pending` của hợp đồng `cancelled`/`completed`
 * KHÔNG tính vào dư nợ — hợp đồng đó không còn hiệu lực, và mọi truy vấn công nợ (widget, trang
 * "Công nợ", nhắc quá hạn) đều phải lọc như vậy để khỏi biến lịch sử của một hợp đồng đã đóng
 * thành một khoản nợ đang đòi.
 *
 * **Trừ phần đã miễn.** `waived` vẫn tính vào TỔNG hợp đồng (bất biến tổng M9, xem
 * `ScheduleTotal::counted()`), vì miễn không đổi `total_amount`; nhưng nó KHÔNG còn là tiền phải
 * đòi, nên {@see Instalment::outstanding()} trả `0` cho một đợt `waived` — và tổng ở
 * đây thừa hưởng đúng điều đó mà không viết lại phép trừ một lần nữa.
 *
 * **Dùng ở hai nơi hôm nay, và một nơi tương lai:** hook `Matter::deleting`, phần mở rộng của
 * `ClientPolicy::delete()` (M6.5 Task 2), và `App\Actions\Matter\CancelMatter` khi nó merge
 * (M6.5 Task 5 — xem ghi chú ở `MatterHasOutstandingBalance`). Không nơi nào trong ba nơi đó được
 * viết lại phép tính này bằng tay.
 *
 * Bỏ `ClientPortalScope` — cùng lý do với `ScheduleTotal::counted()`: dư nợ là sự thật của dữ
 * liệu, không phụ thuộc một phiên cổng khách đang mở song song trong cùng tiến trình.
 */
final class BillingSummary
{
    /**
     * @return array{amount: int, count: int} `amount` — tổng còn phải thu (đồng), đã trừ phần
     *                                        miễn; `count` — số đợt còn dư nợ dương (không đếm đợt `pending` đã thu đủ nhưng cột
     *                                        `status` chưa kịp đồng bộ — {@see Instalment::outstanding()} trả 0 cho trường hợp
     *                                        đó vì nó không đọc theo tổng đã thu, mà theo `status` đã LƯU; đây là chỗ duy nhất
     *                                        khác với `Instalment::state()`, có chủ đích: dư nợ CHẶN XOÁ phải là một câu hỏi về
     *                                        SỰ THẬT đã ghi, không phải một suy luận hiển thị).
     */
    public static function outstandingForMatter(int $matterId): array
    {
        $outstandingAmounts = self::pendingInstalmentsOf($matterId)
            ->get()
            ->map(fn (Instalment $instalment): int => $instalment->outstanding())
            ->filter(fn (int $outstanding): bool => $outstanding > 0);

        return [
            'amount' => (int) $outstandingAmounts->sum(),
            'count' => $outstandingAmounts->count(),
        ];
    }

    /** Có dư nợ hay không — dùng ở nơi chỉ cần biết có/không, không cần con số. */
    public static function hasOutstandingBalance(int $matterId): bool
    {
        return self::outstandingForMatter($matterId)['amount'] > 0;
    }

    /**
     * @return Builder<Instalment>
     */
    private static function pendingInstalmentsOf(int $matterId): Builder
    {
        return Instalment::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('status', InstalmentStatus::Pending->value)
            ->whereHas('contract', fn (Builder $query) => $query
                ->where('matter_id', $matterId)
                ->where('status', ContractStatus::Active->value));
    }

    // =============================================================================================
    // Cách tính TỔNG HỢP bằng SQL — M9 Task 8, phán quyết controller 1 ("SQL-aggregate outstanding").
    // =============================================================================================

    /**
     * MỘT công thức, viết đúng một chỗ: "còn phải thu của một đợt" — `amount` trừ tổng khoản thu
     * CHƯA HUỶ, kẹp dưới 0. Cùng định nghĩa với {@see Instalment::outstanding()}, nhưng viết bằng
     * biểu thức SQL để cơ sở dữ liệu tính GỘP trong một câu SELECT, thay vì một câu SUM() riêng
     * cho từng đợt — {@see BillingSummary::outstandingForMatter()} và
     * `Instalment::outstanding()`/`collectedAmount()` đều đúng nhưng chạy N+1 khi gọi lặp qua một
     * danh sách (trang "Công nợ" Task 8, cột "còn phải thu" của `MattersTable` Task 7).
     *
     * `$amountColumn`/`$idColumn` để MỘT công thức phục vụ được hai ngữ cảnh khác bảng: chạy trên
     * chính `instalments` ({@see self::instalmentAggregatesQuery()}) hay chạy như một cột con
     * tương quan bên trong câu truy vấn của MỘT bảng khác ({@see self::outstandingPerMatterExpression()},
     * alias `i`) — không viết lại phép trừ đó ở hai nơi.
     *
     * `case when … > 0 then … else 0 end`, không `greatest()`/`max(a,b)`: hai hàm đó có nghĩa khác
     * nhau giữa MariaDB (hàm vô hướng `GREATEST`) và SQLite (`max()` đa đối số đóng vai trò đó,
     * nhưng SQLite không có `GREATEST`) — dự án chạy test trên cả hai (SQLite ở `bin/dev test`,
     * MariaDB thật ở `bin/dev test:mariadb`), nên biểu thức phải là SQL chuẩn cả hai hiểu giống
     * nhau. `case when` là SQL chuẩn, không phải một hàm riêng của một hãng.
     */
    private static function outstandingExpression(string $amountColumn, string $idColumn): string
    {
        $diff = "({$amountColumn} - ".self::collectedExpression($idColumn).')';

        return "(case when {$diff} > 0 then {$diff} else 0 end)";
    }

    /** Biểu thức SQL: tổng khoản thu CHƯA HUỶ của một đợt, tương quan theo `$idColumn`. */
    public static function collectedExpression(string $idColumn = 'instalments.id'): string
    {
        return "coalesce((select sum(amount) from payments where payments.instalment_id = {$idColumn} and payments.voided_at is null), 0)";
    }

    /**
     * Mọi đợt còn tính là công nợ (`pending`, hợp đồng `active`) trong MỘT câu truy vấn, kèm hai
     * cột tính sẵn `collected_amount`/`outstanding_amount` — nền của trang "Công nợ" (Task 8): một
     * round-trip cho TOÀN BỘ danh sách, không phải một câu SUM() cho từng dòng.
     *
     * Nơi gọi tự thêm điều kiện phạm vi (`Matter::listableBy`, bộ lọc quá hạn/đến hạn/đã đóng còn
     * nợ/theo khách) và tự nạp quan hệ cần cho hiển thị — hàm này chỉ giữ đúng BỘ LỌC và HAI CỘT
     * mà mọi nơi dùng đều cần, không viết lại chúng.
     *
     * @return Builder<Instalment>
     */
    public static function pendingInstalmentsQuery(): Builder
    {
        return Instalment::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('status', InstalmentStatus::Pending->value)
            ->whereHas('contract', fn (Builder $query) => $query->where('status', ContractStatus::Active->value))
            ->selectRaw('instalments.*')
            ->selectRaw(self::collectedExpression().' as collected_amount')
            ->selectRaw(self::outstandingExpression('instalments.amount', 'instalments.id').' as outstanding_amount');
    }

    /**
     * Biểu thức SQL: tổng còn phải thu của MỘT vụ việc, tương quan `matters.id` của câu truy vấn
     * NGOÀI (`MattersTable` Task 7, cột "còn phải thu") — cùng công thức với
     * {@see self::pendingInstalmentsQuery()}, chỉ đổi bảng gốc từ `instalments` sang một subquery
     * `instalments i JOIN contracts c`, để MỘT câu SELECT liệt kê nhiều vụ việc tính được cột này
     * cho MỌI dòng cùng lúc, thay vì `outstandingForMatter()` gọi lặp mỗi dòng của bảng liệt kê.
     */
    public static function outstandingPerMatterExpression(): string
    {
        $perInstalment = self::outstandingExpression('i.amount', 'i.id');

        return 'coalesce((select sum('.$perInstalment.') from instalments i '
            .'inner join contracts c on c.id = i.contract_id '
            ."where c.matter_id = matters.id and c.status = '".ContractStatus::Active->value."' "
            ."and i.status = '".InstalmentStatus::Pending->value."'), 0)";
    }
}
