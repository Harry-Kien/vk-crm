{{--
    Trang "Theo dõi đội ngũ" (M13) — luật ở docblock `App\Filament\Admin\Pages\TeamOverview`; tệp
    này chỉ vẽ ra: câu phạm vi cố định (R4), bảng N1–N10 (N11 chỉ ở trang của một người), và khối
    thu gọn "Cách tính các con số" (R6) liệt kê câu giải thích của mọi cột. Style nội tuyến trên biến
    CSS của Filament (dự án không có bước dựng CSS).
--}}
<x-filament-panels::page>
    @include('filament.admin.pages.performance-scope-note')

    {{ $this->table }}

    <details data-vk-performance-explain style="border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);border-radius:0.75rem;padding:0.75rem 1rem;">
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
</x-filament-panels::page>
