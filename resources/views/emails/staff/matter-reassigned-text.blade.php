{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: thân thư là text/plain, xem docblock của emails.layout-text. --}}
@extends('emails.layout-text')

@section('content')
{!! __('reassign.email.greeting', ['name' => $recipientName]) !!}

{!! __('reassign.email.intro', ['count' => count($blocks)]) !!}
@foreach ($blocks as $block)
@php $matter = $block['matter']; @endphp

{!! __('reassign.email.matter', ['code' => $matter->code, 'title' => $matter->title]) !!}
{!! __('reassign.email.client', ['name' => $matter->client?->name ?? '']) !!}
{!! __('reassign.email.reason', ['reason' => $block['reason']]) !!}
@if (count($block['deadlines']) > 0)
{!! __('reassign.email.deadlines_heading') !!}
@foreach ($block['deadlines'] as $deadline)
- {!! __('reassign.email.deadline_line', [
    'name' => $deadline->name,
    'date' => $deadline->due_date->format('d/m/Y'),
    'severity' => $deadline->severity->label(),
]) !!}
@endforeach
@else
{!! __('reassign.email.no_deadlines') !!}
@endif
@if ($block['client_requests_moved'] > 0)
{!! __('reassign.email.client_requests_moved', ['count' => $block['client_requests_moved']]) !!}
@endif
@endforeach

{!! __('reassign.email.action') !!}

{!! __('reassign.email.salutation', ['office' => $office]) !!}
@endsection
