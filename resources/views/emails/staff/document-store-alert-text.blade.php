{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: thân thư là text/plain, xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! $heading !!}

{!! __('document_store.alert.action') !!}

{!! __('document_store.alert.salutation', ['office' => $office]) !!}
@endsection
