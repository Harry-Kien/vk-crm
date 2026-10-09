<?php

namespace App\Support\Storage;

use App\Actions\Storage\OpenStoredFile;

/**
 * Một luồng đọc ĐÃ MỞ của tệp hồ sơ, cộng hai header lấy từ dòng `media` (kế hoạch M14, R3): kết quả
 * của {@see OpenStoredFile}. Route tải dựng phản hồi từ đây mà không hỏi kho thêm lần nào — `size` và
 * `mimeType` là của dòng `media`, không của Drive (Laravel `download()` hỏi `mimeType()`, `size()` rồi mới
 * `readStream()`: ba lời gọi, và luồng mở trong callback sau khi header 200 đã gửi).
 *
 * Người nhận chịu trách nhiệm đóng `$resource` (route tải đóng nó sau `fpassthru()`).
 */
final class StoredFileStream
{
    /** @param  resource  $resource */
    public function __construct(public $resource, public int $size, public ?string $mimeType) {}
}
