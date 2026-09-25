<?php

namespace App\Support\Billing;

/**
 * Phần thuế GTGT nằm TRONG một tổng đã gồm thuế — chỗ DUY NHẤT tính nó (kế hoạch M9, "Kết luận
 * về VAT"). `contracts.total_amount` luôn là số khách phải trả, đã gồm VAT ở nơi có VAT; nên
 * không bao giờ có phép "cộng thuế vào" ở phía dữ liệu, chỉ có phép tách ra để hiển thị.
 *
 * `thuế = intdiv(total × r, 100 + r)` — làm tròn XUỐNG; phần chưa thuế nhận phần dư, nên
 * `tax() + net() === total` với mọi đầu vào, không lệch một đồng.
 *
 * `null` (không có dòng thuế) và `0` (hoá đơn thuế suất 0%) là hai trạng thái khác nhau của
 * `vat_rate_percent`, nhưng cùng một con số ở đây: không đồng thuế nào. Thuế suất là dữ liệu của
 * từng hợp đồng, không bao giờ là hằng số trong mã.
 */
final class Vat
{
    public static function tax(int $total, ?int $ratePercent): int
    {
        // `null` và `0` đi cùng một đường: intdiv(0, 100) === 0. Không có nhánh riêng nào để một
        // ngày nó lệch khỏi công thức.
        $rate = $ratePercent ?? 0;

        return intdiv($total * $rate, 100 + $rate);
    }

    public static function net(int $total, ?int $ratePercent): int
    {
        return $total - self::tax($total, $ratePercent);
    }
}
