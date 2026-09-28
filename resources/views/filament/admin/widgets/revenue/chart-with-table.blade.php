{{--
    View DÙNG CHUNG cho các widget ChartWidget của trang doanh thu (M9 Task 9). Bản sao TRUNG THỰC
    của vendor/filament/widgets/resources/views/chart-widget.blade.php — kể cả bộ lọc riêng của
    widget (`getFilters()`, ô <select> tháng/quý/năm), `role="img"`/`aria-label` cho canvas, polling
    và trạng thái rỗng — CHỈ THÊM một bảng số ở cuối, trước khi đóng `<x-filament::section>` (Fix
    round 1, I5: bản trước cắt bớt ô lọc và thuộc tính accessibility của vendor khi tự viết lại từ
    đầu; giờ sao lại NGUYÊN VẸN rồi mới thêm).

    KHÔNG có class Tailwind viết tay: dự án không có bước build CSS (xem
    resources/views/filament/admin/widgets/system-health.blade.php). Mọi class ở đây hoặc là
    nguyên văn từ vendor (đã có sẵn trong theme biên dịch) hoặc là style nội tuyến trên biến CSS
    của Filament, cùng thành ngữ system-health.blade.php.

    KHÔNG tooltip callback, KHÔNG RawJs (M8 R4 chưa có phán quyết CSP — xem docblock từng widget):
    `getOptions()` chỉ là mảng PHP thuần, `@js()` serialize nó y hệt cách vendor làm. Toàn bộ số
    tiền đã là chuỗi Money::format() PHP, nằm trong nhãn (`labels`) và trong bảng số dưới đây —
    không có số tiền thô nào cần một bộ định dạng JS.
--}}
@php
    use Filament\Support\Facades\FilamentAsset;
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\Contracts\Support\Htmlable;

    $color = $this->getColor();
    $heading = $this->getHeading();
    $description = $this->getDescription();
    $filters = $this->getFilters();
    $isCollapsible = $this->isCollapsible();
    $type = $this->getType();
    $maxHeight = $this->getMaxHeight();
    $hasMaxHeight = filled($maxHeight) && $maxHeight !== '100%';
    $isEmpty = $this->isEmpty();
    $rows = $this->numberTableRows();

    $chartAccessibleLabel = trim(implode('. ', array_filter([
        $heading instanceof Htmlable ? strip_tags($heading->toHtml()) : $heading,
        $description instanceof Htmlable ? strip_tags($description->toHtml()) : $description,
    ], fn ($value): bool => filled($value))));
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section
        :description="$description"
        :heading="$heading"
        :collapsible="$isCollapsible"
    >
        @if ($filters || method_exists($this, 'getFiltersSchema'))
            <x-slot name="afterHeader">
                @if ($filters)
                    <x-filament::input.wrapper
                        inline-prefix
                        wire:target="filter"
                        class="fi-wi-chart-filter"
                    >
                        <x-filament::input.select
                            :aria-label="__('filament-widgets::chart.filter.label')"
                            inline-prefix
                            wire:model.live="filter"
                        >
                            @foreach ($filters as $value => $label)
                                <option value="{{ $value }}">
                                    {{ $label }}
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endif

                @if (method_exists($this, 'getFiltersSchema'))
                    <x-filament::dropdown
                        placement="bottom-end"
                        shift
                        width="xs"
                        class="fi-wi-chart-filter"
                    >
                        <x-slot name="trigger">
                            {{ $this->getFiltersTriggerAction() }}
                        </x-slot>

                        <div class="fi-wi-chart-filter-content">
                            {{ $this->getFiltersSchema() }}

                            @if (method_exists($this, 'hasDeferredFilters') && $this->hasDeferredFilters())
                                <div
                                    class="fi-wi-chart-filter-content-actions-ctn"
                                >
                                    {{ $this->getFiltersApplyAction() }}

                                    {{ $this->getFiltersResetAction() }}
                                </div>
                            @endif
                        </div>
                    </x-filament::dropdown>
                @endif
            </x-slot>
        @endif

        <div
            @if ($pollingInterval = $this->getPollingInterval())
                wire:poll.{{ $pollingInterval }}="updateChartData"
            @endif
            @if ($isEmpty)
                style="display: none"
            @endif
        >
            <div
                x-load
                x-load-src="{{ FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                wire:ignore
                data-chart-type="{{ $type }}"
                x-data="chart({
                            cachedData: @js($this->getCachedData()),
                            options: @js($this->getOptions()),
                            type: @js($type),
                        })"
                {{
                    (new FilamentComponentAttributeBag)
                        ->color(ChartWidgetComponent::class, $color)
                        ->class([
                            'fi-wi-chart-frame',
                            'fi-wi-chart-canvas-ctn',
                            'fi-wi-chart-frame-no-aspect-ratio' => $hasMaxHeight,
                        ])
                }}
            >
                <canvas
                    x-ref="canvas"
                    @if (filled($chartAccessibleLabel))
                        role="img"
                        aria-label="{{ $chartAccessibleLabel }}"
                    @endif
                    @style([
                        'width: 100%',
                        'height: 100%; max-height: 100%' => ! $hasMaxHeight,
                        ('max-height: ' . e($maxHeight)) => $hasMaxHeight,
                    ])
                ></canvas>

                {{--
                    Chart.js paints the chart onto the canvas, where a stylesheet cannot reach it. These empty
                    elements carry the colors it should use, so that a theme can set them with an ordinary
                    `color` declaration and they follow light and dark mode like any other element.
                --}}
                <span
                    aria-hidden="true"
                    x-ref="backgroundColorElement"
                    class="fi-wi-chart-bg-color"
                ></span>

                <span
                    aria-hidden="true"
                    x-ref="borderColorElement"
                    class="fi-wi-chart-border-color"
                ></span>

                <span
                    aria-hidden="true"
                    x-ref="gridColorElement"
                    class="fi-wi-chart-grid-color"
                ></span>

                <span
                    aria-hidden="true"
                    x-ref="textColorElement"
                    class="fi-wi-chart-text-color"
                ></span>

                <span
                    aria-hidden="true"
                    x-ref="tooltipBackgroundColorElement"
                    class="fi-wi-chart-tooltip-bg-color"
                ></span>

                <span
                    aria-hidden="true"
                    x-ref="tooltipTextColorElement"
                    class="fi-wi-chart-tooltip-text-color"
                ></span>

                <span
                    aria-hidden="true"
                    x-ref="tooltipBorderColorElement"
                    class="fi-wi-chart-tooltip-border-color"
                ></span>
            </div>
        </div>

        {{-- Thêm so với vendor: bảng số đi kèm mỗi biểu đồ (kế hoạch M9, "luôn có một bảng số").
             Màu chữ (lượt rà soát cuối M9, M6): theme Filament không có MỘT biến CSS màu chữ tự đổi
             theo chế độ tối — nó đổi bằng class `:where(.dark, .dark *)`. Nên ô số KHÔNG đặt màu
             cố định (bản trước `var(--gray-950)` — gần đen trên nền tối, không đọc được) mà
             `inherit` màu chữ của `.fi-body` (gray-950 ở chế độ sáng, trắng ở chế độ tối); nhãn
             dùng `var(--gray-500)`, mức xám trung tính đọc được trên cả hai nền. --}}
        @if (! $isEmpty && count($rows))
            <details style="margin-top: 12px;">
                <summary style="cursor: pointer; font-size: 0.875rem; color: var(--gray-500);">
                    {{ __('widgets.revenue_dashboard.number_table_toggle') }}
                </summary>

                <table style="width: 100%; margin-top: 8px; border-collapse: collapse; font-size: 0.875rem;">
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-top: 1px solid var(--gray-200);">
                                <td style="padding: 4px 8px 4px 0; color: var(--gray-500);">{{ $row['label'] }}</td>
                                <td style="padding: 4px 0; text-align: right; font-weight: 600; color: inherit;">{{ $row['value'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        @endif

        @if ($isEmpty)
            @if ($emptyState = $this->getEmptyState())
                {{ $emptyState }}
            @else
                <div
                    @class([
                        'fi-wi-chart-frame',
                        'fi-wi-chart-frame-no-aspect-ratio' => $hasMaxHeight,
                    ])
                    @style([
                        ('min-height: ' . e($maxHeight)) => $hasMaxHeight,
                    ])
                >
                    <x-filament::empty-state
                        :contained="false"
                        :description="$this->getEmptyStateDescription()"
                        :heading="$this->getEmptyStateHeading()"
                        :icon="$this->getEmptyStateIcon()"
                        icon-color="gray"
                    >
                        @if ($emptyStateActions = $this->getEmptyStateActions())
                            <x-slot name="footer">
                                <x-filament::actions
                                    :actions="$emptyStateActions"
                                    alignment="center"
                                />
                            </x-slot>
                        @endif
                    </x-filament::empty-state>
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
