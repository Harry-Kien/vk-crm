{{-- Mẫu `staff.matter_reassigned` của SPEC §9 — bản HTML (M7 Task 1, SPEC §6.11 bước 3). --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('reassign.email.greeting', ['name' => $recipientName]) }}</p>

    <p style="margin:0 0 20px;">{{ __('reassign.email.intro', ['count' => count($blocks)]) }}</p>

    @foreach ($blocks as $block)
        @php $matter = $block['matter']; @endphp
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px; border:1px solid {{ $colors['muted'] }}; border-radius:4px;">
            <tr>
                <td style="padding:14px 16px;">
                    <p style="margin:0 0 4px; font-weight:700; color:{{ $colors['navy'] }};">
                        {{ __('reassign.email.matter', ['code' => $matter->code, 'title' => $matter->title]) }}
                    </p>
                    <p style="margin:0 0 8px;">{{ __('reassign.email.client', ['name' => $matter->client?->name ?? '']) }}</p>
                    <p style="margin:0 0 12px;">{{ __('reassign.email.reason', ['reason' => $block['reason']]) }}</p>

                    @if (count($block['deadlines']) > 0)
                        <p style="margin:0 0 4px; font-weight:700;">{{ __('reassign.email.deadlines_heading') }}</p>
                        <ul style="margin:0 0 8px; padding-left:18px;">
                            @foreach ($block['deadlines'] as $deadline)
                                <li>{{ __('reassign.email.deadline_line', [
                                    'name' => $deadline->name,
                                    'date' => $deadline->due_date->format('d/m/Y'),
                                    'severity' => $deadline->severity->label(),
                                ]) }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p style="margin:0 0 8px;">{{ __('reassign.email.no_deadlines') }}</p>
                    @endif

                    @if ($block['client_requests_moved'] > 0)
                        <p style="margin:0;">{{ __('reassign.email.client_requests_moved', ['count' => $block['client_requests_moved']]) }}</p>
                    @endif

                    {{-- Làn tk, Task 2: việc nội bộ chuyển sang theo lần bàn giao (trạng thái lúc gửi trên từng dòng). --}}
                    @if (count($block['tasks'] ?? []) > 0)
                        <p style="margin:8px 0 4px; font-weight:700;">{{ __('tasks.handover.email.tasks_heading') }}</p>
                        <ul style="margin:0; padding-left:18px;">
                            @foreach ($block['tasks'] as $task)
                                <li>{{ __('tasks.handover.email.task_line', [
                                    'title' => $task->title,
                                    'status' => $task->status->label(),
                                    'due' => $task->due_date?->format('d/m/Y') ?? __('tasks.email.no_due_date'),
                                ]) }}</li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- Làn tk, Task 2, vòng sửa 1 (I1): việc lead cũ đã giao trên vụ, nay lead mới là người giao. --}}
                    @if (count($block['followed_tasks'] ?? []) > 0)
                        <p style="margin:8px 0 4px; font-weight:700;">{{ __('tasks.handover.email.followed_heading') }}</p>
                        <ul style="margin:0; padding-left:18px;">
                            @foreach ($block['followed_tasks'] as $task)
                                <li>{{ __('tasks.handover.email.followed_line', [
                                    'title' => $task->title,
                                    'assignee' => (string) $task->assignee?->name,
                                    'status' => $task->status->label(),
                                    'due' => $task->due_date?->format('d/m/Y') ?? __('tasks.email.no_due_date'),
                                ]) }}</li>
                            @endforeach
                        </ul>
                    @endif
                </td>
            </tr>
        </table>
    @endforeach

    <p style="margin:0 0 16px;">{{ __('reassign.email.action') }}</p>
    {{-- Làn tk, Task 2: ghế luật sư phụ trách vừa bàn giao chờ người nhận bấm "Đã nhận hồ sơ" (vòng sửa cuối 1: chỉ khi còn chờ — MatterReassigned::acceptHint()). --}}
    @if ($acceptHint !== null)
        <p style="margin:0 0 16px;">{{ $acceptHint }}</p>
    @endif
    <p style="margin:0;">{{ __('reassign.email.salutation', ['office' => $office]) }}</p>
@endsection
