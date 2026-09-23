{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: thân thư là text/plain, xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('deadlines.email.greeting', ['name' => $recipientName]) !!}

{!! $isOverdue
    ? __('deadlines.email.headline.overdue', ['days' => abs($daysLeft)])
    : __('deadlines.email.headline.upcoming', ['days' => $daysLeft]) !!}

{!! $deadlineName !!}
{!! __('deadlines.email.due', ['date' => $dueDate]) !!}
@if ($matterCode)

{!! __('deadlines.email.matter', ['code' => $matterCode, 'title' => $matterTitle]) !!}
@endif

{!! __('deadlines.email.action') !!}

{!! __('deadlines.email.salutation', ['office' => $office]) !!}
@endsection
