{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('portal.email.handover_published.greeting', ['name' => $name]) !!}

{!! __('portal.email.handover_published.line', ['code' => $matterCode]) !!}

{!! $accessLine !!}

{!! __('portal.email.handover_published.open') !!} {!! $portalUrl !!}

{!! __('portal.email.handover_published.help', ['phone' => $hotline]) !!}

{!! __('portal.email.handover_published.salutation', ['office' => $office]) !!}
@endsection
