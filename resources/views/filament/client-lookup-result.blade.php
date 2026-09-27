{{--
    Kết quả tra khách hàng theo định danh (M6.5 Task 6, R4a). Chỉ hiện ĐÚNG câu đã dịch sẵn ở PHP
    (App\Filament\Admin\Resources\Matters\Pages\CreateMatter::lookupClient(), khoá
    matters.create_form.client_lookup_found) — mã hồ sơ + tên của ĐÚNG một hồ sơ khớp tuyệt đối,
    không có gì thêm. Không truy vấn, không nhận model — cùng quy ước với
    filament.conflict-check-result (xem docblock ở đó cho lý do ranh giới lộ thông tin).

    Không có class Tailwind viết tay (CLAUDE.md — dự án không có bước build CSS): style nội tuyến
    trên biến CSS của Filament, cùng quy ước với conflict-check-result.blade.php.
--}}
<div style="border: 1px solid var(--success-300); background-color: var(--success-50); color: var(--gray-950); border-radius: 0.5rem; padding: 0.75rem 1rem; font-size: 0.875rem;">
    {{ $label }}
</div>
