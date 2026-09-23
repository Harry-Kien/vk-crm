{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('portal.email.stage_update.greeting', ['name' => $name]) !!}

{!! __('portal.email.stage_update.line', ['code' => $matterCode]) !!}
@if (filled($excerpt))

{!! $excerpt !!}
@endif
@if (filled($clientAction))

{!! __('portal.email.stage_update.action_label') !!}
{!! $clientAction !!}
@endif

{!! __('portal.email.stage_update.open') !!} {!! $portalUrl !!}

{!! __('portal.email.stage_update.help', ['phone' => $hotline]) !!}

{!! __('portal.email.stage_update.salutation', ['office' => $office]) !!}
@endsection
