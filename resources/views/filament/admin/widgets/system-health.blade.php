{{--
    Dải cảnh báo lịch chạy tự động (SPEC §2, §7.1 mục 7).

    KHÔNG có class Tailwind viết tay ở đây, và đó không phải sở thích: dự án KHÔNG có bước build
    CSS, Filament phục vụ một tệp theme biên dịch sẵn, nên một class viết tay render ra KHÔNG CÓ
    GÌ. Hai tính năng của dự án này đã từng xuất xưởng ở trạng thái vô hình vì đúng lỗi đó. Màu
    lấy từ biến CSS của Filament qua style nội tuyến, và có test đọc chính markup phát ra.

    Im lặng khi mọi thứ bình thường: một dải luôn hiện là một dải không ai đọc.
--}}
@if ($neverRan || $stale)
    <div
        data-widget="system-health"
        style="
            border: 1px solid var(--danger-600);
            border-left-width: 4px;
            background-color: var(--danger-50);
            color: var(--danger-700);
            border-radius: 3px;
            padding: 12px 16px;
        "
    >
        <div style="font-weight: 600;">
            {{ $neverRan ? __('widgets.system_health.never_ran') : __('widgets.system_health.stale') }}
        </div>

        <div style="margin-top: 4px; font-size: 0.875rem;">
            @if ($neverRan)
                {{ __('widgets.system_health.never_ran_hint') }}
            @else
                {{ __('widgets.system_health.stale_hint', [
                    'minutes' => $staleAfterMinutes,
                    'at' => $lastRunAt?->timezone(config('app.timezone'))->format('H:i d/m/Y'),
                ]) }}
            @endif
        </div>
    </div>
@endif
