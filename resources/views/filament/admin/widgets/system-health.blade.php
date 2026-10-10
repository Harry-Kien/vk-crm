{{--
    Dải cảnh báo lịch chạy tự động (SPEC §2, §7.1 mục 7), và từ M14 Task 5 dòng kho tài liệu
    (`$documentStore`, chỉ cho người có settings.manage — xem docblock `SystemHealthWidget`).

    KHÔNG có class Tailwind viết tay ở đây, và đó không phải sở thích: dự án KHÔNG có bước build
    CSS, Filament phục vụ một tệp theme biên dịch sẵn, nên một class viết tay render ra KHÔNG CÓ
    GÌ. Hai tính năng của dự án này đã từng xuất xưởng ở trạng thái vô hình vì đúng lỗi đó. Màu
    lấy từ biến CSS của Filament qua style nội tuyến, và có test đọc chính markup phát ra.

    Im lặng khi mọi thứ bình thường: một dải luôn hiện là một dải không ai đọc. Khi có dải, mọi dải
    nằm trong MỘT phần tử gốc: component Livewire chỉ có một gốc.

    Và component Livewire PHẢI có một gốc, đứng NGOÀI mọi `@if`. Hai lý do, cả hai đều đã cắn:
    (1) view không phát ra phần tử nào làm Livewire ném RootTagMissingFromViewException, tức lỗi 500
    trên trang chủ đúng lúc hệ thống khoẻ (cron chạy đúng, kho bình thường); (2) khi dựng một
    component, Livewire chèn dấu `<!--[if BLOCK]><![endif]-->` ngay TRƯỚC mỗi `@if`, nên một gốc nằm
    trong `@if` không còn đứng đầu dòng và Livewire không nhận ra nó là gốc — nó hoặc ném cùng ngoại
    lệ trên, hoặc gắn `wire:id` nhầm vào một thẻ con. Vì vậy gốc dưới đây luôn được phát ra; lúc im
    lặng nó RỖNG và ẨN (`display: none`: một phần tử ẩn không chiếm ô nào trên lưới trang chủ).
    Test: `tests/Feature/Schedule/SystemHealthTest.php`, hai test "renders through Livewire…".
--}}
@php
    $band = 'border: 1px solid var(--danger-600); border-left-width: 4px; background-color: var(--danger-50); color: var(--danger-700); border-radius: 3px; padding: 12px 16px;';
    $hasBand = $neverRan || $stale || $documentStore !== null;
@endphp

<div
    data-widget="system-health-root"
    data-state="{{ $hasBand ? 'alert' : 'idle' }}"
    style="{{ $hasBand ? 'display: flex; flex-direction: column; gap: 8px;' : 'display: none;' }}"
>
        @if ($neverRan || $stale)
            <div data-widget="system-health" style="{{ $band }}">
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

        @if ($documentStore !== null)
            <div data-widget="document-store-health" style="{{ $band }}">
                <div style="font-weight: 600;">
                    {{ __('document_store.widget.heading', ['status' => $documentStore['status']]) }}
                </div>

                <div style="margin-top: 4px; font-size: 0.875rem;">
                    @if (filled($documentStore['detail']))
                        {{ $documentStore['detail'] }}
                    @endif
                    @if ($documentStore['checkedAt'])
                        {{ __('document_store.widget.checked_at', ['at' => $documentStore['checkedAt']->timezone(config('app.timezone'))->format('H:i d/m/Y')]) }}
                    @endif
                    {{ __('document_store.widget.hint') }}
                </div>
            </div>
        @endif
</div>
