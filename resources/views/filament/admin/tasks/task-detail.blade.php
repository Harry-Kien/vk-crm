{{--
    Khung "Chi tiết việc" (làn tk, Task 1) — nút "Xem" của mọi bảng việc
    (App\Filament\Admin\Resources\Tasks\Concerns\ProvidesTaskActions::viewTaskAction()). Chỉ đọc. Mã vụ,
    KHÔNG tên khách. Chữ người dùng gõ (mô tả, lý do, ghi chú) in qua `{{ }}` (escape) và giữ xuống dòng
    bằng `white-space: pre-line`.

    Style nội tuyến trên biến CSS của Filament (dự án không có bước dựng CSS).
--}}
@php
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $moment = fn ($value) => $value?->format('H:i d/m/Y');
    $rows = array_filter([
        __('tasks.detail.matter') => $task->matter?->code ?? __('tasks.detail.general'),
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
@endphp
<div data-vk-task-detail style="display:flex;flex-direction:column;gap:1rem;">
    <dl style="display:grid;grid-template-columns:repeat(auto-fill, minmax(12rem, 1fr));gap:0.75rem;margin:0;">
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
</div>
