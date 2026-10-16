{{--
    Trang tổng "Cài đặt" (làn st) — danh mục và trạng thái ở `App\Support\Settings\SettingsCatalog`, cổng ở
    `App\Filament\Admin\Pages\Settings\SettingsHub`; tệp này chỉ vẽ ra.

    Không có bước dựng CSS trong dự án (CLAUDE.md) — style nội tuyến trên biến CSS của Filament; huy hiệu
    trạng thái dùng `settings.partials.pill` (xuống dòng được, không cắt chữ).
--}}
@php
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $groups = $this->groups();
@endphp

<x-filament-panels::page>
    <div style="display:flex;flex-direction:column;gap:1rem;">
        <p style="max-width:48rem;">{{ __('settings.hub.intro') }}</p>

        <div style="max-width:32rem;">
            <label for="settings-search" style="display:block;font-weight:600;margin-bottom:0.375rem;">{{ __('settings.hub.search.label') }}</label>
            <input
                id="settings-search"
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('settings.hub.search.placeholder') }}"
                autocomplete="off"
                style="width:100%;min-height:44px;padding:0.5rem 0.75rem;border-radius:0.5rem;font-size:1rem;background-color:transparent;color:inherit;border:1px solid color-mix(in srgb, var(--gray-500) 45%, transparent);"
            >
        </div>

        @if ($groups === [])
            <p data-settings="empty" style="{{ $muted }}">{{ __('settings.hub.search.empty', ['term' => $search]) }}</p>
        @endif

        @foreach ($groups as $group => $cards)
            <section data-settings-group="{{ $group }}" style="display:flex;flex-direction:column;gap:0.75rem;">
                <h2 style="font-size:1rem;font-weight:700;letter-spacing:0.01em;">{{ __("settings.hub.groups.{$group}") }}</h2>

                <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(min(100%, 17rem), 1fr));gap:0.75rem;">
                    @foreach ($cards as $card)
                        <a
                            href="{{ $card->url }}"
                            data-settings-card="{{ $card->key }}"
                            style="display:flex;gap:0.75rem;align-items:flex-start;padding:1rem;border-radius:0.75rem;text-decoration:none;color:inherit;background-color:color-mix(in srgb, var(--gray-500) 5%, transparent);border:1px solid color-mix(in srgb, var(--gray-500) 25%, transparent);"
                        >
                            <span style="flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:2.5rem;height:2.5rem;border-radius:0.625rem;color:var(--primary-600);background-color:color-mix(in srgb, var(--primary-500) 12%, transparent);">
                                <x-filament::icon :icon="$card->icon" style="width:1.375rem;height:1.375rem;" />
                            </span>

                            <span style="display:flex;flex-direction:column;gap:0.375rem;min-width:0;">
                                <span style="font-weight:700;">{{ $card->title }}</span>
                                <span style="font-size:0.875rem;line-height:1.4;{{ $muted }}">{{ $card->description }}</span>
                                @if ($card->status !== null)
                                    <span style="display:flex;">
                                        @include('filament.admin.pages.settings.partials.pill', ['tone' => $card->tone, 'text' => $card->status])
                                    </span>
                                @endif
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
