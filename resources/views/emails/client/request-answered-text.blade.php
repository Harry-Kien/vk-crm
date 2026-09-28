{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('portal.email.request_answered.greeting', ['name' => $name]) !!}

{!! __('portal.email.request_answered.line', ['code' => $matterCode]) !!}

{!! __('portal.email.request_answered.open') !!} {!! $portalUrl !!}

{!! __('portal.email.request_answered.help', ['phone' => $hotline]) !!}

{!! __('portal.email.request_answered.salutation', ['office' => $office]) !!}
@endsection
