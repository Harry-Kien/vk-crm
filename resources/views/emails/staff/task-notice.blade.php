{{-- Mẫu `staff.task.<loại>` (làn tk, Task 1) — bản HTML. Nội dung dựng ở App\Mail\Staff\TaskNotice::content(): tiêu
     đề việc, mã vụ, hạn, mức ưu tiên, lý do/ghi chú. Không tên khách, không mô tả nội bộ. --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('tasks.email.greeting', ['name' => $recipientName]) }}</p>

    <p style="margin:0 0 12px; font-weight:700; color:{{ $colors['navy'] }};">{{ $headline }}</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;">
        @foreach ($details as $detail)
            <tr>
                <td style="padding:2px 12px 2px 0; color:{{ $colors['muted'] }}; vertical-align:top;">{{ $detail['label'] }}</td>
                <td style="padding:2px 0; vertical-align:top;">{{ $detail['value'] }}</td>
            </tr>
        @endforeach
    </table>

    <p style="margin:0 0 16px;">
        <a href="{{ $url }}" style="color:{{ $colors['navy'] }}; font-weight:700;">{{ $action }}</a>
    </p>

    <p style="margin:0;">{{ __('tasks.email.salutation', ['office' => $office]) }}</p>
@endsection
