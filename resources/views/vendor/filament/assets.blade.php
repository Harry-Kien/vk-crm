{{--
    GIỮ RIÊNG cho CSP (SPEC §10 mục 2, phán quyết R4 — docs/research/2026-09-26-csp-khao-sat.md).
    Bản sao NGUYÊN VĂN của vendor/filament/support/resources/views/assets.blade.php (Filament 5.8.1). Điểm khác DUY NHẤT: mỗi thẻ
    <script> mang nonce của request, để CSP không phải cho `unsafe-inline`.

    Nâng cấp Filament: tests/Feature/Http/PublishedFilamentViewsTest.php đỏ khi view gốc đổi. Chép
    lại bản gốc mới vào đây, gắn lại nonce cho MỌI thẻ <script>, rồi cập nhật băm trong test đó.
--}}
@if (isset($data))
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.filamentData = @js($data)
    </script>
@endif

@foreach ($assets as $asset)
    @if (! $asset->isLoadedOnRequest())
        {{ $asset->getHtml() }}
    @endif
@endforeach

<style>
    :root {
        @foreach ($cssVariables ?? [] as $cssVariableName => $cssVariableValue) --{{ $cssVariableName }}:{{ $cssVariableValue }}; @endforeach
    }

    @foreach ($customColors ?? [] as $customColorName => $customColorShades) .fi-color-{{ $customColorName }} { @foreach ($customColorShades as $customColorShade) --color-{{ $customColorShade }}:var(--{{ $customColorName }}-{{ $customColorShade }}); @endforeach } @endforeach
</style>
