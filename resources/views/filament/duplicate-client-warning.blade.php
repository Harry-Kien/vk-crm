{{--
    Cảnh báo trùng khi tạo khách hàng qua màn hình "Khách hàng" (M6.5 Task 6, R4/`intake-07`).
    Chỉ hiện cho actor có client.manage — App\Actions\Client\CreateClient chỉ ném
    DuplicateClientDetected cho actor đó (xem docblock lớp), nên $code/$name/$url ở đây luôn của
    một hồ sơ mà chính actor đang xem đã có quyền `client.manage` để mở.

    Không có class Tailwind viết tay (CLAUDE.md): style nội tuyến trên biến CSS của Filament, cùng
    quy ước với conflict-check-result.blade.php.
--}}
<div style="border: 1px solid var(--warning-300); background-color: var(--warning-50); color: var(--gray-950); border-radius: 0.5rem; padding: 0.75rem 1rem; font-size: 0.875rem;">
    <p style="margin: 0;">{{ __('clients.duplicate.warning') }}</p>
    @if($url)
        <p style="margin: 0.5rem 0 0;">
            <a href="{{ $url }}" target="_blank" rel="noopener" style="color: var(--primary-600); text-decoration: underline;">
                {{ __('clients.duplicate.view_link', ['code' => $code, 'name' => $name]) }}
            </a>
        </p>
    @endif
</div>
