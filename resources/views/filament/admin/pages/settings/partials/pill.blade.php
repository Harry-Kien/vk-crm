{{--
    Huy hiệu trạng thái của các trang Cài đặt (làn st). Không dùng `<x-filament::badge>`: huy hiệu đó cắt
    chữ trên một dòng ("Khách xem 90 ngày sau khi k…"), còn trạng thái ở đây là một câu cần đọc hết.
    Style nội tuyến trên biến màu của Filament (không có bước dựng CSS). `$tone`: success | warning |
    danger | gray.
--}}
@php
    $shade = $tone === 'gray' ? '--gray-500' : '--'.$tone.'-500';
    $ink = $tone === 'gray' ? 'inherit' : 'var(--'.$tone.'-600)';
@endphp
<span data-settings-pill="{{ $tone }}" style="display:inline-block;max-width:100%;padding:0.125rem 0.5rem;border-radius:0.375rem;font-size:0.75rem;font-weight:600;line-height:1.45;white-space:normal;overflow-wrap:anywhere;color:{{ $ink }};background-color:color-mix(in srgb, var({{ $shade }}) 12%, transparent);box-shadow:inset 0 0 0 1px color-mix(in srgb, var({{ $shade }}) 30%, transparent);">{{ $text }}</span>
