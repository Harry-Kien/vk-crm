<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Một dòng nói tệp có mà nơi chứa tệp nói không. Hai chiều:
 *
 * - {@see self::forKey()}: chỉ mục `drive_objects` nói tệp có, mà kho (Google Drive) trả 404 (kế
 *   hoạch M14, R3);
 * - {@see self::staged()}: media còn ở `private` mà vùng đệm không còn tệp (lượt đẩy, Task 3, R2).
 *
 * Đây là dữ liệu lệch, không phải kho sập. Luật cho nơi bắt: route tải trả 404 như một tệp thiếu
 * hôm nay, kèm log mức `critical` — không trả 503, vì thử lại không làm tệp quay về (R3); lượt đẩy
 * không đổi đĩa, log `critical`, và job để hàng đợi thử lại theo backoff (R2).
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

    /**
     * Chiều ngược lại, ở lượt đẩy (M14 Task 3, R2): media còn ghi `disk = private` mà vùng đệm không
     * còn tệp của khoá đó. Không đổi đĩa — không có gì để đẩy, và một media trỏ vào kho mà kho không
     * có tệp là trạng thái nửa vời tệ hơn.
     */
    public static function staged(string $key): self
    {
        return new self($key, __('storage.push.staged_file_missing', ['key' => $key]));
    }
}
