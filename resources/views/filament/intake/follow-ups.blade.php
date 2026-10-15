{{--
    Khối "Chăm sóc khách tiềm năng" của trang tiếp nhận (làn kd, 2026-10-14). Dữ liệu dựng sẵn ở
    `EditIntakeRequest::followUpsViewData()` — view này không nhận model, không truy vấn. Luật sư tư vấn
    (sau "Chuyển cho luật sư"), hẹn liên hệ lại, rồi các lần chăm sóc mới nhất trước. Chỉ style nội tuyến
    trên biến CSS của Filament (không bước build CSS).
--}}
<div style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.875rem; color: var(--gray-950);">
    @if($consulting !== null)
        <p style="font-weight: 600; margin: 0;">{{ $consulting }}</p>
    @endif

    <p style="margin: 0; color: var(--gray-700);">{{ $next }}</p>

    @if(count($rows) === 0)
        <p style="margin: 0; color: var(--gray-600);">{{ $empty }}</p>
    @else
        <ul style="margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 0.5rem;">
            @foreach($rows as $row)
                <li style="border-left: 3px solid var(--primary-500); padding-left: 0.75rem;">
                    <p style="margin: 0; font-size: 0.75rem; color: var(--gray-600);">{{ $row['heading'] }}</p>
                    <p style="margin: 0.125rem 0 0 0; white-space: pre-line;">{{ $row['note'] }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
