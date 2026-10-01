{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('requests.email.new_request.greeting', ['name' => $recipientName]) !!}

{!! __('requests.email.new_request.line', ['code' => $matterCode, 'title' => $matterTitle]) !!}

{!! __('requests.email.new_request.subject_line') !!}
{!! $requestSubject !!}

{!! $requestContent !!}

{!! __('requests.email.new_request.action') !!}

{!! __('requests.email.new_request.salutation', ['office' => $office]) !!}
@endsection
