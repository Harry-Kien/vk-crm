{{--
    MUC-LUC.pdf của gói bàn giao (M7 Task 4, SPEC §6.12) — do dompdf dựng từ
    `App\Actions\Matter\RenderHandoverIndex`.

    Ràng buộc của dompdf, không phải của ta: chỉ CSS 2.1 (không flex, không grid), font phải là
    một font TTF nhúng được — `DejaVu Sans` đi kèm dompdf và có đủ dấu tiếng Việt. Font mặc định
    của dompdf (Helvetica/Times) rơi mất dấu, nên `font-family` PHẢI nêu `DejaVu Sans` ở mọi phần
    tử có chữ (dompdf không kế thừa font qua bảng một cách đáng tin).

    Chữ đều qua `__('handover.pdf.…')`; dữ liệu là hình chiếu hẹp do action dựng. KHÔNG BAO GIỜ
    nhắc tới `internal_note` ở đây — action chỉ nạp những cột khách được thấy của dòng tiến độ, nên
    cột đó thậm chí không có trong bộ nhớ khi Blade chạy.

    # Mở rộng

    Mỗi khối nội dung là một partial trong `handover/partials/`, ghép theo thứ tự ở dưới; tệp này
    chỉ giữ khung trang và CSS dùng chung (các lớp `h2`, `table`, `.info`, `.label`, `.muted`,
    `.entry`…). Thêm một khối (ví dụ bảng kê thanh toán của M9 Task 10) = một partial mới dùng
    lại các lớp đó + MỘT dòng `@include` ở vị trí muốn in + MỘT khoá dữ liệu mới truyền từ
    `RenderHandoverIndex::handle()`. Không khối nào cần sửa khối khác.
--}}
@php
    $colors = config('vkcrm.brand.colors');
    $font = "'DejaVu Sans', sans-serif";
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>{{ __('handover.pdf.title') }}</title>
    <style>
        @page { margin: 60px 45px 80px 45px; }
        body { font-family: {{ $font }}; font-size: 10.5px; color: {{ $colors['deep'] }}; line-height: 1.45; }
        h1 { font-family: {{ $font }}; font-size: 19px; color: {{ $colors['navy'] }}; margin: 0 0 4px 0; }
        h2 { font-family: {{ $font }}; font-size: 13px; color: {{ $colors['navy'] }}; margin: 22px 0 8px 0; border-bottom: 2px solid {{ $colors['red'] }}; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; }
        th { font-family: {{ $font }}; text-align: left; background-color: {{ $colors['navy'] }}; color: #ffffff; padding: 5px 6px; font-size: 10px; }
        td { font-family: {{ $font }}; padding: 5px 6px; border-bottom: 1px solid #d0d5dd; vertical-align: top; }
        .info td { border-bottom: 1px solid #eaecf0; }
        .label { color: {{ $colors['muted'] }}; width: 30%; }
        .muted { color: {{ $colors['muted'] }}; }
        .office { font-family: {{ $font }}; font-size: 11px; font-weight: bold; color: {{ $colors['red'] }}; margin-bottom: 10px; }
        .entry { margin-bottom: 11px; page-break-inside: avoid; }
        .entry-head { font-weight: bold; color: {{ $colors['navy'] }}; }
        .entry-body { margin-top: 2px; white-space: pre-wrap; }
        .footer { position: fixed; bottom: -55px; left: 0; right: 0; border-top: 2px solid {{ $colors['red'] }}; padding-top: 5px; font-size: 8.5px; color: {{ $colors['muted'] }}; }
        .pageno:after { content: counter(page); }
    </style>
</head>
<body>
    {{-- Chân trang `position: fixed` phải đứng TRƯỚC nội dung để dompdf lặp nó từ trang đầu. --}}
    @include('handover.partials.footer')

    @if ($officeName !== '')
        <div class="office">{{ $officeName }}</div>
    @endif
    <h1>{{ __('handover.pdf.title') }}</h1>
    <div class="muted">{{ __('handover.pdf.generated_at', ['date' => $generatedAt]) }}</div>

    @include('handover.partials.matter-info')
    @include('handover.partials.documents')
    @include('handover.partials.timeline')
</body>
</html>
