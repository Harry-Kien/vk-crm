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

    Dòng trắng ở đây là chữ, không phải khoảng cách: chân thư dựng từ
    `App\Support\BrandFooter::legalLines()` nên bốn thông tin pháp lý còn trống biến mất hẳn,
    không để lại dòng rỗng nào.
--}}
@yield('content')
--
{!! config('vkcrm.brand.legal_name') !!}
@foreach (App\Support\BrandFooter::legalLines() as $line)
{!! $line !!}
@endforeach
{!! __('emails.footer.hotline', ['value' => config('vkcrm.brand.hotline')]) !!}
{!! __('emails.footer.website', ['value' => config('vkcrm.brand.website')]) !!}
{!! __('emails.footer.automated') !!}
