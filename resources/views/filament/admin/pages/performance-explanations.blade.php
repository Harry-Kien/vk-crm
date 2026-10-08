{{--
    Khối thu gọn "Cách tính các con số" của M13 (R6) — MỘT tệp cho các trang theo dõi đội ngũ. Nhận
    `$explanations`: danh sách `['label' => …, 'sentence' => …]` do trang dựng từ khoá
    `performance.explain.<mã>` (và câu riêng của trang); tệp này chỉ vẽ ra.

    Style nội tuyến trên biến CSS của Filament (dự án không có bước dựng CSS).
--}}
<details data-vk-performance-explain style="border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);border-radius:0.75rem;padding:0.75rem 1rem;">
    <summary style="cursor:pointer;font-weight:600;">{{ __('performance.how_computed') }}</summary>
    <dl style="margin:0.75rem 0 0;display:grid;gap:0.5rem;">
        @foreach ($explanations as $explanation)
            <div>
                <dt style="font-weight:600;">{{ $explanation['label'] }}</dt>
                <dd style="margin:0;">{{ $explanation['sentence'] }}</dd>
            </div>
        @endforeach
    </dl>
</details>
