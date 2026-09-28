{{-- Mẫu `client.document_rejected` của SPEC §9 — bản HTML. --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('portal.email.document_rejected.greeting', ['name' => $name]) }}</p>
    <p style="margin:0 0 16px;">{{ __('portal.email.document_rejected.line', ['code' => $matterCode, 'item' => $itemName]) }}</p>

    <p style="margin:0 0 16px; padding:12px 14px; background-color:{{ $colors['paper'] }}; border-radius:6px;">{{ $reason }}</p>

    <p style="margin:0 0 16px;">
        <a href="{{ $portalUrl }}" style="color:{{ $colors['navy'] }}; font-weight:700;">{{ __('portal.email.document_rejected.open') }}</a>
    </p>

    <p style="margin:0 0 16px;">{{ __('portal.email.document_rejected.help', ['phone' => $hotline]) }}</p>
    <p style="margin:0;">{{ __('portal.email.document_rejected.salutation', ['office' => $office]) }}</p>
@endsection
