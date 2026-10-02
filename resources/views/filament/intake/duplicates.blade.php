{{--
    Gợi ý trùng lúc nhập (M10 R4), dựng sẵn ở `EditIntakeRequest::duplicatesViewData()` từ kết quả
    `FindIntakeDuplicates` của lần ghi nhận. Ranh giới lộ thông tin:
     - bản cùng SĐT/CCCD mà người nhập XEM ĐƯỢC: mã, tên, ngày nhận, liên kết;
     - bản cùng SĐT/CCCD họ KHÔNG xem được: chỉ một câu, không mã, không tên, không đếm;
     - trùng tên: chỉ có trong dữ liệu khi người xem có `intake.viewAny`;
     - "đã là khách": một câu có/không, không tên, không mã, không danh sách.
--}}
<div style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.875rem; color: var(--gray-950);">
    @if(count($sameIdentity) > 0)
        <p style="font-weight: 600; margin: 0;">{{ __('intake.duplicates.same_identity') }}</p>
        <ul style="margin: 0 0 0 1.25rem; padding: 0; list-style: disc;">
            @foreach($sameIdentity as $row)
                <li><a href="{{ $row['url'] }}" style="color: var(--primary-600); font-weight: 600;">{{ $row['code'] }}</a> — {{ $row['name'] }} ({{ $row['received_at'] }})</li>
            @endforeach
        </ul>
    @endif

    @if($hasHidden)
        <p style="margin: 0;">{{ __('intake.duplicates.hidden_same_identity') }}</p>
    @endif

    @if(count($sameName) > 0)
        <p style="font-weight: 600; margin: 0;">{{ __('intake.duplicates.same_name') }}</p>
        <ul style="margin: 0 0 0 1.25rem; padding: 0; list-style: disc;">
            @foreach($sameName as $row)
                <li><a href="{{ $row['url'] }}" style="color: var(--primary-600); font-weight: 600;">{{ $row['code'] }}</a> — {{ $row['name'] }} ({{ $row['received_at'] }})</li>
            @endforeach
        </ul>
    @endif

    @if($isClient)
        <p style="font-weight: 600; color: var(--warning-700); margin: 0;">{{ __('intake.duplicates.existing_client') }}</p>
    @endif

    @if($lookupUnavailable)
        <p style="color: var(--gray-700); margin: 0;">{{ __('intake.duplicates.lookup_unavailable') }}</p>
    @endif

    @if(count($sameIdentity) > 0 || count($sameName) > 0)
        <p style="font-size: 0.75rem; color: var(--gray-600); margin: 0;">{{ __('intake.duplicates.merge_hint') }}</p>
    @endif
</div>
