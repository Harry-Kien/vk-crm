<?php

namespace App\Exceptions;

use App\Models\Matter;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\Money;
use DomainException;

/**
 * Chặn xoá (vụ việc) hoặc xoá mềm (khách hàng) khi còn dư nợ trên một hợp đồng `active` (M9 Task
 * 5, "Tiền trên một vụ việc đã đóng, đã lưu trữ, đã bàn giao, đã xoá mềm"). Ném ở HAI chỗ, cùng
 * một định nghĩa dư nợ ({@see BillingSummary::outstandingForMatter()}):
 *
 * - Hook `Matter::deleting` — xoá mềm một vụ việc còn nợ làm khoản nợ đó BỐC HƠI khỏi mọi báo cáo
 *   (truy vấn doanh thu bỏ qua vụ xoá mềm), nên phải chặn ở đây dù vụ đã ĐÓNG (`closed_at` khác
 *   null) hay chưa — xoá mềm luôn bị chặn, không như đóng hồ sơ.
 * - `App\Actions\Matter\CancelMatter` (M6.5 Task 5, CHƯA merge lúc Task 5 này viết — xem báo cáo):
 *   khi nó merge, nó PHẢI gọi lại đúng {@see BillingSummary::outstandingForMatter()}
 *   và ném lớp này, không viết một kiểm tra dư nợ thứ hai.
 *
 * Thông điệp nêu số tiền VÀ số đợt còn lại (tiền lệ SPEC §6.11, `OpenWork`/`team_member_has_open_work`).
 */
class MatterHasOutstandingBalance extends DomainException
{
    public static function make(Matter $matter, int $outstandingAmount, int $instalmentCount): self
    {
        return new self(__('exceptions.matter_has_outstanding_balance', [
            'code' => $matter->code,
            'amount' => Money::format($outstandingAmount),
            'count' => $instalmentCount,
        ]));
    }
}
