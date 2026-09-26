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
}
