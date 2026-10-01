{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('portal.email.activation.greeting', ['name' => $name]) !!}

{!! __('portal.email.activation.line') !!}

{!! __('portal.email.activation.email_label') !!} {!! $email !!}
{!! $temporaryPassword !!}

{!! __('portal.email.activation.must_change') !!}

{!! __('portal.email.activation.otp_note') !!}

{!! __('portal.email.activation.open') !!} {!! $portalUrl !!}

{!! __('portal.email.activation.help', ['phone' => $hotline]) !!}

{!! __('portal.email.activation.salutation', ['office' => $office]) !!}
@endsection
