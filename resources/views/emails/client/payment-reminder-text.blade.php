{{-- Bản văn bản thuần của `client.payment_reminder.*` (làn nt Task 2) — cũng là bản hộp thoại "Gửi thư
     nhắc" in ra để xem trước (`PaymentReminder::previewText()`). `{!! !!}` có chủ ý: xem docblock của
     emails.layout-text. Mỗi dòng KẾT THÚC bằng một lệnh in (Blade nuốt dòng mới sau `@endif`), nên các
     nhánh dựng cả đoạn bằng biểu thức ba ngôi thay vì `@if` giữa các dòng. --}}
@extends('emails.layout-text')

@php
    $transferLines = $transfer === null ? '' : "\n\n".__('client_payments.reminder_mail.transfer_heading')
        ."\n- ".__('client_payments.reminder_mail.bank', ['name' => $transfer['bank']])
        ."\n- ".__('client_payments.reminder_mail.account_number', ['number' => $transfer['account_number']])
        ."\n- ".__('client_payments.reminder_mail.account_holder', ['holder' => $transfer['account_holder']])
        .(filled($transfer['bank_branch']) ? "\n- ".__('client_payments.reminder_mail.bank_branch', ['branch' => $transfer['bank_branch']]) : '')
        ."\n- ".__('client_payments.reminder_mail.transfer_note', ['note' => $transfer['note']])
        ."\n".__('client_payments.reminder_mail.note_hint');
    $details = $restricted
        ? __('client_payments.reminder_mail.'.$kind.'.restricted_line', ['code' => $matterCode])
        : __('client_payments.reminder_mail.'.$kind.'.line', ['code' => $matterCode])
            ."\n- ".__('client_payments.reminder_mail.instalment', ['name' => $instalmentName])
            ."\n- ".__('client_payments.reminder_mail.amount', ['amount' => $amount])
            ."\n- ".__('client_payments.reminder_mail.due', ['date' => $dueOn])
            .$transferLines
            ."\n\n".__('client_payments.reminder_mail.already_paid');
@endphp

@section('content')
{!! __('client_payments.reminder_mail.greeting', ['name' => $name]) !!}

{!! $details !!}

{!! __('client_payments.reminder_mail.open') !!} {!! $portalUrl !!}

{!! __('client_payments.reminder_mail.help', ['phone' => $hotline]) !!}

{!! __('client_payments.reminder_mail.salutation', ['office' => $office]) !!}
@endsection
