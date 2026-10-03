{{--
    Chân trang đăng nhập: tên pháp lý đầy đủ, hotline và website thật của văn phòng — đọc qua
    `App\Support\OfficeProfile` (M7 Task 10: sửa được ở trang "Thông tin văn phòng").
--}}
@php($office = App\Support\OfficeProfile::current())
<div style="margin-top:1.75rem;text-align:center;font-size:0.75rem;line-height:1.6;color:rgb(107 114 128)">
    <p style="margin:0;font-weight:600;color:rgb(75 85 99)">{{ $office->legalName() }}</p>
    <p style="margin:0.15rem 0 0">
        <a href="tel:{{ $office->hotline() }}"
           style="color:inherit;text-decoration:none">{{ $office->hotline() }}</a>
        <span aria-hidden="true" style="opacity:.5;margin:0 .35rem">·</span>
        <a href="{{ $office->website() }}" target="_blank" rel="noopener noreferrer"
           style="color:inherit;text-decoration:none">{{ str($office->website())->after('://')->toString() }}</a>
    </p>
</div>
