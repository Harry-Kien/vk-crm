{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: thân thư là text/plain, xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('backup.email.heading.'.$kind) !!}
@if ($diskName)

{!! __('backup.email.disk', ['disk' => $diskName]) !!}
@endif

{!! __('backup.email.detail', ['detail' => $detail]) !!}

{!! __('backup.email.action') !!}

{!! __('backup.email.salutation', ['office' => $office]) !!}
@endsection
