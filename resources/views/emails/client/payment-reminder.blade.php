{{-- Hai mẫu `client.payment_reminder.due_soon` / `.overdue` (làn nt Task 2) — bản HTML. Nội dung và ranh
     giới: docblock `App\Mail\Client\PaymentReminder`. Ảnh QR đính vào CHÍNH thư bằng `$message->embedData()`
     (`cid:`): `$message` chỉ có khi thư được GỬI — lúc dựng thư không gửi (`render()`) thì không có ảnh. --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('client_payments.reminder_mail.greeting', ['name' => $name]) }}</p>

    @if ($restricted)
        <p style="margin:0 0 16px;">{{ __('client_payments.reminder_mail.'.$kind.'.restricted_line', ['code' => $matterCode]) }}</p>
    @else
        <p style="margin:0 0 16px;">{{ __('client_payments.reminder_mail.'.$kind.'.line', ['code' => $matterCode]) }}</p>

        <ul style="margin:0 0 16px; padding:12px 14px 12px 32px; background-color:{{ $colors['paper'] }}; border-radius:6px;">
            <li style="margin:0 0 6px;">{{ __('client_payments.reminder_mail.instalment', ['name' => $instalmentName]) }}</li>
            <li style="margin:0 0 6px;"><strong>{{ __('client_payments.reminder_mail.amount', ['amount' => $amount]) }}</strong></li>
            <li style="margin:0 0 6px;">{{ __('client_payments.reminder_mail.due', ['date' => $dueOn]) }}</li>
        </ul>

        @if ($transfer !== null)
            <p style="margin:0 0 8px; font-weight:700;">{{ __('client_payments.reminder_mail.transfer_heading') }}</p>
            <p style="margin:0 0 4px;">{{ __('client_payments.reminder_mail.bank', ['name' => $transfer['bank']]) }}</p>
            <p style="margin:0 0 4px; font-weight:700;">{{ __('client_payments.reminder_mail.account_number', ['number' => $transfer['account_number']]) }}</p>
            <p style="margin:0 0 4px;">{{ __('client_payments.reminder_mail.account_holder', ['holder' => $transfer['account_holder']]) }}</p>
            @if (filled($transfer['bank_branch']))
                <p style="margin:0 0 4px;">{{ __('client_payments.reminder_mail.bank_branch', ['branch' => $transfer['bank_branch']]) }}</p>
            @endif
            <p style="margin:0 0 4px; font-weight:700; font-family:Consolas, Menlo, monospace;">{{ __('client_payments.reminder_mail.transfer_note', ['note' => $transfer['note']]) }}</p>
            <p style="margin:0 0 16px;">{{ __('client_payments.reminder_mail.note_hint') }}</p>

            @if ($qrPng !== null && isset($message))
                <p style="margin:0 0 4px;">
                    <img src="{{ $message->embedData($qrPng, 'ma-qr-chuyen-khoan.png', 'image/png') }}"
                         alt="{{ __('client_payments.reminder_mail.qr_alt', ['amount' => $amount]) }}"
                         width="176" height="176"
                         style="display:block; width:176px; height:176px; background-color:#ffffff;">
                </p>
                <p style="margin:0 0 16px;">{{ __('client_payments.reminder_mail.qr_hint') }}</p>
            @endif
        @endif

        <p style="margin:0 0 16px;">{{ __('client_payments.reminder_mail.already_paid') }}</p>
    @endif

    <p style="margin:0 0 16px;">
        <a href="{{ $portalUrl }}" style="color:{{ $colors['navy'] }}; font-weight:700;">{{ __('client_payments.reminder_mail.open') }}</a>
    </p>

    <p style="margin:0 0 16px;">{{ __('client_payments.reminder_mail.help', ['phone' => $hotline]) }}</p>
    <p style="margin:0;">{{ __('client_payments.reminder_mail.salutation', ['office' => $office]) }}</p>
@endsection
