{{-- Mẫu `staff.deadline_reminder` của SPEC §9 — bản HTML. --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
    // M6.5 Task 12 (`deadlines/F3`): $headlineKey đọc theo SỐ NGÀY THẬT ('upcoming'/'due_today'/
    // 'overdue'), không theo bậc nhắc — xem docblock App\Mail\Staff\DeadlineReminder.
    $isUrgent = $headlineKey !== 'upcoming';
    $headline = match ($headlineKey) {
        'due_today' => __('deadlines.email.headline.due_today'),
        'overdue' => __('deadlines.email.headline.overdue', ['days' => abs($daysLeft)]),
        default => __('deadlines.email.headline.upcoming', ['days' => $daysLeft]),
    };
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('deadlines.email.greeting', ['name' => $recipientName]) }}</p>

    <p style="margin:0 0 12px; font-weight:700; color:{{ $isUrgent ? $colors['red'] : $colors['navy'] }};">
        {{ $headline }}
    </p>

    <p style="margin:0 0 4px;">{{ $deadlineName }}</p>
    <p style="margin:0 0 16px;">{{ __('deadlines.email.due', ['date' => $dueDate]) }}</p>

    @if ($matterCode)
        <p style="margin:0 0 16px;">{{ __('deadlines.email.matter', ['code' => $matterCode, 'title' => $matterTitle]) }}</p>
    @endif

    <p style="margin:0 0 16px;">{{ __('deadlines.email.action') }}</p>
    <p style="margin:0;">{{ __('deadlines.email.salutation', ['office' => $office]) }}</p>
@endsection
