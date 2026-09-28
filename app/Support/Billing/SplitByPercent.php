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
        $basisPoints = array_map(fn ($percent) => self::basisPoints($percent), array_values($percents));

        if (array_sum($basisPoints) !== 10_000) {
            throw ValidationException::withMessages(['percents' => [__('billing.validation.percents_must_total_100')]]);
        }

        $amounts = [];

        foreach (array_slice($basisPoints, 0, -1) as $points) {
            $amounts[] = self::partOf($total, $points);
        }

        $amounts[] = $total - array_sum($amounts);

        return $amounts;
    }

    /**
     * Số tiền của MỘT phần trăm trên một tổng — cùng phép làm tròn XUỐNG mà {@see self::split()}
     * dùng cho mọi đợt trừ đợt cuối (một chỗ tính: {@see self::partOf()}).
     *
     * @throws ValidationException
     */
    public static function part(int $total, int|float|string $percent, string $field = 'percents'): int
    {
        return self::partOf($total, self::basisPoints($percent, $field));
    }

    /**
     * Số tiền của từng dòng lịch thu mà người dùng nhập bằng PHẦN TRĂM, trong form (lượt rà soát
     * cuối M9, I5) — xem trước lúc gõ VÀ tính lại lúc lưu bằng CÙNG hàm này, để số người dùng thấy là
     * số được lưu. Dòng không có phần trăm (`null`/chuỗi rỗng — người dùng gõ số tiền) trả `null`
     * ở đúng vị trí đó.
     *
     * - **Mọi dòng đều có phần trăm VÀ cộng lại đúng 100%** → {@see self::split()}: đợt cuối nhận phần
     *   dư làm tròn, tổng các đợt bằng ĐÚNG `$total`.
     * - **Còn lại** (có dòng gõ số tiền, hoặc phần trăm chưa đủ/vượt 100% — ví dụ lúc đang gõ dở, hay
     *   một phụ lục chỉ đổi vài đợt) → mỗi dòng phần trăm là {@see self::part()} (làm tròn xuống);
     *   không dòng nào "nhận phần dư", vì các dòng này không mô tả trọn tổng. Bất biến tổng vẫn do
     *   Action kiểm (`ActivateContract`, `AmendContract`), không phải hàm này.
     *
     * **Giữ nguyên khoá của `$percents`** — khoá là chỉ số dòng trong form, nên một phụ lục truyền
     * CHỈ các dòng thêm/sửa (bỏ dòng huỷ) vẫn nhận lại đúng chỉ số của từng dòng, và phần trăm sai
     * định dạng ném `ValidationException` trên `"{$fieldPrefix}.{khoá}.percent_basis"` — đúng ô của
     * dòng đó.
     *
     * @param  array<int, mixed>  $percents  khoá = chỉ số dòng; `null`/chuỗi rỗng = dòng gõ số tiền
     * @return array<int, int|null> cùng khoá với `$percents`
     *
     * @throws ValidationException
     */
    public static function amountsForRows(int $total, array $percents, string $fieldPrefix = 'instalments'): array
    {
        $basisPoints = [];

        foreach ($percents as $index => $percent) {
            if (self::isBlank($percent)) {
                $basisPoints[$index] = null;

                continue;
            }

            $field = "{$fieldPrefix}.{$index}.percent_basis";
            $basisPoints[$index] = self::basisPoints($percent, $field);

            // Trần 100% của MỘT dòng — cùng trần `ValidatesBillingInput::validatedPercentBasis()`
            // đặt cho `percent_basis`, để xem trước không hiện một số tiền lớn hơn cả tổng.
            if ($basisPoints[$index] > 10_000) {
                throw ValidationException::withMessages([$field => [__('billing.validation.percent_out_of_range')]]);
            }
        }

        if ($basisPoints !== [] && ! in_array(null, $basisPoints, true) && array_sum($basisPoints) === 10_000) {
            return array_combine(array_keys($percents), self::split($total, array_values($percents)));
        }

        // `array_map()` với MỘT mảng giữ nguyên khoá.
        return array_map(
            fn (?int $points): ?int => $points === null ? null : self::partOf($total, $points),
            $basisPoints,
        );
    }

    /** `intdiv(total × phần vạn, 10000)` — phép làm tròn xuống DUY NHẤT của lớp này. */
    private static function partOf(int $total, int $basisPoints): int
    {
        return intdiv($total * $basisPoints, 10_000);
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
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
     * Một phần trăm thành PHẦN VẠN nguyên: `33.33` → `3333`. Cách đọc một phần trăm DUY NHẤT của
     * hệ thống — `DraftContract`/`AmendContract` dùng nó cho `percent_basis`. Từ chối số âm, số 0
     * và quá hai chữ số thập phân (lỗi gắn trên `$field`).
     *
     * Không có trần 100% ở đây: trong `split()` mọi phần ≥ 0,01% và tổng phải đúng 100% thì không
     * phần nào vượt 100% được; nơi đọc MỘT phần trăm đứng riêng (`percent_basis`) tự đặt trần.
     *
     * @throws ValidationException
     */
    public static function basisPoints(int|float|string $percent, string $field = 'percents'): int
    {
        $text = is_string($percent) ? trim($percent) : (string) $percent;

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $match) !== 1) {
            throw ValidationException::withMessages([$field => [__('billing.validation.percent_out_of_range')]]);
        }

        $points = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');

        if ($points < 1) {
            throw ValidationException::withMessages([$field => [__('billing.validation.percent_out_of_range')]]);
        }

        return $points;
    }
}
