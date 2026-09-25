<?php

namespace App\Support\Billing;

use Illuminate\Validation\ValidationException;

/**
 * Chia một tổng tiền thành các đợt — chỗ DUY NHẤT quyết định phần dư đi đâu (M9 quyết định 3):
 * **mọi đợt trừ đợt cuối lấy phần làm tròn xuống; đợt cuối lấy phần còn lại.** Nên tổng các đợt
 * luôn bằng ĐÚNG tổng đem chia, và bất biến `SUM(instalments.amount) === total_amount` không bao
 * giờ lệch vì một đồng làm tròn.
 *
 * Kết quả là thứ màn hình hiện ra bằng số đồng TRƯỚC khi lưu; con số được lưu vào
 * `instalments.amount` là con số người dùng đã thấy (và có thể sửa tay). Phần trăm người dùng gõ
 * đi vào `instalments.percent_basis` chỉ để hiển thị và truy vết — không Action nào tính lại
 * `amount` từ nó.
 *
 * Phần trăm đọc thành PHẦN VẠN nguyên (33,33% → 3333) trước khi nhân, vì `percent_basis` là
 * `decimal(5,2)`: tính bằng số thực thì `0.1 + 0.2` lệch, và một đồng lệch ở đây là một hợp đồng
 * không kích hoạt được.
 */
final class SplitByPercent
{
    /**
     * `intdiv(total × p, 100)` cho mọi đợt trừ đợt cuối (tính trên phần vạn), đợt cuối nhận phần
     * còn lại. Các phần trăm phải cộng lại ĐÚNG 100 — nếu không, "phần còn lại" sẽ âm thầm nuốt
     * một lỗi gõ (30/30/30 thành 30/30/40).
     *
     * @param  list<int|float|string>  $percents  mỗi phần trăm > 0, ≤ 100, tối đa hai chữ số thập phân
     * @return list<int>
     *
     * @throws ValidationException
     */
    public static function split(int $total, array $percents): array
    {
        // Danh sách rỗng không cần nhánh riêng: tổng của nó là 0, không phải 100.
        $basisPoints = array_map(self::toBasisPoints(...), array_values($percents));

        if (array_sum($basisPoints) !== 10_000) {
            throw ValidationException::withMessages(['percents' => [__('billing.validation.percents_must_total_100')]]);
        }

        $amounts = [];

        foreach (array_slice($basisPoints, 0, -1) as $points) {
            $amounts[] = intdiv($total * $points, 10_000);
        }

        $amounts[] = $total - array_sum($amounts);

        return $amounts;
    }

    /**
     * Chia đều `$count` phần: mỗi phần `intdiv(total, count)`, phần cuối nhận phần dư
     * (10.000.000 / 3 → 3.333.333 / 3.333.333 / 3.333.334). Cùng luật phần dư với `split()`.
     *
     * @return list<int>
     *
     * @throws ValidationException
     */
    public static function evenly(int $total, int $count): array
    {
        if ($count < 1) {
            throw ValidationException::withMessages(['percents' => [__('billing.validation.split_needs_parts')]]);
        }

        $amounts = array_fill(0, $count - 1, intdiv($total, $count));
        $amounts[] = $total - array_sum($amounts);

        return $amounts;
    }

    /**
     * `33.33` → `3333`. Từ chối số âm, số 0 và quá hai chữ số thập phân. Không cần trần 100% riêng
     * cho từng phần: mọi phần ≥ 0,01% và tổng phải đúng 100% (ở `split()`) thì không phần nào vượt
     * 100% được.
     */
    private static function toBasisPoints(int|float|string $percent): int
    {
        $text = is_string($percent) ? trim($percent) : (string) $percent;

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $match) !== 1) {
            throw ValidationException::withMessages(['percents' => [__('billing.validation.percent_out_of_range')]]);
        }

        $points = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');

        if ($points < 1) {
            throw ValidationException::withMessages(['percents' => [__('billing.validation.percent_out_of_range')]]);
        }

        return $points;
    }
}
