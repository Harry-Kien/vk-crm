{{--
    Trang "Hiệu suất theo kỳ" (M13) — luật ở docblock `App\Filament\Admin\Pages\Performance`; tệp này
    chỉ vẽ ra: câu phạm vi cố định (R4), form kỳ (R16) và nhãn kỳ đang hiện (kèm "kỳ đang chạy", R7),
    bảng P1–P7, P9, P10 (dòng "Chung" đầu bảng, R8), khối thu gọn "Cách tính các con số" (R6, gồm câu
    "kỳ đã đóng" của R19) và đoạn "Vì sao không có bảng xếp hạng" (R8). Style nội tuyến trên biến CSS của
    Filament (dự án không có bước dựng CSS).
--}}
@php
    $period = $this->period();
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $box = 'border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);border-radius:0.75rem;padding:0.75rem 1rem;';
    $button = 'min-height:44px;display:inline-flex;align-items:center;gap:0.375rem;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;border:none;color:var(--primary-50);background-color:var(--primary-600);cursor:pointer;';
@endphp

<x-filament-panels::page>
    @include('filament.admin.pages.performance-scope-note')

    <form wire:submit="applyPeriod" data-vk-performance-period style="display:flex;flex-direction:column;gap:0.75rem;">
        {{ $this->form }}

        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem;">
            <button type="submit" style="{{ $button }}" wire:loading.attr="disabled" wire:target="applyPeriod">
                {{ __('performance.period.apply') }}
            </button>

            <p data-vk-performance-period-label style="margin:0;font-weight:600;">
                {{ $period->label() }}
                @if ($period->isRunning())
                    <span style="font-weight:400;{{ $muted }}">{{ __('performance.period.running') }}</span>
                @endif
            </p>
        </div>
    </form>

    {{ $this->table }}

    <details data-vk-performance-explain style="{{ $box }}">
        <summary style="cursor:pointer;font-weight:600;">{{ __('performance.how_computed') }}</summary>
        <dl style="margin:0.75rem 0 0;display:grid;gap:0.5rem;">
            @foreach ($this->explanations() as $explanation)
                <div>
                    <dt style="font-weight:600;">{{ $explanation['label'] }}</dt>
                    <dd style="margin:0;">{{ $explanation['sentence'] }}</dd>
                </div>
            @endforeach
        </dl>
    </details>

    <details data-vk-performance-no-ranking style="{{ $box }}">
        <summary style="cursor:pointer;font-weight:600;">{{ __('performance.period_page.no_ranking.heading') }}</summary>
        <p style="margin:0.75rem 0 0;">{{ __('performance.period_page.no_ranking.intro') }}</p>
        <ul style="margin:0.5rem 0 0;padding-left:1.25rem;list-style:disc;display:grid;gap:0.25rem;">
            @foreach (__('performance.period_page.no_ranking.reasons') as $reason)
                <li>{{ $reason }}</li>
            @endforeach
        </ul>
    </details>
</x-filament-panels::page>
