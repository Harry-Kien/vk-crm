{{--
    Hàng ô số đầu trang Doanh thu (làn rv, 2026-10-15), `RevenueKpiWidget::cards()`.

    Dựng lại bằng chính các class của ô số Filament (`fi-wi-stats-overview-stat*`, có sẵn trong theme đã
    biên dịch, tự đổi theo chế độ tối) thay vì `StatsOverviewWidget`: ô của vendor không có chỗ cho chấm
    màu, nút ⓘ và hai dòng phụ. Không class Tailwind viết tay — bố cục là style nội tuyến: lưới tự xếp,
    mỗi ô rộng tối thiểu 15rem, nên máy tính rộng một hàng bốn ô, hẹp hơn thì hai, điện thoại 375px một
    cột (`min(100%, 15rem)`: ô không bao giờ rộng hơn màn hình, không cuộn ngang).

    Màu (một hệ cho cả trang, `App\Support\Billing\RevenueColours`): chấm màu cạnh nhãn — Đã thu xanh lá,
    Còn phải thu xanh dương, Quá hạn đỏ, đúng ba màu của vành khuyên. Con số quá hạn đỏ khi > 0. ▲ xanh,
    ▼ đỏ; "—" và "0 %" xám.
--}}
@php
    $cards = $this->cards();
    $deltaColour = fn (?string $direction): string => match ($direction) {
        'up' => 'var(--success-600)',
        'down' => 'var(--danger-600)',
        default => 'var(--gray-500)',
    };
@endphp

<x-filament-widgets::widget>
    <div style="display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 15rem), 1fr));">
        @foreach ($cards as $card)
            {{-- Cả ô bấm được khi có `url`: liên kết phủ kín ô (`position: absolute; inset: 0`) thay vì bọc
                 ô trong `<a>` — nút ⓘ là một `<button>`, không được nằm trong một liên kết. Nút ⓘ nổi lên
                 trên liên kết (`z-index: 1`), nên chạm vào ⓘ mở giải thích chứ không chuyển trang. --}}
            <div
                class="fi-wi-stats-overview-stat"
                data-kpi="{{ $card['key'] }}"
                style="position: relative; min-width: 0;"
            >
                @if ($card['url'])
                    <a
                        href="{{ $card['url'] }}"
                        aria-label="{{ $card['label'] }}: {{ $card['value'] }}"
                        style="position: absolute; inset: 0; z-index: 0; border-radius: inherit;"
                    ></a>
                @endif

                <div class="fi-wi-stats-overview-stat-content">
                    <div class="fi-wi-stats-overview-stat-label-ctn" style="display: flex; align-items: center; gap: 6px;">
                        <span aria-hidden="true" style="display: inline-block; flex: none; width: 10px; height: 10px; border-radius: 9999px; background: {{ $card['accent'] }};"></span>
                        <span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span>
                        <span style="position: relative; z-index: 1; margin-inline-start: auto;">
                            <x-info-tip :text="$card['explanation']" :label="$card['label']" />
                        </span>
                    </div>

                    {{-- Một dòng, không bẻ giữa các chữ số ("1.208.100.00 / 0 ₫" là đọc sai): cỡ chữ nhỏ hơn
                         ô số mặc định của Filament để số tiền mười hai chữ số vừa ô 15rem; nếu vẫn tràn thì
                         cắt bằng "…" và `title` mang đủ con số. --}}
                    <div
                        class="fi-wi-stats-overview-stat-value"
                        title="{{ $card['value'] }}"
                        style="font-size: 1.5rem; line-height: 2rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;{{ $card['tone'] === 'danger' ? ' color: var(--danger-600);' : '' }}"
                    >
                        {{ $card['value'] }}
                    </div>

                    @if ($card['delta'])
                        <div style="font-size: 0.875rem; line-height: 1.4;">
                            <span style="font-weight: 600; color: {{ $deltaColour($card['delta']['direction']) }};">{{ $card['delta']['text'] }}</span>
                            <span style="color: var(--gray-500);">{{ $card['comparison'] }}</span>
                        </div>
                    @endif

                    @if ($card['detail'])
                        <div style="font-size: 0.875rem; line-height: 1.4; color: var(--gray-500);">{{ $card['detail'] }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-filament-widgets::widget>
