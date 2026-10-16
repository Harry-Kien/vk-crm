{{--
    Trang "Kho tài liệu" (M14 Task 5; làn tl viết lại bằng tiếng thường) — luật ở docblock
    `App\Filament\Admin\Pages\DocumentStorePage`; tệp này chỉ vẽ ra. Không có bước dựng CSS trong dự án
    (CLAUDE.md): style nội tuyến trên biến CSS của Filament, cùng thành ngữ `office-profile.blade.php`.
--}}
@php
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $button = 'min-height:44px;display:inline-flex;align-items:center;gap:0.375rem;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;border:none;color:var(--primary-50);background-color:var(--primary-600);cursor:pointer;';
    $documentsUrl = \App\Filament\Admin\Pages\DocumentLibrary::getUrl(panel: 'admin');
@endphp

<x-filament-panels::page>
    <p style="{{ $muted }}max-width:48rem;margin:0;">
        {{ __('document_store.page.overview.intro') }}
        {{ __('document_store.page.overview.intro_documents_before') }}
        <a href="{{ $documentsUrl }}" style="font-weight:600;color:inherit;text-decoration:underline;">{{ __('document_store.page.overview.intro_documents_link') }}</a>
        {{ __('document_store.page.overview.intro_documents_after') }}
    </p>

    @include('filament.admin.pages.document-store-figures', $figures)

    <form wire:submit="save">
        {{ $this->form }}

        <button type="submit" style="{{ $button }}margin-top:1.5rem;"
                wire:loading.attr="disabled" wire:target="save">
            {{ __('document_store.page.submit') }}
        </button>
    </form>
</x-filament-panels::page>
