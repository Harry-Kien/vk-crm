{{-- Mẫu `staff.stale_matter` của SPEC §9 — bản HTML. --}}
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 16px;">{{ __('matters.stale_reminder_email.greeting', ['name' => $recipientName]) }}</p>
    <p style="margin:0 0 16px;">
        {{ __('matters.stale_reminder_email.line', [
            'code' => $matterCode,
            'title' => $matterTitle,
            'days' => $daysSinceUpdate,
        ]) }}
    </p>

    <p style="margin:0 0 16px;">{{ __('matters.stale_reminder_email.action') }}</p>
    <p style="margin:0;">{{ __('matters.stale_reminder_email.salutation', ['office' => $office]) }}</p>
@endsection
