{{--
    SPEC §8.1 — đổi mật khẩu lần đầu. Một cột dọc, không bảng, chữ to: màn hình này là thứ khách
    gặp ngay sau khi nhập mã, thường trên điện thoại.

    Không có bước dựng CSS trong dự án (CLAUDE.md), nên mọi kích thước ở đây là style nội tuyến
    trên biến CSS của Filament, không phải lớp tiện ích Tailwind — một lớp Tailwind viết tay
    trên `theme.css` dựng sẵn của Filament không tô gì cả.
--}}
<x-filament-panels::page>
    <form wire:submit="changePassword" style="display: flex; flex-direction: column; gap: 1.5rem; max-width: 28rem;">
        {{ $this->form }}

        <x-filament::button
            type="submit"
            size="lg"
            style="min-height: 44px; width: 100%;"
        >
            {{ __('portal.change_password.submit') }}
        </x-filament::button>
    </form>
</x-filament-panels::page>
