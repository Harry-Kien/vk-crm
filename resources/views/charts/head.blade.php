{{--
    Làn rv (2026-10-15): định dạng tiền tiếng Việt cho trục và tooltip biểu đồ — App\Support\Charts\MoneyChart.
    Thẻ có `src` ('self' trong CSP, không nội tuyến), KHÔNG `defer`: phải chạy trước khi component biểu
    đồ của Filament (tải lười) dựng Chart.js và đọc `window.filamentChartJsPlugins`.
--}}
<script src="{{ \App\Support\Charts\MoneyChart::scriptUrl() }}"></script>
