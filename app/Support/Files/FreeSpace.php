<?php

namespace App\Support\Files;

use Closure;

/**
 * Chỗ trống của ổ đĩa chứa một đường dẫn, tính bằng byte (kế hoạch M14, R12): gói bàn giao kiểm chỗ
 * trống trước khi tải tệp về và nén; kiểm tra sẵn sàng báo VÀNG khi không đo được.
 *
 * `null` khi KHÔNG đo được:
 *  - hàm `disk_free_space` không tồn tại. Nhiều shared hosting tắt nó trong `disable_functions`, và
 *    trên PHP 8 một hàm bị tắt là hàm không tồn tại: gọi nó là `Error`, không phải `false`. Nơi gọi
 *    bỏ kiểm (kèm log `warning`) thay vì làm hỏng cả gói bàn giao;
 *  - hàm trả `false` (đường dẫn không có, không đọc được). Cảnh báo PHP của lần gọi đó bị nuốt (`@`):
 *    câu trả lời là `null`, không phải một `ErrorException`.
 *
 * Hàm dựng nhận thứ dùng để đo: mặc định tên hàm PHP `disk_free_space`. `function_exists()` không giả
 * được, nên test truyền một tên không tồn tại để dựng tình huống hàm bị tắt, hay một closure để có
 * con số cố định (`app()->instance(FreeSpace::class, new FreeSpace(fn (string $path) => …))`).
 * Giao diện công khai đúng như kế hoạch: một phương thức {@see self::bytes()}.
 */
final class FreeSpace
{
    /** @param  string|Closure(string): (float|int|false)  $probe */
    public function __construct(private readonly string|Closure $probe = 'disk_free_space') {}

    public function bytes(string $path): ?int
    {
        if (is_string($this->probe) && ! function_exists($this->probe)) {
            return null;
        }

        $bytes = @($this->probe)($path);

        return $bytes === false ? null : (int) $bytes;
    }
}
