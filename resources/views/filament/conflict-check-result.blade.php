{{--
    Bảng kết quả kiểm tra xung đột lợi ích, hiện NGAY TRONG form tạo vụ việc (SPEC §6.10, đoạn
    "Giao diện").

    **Ranh giới lộ thông tin có chủ đích — đừng thêm cột nào vào bảng này** ngoài đúng những gì
    App\Support\ConflictMatch::toArray() liệt kê: mã hồ sơ, tên loại vụ việc, vai và tên của bên
    trùng, tiêu chí đã khớp, mức, và (M6.5 Task 8, R13d) vai/tên của bên PHÍA MÌNH gây ra khớp đó.
    Đây là ngoại lệ có chủ đích duy nhất của quy tắc phân quyền trong toàn hệ thống: đủ để nhận ra
    xung đột, KHÔNG đủ để lộ bí mật hồ sơ khác — người đang nhìn bảng này thường không có quyền xem
    những hồ sơ đó. View này không nhận model, không truy vấn, và không được phép làm cả hai việc.

    **`already_confirmed` (R13c/`conflict-01`).** Một khớp đã được xác nhận/ghi đè ở một lần chạy
    TRƯỚC trên CÙNG vụ việc không còn chặn lưu, nhưng SPEC §11 vẫn đòi nó "vẫn hiện" — người xem xét
    phải thấy đủ bức tranh, không phải chỉ những gì MỚI. Cột "Mức" của một dòng như vậy vẫn đúng
    (đỏ/vàng thật), chỉ có nhãn nhỏ này nói thêm rằng dòng đó không phải lý do lượt lưu lần này cần
    xác nhận.

    **Fix round 1, minor (`conflict-check-result.blade.php:44`/`:62`).** Toàn bộ file này TRƯỚC
    ĐÂY dùng class Tailwind viết tay (`rounded-xl`, `border-danger-300`, `py-1`, `text-gray-600`,
    v.v.). Dự án KHÔNG có bước build CSS riêng — Filament chỉ phục vụ một tệp theme BIÊN DỊCH SẴN,
    không JIT-quét các view tuỳ biến của ứng dụng — nên MỌI class như vậy render ra KHÔNG CÓ GÌ
    (đã xảy ra thật với hai tính năng khác của dự án, xem docblock
    `resources/views/filament/admin/widgets/system-health.blade.php`, quy ước đã thiết lập ở đó).
    Toàn bộ style ở đây giờ nội tuyến, lấy màu qua biến CSS của Filament (`var(--danger-600)`,
    v.v.) — cùng quy ước với `system-health.blade.php` và `resources/views/errors/403.blade.php`.

    **Bốn câu tuỳ chọn (M10 Task 3):** `$headingRed`, `$headingAttention`, `$headingClear`, `$intro` —
    màn hình tiếp nhận nói về "ô câu chuyện" chứ không về "lưu vụ việc". Không truyền thì giữ nguyên
    câu của form mở vụ; bảng và ranh giới lộ thông tin không đổi.
--}}
@php
    $boxStyle = match (true) {
        $level === 'red' => 'border-color: var(--danger-300); background-color: var(--danger-50); color: var(--gray-950);',
        $requiresAttention => 'border-color: var(--warning-300); background-color: var(--warning-50); color: var(--gray-950);',
        default => 'border-color: var(--success-300); background-color: var(--success-50); color: var(--gray-950);',
    };
@endphp
<div style="{{ $boxStyle }} border-width: 1px; border-style: solid; border-radius: 0.75rem; padding: 1rem;">
    <p style="font-size: 1rem; font-weight: 700; color: var(--gray-950); margin: 0;">
        @if($level === 'red')
            {{ $headingRed ?? __('matters.conflict.heading_red') }}
        @elseif($requiresAttention)
            {{ $headingAttention ?? __('matters.conflict.heading_attention') }}
        @else
            {{ $headingClear ?? __('matters.conflict.heading_clear') }}
        @endif
    </p>

    @if(count($matches) > 0)
        <p style="font-size: 0.875rem; color: var(--gray-700); margin-top: 0.75rem;">{{ $intro ?? __('matters.conflict.intro') }}</p>

        <div style="overflow-x: auto; margin-top: 0.75rem;">
            <table style="width: 100%; font-size: 0.875rem; text-align: left; border-collapse: collapse;">
                <thead style="color: var(--gray-600);">
                    <tr>
                        <th style="padding: 0.25rem 0.75rem 0.25rem 0; font-weight: 500;">{{ __('matters.conflict.column_our_party') }}</th>
                        <th style="padding: 0.25rem 0.75rem 0.25rem 0; font-weight: 500;">{{ __('matters.conflict.column_matter_code') }}</th>
                        <th style="padding: 0.25rem 0.75rem 0.25rem 0; font-weight: 500;">{{ __('matters.conflict.column_matter_type') }}</th>
                        <th style="padding: 0.25rem 0.75rem 0.25rem 0; font-weight: 500;">{{ __('matters.conflict.column_party_role') }}</th>
                        <th style="padding: 0.25rem 0.75rem 0.25rem 0; font-weight: 500;">{{ __('matters.conflict.column_party_name') }}</th>
                        <th style="padding: 0.25rem 0.75rem 0.25rem 0; font-weight: 500;">{{ __('matters.conflict.column_tier') }}</th>
                        <th style="padding: 0.25rem 0; font-weight: 500;">{{ __('matters.conflict.column_level') }}</th>
                    </tr>
                </thead>
                <tbody style="color: var(--gray-900);">
                    @foreach($matches as $match)
                        <tr style="border-top: 1px solid var(--gray-200);">
                            <td style="padding: 0.25rem 0.75rem 0.25rem 0;">
                                {{ $match['our_party_role'] }} — {{ $match['our_party_name'] }}
                            </td>
                            <td style="padding: 0.25rem 0.75rem 0.25rem 0; font-weight: 600;">{{ $match['matter_code'] }}</td>
                            <td style="padding: 0.25rem 0.75rem 0.25rem 0;">{{ $match['matter_type_name'] }}</td>
                            <td style="padding: 0.25rem 0.75rem 0.25rem 0;">{{ $match['party_role'] }}</td>
                            <td style="padding: 0.25rem 0.75rem 0.25rem 0;">{{ $match['party_name'] }}</td>
                            <td style="padding: 0.25rem 0.75rem 0.25rem 0;">{{ $match['tier'] }}</td>
                            <td style="padding: 0.25rem 0; font-weight: 600;">
                                {{ $match['level'] }}
                                @if($match['already_confirmed'] ?? false)
                                    <span style="display: block; font-size: 0.75rem; font-weight: 400; color: var(--gray-500);">{{ __('matters.conflict.already_confirmed') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p style="font-size: 0.75rem; color: var(--gray-600); margin-top: 0.75rem;">{{ __('matters.conflict.boundary_note') }}</p>
    @else
        <p style="font-size: 0.875rem; color: var(--gray-700); margin-top: 0.75rem;">{{ __('matters.conflict.no_matches') }}</p>
    @endif

    @if(count($incompleteParties) > 0)
        <p style="font-size: 0.875rem; font-weight: 500; color: var(--warning-700); margin-top: 0.75rem;">
            {{ __('matters.conflict.incomplete', ['names' => implode(', ', $incompleteParties)]) }}
        </p>
    @endif
</div>
