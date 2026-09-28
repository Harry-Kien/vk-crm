<?php

namespace App\Exceptions;

use App\Models\Matter;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\Money;
use DomainException;

/**
 * Chặn xoá mềm một vụ việc khi còn dư nợ trên một hợp đồng `active` (M9 Task 5, "Tiền trên một vụ
 * việc đã đóng, đã lưu trữ, đã bàn giao, đã xoá mềm"). MỘT định nghĩa dư nợ
 * ({@see BillingSummary::outstandingForMatter()}), dùng ở ba chỗ:
 *
 * - Hook `Matter::deleting` ném lớp này — xoá mềm một vụ việc còn nợ làm khoản nợ đó BỐC HƠI khỏi
 *   mọi báo cáo (truy vấn doanh thu bỏ qua vụ xoá mềm), nên phải chặn dù vụ đã ĐÓNG hay chưa. Đây
 *   là chốt chặn cuối cho MỌI đường xoá mềm.
 * - `App\Actions\Matter\CancelMatter` (gộp M6.5 + M9, xung đột 1) hỏi CÙNG hàm đó TRƯỚC lệnh
 *   `delete()` và từ chối bằng `ValidationException` trên ô `reason` (`actions.cancel_matter.
 *   outstanding_balance`) — không ném lớp này, vì hộp thoại "Huỷ hồ sơ" chỉ hiện được một lỗi
 *   của ô; một `DomainException` ở đó là một lỗi 500.
 * - `ClientPolicy::delete()` cộng dư nợ mọi vụ của khách để chặn xoá mềm khách hàng.
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
