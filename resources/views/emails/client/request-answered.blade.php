{{-- Mẫu `client.request_answered` của SPEC §9 — bản HTML. --}}
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 16px;">{{ __('portal.email.request_answered.greeting', ['name' => $name]) }}</p>
    <p style="margin:0 0 16px;">{{ __('portal.email.request_answered.line', ['code' => $matterCode]) }}</p>

    <p style="margin:0 0 16px;">
        <a href="{{ $portalUrl }}" style="color:{{ config('vkcrm.brand.colors')['navy'] }}; font-weight:700;">{{ __('portal.email.request_answered.open') }}</a>
    </p>

    <p style="margin:0 0 16px;">{{ __('portal.email.request_answered.help', ['phone' => $hotline]) }}</p>
    <p style="margin:0;">{{ __('portal.email.request_answered.salutation', ['office' => $office]) }}</p>
@endsection
