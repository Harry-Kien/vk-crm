{{--
    Mẫu `client.otp` của SPEC §9 — bản HTML.

    Từ M6 Task 1, mẫu này nằm trên layout thương hiệu dùng chung (`emails.layout`): logo, khối
    nhận diện, chân thư pháp lý. Thư vẫn gửi ĐỒNG BỘ, không xếp hàng — lý do ở docblock của
    `App\Notifications\Client\SendLoginCode`, và có một test ghim điều đó ở `LoginTest`.
--}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('portal.email.otp.greeting', ['name' => $name]) }}</p>
    <p style="margin:0 0 12px;">{{ __('portal.email.otp.line') }}</p>
    <p style="margin:0 0 16px; padding:14px 0; text-align:center; background-color:{{ $colors['paper'] }}; border-radius:6px; color:{{ $colors['navy'] }}; font-size:30px; font-weight:700; letter-spacing:8px;">{{ $code }}</p>
    <p style="margin:0 0 16px;">{{ __('portal.email.otp.expiry', ['minutes' => $codeExpiryMinutes]) }}</p>
    <p style="margin:0 0 16px;">{{ __('portal.email.otp.ignore', ['phone' => $hotline]) }}</p>
    <p style="margin:0;">{{ __('portal.email.otp.salutation', ['office' => $office]) }}</p>
@endsection
