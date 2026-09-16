<?php

namespace App\Support\Files;

use App\Exceptions\FileRejected;

/**
 * Điểm nối để bật quét virus thật (ClamAV) mà không phải sửa `UploadStaffDocument` hay
 * `SubmitClientDocument` (SPEC §6.6 bước 5: "phải có interface ... để bật lên sau không phải sửa
 * logic"). Action chỉ biết tới interface này; `AppServiceProvider` mới quyết định implementation
 * nào được bind, dựa trên `config('vkcrm.clamav.enabled')`.
 *
 * `scan()` trả `void` chứ không trả `bool` có chủ đích: một giá trị boolean có thể bị một lời gọi
 * quên kiểm tra (`$scanner->scan($path);` bỏ qua kết quả trả về là lỗi im lặng nguy hiểm nhất có
 * thể có ở đây — tệp nhiễm mã độc được lưu như thể đã sạch). Ném `FileRejected` là cách duy nhất
 * để báo "không sạch", nên không có đường nào bỏ qua được kết quả.
 */
interface VirusScanner
{
    /**
     * @throws FileRejected khi tệp bị nghi nhiễm mã độc, hoặc (với implementation nối tới một
     *                      daemon thật) khi không thể xác nhận tệp sạch.
     */
    public function scan(string $path): void;

    /**
     * Cho biết implementation này có THẬT SỰ quét hay không. Đây là điểm để một người vận hành tự
     * hỏi được hệ thống — qua `php artisan about` (xem `AppServiceProvider::boot()`) hoặc gọi
     * trực tiếp `app(VirusScanner::class)->isActive()` — thay vì phải đọc mã nguồn hay đoán từ
     * `.env`. Một scanner mặc định im lặng bỏ qua mọi tệp là một rủi ro nếu không ai biết là nó
     * đang im lặng.
     */
    public function isActive(): bool;
}
