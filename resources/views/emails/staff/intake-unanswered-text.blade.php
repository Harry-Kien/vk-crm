{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: thân thư là text/plain, xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('intake.reminder.email.greeting', ['name' => $recipientName]) !!}

{!! __('intake.reminder.email.headline', ['hours' => $thresholdHours]) !!}

{!! __('intake.reminder.email.code', ['code' => $code]) !!}
{!! __('intake.reminder.email.source', ['source' => $source]) !!}
{!! __('intake.reminder.email.received', ['at' => $receivedAt]) !!}
{!! __('intake.reminder.email.waited', ['duration' => $waited]) !!}

{!! __('intake.reminder.email.open') !!}: {!! $url !!}

{!! __('intake.reminder.email.privacy') !!}

{!! __('intake.reminder.email.salutation', ['office' => $office]) !!}
@endsection
