<?php

/*
 * Sinh mọi biểu tượng PNG của văn phòng từ ảnh nguồn `tools/brand/vk-logo-source.jpg`, rồi commit
 * PNG dưới `public/brand/`. Không ảnh nào sinh lúc chạy ứng dụng. Chạy từ gốc dự án:
 *
 *     bin/dev php tools/brand/make-logo.php
 *
 * (`bin/dev up -d` trước: lệnh chạy trong container `app` của `compose.yaml`, có GD.) Sau đó
 * `bin/dev test tests/Feature/Pwa/IconsTest.php`: kích thước, màu nền, độ đục và vùng an toàn của
 * từng PNG.
 *
 * Hai họ ảnh:
 *  - `brand/vk-mark-{512,256,192,96,64,32}.png`: con dấu, nền TRONG SUỐT (logo, favicon, biểu tượng
 *    `any` của manifest — 192 thêm ở M12 cho tiêu chí cài đặt của Chrome);
 *  - M12 R3, theo từng app (`App\Support\Pwa\AppIcons`): bản maskable 512 (con dấu trong vùng an
 *    toàn 80% trên nền đặc) và `apple-touch-icon` 180 (đục hoàn toàn — iOS tô đen phần trong suốt).
 *    Màu nền đọc từ `config('vkcrm.pwa.icon_background')` → `config('vkcrm.brand.colors')`, nên
 *    công cụ khởi động Laravel thay vì chép mã màu lần thứ hai.
 *
 * **Đổi hình về sau thì đổi TÊN tệp, không ghi đè.** Mẫu nginx (`tools/deploy/nginx.conf.example`)
 * và Apache (`tools/deploy/apache-vhost.conf.example`) gửi `Cache-Control: public, max-age=31536000,
 * immutable` cho mọi `.png`: một tệp cùng tên bị ghi đè sẽ không tới điện thoại nào đã tải bản cũ
 * trong một năm. Đổi tên ở `AppIcons` (và vòng kích thước dưới đây), rồi chạy lại công cụ.
 */

use App\Support\Pwa\AppIcons;
use App\Support\Pwa\PwaPanels;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
(require __DIR__.'/../../bootstrap/app.php')->make(Kernel::class)->bootstrap();

$src = imagecreatefromjpeg('tools/brand/vk-logo-source.jpg');
$w = imagesx($src);
$h = imagesy($src);

$isInk = function ($x, $y) use ($src) {
    $c = imagecolorat($src, $x, $y);
    $r = ($c >> 16) & 0xFF;
    $g = ($c >> 8) & 0xFF;
    $b = $c & 0xFF;

    return min($r, $g, $b) < 232;
};

// Outer edge of the gold ring, measured on the middle row and middle column.
$cy = intdiv($h, 2);
$cx = intdiv($w, 2);
for ($x = 0; $x < $w && ! $isInk($x, $cy); $x++);
$left = $x;
for ($x = $w - 1; $x >= 0 && ! $isInk($x, $cy); $x--);
$right = $x;
for ($y = 0; $y < $h && ! $isInk($cx, $y); $y++);
$top = $y;
for ($y = $h - 1; $y >= 0 && ! $isInk($cx, $y); $y--);
$bottom = $y;

$centreX = ($left + $right) / 2;
$centreY = ($top + $bottom) / 2;
$radius = (($right - $left) + ($bottom - $top)) / 4;
printf("ring: left=%d right=%d top=%d bottom=%d centre=(%.1f,%.1f) r=%.1f\n",
    $left, $right, $top, $bottom, $centreX, $centreY, $radius);

// Square crop tight to the ring with a hair of breathing room, alpha outside the circle.
$pad = (int) round($radius * 0.02);
$side = (int) round(($radius + $pad) * 2);
$out = imagecreatetruecolor($side, $side);
imagealphablending($out, false);
imagesavealpha($out, true);
imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));

$outCentre = $side / 2;
for ($y = 0; $y < $side; $y++) {
    for ($x = 0; $x < $side; $x++) {
        $sx = (int) round($centreX - $outCentre + $x);
        $sy = (int) round($centreY - $outCentre + $y);
        if ($sx < 0 || $sy < 0 || $sx >= $w || $sy >= $h) {
            continue;
        }

        $d = hypot($x - $outCentre + 0.5, $y - $outCentre + 0.5);
        $edge = $radius + $pad - 1;
        if ($d > $edge + 1) {
            continue;
        }

        $c = imagecolorat($src, $sx, $sy);
        $alpha = $d > $edge ? (int) round(127 * ($d - $edge)) : 0;
        imagesetpixel($out, $x, $y, imagecolorallocatealpha(
            $out, ($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF, min(127, $alpha)
        ));
    }
}

foreach ([512, 256, 192, 96, 64, 32] as $size) {
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagealphablending($dst, true);
    imagecopyresampled($dst, $out, 0, 0, 0, 0, $size, $size, $side, $side);
    imagesavealpha($dst, true);
    imagepng($dst, "public/brand/vk-mark-{$size}.png", 9);
    imagedestroy($dst);
    echo "wrote public/brand/vk-mark-{$size}.png\n";
}

/*
 * M12 R3 — biểu tượng của từng app: con dấu đặt GIỮA một nền đặc, rồi lưu KHÔNG kênh alpha.
 *
 * `$seal` là tỉ lệ đường kính con dấu trên cạnh ảnh:
 *  - maskable: vùng an toàn là hình tròn bán kính 40% cạnh (đường kính 80%); 0.72 chừa một vành
 *    nền để con dấu không chạm mép vùng an toàn trên launcher cắt sát nhất;
 *  - `apple-touch-icon`: iOS bo góc ô vuông, không cắt tròn; 0.80 giữ con dấu xa góc bo.
 */
$solidIcon = function (int $size, float $seal, string $hex) use ($out, $side): GdImage {
    [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');

    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, true);
    imagefill($dst, 0, 0, imagecolorallocate($dst, $r, $g, $b));

    $diameter = (int) round($size * $seal);
    $offset = intdiv($size - $diameter, 2);
    imagecopyresampled($dst, $out, $offset, $offset, 0, 0, $diameter, $diameter, $side, $side);

    // Đục hoàn toàn: không lưu kênh alpha, mép con dấu đã hoà vào nền khi chép ở trên.
    imagesavealpha($dst, false);

    return $dst;
};

foreach (PwaPanels::IDS as $panel) {
    $background = config('vkcrm.brand.colors.'.config("vkcrm.pwa.icon_background.{$panel}"));

    foreach ([
        AppIcons::maskable($panel) => [AppIcons::MASKABLE_SIZE, 0.72],
        AppIcons::appleTouch($panel) => [AppIcons::APPLE_TOUCH_SIZE, 0.80],
    ] as $path => [$size, $seal]) {
        $dst = $solidIcon($size, $seal, $background);
        imagepng($dst, "public/{$path}", 9);
        imagedestroy($dst);
        echo "wrote public/{$path} ({$background})\n";
    }
}
