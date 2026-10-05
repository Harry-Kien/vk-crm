{{-- Mẫu `client.document_published` của SPEC §9, biến thể GÓI BÀN GIAO HỒ SƠ (việc sau gộp M7) — bản HTML. Không in tên tài liệu nào: xem docblock App\Mail\Client\DocumentPublished. --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px;">{{ __('portal.email.handover_published.greeting', ['name' => $name]) }}</p>
    <p style="margin:0 0 16px;">{{ __('portal.email.handover_published.line', ['code' => $matterCode]) }}</p>

    <p style="margin:0 0 16px; padding:12px 14px; background-color:{{ $colors['paper'] }}; border-radius:6px;">
        {{ $accessLine }}
    </p>

    <p style="margin:0 0 16px;">
        <a href="{{ $portalUrl }}" style="color:{{ $colors['navy'] }}; font-weight:700;">{{ __('portal.email.handover_published.open') }}</a>
    </p>

    <p style="margin:0 0 16px;">{{ __('portal.email.handover_published.help', ['phone' => $hotline]) }}</p>
    <p style="margin:0;">{{ __('portal.email.handover_published.salutation', ['office' => $office]) }}</p>
@endsection
