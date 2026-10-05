{{-- Mẫu `staff.intake_unanswered` của SPEC §9 (đính chính M10) — bản HTML. Chỉ mã, nguồn, lúc nhận,
     thời gian đã chờ và liên kết: KHÔNG gì của người liên hệ — xem docblock App\Mail\Staff\IntakeUnanswered. --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('intake.reminder.email.greeting', ['name' => $recipientName]) }}</p>

    <p style="margin:0 0 12px; font-weight:700; color:{{ $colors['red'] }};">
        {{ __('intake.reminder.email.headline', ['hours' => $thresholdHours]) }}
    </p>

    <p style="margin:0 0 4px;">{{ __('intake.reminder.email.code', ['code' => $code]) }}</p>
    <p style="margin:0 0 4px;">{{ __('intake.reminder.email.source', ['source' => $source]) }}</p>
    <p style="margin:0 0 4px;">{{ __('intake.reminder.email.received', ['at' => $receivedAt]) }}</p>
    <p style="margin:0 0 16px;">{{ __('intake.reminder.email.waited', ['duration' => $waited]) }}</p>

    <p style="margin:0 0 16px;">
        <a href="{{ $url }}" style="color:{{ $colors['navy'] }}; font-weight:700;">{{ __('intake.reminder.email.open') }}</a>
    </p>

    <p style="margin:0 0 16px;">{{ __('intake.reminder.email.privacy') }}</p>
    <p style="margin:0;">{{ __('intake.reminder.email.salutation', ['office' => $office]) }}</p>
@endsection
