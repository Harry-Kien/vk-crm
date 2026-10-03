{{--
    Trang "Tìm kiếm" (M7 Task 9) — luật ở docblock `App\Filament\Admin\Pages\Search` và
    `App\Actions\Search\SearchMatters`; tệp này chỉ vẽ ra.

    Không có bước dựng CSS trong dự án (CLAUDE.md) — style nội tuyến trên biến CSS của Filament,
    cùng thành ngữ `office-profile.blade.php`/`bulk-reassign.blade.php`.
--}}
@php
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $hidden = 'position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;';
    $input = 'min-height:44px;flex:1 1 18rem;min-width:0;padding:0.5rem 0.75rem;border-radius:0.5rem;border:1px solid color-mix(in srgb, var(--gray-500) 40%, transparent);background-color:transparent;color:inherit;font-size:1rem;';
    $button = 'min-height:44px;display:inline-flex;align-items:center;gap:0.375rem;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;border:none;color:var(--primary-50);background-color:var(--primary-600);cursor:pointer;';
@endphp

<x-filament-panels::page>
    <form wire:submit="search" role="search" style="display:flex;flex-wrap:wrap;gap:0.5rem;max-width:48rem;">
        <label for="vk-search-term" style="{{ $hidden }}">{{ __('search.input_label') }}</label>
        <input id="vk-search-term" type="search" wire:model.live.debounce.500ms="term"
               maxlength="{{ \App\Actions\Search\SearchMatters::MAX_TERM_LENGTH }}"
               placeholder="{{ __('search.placeholder') }}" autocomplete="off" autofocus
               style="{{ $input }}">
        <button type="submit" style="{{ $button }}">{{ __('search.submit') }}</button>
    </form>

    @if ($sources !== [])
        <p style="{{ $muted }}">
            {{ __('search.searching_in', ['sources' => implode(', ', array_map(fn ($source) => $source->label(), $sources))]) }}
        </p>
    @endif

    @include('filament.admin.pages.search-results')
</x-filament-panels::page>
