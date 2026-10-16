{{-- Mẫu `staff.test_mail` (làn st, `App\Mail\Staff\TestMail`) — bản HTML. --}}
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 16px;">{{ __('settings.mail.email.greeting', ['name' => $recipientName]) }}</p>
    <p style="margin:0 0 16px;">{{ __('settings.mail.email.line', ['at' => $sentAt]) }}</p>
    <p style="margin:0 0 16px;">{{ __('settings.mail.email.transport', ['transport' => $transport]) }}</p>
    <p style="margin:0 0 16px;">{{ __('settings.mail.email.action') }}</p>
    <p style="margin:0;">{{ __('settings.mail.email.salutation', ['office' => $office]) }}</p>
@endsection
