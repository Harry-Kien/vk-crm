<?php

namespace App\Exceptions;

use App\Models\Instalment;
use DomainException;

/**
 * Một đợt thanh toán được yêu cầu ghi khoản thu hoặc miễn, nhưng nó không ở trạng thái cho phép
 * việc đó ngay bây giờ (M9 Task 5). Hai lý do khác nhau, hai thân hàm khác nhau — cùng lớp vì cả
 * hai đều trả lời câu "sao không thao tác được trên đợt này":
 *
 * - {@see self::toRecordPayment()} / {@see self::toWaive()} — `instalments.status` không phải
 *   `pending`: đã thu đủ (`paid`), đã miễn (`waived`), hay đã huỷ (`cancelled`) rồi.
 * - {@see self::contractNotActive()} — đợt vẫn `pending` (M9 quyết định — hợp đồng huỷ hay hoàn
 *   tất KHÔNG chạm tới đợt, xem `CancelContract`/`CompleteContract`), nhưng hợp đồng cha không
 *   còn `active`. Một đợt như vậy là lịch sử; ghi thêm tiền hay miễn nó là sửa một cuốn sổ đã
 *   đóng — đường đi đúng là phụ lục (nếu hợp đồng còn `active`) hoặc chấp nhận nó đứng nguyên như
 *   một khoản chưa xong của một hợp đồng đã kết thúc.
 */
class InstalmentNotPayable extends DomainException
{
    public static function toRecordPayment(Instalment $instalment): self
    {
        return new self(__('billing.errors.instalment_not_payable_to_record', [
            'name' => $instalment->name,
            'status' => $instalment->status->label(),
        ]));
    }

    public static function toWaive(Instalment $instalment): self
    {
        return new self(__('billing.errors.instalment_not_payable_to_waive', [
            'name' => $instalment->name,
            'status' => $instalment->status->label(),
        ]));
    }

    public static function contractNotActive(Instalment $instalment): self
    {
        return new self(__('billing.errors.instalment_contract_not_active', [
            'name' => $instalment->name,
            'code' => $instalment->contract->code,
            'status' => $instalment->contract->status->label(),
        ]));
    }
}
