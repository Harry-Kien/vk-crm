{{--
    Khối "Kiểm tra xung đột lợi ích" của trang tiếp nhận (M10 Task 3). Dữ liệu dựng sẵn ở
    `EditIntakeRequest::checkPanelViewData()` — view này không nhận model, không truy vấn.

    Hiện NGÀY GIỜ của lần kiểm tra gần nhất, không bao giờ chữ "đã kiểm tra" (kế hoạch): kết quả có
    hạn dùng, và người đọc phải thấy nó cũ tới đâu. Bảng kết quả dùng lại đúng
    `filament.conflict-check-result` (cùng ranh giới lộ thông tin của `ConflictMatch`: mã hồ sơ, vai,
    tên bên trùng — không gì thêm; với người không xử lý được Đỏ, dòng Đỏ chỉ còn mã hồ sơ và vai — R1).
    Chỉ style nội tuyến trên biến CSS của Filament (không bước build CSS).

    Rà soát cuối M10, vòng sửa 1: `$redPendingNote` (FI5 — bản ghi còn chờ trưởng phòng dù lần chạy gần
    nhất không ra Đỏ), `$redHiddenNote` (FI6, R1) và `$heldRepeatCalls` (FI2 — chỉ có dữ liệu với người
    xử lý được Đỏ: các lần gọi khác của cùng người đang bị khoá theo bản ghi này).
--}}
<div style="display: flex; flex-direction: column; gap: 0.75rem;">
    <p style="font-size: 0.875rem; font-weight: 600; color: var(--gray-950); margin: 0;">
        @if($checkedAt !== null)
            {{ __('intake.check.checked_at', ['at' => $checkedAt]) }}
        @else
            {{ __('intake.check.never_checked') }}
        @endif
    </p>

    @if($result !== null)
        @include('filament.conflict-check-result', $result)
    @endif

    @if($redPendingNote !== null)
        <p style="font-size: 0.875rem; color: var(--danger-700); margin: 0;">{{ $redPendingNote }}</p>
    @endif

    @if($redHiddenNote !== null)
        <p style="font-size: 0.875rem; color: var(--gray-700); margin: 0;">{{ $redHiddenNote }}</p>
    @endif

    @if(count($heldRepeatCalls) > 0)
        <div style="font-size: 0.875rem; color: var(--gray-950); border-left: 3px solid var(--danger-500); padding-left: 0.75rem;">
            <p style="font-weight: 600; margin: 0;">{{ __('intake.check.held_repeat_calls') }}</p>
            <p style="margin: 0.25rem 0 0 0;">
                @foreach($heldRepeatCalls as $held)
                    <a href="{{ $held['url'] }}" style="color: var(--primary-600); font-weight: 600;">{{ $held['code'] }}</a>@if(! $loop->last), @endif
                @endforeach
            </p>
            <p style="color: var(--gray-700); margin: 0.25rem 0 0 0;">{{ __('intake.check.held_repeat_calls_note') }}</p>
        </div>
    @endif

    @if($carried)
        <p style="font-size: 0.875rem; color: var(--warning-700); margin: 0;">{{ __('intake.check.carried_note') }}</p>
    @endif

    <p style="font-size: 0.75rem; color: var(--gray-600); margin: 0;">{{ __('intake.check.stale_note') }}</p>
</div>
