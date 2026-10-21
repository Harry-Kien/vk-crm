{{--
    Khung "Chi tiết việc" (làn tk, Task 1) — nút "Xem" của mọi bảng việc
    (App\Filament\Admin\Resources\Tasks\Concerns\ProvidesTaskActions::viewTaskAction()). Mã vụ, KHÔNG tên khách.
    Chữ người dùng gõ (mô tả, lý do, ghi chú) in qua `{{ }}` (escape) và giữ xuống dòng bằng `white-space: pre-line`.

    Kiểm toán màn hình 2026-10-21 (mục 5): mã vụ là liên kết tới tab "Công việc" của hồ sơ khi người xem
    (`$viewer`) mở được hồ sơ (`MatterPolicy::view`); khối "Diễn biến" đọc nhật ký của CHÍNH việc này
    (App\Support\Tasks\TaskTimeline — docblock lớp đó nói ai thấy gì). Các nút thao tác ở chân khung là chính các
    nút của dòng (cùng cổng), không ở đây.

    Style nội tuyến trên biến CSS của Filament (dự án không có bước dựng CSS).
--}}
@php
    $viewer ??= auth()->user();
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $moment = fn ($value) => $value?->format('H:i d/m/Y');
    $matterUrl = $task->matter !== null && $viewer !== null && \Illuminate\Support\Facades\Gate::forUser($viewer)->allows('view', $task->matter)
        ? \App\Filament\Admin\Resources\Tasks\Tables\TaskColumns::matterTabUrl($task->matter->getKey())
        : null;
    $rows = array_filter([
        __('tasks.detail.assignee') => $task->assignee?->name,
        __('tasks.detail.assigner') => $task->assigner?->name,
        __('tasks.detail.status') => $task->status->label(),
        __('tasks.detail.priority') => $task->priority->label(),
        __('tasks.detail.due_date') => $task->due_date?->format('d/m/Y') ?? __('tasks.due.none'),
        __('tasks.detail.assigned_at') => $moment($task->assigned_at),
        __('tasks.detail.accepted_at') => $moment($task->accepted_at),
        __('tasks.detail.declined_at') => $moment($task->declined_at),
        __('tasks.detail.completed_at') => $moment($task->completed_at),
        __('tasks.detail.cancelled_at') => $moment($task->cancelled_at),
    ], fn ($value) => filled($value));
    $texts = array_filter([
        __('tasks.detail.description') => $task->description,
        __('tasks.detail.decline_reason') => $task->decline_reason,
        __('tasks.detail.completion_note') => $task->completion_note,
        __('tasks.detail.cancel_reason') => $task->cancel_reason,
    ], fn ($value) => filled($value));
    $history = \App\Support\Tasks\TaskTimeline::for($task);
@endphp
<div data-vk-task-detail style="display:flex;flex-direction:column;gap:1rem;">
    <dl style="display:grid;grid-template-columns:repeat(auto-fill, minmax(12rem, 1fr));gap:0.75rem;margin:0;">
        <div>
            <dt style="{{ $muted }}font-size:0.8125rem;">{{ __('tasks.detail.matter') }}</dt>
            <dd style="margin:0;font-weight:600;">
                @if ($matterUrl !== null)
                    <a href="{{ $matterUrl }}" title="{{ __('tasks.detail.open_matter') }}" style="color:var(--primary-600);text-decoration:underline;text-underline-offset:2px;">{{ $task->matter->code }}</a>
                @else
                    {{ $task->matter?->code ?? __('tasks.detail.general') }}
                @endif
            </dd>
        </div>
        @foreach ($rows as $label => $value)
            <div>
                <dt style="{{ $muted }}font-size:0.8125rem;">{{ $label }}</dt>
                <dd style="margin:0;font-weight:600;">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @foreach ($texts as $label => $value)
        <div>
            <p style="{{ $muted }}font-size:0.8125rem;margin:0 0 0.25rem;">{{ $label }}</p>
            <p style="margin:0;white-space:pre-line;">{{ $value }}</p>
        </div>
    @endforeach

    <section data-vk-task-history>
        <p style="{{ $muted }}font-size:0.8125rem;font-weight:600;margin:0 0 0.5rem;">{{ __('tasks.detail.history') }}</p>
        @if ($history === [])
            <p style="{{ $muted }}margin:0;">{{ __('tasks.detail.history_empty') }}</p>
        @else
            <ol style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:0.625rem;">
                @foreach ($history as $event)
                    <li style="padding-left:0.75rem;border-left:2px solid color-mix(in srgb, var(--gray-500) 35%, transparent);">
                        <span style="{{ $muted }}font-size:0.75rem;display:block;">{{ $moment($event['at']) }}</span>
                        <span style="display:block;">{{ $event['text'] }}</span>
                        @foreach ($event['notes'] as $note)
                            <span style="{{ $muted }}display:block;font-size:0.8125rem;white-space:pre-line;">{{ $note }}</span>
                        @endforeach
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
</div>
