{{--
    Trạng thái ô câu chuyện của một lần tiếp nhận (M10 Task 3, R1 + R7a), nói BẰNG LỜI: ô đang mở,
    hoặc đang khoá vì điều gì và phải làm gì tiếp. Danh sách lý do lấy đúng từ
    `App\Actions\Intake\IntakeSummaryGate::blockers()` (dựng sẵn ở `EditIntakeRequest`), nên màn hình
    và cổng thật không lệch nhau. Không bao giờ nói VÌ SAO Đỏ — chỉ rằng đang chờ trưởng phòng/quản trị.
--}}
@if($open)
    <p style="font-size: 0.875rem; font-weight: 600; color: var(--success-700); margin: 0;">{{ __('intake.gate.open') }}</p>
@else
    <div style="border: 1px solid var(--warning-300); background-color: var(--warning-50); border-radius: 0.75rem; padding: 0.75rem 1rem; color: var(--gray-950);">
        <p style="font-size: 0.875rem; font-weight: 600; margin: 0;">{{ $lockedHeading }}</p>
        <ul style="margin: 0.5rem 0 0 1.25rem; padding: 0; list-style: disc;">
            @foreach($blockers as $blocker)
                <li style="font-size: 0.875rem; margin-top: 0.25rem;">
                    <span style="font-weight: 600;">{{ $blocker['label'] }}</span>
                    @if($blocker['hint'] !== null)
                        <span style="display: block; color: var(--gray-700);">{{ $blocker['hint'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
