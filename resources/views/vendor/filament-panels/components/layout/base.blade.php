{{--
    GIỮ RIÊNG cho CSP (SPEC §10 mục 2, phán quyết R4 — docs/research/2026-09-26-csp-khao-sat.md).
    Bản sao NGUYÊN VĂN của vendor/filament/filament/resources/views/components/layout/base.blade.php (Filament 5.8.1). Điểm khác DUY NHẤT: mỗi thẻ
    <script> mang nonce của request, để CSP không phải cho `unsafe-inline`.

    Nâng cấp Filament: tests/Feature/Http/PublishedFilamentViewsTest.php đỏ khi view gốc đổi. Chép
    lại bản gốc mới vào đây, gắn lại nonce cho MỌI thẻ <script>, rồi cập nhật băm trong test đó.
--}}
@props([
    'livewire' => null,
])

@php
    use Filament\Livewire\Notifications;
    use Filament\Support\Facades\FilamentView;
    use Filament\View\PanelsRenderHook;

    $renderHookScopes = $livewire?->getRenderHookScopes();
@endphp

<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ __('filament-panels::layout.direction') ?? 'ltr' }}"
    @class([
        'fi',
        'dark' => filament()->hasDarkMode() && filament()->hasDarkModeForced(),
    ])
>
    <head>
        {{ FilamentView::renderHook(PanelsRenderHook::HEAD_START, scopes: $renderHookScopes) }}

        <meta charset="utf-8" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />

        @if ($favicon = filament()->getFavicon())
            <link rel="icon" href="{{ $favicon }}" />
        @endif

        @php
            $title = trim(strip_tags($livewire?->getTitle() ?? ''));
            $brandName = trim(strip_tags(filament()->getBrandName()));
        @endphp

        <title>
            {{ filled($title) ? $title : null }}
            {{ filled($brandName) && filled($title) ? ' - ' : null }}
            {{ filled($brandName) ? $brandName : null }}
        </title>

        {{ FilamentView::renderHook(PanelsRenderHook::STYLES_BEFORE, scopes: $renderHookScopes) }}

        <style>
            [x-cloak=''],
            [x-cloak='x-cloak'],
            [x-cloak='1'] {
                display: none !important;
            }

            [x-cloak='inline-flex'] {
                display: inline-flex !important;
            }

            @media (max-width: 1023px) {
                [x-cloak='-lg'] {
                    display: none !important;
                }
            }

            @media (min-width: 1024px) {
                [x-cloak='lg'] {
                    display: none !important;
                }
            }
        </style>

        @filamentStyles

        {{ filament()->getTheme()->getHtml() }}
        {{ filament()->getFontPreloadHtml() }}
        {{ filament()->getMonoFontPreloadHtml() }}
        {{ filament()->getSerifFontPreloadHtml() }}
        {{ filament()->getFontHtml() }}
        {{ filament()->getMonoFontHtml() }}
        {{ filament()->getSerifFontHtml() }}

        <style>
            :root {
                --font-family: '{!! filament()->getFontFamily() !!}';
                --mono-font-family: '{!! filament()->getMonoFontFamily() !!}';
                --serif-font-family: '{!! filament()->getSerifFontFamily() !!}';
                --sidebar-width: {{ filament()->getSidebarWidth() }};
                --collapsed-sidebar-width: {{ filament()->getCollapsedSidebarWidth() }};
                --default-theme-mode: {{ filament()->getDefaultThemeMode()->value }};
            }

            html.fi {
                --livewire-progress-bar-color: var(--primary-500);
            }
        </style>

        @stack('styles')

        {{ FilamentView::renderHook(PanelsRenderHook::STYLES_AFTER, scopes: $renderHookScopes) }}

        @if (! filament()->hasDarkMode())
            <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
                localStorage.setItem('theme', 'light')
            </script>
        @elseif (filament()->hasDarkModeForced())
            <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
                localStorage.setItem('theme', 'dark')
            </script>
        @else
            <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
                const loadDarkMode = () => {
                    window.theme = localStorage.getItem('theme') ?? @js(filament()->getDefaultThemeMode()->value)

                    if (
                        window.theme === 'dark' ||
                        (window.theme === 'system' &&
                            window.matchMedia('(prefers-color-scheme: dark)')
                                .matches)
                    ) {
                        document.documentElement.classList.add('dark')
                    }
                }

                loadDarkMode()

                document.addEventListener('livewire:navigated', loadDarkMode)
            </script>
        @endif

        {{ FilamentView::renderHook(PanelsRenderHook::HEAD_END, scopes: $renderHookScopes) }}
    </head>

    <body
        {{
            $attributes
                ->merge($livewire?->getExtraBodyAttributes() ?? [], escape: false)
                ->class([
                    'fi-body',
                    'fi-panel-' . filament()->getId(),
                ])
        }}
    >
        {{ FilamentView::renderHook(PanelsRenderHook::BODY_START, scopes: $renderHookScopes) }}

        {{ $slot }}

        @livewire(Notifications::class)

        {{ FilamentView::renderHook(PanelsRenderHook::SCRIPTS_BEFORE, scopes: $renderHookScopes) }}

        @filamentScripts(withCore: true)

        @if (filament()->hasBroadcasting() && config('filament.broadcasting.echo'))
            <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" data-navigate-once>
                window.Echo = new window.EchoFactory(@js(config('filament.broadcasting.echo')))

                window.dispatchEvent(new CustomEvent('EchoLoaded'))
            </script>
        @endif

        @if (filament()->hasDarkMode() && (! filament()->hasDarkModeForced()))
            <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
                loadDarkMode()
            </script>
        @endif

        @stack('scripts')

        {{ FilamentView::renderHook(PanelsRenderHook::SCRIPTS_AFTER, scopes: $renderHookScopes) }}

        {{ FilamentView::renderHook(PanelsRenderHook::BODY_END, scopes: $renderHookScopes) }}
    </body>
</html>
