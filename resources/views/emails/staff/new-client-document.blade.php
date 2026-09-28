{{-- Mẫu `staff.new_client_document` của SPEC §9 — bản HTML. --}}
@extends('emails.layout')

@section('content')
    <p style="margin:0 0 16px;">{{ __('requests.email.new_document.greeting', ['name' => $recipientName]) }}</p>
    <p style="margin:0 0 16px;">
        {{ __('requests.email.new_document.line', [
            'code' => $matterCode,
            'title' => $matterTitle,
            'item' => $itemName,
            'count' => $count,
        ]) }}
    </p>

    <p style="margin:0 0 16px;">{{ __('requests.email.new_document.action') }}</p>
    <p style="margin:0;">{{ __('requests.email.new_document.salutation', ['office' => $office]) }}</p>
@endsection
