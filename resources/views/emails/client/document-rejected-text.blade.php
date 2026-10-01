{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('portal.email.document_rejected.greeting', ['name' => $name]) !!}

{!! __('portal.email.document_rejected.line', ['code' => $matterCode, 'item' => $itemName]) !!}

{!! $reason !!}

{!! __('portal.email.document_rejected.open') !!} {!! $portalUrl !!}

{!! __('portal.email.document_rejected.help', ['phone' => $hotline]) !!}

{!! __('portal.email.document_rejected.salutation', ['office' => $office]) !!}
@endsection
