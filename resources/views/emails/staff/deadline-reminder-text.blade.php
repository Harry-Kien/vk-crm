{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: thân thư là text/plain, xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@php
    // M6.5 Task 12 (`deadlines/F3`): xem bản HTML cho lý do đầy đủ — cùng $headlineKey, không lặp
    // lại luật ở đây theo cách khác (hai bản mà lệch nhau là đúng lỗi F3 dưới một hình dạng khác).
    $headline = match ($headlineKey) {
        'due_today' => __('deadlines.email.headline.due_today'),
        'overdue' => __('deadlines.email.headline.overdue', ['days' => abs($daysLeft)]),
        default => __('deadlines.email.headline.upcoming', ['days' => $daysLeft]),
    };
@endphp
@section('content')
{!! __('deadlines.email.greeting', ['name' => $recipientName]) !!}

{!! $headline !!}

{!! $deadlineName !!}
{!! __('deadlines.email.due', ['date' => $dueDate]) !!}
@if ($matterCode)

{!! __('deadlines.email.matter', ['code' => $matterCode, 'title' => $matterTitle]) !!}
@endif

{!! __('deadlines.email.action') !!}

{!! __('deadlines.email.salutation', ['office' => $office]) !!}
@endsection
