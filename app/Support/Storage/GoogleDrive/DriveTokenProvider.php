<?php

namespace App\Support\Storage\GoogleDrive;

use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;

/**
 * Nguồn access token cho {@see DriveClient} (kế hoạch M14, R1, R6). Bản production là
 * {@see ServiceAccountTokenProvider}; test thay bằng một token cố định qua container
 * (`app()->instance(DriveTokenProvider::class, …)`) để không gọi endpoint token.
 */
interface DriveTokenProvider
{
    /**
     * Access token còn hiệu lực (có thể lấy từ cache).
     *
     * @throws DocumentStorageMisconfigured khoá thiếu, hỏng, hay bị Google từ chối
     * @throws DocumentStorageUnavailable endpoint token tạm thời không trả lời
     */
    public function token(): string;

    /** Bỏ token đã cache: lần {@see self::token()} sau xin token mới. Gọi khi Drive trả 401. */
    public function forget(): void;
}
