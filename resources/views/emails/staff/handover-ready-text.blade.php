{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: thân thư là text/plain, xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('handover.email.greeting', ['name' => $recipientName]) !!}

{!! __('handover.email.intro', ['code' => $matterCode, 'title' => $matterTitle]) !!}

{!! __('handover.email.action') !!}

{!! __('handover.email.link', ['url' => $url]) !!}

{!! __('handover.email.salutation', ['office' => $office]) !!}
@endsection
