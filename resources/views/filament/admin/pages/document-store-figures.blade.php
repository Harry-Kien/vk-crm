{{--
    Con số của trang "Kho tài liệu" (`DocumentStorePage::figures()`): CHỈ số đếm, thời điểm và câu
    trạng thái — không tài liệu, hồ sơ, khách hay mã tệp Drive nào. Mỗi con số trong một `div` có
    `data-figure` (test đọc đúng con số đó). Không class viết tay: style nội tuyến trên biến CSS của
    Filament.
--}}
@php
    $card = 'border:1px solid var(--gray-200);border-radius:0.75rem;padding:1rem 1.25rem;background-color:var(--gray-50);';
    $heading = 'font-weight:600;margin:0 0 0.75rem 0;';
    $row = 'display:flex;flex-wrap:wrap;justify-content:space-between;gap:0.25rem 1rem;padding:0.375rem 0;border-top:1px solid var(--gray-200);';
    $label = 'color:var(--gray-600);';
    $value = 'font-weight:600;color:var(--gray-950);';
@endphp

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(18rem,1fr));gap:1rem;">
    @foreach ($sections as $section => $values)
        <section style="{{ $card }}">
            <h3 style="{{ $heading }}">{{ __('document_store.page.sections.'.$section) }}</h3>

            @foreach ($values as $key => $text)
                <div data-figure="{{ $key }}" style="{{ $row }}"><span style="{{ $label }}">{{ __('document_store.page.figures.'.$key) }}</span> <span style="{{ $value }}">{{ $text }}</span></div>
            @endforeach

            @if ($section === 'status' && filled($detail))
                <div data-figure="detail" style="{{ $row }}"><span style="{{ $label }}">{{ __('document_store.page.figures.detail') }}</span> <span style="{{ $value }}">{{ $detail }}</span></div>
            @endif
        </section>
    @endforeach
</div>
