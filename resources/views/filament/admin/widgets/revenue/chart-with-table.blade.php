{{--
    View DÙNG CHUNG cho năm widget ChartWidget của trang doanh thu (M9 Task 9). Sao lại đúng phần
    khung của filament-widgets::chart-widget (canvas + Alpine `chart()`), CHỈ thêm một bảng số ở
    cuối — "luôn có một bảng số đi kèm mỗi biểu đồ" (kế hoạch M9).

    KHÔNG có class Tailwind viết tay: dự án không có bước build CSS (xem
    resources/views/filament/admin/widgets/system-health.blade.php). `<x-filament::section>` và
    `<x-filament-widgets::widget>` an toàn vì chúng là COMPONENT của Filament — CSS của chúng đã
    nằm sẵn trong theme biên dịch, không phải class tôi tự gõ. Bảng số dùng style nội tuyến trên
    biến CSS của Filament, cùng thành ngữ system-health.blade.php.

    KHÔNG tooltip callback, KHÔNG RawJs (M8 R4 chưa có phán quyết CSP — xem docblock từng widget):
    `getOptions()` chỉ là mảng PHP thuần, `@js()` serialize nó y hệt cách vendor làm. Toàn bộ số
    tiền đã là chuỗi Money::format() PHP, nằm trong nhãn (`labels`) và trong bảng số dưới đây —
    không có số tiền thô nào cần một bộ định dạng JS.
--}}
@php
    use Filament\Support\Facades\FilamentAsset;

    $heading = $this->getHeading();
    $description = $this->getDescription();
    $type = $this->getType();
    $isEmpty = $this->isEmpty();
    $rows = $this->numberTableRows();
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section :description="$description" :heading="$heading">
        <div
            @if ($isEmpty) style="display: none" @endif
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
                class="fi-wi-chart-frame fi-wi-chart-canvas-ctn"
            >
                <canvas x-ref="canvas" style="width: 100%; height: 100%; max-height: 100%"></canvas>

                <span aria-hidden="true" x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                <span aria-hidden="true" x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                <span aria-hidden="true" x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                <span aria-hidden="true" x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
                <span aria-hidden="true" x-ref="tooltipBackgroundColorElement" class="fi-wi-chart-tooltip-bg-color"></span>
                <span aria-hidden="true" x-ref="tooltipTextColorElement" class="fi-wi-chart-tooltip-text-color"></span>
                <span aria-hidden="true" x-ref="tooltipBorderColorElement" class="fi-wi-chart-tooltip-border-color"></span>
            </div>
        </div>

        @if (! $isEmpty && count($rows))
            <details style="margin-top: 12px;">
                <summary style="cursor: pointer; font-size: 0.875rem; color: var(--gray-500);">
                    {{ __('widgets.revenue_dashboard.number_table_toggle') }}
                </summary>

                <table style="width: 100%; margin-top: 8px; border-collapse: collapse; font-size: 0.875rem;">
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-top: 1px solid var(--gray-200);">
                                <td style="padding: 4px 8px 4px 0; color: var(--gray-600);">{{ $row['label'] }}</td>
                                <td style="padding: 4px 0; text-align: right; font-weight: 600; color: var(--gray-950);">{{ $row['value'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        @endif

        @if ($isEmpty)
            <x-filament::empty-state
                :contained="false"
                :description="$this->getEmptyStateDescription()"
                :heading="$this->getEmptyStateHeading()"
                :icon="$this->getEmptyStateIcon()"
                icon-color="gray"
            />
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
