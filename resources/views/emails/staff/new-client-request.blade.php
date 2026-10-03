{{-- Mẫu `staff.new_client_request` của SPEC §9 — bản HTML. --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('requests.email.new_request.greeting', ['name' => $recipientName]) }}</p>
    <p style="margin:0 0 16px;">{{ __('requests.email.new_request.line', ['code' => $matterCode, 'title' => $matterTitle]) }}</p>

    <p style="margin:0 0 4px; font-weight:700;">{{ __('requests.email.new_request.subject_line') }}</p>
    <p style="margin:0 0 4px; font-weight:700;">{{ $requestSubject }}</p>
    <p style="margin:0 0 16px; padding:12px 14px; background-color:{{ $colors['paper'] }}; border-radius:6px; white-space:pre-line;">
        {{ $requestContent }}
    </p>

    <p style="margin:0 0 16px;">{{ __('requests.email.new_request.action') }}</p>
    <p style="margin:0;">{{ __('requests.email.new_request.salutation', ['office' => $office]) }}</p>
@endsection
