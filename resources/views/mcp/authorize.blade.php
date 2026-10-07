{{--
    Màn hình đồng ý OAuth của máy chủ MCP (M11 Task 4). Dựng bởi
    `App\Http\Responses\Mcp\ConsentScreenResponse` — đọc docblock ở đó cho luật của từng phần.

    Viết mới, không publish view của Passport (CSS của view đó đang hỏng, issue #348 [PL:58]).

    Ba luật của tệp này:

    1. **Không bao giờ in `client_name`.** Tên đó do client tự khai lúc đăng ký động; ai cũng đặt được
       "Claude chính chủ". Thứ duy nhất nói lên client là HOST của redirect (mã chỉ đi tới đó), nên
       màn hình hiện nền tảng suy từ host CÙNG chính host ấy. View không nhận đối tượng client nào.

    2. **Không có script nào.** CSP của SPEC §10.2 cấm script nội tuyến không nonce, và trang này
       không cần script: hai nút là hai form HTML thường (POST "Đồng ý", DELETE "Từ chối" qua
       `_method`), có CSRF và `auth_token` dùng một lần của Passport.

    3. **Kiểu dáng nội tuyến, mọi biến màu có giá trị dự phòng** (như `errors/404.blade.php`): không có
       bước dựng CSS, và trang được trả NGOÀI Filament, nên biến của Filament có thể chưa được nạp.
--}}
@php
    $muted = 'color:color-mix(in srgb, var(--gray-500, #6b7280) 95%, transparent);';
    $tap = 'min-height:44px;display:inline-flex;align-items:center;justify-content:center;padding:0.625rem 1.25rem;border-radius:3px;font:inherit;font-weight:600;cursor:pointer;';
    $box = 'margin-top:1rem;padding:0.75rem 1rem;border-radius:3px;';
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('mcp_consent.title') }}</title>
</head>
<body style="margin:0;padding:1.5rem;background-color:var(--gray-50, #f9fafb);color:var(--gray-950, #0f172a);font-family:'Be Vietnam Pro', ui-sans-serif, system-ui, 'Segoe UI', Roboto, sans-serif;line-height:1.6;">
    <main style="max-width:34rem;margin:0 auto;padding:1.5rem;border-radius:3px;background-color:var(--gray-25, #ffffff);border:1px solid color-mix(in srgb, var(--gray-500, #6b7280) 30%, transparent);">
        <p style="margin:0;font-size:0.875rem;font-weight:600;letter-spacing:0.02em;{{ $muted }}">{{ config('vkcrm.brand.short_name') }}</p>

        @if ($refusal === null)
            <h1 style="margin:0.25rem 0 0;font-size:1.375rem;font-weight:700;line-height:1.35;">{{ __('mcp_consent.heading', ['platform' => $platform->label()]) }}</h1>
        @else
            <h1 style="margin:0.25rem 0 0;font-size:1.375rem;font-weight:700;line-height:1.35;">{{ __('mcp_consent.refused_heading') }}</h1>
            <p role="alert" style="{{ $box }}background-color:color-mix(in srgb, var(--danger-600, #dc2626) 10%, transparent);border:1px solid color-mix(in srgb, var(--danger-600, #dc2626) 40%, transparent);">{{ $refusal->label() }}</p>
        @endif

        <dl style="margin:1rem 0 0;display:grid;grid-template-columns:max-content 1fr;gap:0.25rem 1rem;">
            <dt style="{{ $muted }}">{{ __('mcp_consent.platform') }}</dt>
            <dd style="margin:0;font-weight:600;">{{ $platform->label() }}</dd>

            <dt style="{{ $muted }}">{{ __('mcp_consent.redirect_host') }}</dt>
            <dd style="margin:0;font-weight:600;font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;word-break:break-all;">{{ $host }}</dd>

            @if ($staff !== null)
                <dt style="{{ $muted }}">{{ __('mcp_consent.account') }}</dt>
                <dd style="margin:0;">{{ $staff->name }} ({{ $staff->email }})</dd>
            @endif

            @if ($refusal === null && $mode !== null)
                <dt style="{{ $muted }}">{{ __('mcp_consent.mode') }}</dt>
                <dd style="margin:0;">{{ $mode->label() }}@if ($writeSwitchOff) ({{ __('mcp_consent.write_switch_off') }})@endif</dd>
            @endif
        </dl>

        @if ($platform->isLocal())
            <p role="alert" style="{{ $box }}background-color:color-mix(in srgb, var(--warning-500, #f59e0b) 14%, transparent);border:1px solid color-mix(in srgb, var(--warning-600, #d97706) 45%, transparent);">{{ __('mcp_consent.loopback_warning', ['host' => $host]) }}</p>
        @elseif ($platform === \App\Enums\McpPlatform::Other)
            <p role="alert" style="{{ $box }}background-color:color-mix(in srgb, var(--warning-500, #f59e0b) 14%, transparent);border:1px solid color-mix(in srgb, var(--warning-600, #d97706) 45%, transparent);">{{ __('mcp_consent.other_warning') }}</p>
        @endif

        @if ($refusal === null)
            <p style="margin:1rem 0 0;font-weight:600;">{{ __('mcp_consent.acts_as_you') }}</p>
            <p style="margin:0.25rem 0 0;">{{ __('mcp_consent.acts_as_you_detail') }}</p>
        @endif

        <p style="margin:1rem 0 0;">
            <a href="{{ $policyUrl }}" style="color:var(--primary-600, #334155);font-weight:600;">{{ __('mcp_consent.policy_link') }}</a>
        </p>

        @if ($authToken !== null)
            <div style="margin-top:1.5rem;display:flex;flex-wrap:wrap;gap:0.75rem;">
                @if ($refusal === null)
                    <form method="post" action="{{ route('passport.authorizations.approve') }}" data-consent="approve" style="margin:0;">
                        @csrf
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">
                        <button type="submit" style="{{ $tap }}border:1px solid var(--primary-600, #334155);background-color:var(--primary-600, #334155);color:var(--primary-50, #f8fafc);">{{ __('mcp_consent.approve') }}</button>
                    </form>
                @endif

                <form method="post" action="{{ route('passport.authorizations.deny') }}" data-consent="deny" style="margin:0;">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" style="{{ $tap }}border:1px solid color-mix(in srgb, var(--gray-500, #6b7280) 40%, transparent);background-color:transparent;color:var(--gray-950, #0f172a);">{{ __('mcp_consent.deny') }}</button>
                </form>
            </div>

            @if ($refusal !== null)
                <p style="margin:0.75rem 0 0;{{ $muted }}">{{ __('mcp_consent.refused_deny_hint') }}</p>
            @endif
        @endif
    </main>
</body>
</html>
