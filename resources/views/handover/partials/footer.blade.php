{{--
    MUC-LUC.pdf — chân trang cố định, lặp lại mỗi trang. `$legalLines` từ `BrandFooter::legalLines()`,
    đã bỏ dòng trống: khi bốn thông tin pháp lý chưa có thì chỉ còn tên văn phòng, không nhãn cụt.
    Cả hai đọc qua `App\Support\OfficeProfile` (M7 Task 10); tên văn phòng cũng chỉ in khi có.
--}}
<div class="footer">
    @if ($officeName !== '')
        <div><strong>{{ $officeName }}</strong></div>
    @endif
    @foreach ($legalLines as $line)
        <div>{{ $line }}</div>
    @endforeach
    <div>{{ __('handover.pdf.page') }} <span class="pageno"></span></div>
</div>
