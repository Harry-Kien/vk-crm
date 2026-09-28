{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('portal.email.document_published.greeting', ['name' => $name]) !!}

{!! __('portal.email.document_published.line', ['code' => $matterCode]) !!}

{!! __('portal.email.document_published.document_line', ['title' => $documentTitle, 'group' => $groupLabel]) !!}

{!! __('portal.email.document_published.open') !!} {!! $portalUrl !!}

{!! __('portal.email.document_published.help', ['phone' => $hotline]) !!}

{!! __('portal.email.document_published.salutation', ['office' => $office]) !!}
@endsection
