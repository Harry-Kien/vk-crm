<?php
$src = imagecreatefromjpeg('tools/brand/vk-logo-source.jpg');
$w = imagesx($src); $h = imagesy($src);

$isInk = function ($x, $y) use ($src) {
    $c = imagecolorat($src, $x, $y);
    $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
    return min($r, $g, $b) < 232;
};

// Outer edge of the gold ring, measured on the middle row and middle column.
$cy = intdiv($h, 2); $cx = intdiv($w, 2);
for ($x = 0; $x < $w && ! $isInk($x, $cy); $x++);       $left = $x;
for ($x = $w - 1; $x >= 0 && ! $isInk($x, $cy); $x--);  $right = $x;
for ($y = 0; $y < $h && ! $isInk($cx, $y); $y++);       $top = $y;
for ($y = $h - 1; $y >= 0 && ! $isInk($cx, $y); $y--);  $bottom = $y;

$centreX = ($left + $right) / 2;
$centreY = ($top + $bottom) / 2;
$radius  = (($right - $left) + ($bottom - $top)) / 4;
printf("ring: left=%d right=%d top=%d bottom=%d centre=(%.1f,%.1f) r=%.1f\n",
    $left, $right, $top, $bottom, $centreX, $centreY, $radius);

// Square crop tight to the ring with a hair of breathing room, alpha outside the circle.
$pad  = (int) round($radius * 0.02);
$side = (int) round(($radius + $pad) * 2);
$out  = imagecreatetruecolor($side, $side);
imagealphablending($out, false);
imagesavealpha($out, true);
imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));

$outCentre = $side / 2;
for ($y = 0; $y < $side; $y++) {
    for ($x = 0; $x < $side; $x++) {
        $sx = (int) round($centreX - $outCentre + $x);
        $sy = (int) round($centreY - $outCentre + $y);
        if ($sx < 0 || $sy < 0 || $sx >= $w || $sy >= $h) { continue; }

        $d = hypot($x - $outCentre + 0.5, $y - $outCentre + 0.5);
        $edge = $radius + $pad - 1;
        if ($d > $edge + 1) { continue; }

        $c = imagecolorat($src, $sx, $sy);
        $alpha = $d > $edge ? (int) round(127 * ($d - $edge)) : 0;
        imagesetpixel($out, $x, $y, imagecolorallocatealpha(
            $out, ($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF, min(127, $alpha)
        ));
    }
}

foreach ([512, 256, 96, 64, 32] as $size) {
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
