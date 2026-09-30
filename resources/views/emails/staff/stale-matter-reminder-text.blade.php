{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('matters.stale_reminder_email.greeting', ['name' => $recipientName]) !!}

{!! __('matters.stale_reminder_email.line', [
    'code' => $matterCode,
    'title' => $matterTitle,
    'days' => $daysSinceUpdate,
]) !!}

{!! __('matters.stale_reminder_email.action') !!}

{!! __('matters.stale_reminder_email.salutation', ['office' => $office]) !!}
@endsection
