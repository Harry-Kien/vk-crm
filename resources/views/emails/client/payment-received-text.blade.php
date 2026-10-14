{{-- Bản văn bản thuần của `client.payment_received`. `{!! !!}` có chủ ý: xem docblock của
     emails.layout-text. Mỗi dòng KẾT THÚC bằng một lệnh in (Blade nuốt dòng mới sau `@endif`), nên
     các nhánh dưới đây dựng cả đoạn bằng biểu thức ba ngôi thay vì `@if` giữa các dòng. --}}
@extends('emails.layout-text')

@section('content')
{!! __('client_payments.mail.greeting', ['name' => $name]) !!}

{!! $restricted ? __('client_payments.mail.restricted_line', ['code' => $matterCode]) : __('client_payments.mail.line', ['code' => $matterCode])."\n- ".__('client_payments.mail.amount', ['amount' => $amount])."\n- ".__('client_payments.mail.paid_on', ['date' => $paidOn])."\n\n".($outstanding !== null ? __('client_payments.mail.outstanding', ['amount' => $outstanding]) : __('client_payments.mail.settled')) !!}

{!! __('client_payments.mail.open') !!} {!! $portalUrl !!}

{!! __('client_payments.mail.help', ['phone' => $hotline]) !!}

{!! __('client_payments.mail.salutation', ['office' => $office]) !!}
@endsection
