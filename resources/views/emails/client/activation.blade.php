{{-- Mẫu `client.activation` của SPEC §9 — bản HTML. --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('portal.email.activation.greeting', ['name' => $name]) }}</p>
    <p style="margin:0 0 16px;">{{ __('portal.email.activation.line') }}</p>

    <p style="margin:0 0 16px;">{{ __('portal.email.activation.email_label') }} <strong>{{ $email }}</strong></p>
    <p style="margin:0 0 16px; padding:12px 14px; background-color:{{ $colors['paper'] }}; border-radius:6px; font-family:monospace; font-size:16px;">{{ $temporaryPassword }}</p>

    <p style="margin:0 0 16px;">{{ __('portal.email.activation.must_change') }}</p>
    <p style="margin:0 0 16px;">{{ __('portal.email.activation.otp_note') }}</p>

    <p style="margin:0 0 16px;">
        <a href="{{ $portalUrl }}" style="color:{{ $colors['navy'] }}; font-weight:700;">{{ __('portal.email.activation.open') }}</a>
    </p>

    <p style="margin:0 0 16px;">{{ __('portal.email.activation.help', ['phone' => $hotline]) }}</p>
    <p style="margin:0;">{{ __('portal.email.activation.salutation', ['office' => $office]) }}</p>
@endsection
