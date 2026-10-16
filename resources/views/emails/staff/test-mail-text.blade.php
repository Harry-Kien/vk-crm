{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: thân thư là text/plain, xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('settings.mail.email.greeting', ['name' => $recipientName]) !!}

{!! __('settings.mail.email.line', ['at' => $sentAt]) !!}

{!! __('settings.mail.email.transport', ['transport' => $transport]) !!}

{!! __('settings.mail.email.action') !!}

{!! __('settings.mail.email.salutation', ['office' => $office]) !!}
@endsection
