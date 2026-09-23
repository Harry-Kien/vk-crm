{{-- Mẫu `client.stage_update` của SPEC §9 — bản HTML. --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('portal.email.stage_update.greeting', ['name' => $name]) }}</p>
    <p style="margin:0 0 16px;">{{ __('portal.email.stage_update.line', ['code' => $matterCode]) }}</p>

    @if (filled($excerpt))
        <p style="margin:0 0 16px; padding:12px 14px; background-color:{{ $colors['paper'] }}; border-radius:6px;">{{ $excerpt }}</p>
    @endif

    @if (filled($clientAction))
        <p style="margin:0 0 16px; font-weight:700; color:{{ $colors['navy'] }};">{{ __('portal.email.stage_update.action_label') }}</p>
        <p style="margin:0 0 16px;">{{ $clientAction }}</p>
    @endif

    <p style="margin:0 0 16px;">
        <a href="{{ $portalUrl }}" style="color:{{ $colors['navy'] }}; font-weight:700;">{{ __('portal.email.stage_update.open') }}</a>
    </p>

    <p style="margin:0 0 16px;">{{ __('portal.email.stage_update.help', ['phone' => $hotline]) }}</p>
    <p style="margin:0;">{{ __('portal.email.stage_update.salutation', ['office' => $office]) }}</p>
@endsection
