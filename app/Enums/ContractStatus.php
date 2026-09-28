<?php

namespace App\Enums;

/**
 * Vòng đời hợp đồng dịch vụ pháp lý (M9 §"Mô hình dữ liệu" — `contracts.status`).
 * `draft` xoá cứng được (chưa có khoản thu nào); từ `active` trở đi chỉ đóng lại bằng
 * `completed` hoặc `cancelled`, không bao giờ xoá — xem `Contract::booted()`.
 */
enum ContractStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('enums.contract_status.'.$this->value);
    }

    /**
     * Khoản thu của một hợp đồng ở trạng thái này có HUỶ được không — MỘT định nghĩa, cho cả
     * `App\Actions\Billing\VoidPayment` (chốt chặn thật) lẫn nút "Huỷ khoản thu" (chỉ hiện khi
     * huỷ được). Hợp đồng đã hoàn tất thì không (phán quyết C1 của lượt rà soát cuối M9: huỷ ở đó
     * mở ra một khoản nợ không màn hình nào thấy); đang hiệu lực và đã huỷ thì được.
     */
    public function allowsPaymentVoid(): bool
    {
        return $this !== self::Completed;
    }
}
