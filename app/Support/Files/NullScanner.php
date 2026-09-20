<?php

namespace App\Support\Files;

/**
 * Implementation mặc định của `VirusScanner` khi `CLAMAV_ENABLED=false` (mặc định — SPEC §6.6
 * bước 5). KHÔNG quét bất kỳ điều gì: `scan()` là no-op tuyệt đối, không đọc tệp, không gọi ra
 * ngoài. Lớp này tồn tại chỉ để giữ đúng interface `VirusScanner` cho các Action ở Task 3/4, để
 * `ClamAvScanner` thay được vào sau mà không sửa logic gọi.
 *
 * `isActive()` trả `false` một cách tường minh — đây là dòng duy nhất phân biệt "không quét gì
 * cả" với "đã quét và sạch", và là thứ `php artisan about` hiển thị ra để một người vận hành thấy
 * ngay virus scanning đang TẮT, thay vì phải đọc mã nguồn class này để biết.
 */
final class NullScanner implements VirusScanner
{
    public function scan(string $path): void
    {
        // Cố ý không làm gì. Xem docblock lớp.
    }

    public function isActive(): bool
    {
        return false;
    }
}
