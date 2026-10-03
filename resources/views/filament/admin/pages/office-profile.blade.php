{{--
    Trang "Thông tin văn phòng" (M7 Task 10) — luật ở docblock `App\Filament\Admin\Pages\OfficeProfilePage`
    và `App\Actions\Settings\UpdateOfficeProfile`; tệp này chỉ vẽ ra.

    Không có bước dựng CSS trong dự án (CLAUDE.md) — style nội tuyến trên biến CSS của Filament,
    cùng thành ngữ `bulk-reassign.blade.php`.
--}}
@php
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $button = 'min-height:44px;display:inline-flex;align-items:center;gap:0.375rem;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;border:none;color:var(--primary-50);background-color:var(--primary-600);cursor:pointer;';
@endphp

<x-filament-panels::page>
    <div style="display:flex;flex-direction:column;gap:0.5rem;max-width:48rem;">
        <p>{{ __('office.intro') }}</p>
        <p style="{{ $muted }}">{{ __('office.blank_hint') }}</p>
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <button type="submit" style="{{ $button }}margin-top:1.5rem;"
                wire:loading.attr="disabled" wire:target="save">
            {{ __('office.submit') }}
        </button>
    </form>
</x-filament-panels::page>
