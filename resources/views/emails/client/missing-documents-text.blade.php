{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('portal.email.missing_documents.greeting', ['name' => $name]) !!}

{!! __('portal.email.missing_documents.line', ['code' => $matterCode]) !!}

@foreach ($lines as $line)
- {!! $line['name'] !!}@if ($line['rejected']) — {!! __('portal.email.missing_documents.rejected') !!}@endif @if ($line['reason']) ({!! __('portal.email.missing_documents.rejected_reason', ['reason' => $line['reason']]) !!})@endif

@endforeach
{!! __('portal.email.missing_documents.open') !!} {!! $portalUrl !!}

{!! __('portal.email.missing_documents.help', ['phone' => $hotline]) !!}

{!! __('portal.email.missing_documents.salutation', ['office' => $office]) !!}
@endsection
