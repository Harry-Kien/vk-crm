{{-- Thư cảnh báo kho tài liệu (`App\Mail\Staff\DocumentStoreAlert`, M14 Task 5) — bản HTML. Chỉ loại sự cố và số đếm. --}}
@extends('emails.layout')

@php
    $colors = config('vkcrm.brand.colors');
@endphp

@section('content')
    <p style="margin:0 0 16px; font-weight:700; color:{{ $colors['red'] }};">
        {{ $heading }}
    </p>

    <p style="margin:0 0 16px;">{{ __('document_store.alert.action') }}</p>
    <p style="margin:0;">{{ __('document_store.alert.salutation', ['office' => $office]) }}</p>
@endsection
