{{--
    Mẫu `staff.instalment_overdue` của SPEC §9 — bản HTML.

    Chỉ các trường của `App\Support\Billing\AccountantBillingRow` + số ngày quá hạn (xem docblock
    `App\Mail\Staff\InstalmentOverdue`): KHÔNG thêm tiêu đề vụ việc hay ghi chú nội bộ vào đây.
    Style nội tuyến trên màu thương hiệu, không class Tailwind (không có bước build CSS).
--}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('billing.overdue_email.greeting', ['name' => $recipientName]) }}</p>

    <p style="margin:0 0 16px; font-weight:700; color:{{ $colors['red'] }};">
        {{ __('billing.overdue_email.headline', ['days' => $days]) }}
    </p>

    <p style="margin:0 0 4px;">{{ __('billing.overdue_email.matter', ['code' => $matterCode, 'type' => $matterTypeName]) }}</p>
    <p style="margin:0 0 4px;">{{ __('billing.overdue_email.client', ['client' => $clientName]) }}</p>
    <p style="margin:0 0 4px;">{{ __('billing.overdue_email.instalment', ['name' => $instalmentName]) }}</p>
    <p style="margin:0 0 4px; font-weight:700;">{{ __('billing.overdue_email.outstanding', ['amount' => $outstanding]) }}</p>
    <p style="margin:0 0 20px;">{{ __('billing.overdue_email.due', ['date' => $dueDate]) }}</p>

    <p style="margin:0 0 20px;">
        <a href="{{ $linkUrl }}" style="display:inline-block; padding:10px 18px; background-color:{{ $colors['navy'] }}; color:#ffffff; text-decoration:none; border-radius:4px; font-weight:700;">{{ $linkLabel }}</a>
    </p>

    <p style="margin:0 0 16px;">{{ $actionLine }}</p>
    <p style="margin:0;">{{ __('billing.overdue_email.salutation', ['office' => $office]) }}</p>
@endsection
