{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: thân thư là text/plain, xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('tasks.email.greeting', ['name' => $recipientName]) !!}

{!! $headline !!}

@foreach ($details as $detail)
{!! $detail['label'] !!}: {!! $detail['value'] !!}
@endforeach

{!! $action !!}: {!! $url !!}

{!! __('tasks.email.salutation', ['office' => $office]) !!}
@endsection
