{{--
    Nội dung modal "Xem chi tiết" của ActivityLogPage (Task 20).

    `$properties` đã đi qua App\Support\SensitivePropertyFilter TRƯỚC KHI tới view này — không
    có khoá `id_number` thô nào lọt tới đây để mà giấu ở tầng hiển thị; view chỉ có việc trình bày.

    Không có bước dựng CSS trong dự án (CLAUDE.md) — style nội tuyến trên biến CSS của Filament,
    không phải lớp Tailwind viết tay.
--}}
<div style="max-height: 60vh; overflow-y: auto;">
    @if (empty($properties))
        <p style="color: var(--gray-500, #6b7280); margin: 0;">{{ __('activity.page.properties.empty') }}</p>
    @else
        <pre style="white-space: pre-wrap; word-break: break-word; font-size: 0.8125rem; line-height: 1.5; background: var(--gray-50, #f9fafb); border: 1px solid var(--gray-200, #e5e7eb); border-radius: 0.5rem; padding: 0.75rem; margin: 0;">{{ json_encode($properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    @endif
</div>
