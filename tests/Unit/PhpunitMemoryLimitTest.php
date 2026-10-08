<?php

/*
|--------------------------------------------------------------------------
| M14 rà soát cuối vòng sửa 1 (I2) — trần bộ nhớ của bộ test đúng bằng CI, không thấp hơn
|--------------------------------------------------------------------------
|
| PHPUnit gọi `ini_set()` cho MỌI thẻ `<ini>` của `phpunit.xml`, nên một giá trị hữu hạn ở đó ĐÈ
| `memory_limit -1` của CI (setup-php) chứ không chỉ nâng trần 512M của image Docker máy dev. CI
| (`.github/workflows/ci.yml`) chạy `php artisan test` TUẦN TỰ: một tiến trình mang cả bộ khoảng
| 6.900 test, trong khi ở lượt song song mỗi worker chỉ mang nửa bộ đã chạm 512M — trần 1024M có thể
| làm CI chết hết bộ nhớ ở cổng gộp. Giá trị duy nhất "đưa máy dev lại gần CI" mà không đổi hành vi
| của CI là chính giá trị của CI: -1.
*/

it('runs the suite with the memory limit CI has (-1), not a finite cap that would override CI', function () {
    expect(ini_get('memory_limit'))->toBe('-1');
});

it('pins exactly one memory_limit in phpunit.xml and it is -1, so the dev container matches CI', function () {
    $config = simplexml_load_file(base_path('phpunit.xml'));
    $limits = $config->xpath('/phpunit/php/ini[@name="memory_limit"]');

    expect($limits)->toHaveCount(1)
        ->and((string) $limits[0]['value'])->toBe('-1');
});
