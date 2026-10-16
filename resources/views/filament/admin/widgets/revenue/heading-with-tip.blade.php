{{--
    Tiêu đề widget kèm nút ⓘ (làn rv, 2026-10-15) — dùng cho `chart-with-table` và bảng
    `ClosedWithBalanceWidget`. Biến: `heading` (chuỗi hay Htmlable), `explanation` (chữ của ⓘ).
--}}
@php
    $headingText = $heading instanceof \Illuminate\Contracts\Support\Htmlable ? strip_tags($heading->toHtml()) : (string) $heading;
@endphp
<span style="display: inline-flex; align-items: center; gap: 4px; flex-wrap: wrap;">
    <span>{{ $heading }}</span>
    <x-info-tip :text="$explanation" :label="$headingText" />
</span>
