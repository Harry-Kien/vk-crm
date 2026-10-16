{{--
    Trang "Cài đặt nghiệp vụ" (làn st) — luật ở docblock `App\Filament\Admin\Pages\Settings\BusinessSettingsPage`,
    `App\Support\Settings\BusinessSettings` và `App\Actions\Settings\UpdateBusinessSettings`; tệp này chỉ vẽ ra.

    Không có bước dựng CSS trong dự án (CLAUDE.md) — style nội tuyến trên biến CSS của Filament, cùng
    thành ngữ `office-profile.blade.php`.
--}}
@php
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $button = 'min-height:44px;display:inline-flex;align-items:center;gap:0.375rem;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;border:none;color:var(--primary-50);background-color:var(--primary-600);cursor:pointer;';
    $card = 'border-radius:0.75rem;padding:1rem 1.25rem;border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);max-width:48rem;';
@endphp

<x-filament-panels::page>
    <div style="display:flex;flex-direction:column;gap:0.5rem;max-width:48rem;">
        <p>{{ __('settings.business.intro') }}</p>
        <p style="{{ $muted }}">{{ __('settings.business.audit_hint') }}</p>
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <button type="submit" style="{{ $button }}margin-top:1.5rem;"
                wire:loading.attr="disabled" wire:target="save">
            {{ __('settings.business.submit') }}
        </button>
    </form>

    <section data-settings="fixed-values" style="{{ $card }}">
        <h2 style="font-size:1rem;font-weight:700;margin-bottom:0.5rem;">{{ __('settings.business.fixed.heading') }}</h2>
        <ul style="display:flex;flex-direction:column;gap:0.375rem;margin:0 0 0.75rem 1.25rem;list-style:disc;">
            @foreach ($this->fixedValues() as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
        <p style="{{ $muted }}">{{ __('settings.business.fixed.where') }}</p>
    </section>
</x-filament-panels::page>
