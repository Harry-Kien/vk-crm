{{--
    Trang "Bảo mật đăng nhập" (làn st) — đọc-chỉ; giá trị ở `App\Support\Settings\LoginSecurityStatus`, dòng ở
    `App\Filament\Admin\Pages\Settings\LoginSecurityPage::rows()`. Tệp này chỉ vẽ ra.

    Không có bước dựng CSS (CLAUDE.md) — style nội tuyến trên biến CSS của Filament; huy hiệu dùng
    `settings.partials.pill` (xuống dòng được, không cắt chữ).
--}}
@php
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
@endphp

<x-filament-panels::page>
    <div style="display:flex;flex-direction:column;gap:0.5rem;max-width:56rem;">
        <p>{{ __('settings.security.intro') }}</p>
        <p style="{{ $muted }}">{{ __('settings.security.read_only') }}</p>
    </div>

    <div style="display:flex;flex-direction:column;max-width:56rem;border-radius:0.75rem;border:1px solid color-mix(in srgb, var(--gray-500) 25%, transparent);">
        @foreach ($this->rows() as $row)
            <div data-security-row="{{ $row['key'] }}" style="display:flex;flex-wrap:wrap;gap:0.5rem 1rem;justify-content:space-between;align-items:flex-start;padding:0.875rem 1rem;{{ $loop->last ? '' : 'border-bottom:1px solid color-mix(in srgb, var(--gray-500) 20%, transparent);' }}">
                <div style="display:flex;flex-direction:column;gap:0.25rem;min-width:0;flex:1 1 18rem;">
                    <span style="font-weight:700;">{{ $row['label'] }}</span>
                    <span style="font-size:0.8125rem;{{ $muted }}">{{ $row['where'] }}</span>
                    @if (! empty($row['names']))
                        <span style="font-size:0.875rem;">{{ __('settings.security.rows.two_factor_staff.names', ['names' => implode(', ', $row['names'])]) }}</span>
                    @endif
                </div>
                <div style="flex:0 1 auto;">
                    @include('filament.admin.pages.settings.partials.pill', ['tone' => $row['tone'], 'text' => $row['value']])
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
