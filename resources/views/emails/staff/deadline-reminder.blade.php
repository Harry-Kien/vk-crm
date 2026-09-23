{{-- Mẫu `staff.deadline_reminder` của SPEC §9 — bản HTML. --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('deadlines.email.greeting', ['name' => $recipientName]) }}</p>

    <p style="margin:0 0 12px; font-weight:700; color:{{ $isOverdue ? $colors['red'] : $colors['navy'] }};">
        {{ $isOverdue
            ? __('deadlines.email.headline.overdue', ['days' => abs($daysLeft)])
            : __('deadlines.email.headline.upcoming', ['days' => $daysLeft]) }}
    </p>

    <p style="margin:0 0 4px;">{{ $deadlineName }}</p>
    <p style="margin:0 0 16px;">{{ __('deadlines.email.due', ['date' => $dueDate]) }}</p>

    @if ($matterCode)
        <p style="margin:0 0 16px;">{{ __('deadlines.email.matter', ['code' => $matterCode, 'title' => $matterTitle]) }}</p>
    @endif

    <p style="margin:0 0 16px;">{{ __('deadlines.email.action') }}</p>
    <p style="margin:0;">{{ __('deadlines.email.salutation', ['office' => $office]) }}</p>
@endsection
