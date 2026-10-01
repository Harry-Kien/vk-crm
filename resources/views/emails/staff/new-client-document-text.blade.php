{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('requests.email.new_document.greeting', ['name' => $recipientName]) !!}

{!! __('requests.email.new_document.line', [
    'code' => $matterCode,
    'title' => $matterTitle,
    'item' => $itemName,
    'count' => $count,
]) !!}

{!! __('requests.email.new_document.action') !!}

{!! __('requests.email.new_document.salutation', ['office' => $office]) !!}
@endsection
