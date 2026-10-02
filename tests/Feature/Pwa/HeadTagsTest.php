<?php

use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Pwa\AppIcons;

/*
|--------------------------------------------------------------------------
| M12 Task 2 — thẻ `<head>` của hai app (kế hoạch M12, phán quyết R2 và R5)
|--------------------------------------------------------------------------
|
| Thẻ gắn qua render hook `PanelsRenderHook::HEAD_END` (view `pwa.head`), trên trang đăng nhập VÀ
| trên trang đã đăng nhập của mỗi panel — trình duyệt chỉ đề nghị cài từ trang nó đang mở, và
| người dùng có thể mở app từ bất kỳ trang nào.
|
| R5: không thêm script nội tuyến nào. Phần HTML hook M12 in ra (giữa hai dấu chú thích
| `vk-pwa:head`) không được có `<script>` nào thiếu `src` — đúng cả khi CSP của M8 ở chế độ
| `report`/`off`, và đúng cả hôm nay, khi hook chưa in script nào (thẻ `register.js` là của Task 3).
*/

/** Phần HTML giữa hai dấu chú thích của `resources/views/pwa/head.blade.php`; đúng một lần trong trang. */
function pwaHeadBlock(string $html): string
{
    expect(substr_count($html, '<!-- vk-pwa:head -->'))->toBe(1)
        ->and(substr_count($html, '<!-- /vk-pwa:head -->'))->toBe(1);

    $start = strpos($html, '<!-- vk-pwa:head -->');
    $end = strpos($html, '<!-- /vk-pwa:head -->');

    // Trong `<head>`, không phải lạc xuống thân trang.
    expect($start)->toBeLessThan(strpos($html, '</head>'));

    return substr($html, $start, $end - $start);
}

function pwaAssertHeadTags(string $html, string $panel): void
{
    $block = pwaHeadBlock($html);

    expect($block)
        ->toContain('<link rel="manifest" href="'.route("pwa.{$panel}.manifest").'">')
        ->toContain('<meta name="theme-color" content="'.config('vkcrm.brand.colors.navy').'">')
        ->toContain('<link rel="apple-touch-icon" href="'.asset(AppIcons::appleTouch($panel)).'">')
        ->toContain('<meta name="apple-mobile-web-app-title" content="'.e(__("pwa.{$panel}.short_name", ['firm' => config('vkcrm.brand.short_name')])).'">')
        ->toContain('<meta name="mobile-web-app-capable" content="yes">');

    // R5 — mọi `<script` trong phần M12 in ra phải mang `src`.
    preg_match_all('/<script\b[^>]*>/i', $block, $scripts);
    foreach ($scripts[0] as $tag) {
        expect($tag)->toMatch('/\ssrc\s*=/i');
    }

    // Favicon giữ nguyên như hiện nay (R2) — không bị thay bằng biểu tượng app.
    expect($html)->toContain(asset('brand/vk-mark-64.png'));
}

it('puts the R2 tags on the internal login page', function () {
    pwaAssertHeadTags($this->get('/admin/login')->assertOk()->getContent(), 'admin');
});

it('puts the R2 tags on a signed-in internal page', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    pwaAssertHeadTags($this->actingAs($admin, 'web')->get('/admin')->assertOk()->getContent(), 'admin');
});

it('puts the R2 tags on the client portal login page', function () {
    pwaAssertHeadTags($this->get('/portal/login')->assertOk()->getContent(), 'portal');
});

it('puts the R2 tags on a signed-in client portal page', function () {
    $client = ClientUser::factory()->activated()->create();

    pwaAssertHeadTags($this->actingAs($client, 'client')->get('/portal')->assertOk()->getContent(), 'portal');
});

it('points each panel at its own manifest and its own apple-touch icon', function () {
    $admin = pwaHeadBlock($this->get('/admin/login')->getContent());
    $portal = pwaHeadBlock($this->get('/portal/login')->getContent());

    expect($admin)->toContain('/admin/manifest.webmanifest', AppIcons::appleTouch('admin'));
    expect($admin)->not->toContain('/portal/manifest.webmanifest');
    expect($admin)->not->toContain(AppIcons::appleTouch('portal'));

    expect($portal)->toContain('/portal/manifest.webmanifest', AppIcons::appleTouch('portal'));
    expect($portal)->not->toContain('/admin/manifest.webmanifest');
    expect($portal)->not->toContain(AppIcons::appleTouch('admin'));
});

it('reads the theme colour of the head tag from config', function () {
    config(['vkcrm.brand.colors.navy' => '#123456']);

    expect(pwaHeadBlock($this->get('/portal/login')->getContent()))
        ->toContain('<meta name="theme-color" content="#123456">');
});

/**
 * Phán quyết 4 của làn: thẻ head là tài nguyên công khai — không tên khách, không dữ liệu phiên.
 * Khách đăng nhập có tên riêng; tên đó không được lọt vào phần M12 in ra.
 */
it('carries no session data in the PWA head block', function () {
    $client = ClientUser::factory()->activated()->create(['name' => 'Khách Đánh Dấu Zeta']);

    $block = pwaHeadBlock($this->actingAs($client, 'client')->get('/portal')->assertOk()->getContent());

    expect($block)->not->toContain('Zeta')
        ->not->toContain($client->email);
});
