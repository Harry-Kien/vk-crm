/*
 * Trục tiền rút gọn ("60 tr", "1,2 tỷ") và tooltip đủ chữ số ("60.000.000 ₫") cho biểu đồ Chart.js của
 * panel nội bộ (làn rv, 2026-10-15). Lý do và hợp đồng: App\Support\Charts\MoneyChart.
 *
 * Plugin chỉ tác dụng lên biểu đồ có `options.plugins.vkMoney.enabled` (cờ do PHP khai). Ở `beforeInit`
 * nó gắn `ticks.callback` cho trục giá trị (y; x khi `indexAxis = 'y'`) và `tooltip.callbacks.label`;
 * Chart.js dựng lại tuỳ chọn ở lần `update()` đầu tiên ngay sau đó, nên hai hàm có hiệu lực từ lần vẽ đầu.
 *
 * compact() phải trùng App\Support\Billing\Money::compact(): đơn vị theo độ lớn (nghìn/tr/tỷ), một chữ
 * số lẻ, dấu phẩy thập phân, bỏ ",0", làm tròn chạm 1000 thì lên đơn vị kế tiếp.
 */
(function () {
    'use strict';

    var UNITS = [[1e3, 'nghìn'], [1e6, 'tr'], [1e9, 'tỷ']];

    function group(whole) {
        return String(whole).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function compact(value) {
        var number = Number(value);

        if (!isFinite(number)) {
            return String(value);
        }

        var sign = number < 0 ? '-' : '';
        var abs = Math.abs(number);

        for (var i = UNITS.length - 1; i >= 0; i--) {
            if (abs < UNITS[i][0]) {
                continue;
            }

            var shown = Math.round(abs / UNITS[i][0] * 10) / 10;

            if (shown >= 1000 && UNITS[i + 1]) {
                i++;
                shown = Math.round(abs / UNITS[i][0] * 10) / 10;
            }

            var whole = Math.floor(shown);
            var tenth = Math.round((shown - whole) * 10);

            return sign + group(whole) + (tenth ? ',' + tenth : '') + ' ' + UNITS[i][1];
        }

        return sign + group(Math.round(abs));
    }

    function full(value) {
        var number = Math.round(Number(value) || 0);

        return (number < 0 ? '-' : '') + group(Math.abs(number)) + ' ₫';
    }

    var plugin = {
        id: 'vkMoney',
        beforeInit: function (chart) {
            var options = chart.config.options || {};
            var marker = options.plugins && options.plugins.vkMoney;

            if (!marker || !marker.enabled) {
                return;
            }

            var type = chart.config.type;
            var round = type === 'pie' || type === 'doughnut';
            var valueAxis = options.indexAxis === 'y' ? 'x' : 'y';

            if (!round) {
                options.scales = options.scales || {};
                options.scales[valueAxis] = options.scales[valueAxis] || {};
                options.scales[valueAxis].ticks = options.scales[valueAxis].ticks || {};
                // 2026-10-21: hai vạch kề nhau rút gọn ra CÙNG một chữ ("0, 0, 0, 1, 1, 1" trong ảnh của chủ văn
                // phòng) thì vạch sau không in nhãn. PHP đã đặt trục từ 0 với vạch nguyên (MoneyChart::valueAxis());
                // đây là lưới cuối cho trường hợp chưa nghĩ tới.
                options.scales[valueAxis].ticks.callback = function (tick, index, ticks) {
                    var label = compact(tick);

                    if (index > 0 && ticks && ticks[index - 1] && compact(ticks[index - 1].value) === label) {
                        return '';
                    }

                    return label;
                };
            }

            options.plugins.tooltip = options.plugins.tooltip || {};
            options.plugins.tooltip.callbacks = options.plugins.tooltip.callbacks || {};
            options.plugins.tooltip.callbacks.label = function (context) {
                var raw = round ? context.parsed : (context.parsed && context.parsed[valueAxis]);
                var name = round ? context.label : context.dataset.label;

                return (name ? name + ': ' : '') + full(raw);
            };
        },
    };

    window.filamentChartJsPlugins = window.filamentChartJsPlugins || [];
    window.filamentChartJsPlugins.push(plugin);
    window.vkChartMoney = { compact: compact, full: full };
})();
