<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Chỉ mục `drive_objects` nói tệp có, mà kho (Google Drive) trả 404 (kế hoạch M14, R3). Đây là dữ
 * liệu lệch, không phải kho sập. Luật cho nơi bắt (R3): route tải trả 404 như một tệp thiếu hôm
 * nay, kèm log mức `critical` — không trả 503, vì thử lại không làm tệp quay về.
 *
 * Thông điệp mang khoá mờ (`<media_id>/<ulid>.<đuôi>`, cũng ở {@see self::$key}) để người vận hành
 * tìm được dòng chỉ mục; nó chỉ dành cho log, không cho người dùng. Không bao giờ mang mã tệp Drive.
 */
class StoredFileMissing extends RuntimeException
{
    public function __construct(public readonly string $key, string $message)
    {
        parent::__construct($message);
    }

    public static function forKey(string $key): self
    {
        return new self($key, __('storage.exceptions.stored_file_missing', ['key' => $key]));
    }
}
