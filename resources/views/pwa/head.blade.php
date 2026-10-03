{{--
    M12 R2 — thẻ `<head>` của app trên điện thoại, gắn qua `PanelsRenderHook::HEAD_END` ở cả hai
    panel provider. Tài nguyên công khai: không tên khách, không dữ liệu phiên.

    Panel đọc từ panel HIỆN HÀNH (`filament()->getId()`, do middleware `panel:{id}` của từng route
    đặt), không truyền từ closure của provider: `Filament\FilamentManager::bootCurrentPanel()` chỉ
    khởi động panel (tức đăng ký render hook của nó, `Panel::boot()`) MỘT lần cho mỗi ứng dụng —
    cờ `isCurrentPanelBooted` — nên khi hai panel phục vụ trong cùng một ứng dụng, hook của panel
    đầu tiên được dùng cho cả panel thứ hai. Một closure mang sẵn `'admin'` khi đó in thẻ của app
    nội bộ lên trang cổng khách (đo được trong test: hai request liên tiếp `/admin/login` rồi
    `/portal/login`, `tests/Feature/Pwa/HeadTagsTest.php`).

    - `<link rel="manifest">` KHÔNG mang `crossorigin="use-credentials"`: manifest được tải không
      kèm cookie và route của nó không có phiên (`routes/pwa.php`).
    - `theme-color` đọc `config('vkcrm.brand.colors.navy')`, cùng nguồn với manifest.
    - `apple-touch-icon` riêng từng panel, đục hoàn toàn (`App\Support\Pwa\AppIcons`, R3).
      `apple-mobile-web-app-title` là tên iOS đề xuất khi "Thêm vào Màn hình chính" = `short_name`.
    - Favicon KHÔNG ở đây: giữ nguyên `->favicon()` của provider.
    - R5: không script nội tuyến nào. Việc đăng ký service worker nằm trong tệp TĨNH
      `public/pwa/register.js`, tham số qua `data-*` của chính thẻ: `data-sw` (route
      `pwa.{panel}.sw`), `data-scope` (`PwaPanels::path()`, không dấu `/` cuối). `?v=` là băm nội
      dung tệp (`App\Support\Pwa\RegisterScript`) vì mẫu máy chủ web giữ `.js` tĩnh một năm
      `immutable`. `defer`: chạy sau khi phân tích xong trang, `document.currentScript` vẫn là thẻ
      này. Test `tests/Feature/Pwa/HeadTagsTest.php` khẳng định mọi `<script>` giữa hai dấu chú
      thích `vk-pwa:head` đều có `src`; `RegisterScriptTest.php` ghim hợp đồng `data-*`.
--}}
@php($panel = filament()->getId())
<!-- vk-pwa:head -->
<link rel="manifest" href="{{ route("pwa.{$panel}.manifest") }}">
<meta name="theme-color" content="{{ config('vkcrm.brand.colors.navy') }}">
<link rel="apple-touch-icon" href="{{ asset(\App\Support\Pwa\AppIcons::appleTouch($panel)) }}">
<meta name="apple-mobile-web-app-title" content="{{ __("pwa.{$panel}.short_name", ['firm' => config('vkcrm.brand.short_name')]) }}">
<meta name="mobile-web-app-capable" content="yes">
<script src="{{ \App\Support\Pwa\RegisterScript::url() }}" defer data-sw="{{ route("pwa.{$panel}.sw") }}" data-scope="{{ \App\Support\Pwa\PwaPanels::path($panel) }}"></script>
<!-- /vk-pwa:head -->
