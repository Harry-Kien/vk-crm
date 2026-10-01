{{-- Mẫu `client.missing_documents` của SPEC §6.9/§9 — bản HTML. --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('portal.email.missing_documents.greeting', ['name' => $name]) }}</p>
    <p style="margin:0 0 16px;">{{ __('portal.email.missing_documents.line', ['code' => $matterCode]) }}</p>

    <ul style="margin:0 0 16px; padding:12px 14px 12px 32px; background-color:{{ $colors['paper'] }}; border-radius:6px;">
        @foreach ($lines as $line)
            <li style="margin:0 0 6px;">
                <strong>{{ $line['name'] }}</strong>
                @if ($line['rejected'])
                    — {{ __('portal.email.missing_documents.rejected') }}
                @endif
                @if ($line['reason'])
                    <br>{{ __('portal.email.missing_documents.rejected_reason', ['reason' => $line['reason']]) }}
                @endif
            </li>
        @endforeach
    </ul>

    <p style="margin:0 0 16px;">
        <a href="{{ $portalUrl }}" style="color:{{ $colors['navy'] }}; font-weight:700;">{{ __('portal.email.missing_documents.open') }}</a>
    </p>

    <p style="margin:0 0 16px;">{{ __('portal.email.missing_documents.help', ['phone' => $hotline]) }}</p>
    <p style="margin:0;">{{ __('portal.email.missing_documents.salutation', ['office' => $office]) }}</p>
@endsection
