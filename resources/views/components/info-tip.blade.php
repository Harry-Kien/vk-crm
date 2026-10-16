{{--
    Nút ⓘ dùng chung (làn rv, 2026-10-15 — "nhiều chữ nhìn rối quá"): giấu một đoạn giải thích sau một
    biểu tượng nhỏ cạnh tiêu đề. Bấm (hay chạm trên điện thoại) thì mở; bấm ra ngoài thì đóng.

    Dựng trên `<x-filament::dropdown>` của Filament, KHÔNG tooltip khi rê chuột: tooltip không mở được
    bằng ngón tay trên điện thoại, còn dropdown mở bằng `mousedown` (chạm cũng sinh sự kiện đó) và tự dịch
    vào trong màn hình (`shift`) khi nút nằm sát mép. `teleport` của dropdown chỉ đổi cách đặt khung sang
    `position: fixed` (không bị khung widget cắt) — khung VẪN nằm trong DOM của tiêu đề, nên phông, cỡ và
    độ đậm của chữ ghi tường minh, không thừa hưởng phông tiêu đề có chân của thương hiệu.

    Đoạn chữ nằm trong HTML ngay từ đầu (khung ẩn, `x-cloak`) — người đọc màn hình và test đọc được;
    `aria-label` của nút là tên của điều được giải thích. `data-info-tip` để test đếm/tìm.

    Không class Tailwind viết tay (dự án không có bước build CSS): class ở đây đều là của Filament, phần
    còn lại là style nội tuyến trên biến CSS của Filament.

    Props: `text` (chuỗi giải thích, bắt buộc), `label` (tên điều được giải thích, cho aria-label).
--}}
@props(['text', 'label' => null])

<x-filament::dropdown
    placement="bottom-start"
    shift
    teleport
    width="sm"
    data-info-tip
    style="display: inline-flex; vertical-align: middle;"
>
    <x-slot name="trigger">
        <x-filament::icon-button
            color="gray"
            size="sm"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedInformationCircle"
            :label="filled($label) ? __('widgets.info_tip.label_for', ['subject' => $label]) : __('widgets.info_tip.label')"
        />
    </x-slot>

    <div style="padding: 10px 12px; font-family: var(--font-family), ui-sans-serif, system-ui, sans-serif; font-size: 0.8125rem; font-weight: 400; line-height: 1.5; letter-spacing: normal; text-transform: none; white-space: normal; text-align: start;">
        {{ $text }}
    </div>
</x-filament::dropdown>
