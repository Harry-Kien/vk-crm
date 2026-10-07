<?php

use App\Support\Pwa\AppIcons;

/*
|--------------------------------------------------------------------------
| M12 Task 2 — biểu tượng của hai app (kế hoạch M12, phán quyết R3)
|--------------------------------------------------------------------------
|
| Các PNG sinh bằng `tools/brand/make-logo.php` và commit dưới dạng tệp tĩnh; không ảnh nào sinh
| lúc chạy ứng dụng. Ba thứ R3 đòi, mỗi thứ một lỗi nhìn thấy được trên điện thoại nếu sai:
|
|  - bản 192×192 cho tiêu chí cài đặt của Chrome;
|  - bản maskable 512×512: con dấu nằm trong vùng an toàn 80% (hình tròn bán kính 40% cạnh, giữa
|    ảnh) trên nền ĐẶC — Android cắt ảnh theo hình của launcher, phần ngoài vùng an toàn có thể bị
|    cắt mất. Portal nền navy, nội bộ nền paper;
|  - `apple-touch-icon` 180×180 KHÔNG trong suốt — iOS tô đen phần trong suốt, nên con dấu vành
|    vàng sẽ nằm trên một ô đen.
|
| Màu nền so với `config('vkcrm.brand.colors')`, không với mã màu viết cứng, để một lần đổi màu
| thương hiệu mà quên sinh lại biểu tượng làm test đỏ.
*/

/** @return array{0: int, 1: int, 2: int, 3: int} r, g, b, alpha của GD (0 = đục, 127 = trong suốt hẳn) */
function pwaPixel(GdImage $image, int $x, int $y): array
{
    $c = imagecolorat($image, $x, $y);

    return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF, ($c >> 24) & 0x7F];
}

/** @return array{0: int, 1: int, 2: int} */
function pwaHexToRgb(string $hex): array
{
    return sscanf(ltrim($hex, '#'), '%02x%02x%02x');
}

/** Màu nền của biểu tượng một panel, đọc từ config theo đúng khoá mà `make-logo.php` đọc. */
function pwaIconBackground(string $panel): string
{
    return config('vkcrm.brand.colors.'.config("vkcrm.pwa.icon_background.{$panel}"));
}

function pwaOpenPng(string $relative): GdImage
{
    $file = public_path($relative);
    expect($file)->toBeFile();

    $image = imagecreatefrompng($file);
    expect($image)->toBeInstanceOf(GdImage::class);

    return $image;
}

it('ships every icon the manifests and head tags point at, at its stated size', function (string $relative, int $size) {
    expect(public_path($relative))->toBeFile();

    [$width, $height, $type] = getimagesize(public_path($relative));

    expect($width)->toBe($size)
        ->and($height)->toBe($size)
        ->and($type)->toBe(IMAGETYPE_PNG);
})->with(fn () => [
    'any 192' => [AppIcons::ANY[192], 192],
    'any 512' => [AppIcons::ANY[512], 512],
    'admin maskable' => [AppIcons::maskable('admin'), 512],
    'portal maskable' => [AppIcons::maskable('portal'), 512],
    'admin apple-touch' => [AppIcons::appleTouch('admin'), 180],
    'portal apple-touch' => [AppIcons::appleTouch('portal'), 180],
]);

it('maps portal to navy and the internal app to paper, as R3 rules', function () {
    expect(config('vkcrm.pwa.icon_background'))->toBe(['admin' => 'paper', 'portal' => 'navy']);
});

/** Một tên panel gõ sai không được thành một đường dẫn tới tệp không có (thẻ `<head>` trỏ vào 404). */
it('refuses to name an icon for a panel that has no app on the phone', function (string $method) {
    expect(AppIcons::{$method}('portal'))->toStartWith('brand/app-portal-');

    AppIcons::{$method}('khach');
})->with(['maskable', 'appleTouch'])
    ->throws(InvalidArgumentException::class, 'Panel [khach] không có app trên điện thoại.');

it('keeps the any-purpose 192 icon in the same transparent family as the other seal sizes', function () {
    $image = pwaOpenPng(AppIcons::ANY[192]);

    expect(pwaPixel($image, 0, 0)[3])->toBe(127);
});

it('makes the apple-touch icon opaque, with its corners exactly the panel background colour', function (string $panel) {
    $image = pwaOpenPng(AppIcons::appleTouch($panel));
    $size = imagesx($image);
    $background = pwaHexToRgb(pwaIconBackground($panel));

    foreach ([[0, 0], [$size - 1, 0], [0, $size - 1], [$size - 1, $size - 1]] as [$x, $y]) {
        [$r, $g, $b, $alpha] = pwaPixel($image, $x, $y);

        expect($alpha)->toBe(0)
            ->and([$r, $g, $b])->toBe($background);
    }

    // Không một điểm ảnh nào trong suốt, kể cả một phần: iOS tô đen mọi chỗ như vậy.
    for ($y = 0; $y < $size; $y += 3) {
        for ($x = 0; $x < $size; $x += 3) {
            expect(pwaPixel($image, $x, $y)[3])->toBe(0);
        }
    }
})->with(['admin', 'portal']);

it('puts the maskable seal inside the 80% safe zone on a solid panel background', function (string $panel) {
    $image = pwaOpenPng(AppIcons::maskable($panel));
    $size = imagesx($image);
    $background = pwaHexToRgb(pwaIconBackground($panel));

    [$r, $g, $b, $alpha] = pwaPixel($image, 0, 0);
    expect($alpha)->toBe(0)
        ->and([$r, $g, $b])->toBe($background);

    // Mọi điểm ảnh ngoài hình tròn an toàn (bán kính 40% cạnh) là đúng màu nền và đục: con dấu
    // không chạm vào phần launcher có thể cắt.
    $centre = $size / 2;
    $safeRadius = $size * 0.4;
    $outside = 0;

    for ($y = 0; $y < $size; $y += 2) {
        for ($x = 0; $x < $size; $x += 2) {
            if (hypot($x + 0.5 - $centre, $y + 0.5 - $centre) <= $safeRadius) {
                continue;
            }

            $outside++;
            [$r, $g, $b, $alpha] = pwaPixel($image, $x, $y);
            expect($alpha)->toBe(0)
                ->and([$r, $g, $b])->toBe($background);
        }
    }

    expect($outside)->toBeGreaterThan(0);

    // Và con dấu thật sự ở đó: điểm giữa ảnh không phải màu nền.
    expect(array_slice(pwaPixel($image, (int) $centre, (int) $centre), 0, 3))->not->toBe($background);
})->with(['admin', 'portal']);

it('follows the config colour rather than a copy written into the test', function () {
    // Mutation probe thường trực: một màu nền khác trong config thì biểu tượng đang có không còn khớp.
    config(['vkcrm.brand.colors.navy' => '#123456']);

    $image = pwaOpenPng(AppIcons::maskable('portal'));

    expect(array_slice(pwaPixel($image, 0, 0), 0, 3))->not->toBe(pwaHexToRgb(pwaIconBackground('portal')));
});
