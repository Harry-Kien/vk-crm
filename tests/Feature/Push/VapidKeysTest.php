<?php

use App\Support\Push\VapidKeys;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| M12 R7 — "thiếu khoá thì push tắt êm": MỘT định nghĩa của "đã có khoá"
|--------------------------------------------------------------------------
|
| `vkcrm:preflight` (dòng VAPID), nút "Bật" của Task 5 và việc xếp job của Task 7 cùng hỏi
| {@see VapidKeys::configured()}. Dòng `KEY=` trống mà CI chép từ `.env.example` là CHUỖI RỖNG, không
| phải "không khai báo" — và phải được hiểu là thiếu khoá.
*/

it('đủ ba biến, khoá đúng định dạng, subject mailto: → đã cấu hình', function () {
    config(WebPushTestKeys::config());

    expect(VapidKeys::configured())->toBeTrue()
        ->and(VapidKeys::missing())->toBe([]);
});

it('chuỗi rỗng (dòng KEY= của .env.example) và null đều là THIẾU khoá', function (?string $blank) {
    config(WebPushTestKeys::config());
    config(['webpush.vapid.public_key' => $blank]);

    expect(VapidKeys::configured())->toBeFalse()
        ->and(VapidKeys::missing())->toBe(['VAPID_PUBLIC_KEY']);
})->with(['chuỗi rỗng' => '', 'khoảng trắng' => '   ', 'null' => null]);

it('khoá sai định dạng hay subject không phải mailto:/https:// đều là chưa cấu hình', function (array $override) {
    config(WebPushTestKeys::config());
    config($override);

    expect(VapidKeys::configured())->toBeFalse()
        ->and(VapidKeys::missing())->toBe([]);
})->with([
    'khoá công khai không phải base64url của 65 byte' => fn () => ['webpush.vapid.public_key' => 'khong-phai-khoa'],
    'khoá riêng không phải 32 byte' => fn () => ['webpush.vapid.private_key' => WebPushTestKeys::vapid()['public']],
    'subject là địa chỉ trần' => fn () => ['webpush.vapid.subject' => 'lienhe@luatvukhang.com'],
]);

it('bộ test mặc định chạy với push TẮT, bất kể .env cục bộ có khoá (phpunit.xml ghim ba biến trống)', function () {
    expect(env('VAPID_PUBLIC_KEY'))->toBe('')
        ->and(env('VAPID_PRIVATE_KEY'))->toBe('')
        ->and(env('VAPID_SUBJECT'))->toBe('')
        ->and(VapidKeys::configured())->toBeFalse();
});
