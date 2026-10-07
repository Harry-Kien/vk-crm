{{--
    Bản văn bản thuần của layout thư (SPEC §9).

    Có bản này chứ không chỉ có HTML vì hai lý do thật: hộp thư nào chặn HTML vẫn đọc được thư,
    và một thư chỉ-HTML bị nhiều bộ lọc thư rác chấm điểm nặng hơn — với thư mang mã đăng nhập
    thì điểm ấy là chênh lệch giữa "khách nhận được mã" và "khách gọi lên văn phòng".

    **`{!! !!}` chứ không `{{ }}`, và đây là chỗ đã cắn một lần.** Blade thoát HTML ở MỌI view,
    kể cả view không phải HTML. Trong thân thư text/plain thì không có HTML để thoát, nên
    `{{ }}` không làm gì an toàn hơn — nó chỉ in ra đúng chữ `&amp;` vào giữa tên pháp lý của
    văn phòng ("… Vũ Khang Solutions &amp; Partners") cho khách đọc. Laravel giấu chuyện này ở
    đường markdown vì `Markdown::renderText()` gọi `html_entity_decode()` ở cuối; đường
    `->view([html, text])` thì không có bước đó. Nhân chứng: test "không để lẫn thực thể HTML
    nào vào bản văn bản thuần" ở `tests/Feature/Mail/EmailLayoutTest.php`.

    Dòng trắng ở đây là chữ, không phải khoảng cách: mỗi dòng của chân thư chỉ in khi có giá trị
    (tên pháp lý, hotline, website qua `App\Support\OfficeProfile`; bốn thông tin pháp lý qua
    `App\Support\BrandFooter::legalLines()`), nên một thông tin còn trống biến mất hẳn, không để
    lại dòng rỗng nào. Giá trị đọc LÚC RENDER (M7 Task 10) — xem `emails/layout.blade.php`.
--}}
@php($office = App\Support\OfficeProfile::current())
@yield('content')
--
@if (filled($office->legalName()))
{!! $office->legalName() !!}
@endif
@foreach (App\Support\BrandFooter::legalLines($office) as $line)
{!! $line !!}
@endforeach
@if (filled($office->hotline()))
{!! __('emails.footer.hotline', ['value' => $office->hotline()]) !!}
@endif
@if (filled($office->website()))
{!! __('emails.footer.website', ['value' => $office->website()]) !!}
@endif
{!! __('emails.footer.automated') !!}
