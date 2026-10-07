{{-- Mẫu `staff.handover_ready` (M7 Task 4) — bản HTML. Báo luật sư gói bàn giao hồ sơ đã sinh xong. --}}
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 16px;">{{ __('handover.email.greeting', ['name' => $recipientName]) }}</p>

    <p style="margin:0 0 16px;">{{ __('handover.email.intro', ['code' => $matterCode, 'title' => $matterTitle]) }}</p>

    <p style="margin:0 0 16px;">{{ __('handover.email.action') }}</p>

    <p style="margin:0 0 16px;"><a href="{{ $url }}">{{ __('handover.email.link', ['url' => $url]) }}</a></p>

    <p style="margin:0;">{{ __('handover.email.salutation', ['office' => $office]) }}</p>
@endsection
