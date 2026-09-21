{{--
    Mẫu `client.otp` của SPEC §9. Layout chung của văn phòng (logo + chân trang) là việc của M6;
    hôm nay dùng layout markdown mặc định của Laravel để thư vẫn đọc được trên điện thoại.
--}}
<x-mail::message>
{{ __('portal.email.otp.greeting', ['name' => $name]) }}

{{ __('portal.email.otp.line') }}

# {{ $code }}

{{ __('portal.email.otp.expiry', ['minutes' => $codeExpiryMinutes]) }}

{{ __('portal.email.otp.ignore', ['phone' => $hotline]) }}

{{ __('portal.email.otp.salutation', ['office' => $office]) }}
</x-mail::message>
