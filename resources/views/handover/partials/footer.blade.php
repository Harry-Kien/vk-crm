{{--
    MUC-LUC.pdf — chân trang cố định, lặp lại mỗi trang. `$legalLines` từ `BrandFooter::legalLines()`,
    đã bỏ dòng trống: khi bốn thông tin pháp lý chưa có thì chỉ còn tên văn phòng, không nhãn cụt.
--}}
<div class="footer">
    <div><strong>{{ $officeName }}</strong></div>
    @foreach ($legalLines as $line)
        <div>{{ $line }}</div>
    @endforeach
    <div>{{ __('handover.pdf.page') }} <span class="pageno"></span></div>
</div>
