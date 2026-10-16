{{--
    Con số của trang "Kho tài liệu" (`DocumentStorePage::figures()`): CHỈ số đếm, thời điểm và câu
    trạng thái — không tài liệu, hồ sơ, khách hay mã tệp Drive nào. Mỗi con số trong một `div` có
    `data-figure` (test đọc đúng con số đó: nhãn rồi giá trị, không thẻ `div` lồng bên trong). Không class
    viết tay: style nội tuyến trên biến CSS của Filament.

    Làn tl: dải trạng thái (`data-overview`, `data-state` = ok/warning/error), các bước bật Drive
    (`data-step`, `data-done`), bốn ô số (`data-tone` = good/warning/danger/neutral), các mục chữ, và mục
    thu gọn "Chi tiết kỹ thuật" (`<details data-section="technical">`, mặc định đóng) — chỉ ở đó mới có tên
    biến môi trường và lệnh artisan. Nền là sắc độ pha trong suốt (`color-mix`), nên đúng cả nền sáng
    lẫn nền tối.
--}}
@php
    $palette = ['ok' => 'success', 'good' => 'success', 'warning' => 'warning', 'error' => 'danger', 'danger' => 'danger', 'neutral' => 'gray'];
    $tint = fn (string $tone, int $percent): string => 'color-mix(in srgb, var(--'.$palette[$tone].'-500) '.$percent.'%, transparent)';
    $card = 'border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);border-radius:0.75rem;padding:1rem 1.25rem;';
    $heading = 'font-weight:600;margin:0 0 0.75rem 0;';
    $row = 'display:flex;flex-wrap:wrap;justify-content:space-between;gap:0.25rem 1rem;padding:0.375rem 0;border-top:1px solid color-mix(in srgb, var(--gray-500) 20%, transparent);';
    $label = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $value = 'font-weight:600;';
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);font-size:0.875rem;';
@endphp

<section data-overview data-state="{{ $overview['state'] }}" data-situation="{{ $overview['situation'] }}" role="status"
         style="border-left:6px solid var(--{{ $palette[$overview['state']] }}-500);background-color:{{ $tint($overview['state'], 12) }};border-radius:0.75rem;padding:1rem 1.25rem;">
    <p style="margin:0;font-size:1.125rem;font-weight:700;">{{ $overview['headline'] }}</p>
    <p style="margin:0.5rem 0 0;"><span style="font-weight:600;">{{ __('document_store.page.overview.next_label') }}</span> {{ $overview['next'] }}</p>
</section>

<section style="{{ $card }}">
    <h3 style="{{ $heading }}">{{ __('document_store.page.overview.tiles_heading') }}</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:0.75rem;">
        @foreach ($tiles as $key => $tile)
            <div style="border-radius:0.75rem;padding:0.75rem 1rem;border-top:4px solid var(--{{ $palette[$tile['tone']] }}-500);background-color:{{ $tint($tile['tone'], 10) }};">
                <div data-figure="{{ $key }}" data-tone="{{ $tile['tone'] }}" style="display:flex;flex-direction:column;gap:0.25rem;"><span style="{{ $label }}">{{ __('document_store.page.figures.'.$key) }}</span> <span style="font-size:1.75rem;font-weight:700;line-height:1.2;">{{ $tile['value'] }}</span></div>
                <p style="{{ $muted }}margin:0.375rem 0 0;">{{ __('document_store.page.overview.tile_hints.'.$key) }}</p>
            </div>
        @endforeach
    </div>
</section>

<section style="{{ $card }}">
    <h3 style="{{ $heading }}">{{ __('document_store.page.overview.steps_heading') }}</h3>
    <p style="{{ $muted }}margin:0 0 0.5rem;">{{ __('document_store.page.overview.steps_intro') }}</p>
    <ol style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:0.5rem;">
        @foreach ($steps as $step => $done)
            <li data-step="{{ $step }}" data-done="{{ $done ? '1' : '0' }}" style="display:flex;gap:0.75rem;align-items:flex-start;padding:0.5rem 0;border-top:1px solid color-mix(in srgb, var(--gray-500) 20%, transparent);">
                <span aria-hidden="true" style="flex:none;width:1.75rem;height:1.75rem;border-radius:9999px;display:inline-flex;align-items:center;justify-content:center;font-weight:700;{{ $done ? 'color:var(--success-600);background-color:'.$tint('good', 18).';' : 'color:var(--gray-500);border:2px solid color-mix(in srgb, var(--gray-500) 50%, transparent);' }}">{{ $done ? '✓' : $loop->iteration }}</span>
                <span style="display:flex;flex-direction:column;gap:0.125rem;">
                    <span>{{ __('document_store.page.overview.steps.'.$step) }}
                        <span style="font-weight:600;color:var(--{{ $done ? 'success' : 'gray' }}-600);">({{ __($done ? 'document_store.page.overview.step_done' : 'document_store.page.overview.step_pending') }})</span></span>
                    <span style="{{ $muted }}">{{ __('document_store.page.overview.guides.'.$step) }}</span>
                </span>
            </li>
        @endforeach
    </ol>
</section>

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

<details data-section="technical" style="{{ $card }}">
    <summary style="font-weight:600;cursor:pointer;">{{ __('document_store.page.overview.technical_heading') }}</summary>
    <p style="{{ $muted }}margin:0.75rem 0 0.5rem;">{{ __('document_store.page.overview.technical_intro') }}</p>
    @foreach ($technical as $key => $text)
        <div data-figure="{{ $key }}" style="{{ $row }}"><span style="{{ $label }}">{{ __('document_store.page.figures.'.$key) }}</span> <span style="{{ $value }}">{{ $text }}</span></div>
    @endforeach
    <ul style="margin:0.5rem 0 0;padding-left:1.25rem;display:flex;flex-direction:column;gap:0.25rem;">
        @foreach (['check', 'enable', 'migrate', 'rollback', 'env'] as $line)
            <li><code style="font-size:0.875rem;overflow-wrap:anywhere;">{{ __('document_store.page.overview.technical.'.$line) }}</code></li>
        @endforeach
    </ul>
</details>
