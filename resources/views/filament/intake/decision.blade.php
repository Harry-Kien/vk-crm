{{--
    Kết quả xử lý một lần tiếp nhận (M10 Task 3): đã từ chối, hoặc đã gộp vào bản khác. Dữ liệu dựng
    sẵn ở `EditIntakeRequest::decisionViewData()`.

    R8: lý do của một lần từ chối VÌ XUNG ĐỘT (và chính việc đó là vì xung đột) chỉ có trong dữ liệu
    khi người xem qua `IntakeRequestPolicy::viewConflictReason`; người khác chỉ thấy nhãn trung tính
    "Văn phòng từ chối" và câu trả lời ra ngoài — luôn "văn phòng xin phép không nhận vụ việc này".
--}}
<div style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.875rem; color: var(--gray-950);">
    @if($declined)
        <p style="font-weight: 600; margin: 0;">{{ $declinedLabel }}</p>
        @if($forConflict)
            <p style="font-weight: 600; color: var(--danger-700); margin: 0;">{{ __('intake.decision.declined_for_conflict') }}</p>
        @endif
        @if($reason !== null)
            <p style="margin: 0; white-space: pre-line;">{{ $reason }}</p>
        @endif
        <p style="color: var(--gray-700); margin: 0;">{{ __('intake.decision.outward_answer') }}</p>
    @endif

    @if($mergedInto !== null)
        <p style="margin: 0;">
            @if($mergedInto['url'] !== null)
                <a href="{{ $mergedInto['url'] }}" style="color: var(--primary-600); font-weight: 600;">{{ __('intake.decision.merged_into', ['code' => $mergedInto['code']]) }}</a>
            @else
                {{ __('intake.decision.merged_into', ['code' => $mergedInto['code']]) }}
            @endif
        </p>
    @endif
</div>
