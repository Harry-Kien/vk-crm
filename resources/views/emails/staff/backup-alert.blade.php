{{-- Thư báo lỗi sao lưu (`App\Mail\Staff\BackupAlert`, SPEC §10 mục 8) — bản HTML. --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px; font-weight:700; color:{{ $colors['red'] }};">
        {{ __('backup.email.heading.'.$kind) }}
    </p>

    @if ($diskName)
        <p style="margin:0 0 12px;">{{ __('backup.email.disk', ['disk' => $diskName]) }}</p>
    @endif

    <p style="margin:0 0 16px;">{{ __('backup.email.detail', ['detail' => $detail]) }}</p>

    <p style="margin:0 0 16px;">{{ __('backup.email.action') }}</p>
    <p style="margin:0;">{{ __('backup.email.salutation', ['office' => $office]) }}</p>
@endsection
