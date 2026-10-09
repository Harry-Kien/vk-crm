<?php

use App\Support\Files\FreeSpace;

/*
|--------------------------------------------------------------------------
| M14 — đo chỗ trống của ổ đĩa (kế hoạch M14, R12; phán quyết controller C8)
|--------------------------------------------------------------------------
|
| Nhiều shared hosting tắt `disk_free_space` trong `disable_functions`. Trên PHP 8 một hàm bị tắt là
| hàm KHÔNG TỒN TẠI, và gọi nó là `Error`: gói bàn giao hỏng kể cả ở chế độ `local`. `FreeSpace`
| trả `null` khi không đo được (hàm không có, hay hàm trả `false`), để nơi gọi bỏ kiểm và báo VÀNG.
|
| `function_exists()` không giả được, nên tên hàm đo là tham số của hàm dựng (mặc định
| `disk_free_space`): test truyền một tên không tồn tại để dựng đúng tình huống hàm bị tắt. Test của
| task sau truyền một closure để có con số cố định.
*/

it('đo được chỗ trống của một thư mục có thật', function () {
    $bytes = (new FreeSpace)->bytes(sys_get_temp_dir());

    expect($bytes)->toBeInt()
        ->and($bytes)->toBeGreaterThan(0)
        ->and($bytes)->toBe((int) disk_free_space(sys_get_temp_dir()));
});

it('hàm đo bị tắt (không tồn tại) → null, không Error', function () {
    expect((new FreeSpace('vkcrm_disk_free_space_bi_tat'))->bytes(sys_get_temp_dir()))->toBeNull();
});

it('hàm đo trả false (đường dẫn không có) → null, không cảnh báo PHP', function () {
    expect((new FreeSpace)->bytes(sys_get_temp_dir().'/vkcrm-khong-co-thu-muc-'.getmypid().'/con'))->toBeNull();
});

it('closure đo thay hàm PHP: số trả về là số byte, false là null', function () {
    expect((new FreeSpace(fn (string $path): float => 1234.0))->bytes('/bat-ky'))->toBe(1234)
        ->and((new FreeSpace(fn (string $path): false => false))->bytes('/bat-ky'))->toBeNull();
});

it('closure nhận đúng đường dẫn được hỏi', function () {
    $asked = null;

    (new FreeSpace(function (string $path) use (&$asked): float {
        $asked = $path;

        return 1.0;
    }))->bytes('/srv/vkcrm/storage/app/private');

    expect($asked)->toBe('/srv/vkcrm/storage/app/private');
});
