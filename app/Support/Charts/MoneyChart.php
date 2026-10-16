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
     * URL của script, kèm `?v=` là 12 ký tự hex đầu của sha256 nội dung tệp — cùng lý do
     * `App\Support\Pwa\RegisterScript::url()`: mẫu máy chủ web giữ `.js` tĩnh một năm `immutable`.
     */
    public static function scriptUrl(): string
    {
        return asset(self::SCRIPT).'?v='.substr((string) hash_file('sha256', public_path(self::SCRIPT)), 0, 12);
    }
}
