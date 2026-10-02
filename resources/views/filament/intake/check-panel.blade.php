{{--
    Khối "Kiểm tra xung đột lợi ích" của trang tiếp nhận (M10 Task 3). Dữ liệu dựng sẵn ở
    `EditIntakeRequest::checkPanelViewData()` — view này không nhận model, không truy vấn.

    Hiện NGÀY GIỜ của lần kiểm tra gần nhất, không bao giờ chữ "đã kiểm tra" (kế hoạch): kết quả có
    hạn dùng, và người đọc phải thấy nó cũ tới đâu. Bảng kết quả dùng lại đúng
    `filament.conflict-check-result` (cùng ranh giới lộ thông tin của `ConflictMatch`: mã hồ sơ, vai,
    tên bên trùng — không gì thêm). Chỉ style nội tuyến trên biến CSS của Filament (không bước build CSS).
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

    @if($carried)
        <p style="font-size: 0.875rem; color: var(--warning-700); margin: 0;">{{ __('intake.check.carried_note') }}</p>
    @endif

    <p style="font-size: 0.75rem; color: var(--gray-600); margin: 0;">{{ __('intake.check.stale_note') }}</p>
</div>
