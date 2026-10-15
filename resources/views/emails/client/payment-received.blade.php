{{-- Mẫu `client.payment_received` (làn nt Task 1) — bản HTML. Nội dung và ranh giới: docblock
     `App\Mail\Client\PaymentReceived`. --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('client_payments.mail.greeting', ['name' => $name]) }}</p>

    @if ($restricted)
        <p style="margin:0 0 16px;">{{ __('client_payments.mail.restricted_line', ['code' => $matterCode]) }}</p>
    @else
        <p style="margin:0 0 16px;">{{ __('client_payments.mail.line', ['code' => $matterCode]) }}</p>

        <ul style="margin:0 0 16px; padding:12px 14px 12px 32px; background-color:{{ $colors['paper'] }}; border-radius:6px;">
            <li style="margin:0 0 6px;"><strong>{{ __('client_payments.mail.amount', ['amount' => $amount]) }}</strong></li>
            <li style="margin:0 0 6px;">{{ __('client_payments.mail.paid_on', ['date' => $paidOn]) }}</li>
        </ul>

        {{-- Hợp đồng đã huỷ: không câu nào về số còn lại (docblock mailable). --}}
        @if ($outstanding !== null)
            <p style="margin:0 0 16px;">{{ __('client_payments.mail.outstanding', ['amount' => $outstanding]) }}</p>
        @elseif ($settled)
            <p style="margin:0 0 16px;">{{ __('client_payments.mail.settled') }}</p>
        @endif
    @endif

    <p style="margin:0 0 16px;">
        <a href="{{ $portalUrl }}" style="color:{{ $colors['navy'] }}; font-weight:700;">{{ __('client_payments.mail.open') }}</a>
    </p>

    <p style="margin:0 0 16px;">{{ __('client_payments.mail.help', ['phone' => $hotline]) }}</p>
    <p style="margin:0;">{{ __('client_payments.mail.salutation', ['office' => $office]) }}</p>
@endsection
