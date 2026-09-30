{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: thân thư là text/plain, xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('billing.overdue_email.greeting', ['name' => $recipientName]) !!}

{!! __('billing.overdue_email.headline', ['days' => $days]) !!}

{!! __('billing.overdue_email.matter', ['code' => $matterCode, 'type' => $matterTypeName]) !!}
{!! __('billing.overdue_email.client', ['client' => $clientName]) !!}
{!! __('billing.overdue_email.instalment', ['name' => $instalmentName]) !!}
{!! __('billing.overdue_email.outstanding', ['amount' => $outstanding]) !!}
{!! __('billing.overdue_email.due', ['date' => $dueDate]) !!}

{!! $linkLabel !!}: {!! $linkUrl !!}

{!! $actionLine !!}

{!! __('billing.overdue_email.salutation', ['office' => $office]) !!}
@endsection
