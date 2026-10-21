<?php

namespace App\Support\Charts;

use App\Support\Billing\Money;

/**
 * Trục tiền dạng rút gọn ("60 tr", "1,2 tỷ") và tooltip đủ chữ số ("60.000.000 ₫") cho biểu đồ Chart.js
 * (làn rv, 2026-10-15 — trước đó trục ghi "60,000,000", kiểu số tiếng Anh).
 *
 * **Vì sao một tệp script tĩnh, không `RawJs` trong `getOptions()`.** Chart.js chỉ định dạng nhãn trục
 * và tooltip bằng HÀM (`ticks.callback`, `tooltip.callbacks.label`); `getOptions()` của các widget là
 * mảng PHP thuần (khuôn M9, xem `chart-with-table.blade.php`), nên không mang được hàm. Thay vào đó:
 *
 *  - widget có trục tiền khai {@see self::pluginOptions()} trong `plugins` của `getOptions()` — một cờ
 *    dữ liệu thuần, đi qua `@js()` như mọi tuỳ chọn khác;
 *  - `public/charts/money.js` (tải ở `<head>` của panel nội bộ, {@see self::scriptUrl()}) đăng ký một
 *    plugin Chart.js qua `window.filamentChartJsPlugins` — cơ chế Filament dành cho plugin của từng biểu
 *    đồ — và ở `beforeInit` gắn hai hàm định dạng cho biểu đồ nào mang cờ đó. Trục giá trị tự suy từ
 *    `indexAxis` (cột ngang: trục x), nên đổi dạng cột/đường/tròn (`HasSwitchableChartKind`) không cần
 *    khai lại; biểu đồ tròn chỉ có tooltip.
 *
 * Script là tệp `'self'` (CSP `script-src 'self'`, không nội tuyến, không nonce), chạy đồng bộ trong
 * `<head>` để có mặt trước khi component biểu đồ (tải lười bằng `x-load`) dựng Chart. Quy tắc rút gọn
 * của script trùng {@see Money::compact()} — sửa một bên thì sửa cả bên kia.
 *
 * Biểu đồ đếm (số vụ, số liên hệ) không mang cờ: trục của chúng là số trần.
 */
final class MoneyChart
{
    /** Id của plugin Chart.js — khoá trong `options.plugins`, trùng `id` trong `public/charts/money.js`. */
    public const PLUGIN = 'vkMoney';

    /** Đường dẫn của script trong `public/`. */
    public const SCRIPT = 'charts/money.js';

    /** Số ký tự tối đa một dòng của nhãn trục biểu đồ ({@see self::wrapLabel()}). */
    public const LABEL_LINE_WIDTH = 28;

    /**
     * Ngắt một nhãn dài thành các dòng ngắn theo từ — Chart.js vẽ một mảng chuỗi thành nhiều dòng. Không có
     * bước này, nhãn dài của trục chữ (cột ngang) bị cắt mất phần ĐẦU (2026-10-21). Một từ dài hơn một dòng
     * không bị chặt đôi.
     *
     * @return list<string>
     */
    public static function wrapLabel(string $label, int $width = self::LABEL_LINE_WIDTH): array
    {
        $lines = [];
        $current = '';

        foreach (preg_split('/\s+/u', trim($label)) ?: [] as $word) {
            if ($current !== '' && mb_strlen($current.' '.$word) > $width) {
                $lines[] = $current;
                $current = $word;

                continue;
            }

            $current = $current === '' ? $word : $current.' '.$word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * Cờ cho `options.plugins` của một biểu đồ có trục tiền.
     *
     * @return array{vkMoney: array{enabled: true}}
     */
    public static function pluginOptions(): array
    {
        return [self::PLUGIN => ['enabled' => true]];
    }

    /**
     * Cấu hình của TRỤC GIÁ TRỊ (tiền hay số đếm) cho biểu đồ cột/đường của trang Doanh thu (2026-10-21, ảnh của
     * chủ văn phòng: nhãn trục lặp "0, 0, 0, 1, 1, 1"). Bắt đầu từ 0 (một cột không bắt đầu từ 0 phóng đại chênh
     * lệch, và Chart.js không tự lấy 0 cho dạng đường) và vạch là số nguyên (`precision: 0`) — không còn vạch
     * 0,2 / 0,4 / … mà bộ định dạng rút gọn làm tròn thành cùng một chữ. Từ 0 lên, với tối đa 11 vạch (mặc định
     * của Chart.js) khoảng vạch "đẹp" (1, 2, 5 × 10ⁿ) không nhỏ hơn khoảng một phần mười giá trị lớn nhất, nên hai vạch kề
     * nhau rút gọn (một chữ số lẻ) ra hai chữ khác nhau; `public/charts/money.js` vẫn bỏ một nhãn trùng nhãn ngay
     * trước nó, phòng một trường hợp chưa nghĩ tới.
     *
     * @return array{beginAtZero: true, ticks: array{precision: 0}}
     */
    public static function valueAxis(): array
    {
        return ['beginAtZero' => true, 'ticks' => ['precision' => 0]];
    }

    /**
     * URL của script, kèm `?v=` là 12 ký tự hex đầu của sha256 nội dung tệp — cùng lý do
     * `App\Support\Pwa\RegisterScript::url()`: mẫu máy chủ web giữ `.js` tĩnh một năm `immutable`.
     */
    public static function scriptUrl(): string
    {
        return asset(self::SCRIPT).'?v='.substr((string) hash_file('sha256', public_path(self::SCRIPT)), 0, 12);
    }
}
