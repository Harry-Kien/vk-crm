{{-- Bản văn bản thuần của `client.payment_received`. `{!! !!}` có chủ ý: xem docblock của
     emails.layout-text. Mỗi dòng KẾT THÚC bằng một lệnh in (Blade nuốt dòng mới sau `@endif`), nên
     các nhánh dưới đây dựng cả đoạn bằng biểu thức ba ngôi thay vì `@if` giữa các dòng. Câu về số
     còn lại: `$outstanding` (còn phải trả), `$settled` (đã đủ), hay không câu nào (hợp đồng đã huỷ) —
     xem docblock `App\Mail\Client\PaymentReceived`. --}}
@extends('emails.layout-text')

@section('content')
{!! __('client_payments.mail.greeting', ['name' => $name]) !!}

{!! $restricted ? __('client_payments.mail.restricted_line', ['code' => $matterCode]) : __('client_payments.mail.line', ['code' => $matterCode])."\n- ".__('client_payments.mail.amount', ['amount' => $amount])."\n- ".__('client_payments.mail.paid_on', ['date' => $paidOn]).($outstanding !== null ? "\n\n".__('client_payments.mail.outstanding', ['amount' => $outstanding]) : ($settled ? "\n\n".__('client_payments.mail.settled') : '')) !!}

{!! __('client_payments.mail.open') !!} {!! $portalUrl !!}

{!! __('client_payments.mail.help', ['phone' => $hotline]) !!}

{!! __('client_payments.mail.salutation', ['office' => $office]) !!}
@endsection
