{{--
    Trang "Nhắc thanh toán cho khách" (làn nt Task 1) — luật ở docblock
    `App\Filament\Admin\Pages\ClientPaymentSettingsPage` và `App\Actions\Settings\UpdateClientPaymentSettings`;
    tệp này chỉ vẽ ra.

    Không có bước dựng CSS trong dự án (CLAUDE.md) — style nội tuyến trên biến CSS của Filament, cùng
    thành ngữ `office-profile.blade.php`.
--}}
@php
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $button = 'min-height:44px;display:inline-flex;align-items:center;gap:0.375rem;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;border:none;color:var(--primary-50);background-color:var(--primary-600);cursor:pointer;';
    $card = 'border-radius:0.75rem;padding:1rem;border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);max-width:48rem;';
    $previewQr = $this->previewQr();
@endphp

<x-filament-panels::page>
    <div style="display:flex;flex-direction:column;gap:0.5rem;max-width:48rem;">
        <p>{{ __('client_payments.settings.intro_off') }}</p>
        <p>{{ __('client_payments.settings.intro_on') }}</p>
        <p style="{{ $muted }}">{{ __('client_payments.settings.audit_hint') }}</p>
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <button type="submit" style="{{ $button }}margin-top:1.5rem;"
                wire:loading.attr="disabled" wire:target="save">
            {{ __('client_payments.settings.submit') }}
        </button>
    </form>

    @if ($previewQr !== null)
        <section data-client-payments="preview-qr" style="{{ $card }}">
            <h2 style="font-size:1.125rem;font-weight:700;margin-bottom:0.5rem;">{{ __('client_payments.settings.preview.heading') }}</h2>
            <p style="{{ $muted }}margin-bottom:0.75rem;">{{ __('client_payments.settings.preview.body') }}</p>
            <img src="{{ $previewQr }}" alt="{{ __('client_payments.settings.preview.heading') }}"
                 style="width:12rem;height:12rem;background-color:#ffffff;border-radius:0.5rem;">
        </section>
    @endif
</x-filament-panels::page>
