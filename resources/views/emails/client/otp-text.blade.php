{{--
    Mẫu `client.otp` của SPEC §9 — bản văn bản thuần, đi kèm bản HTML trong cùng một thư.

    `{!! !!}` là có chủ ý, không phải bất cẩn: thân thư này là text/plain, không có HTML để thoát,
    và `{{ }}` sẽ in `&amp;` vào giữa tên văn phòng ở dòng chào cuối thư. Lý do đầy đủ ở docblock
    của `emails.layout-text`.
--}}
@extends('emails.layout-text')

@section('content')
{!! __('portal.email.otp.greeting', ['name' => $name]) !!}

{!! __('portal.email.otp.line') !!}

{!! $code !!}

{!! __('portal.email.otp.expiry', ['minutes' => $codeExpiryMinutes]) !!}

{!! __('portal.email.otp.ignore', ['phone' => $hotline]) !!}

{!! __('portal.email.otp.salutation', ['office' => $office]) !!}
@endsection
