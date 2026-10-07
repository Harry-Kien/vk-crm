<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Chỉ mục `drive_objects` còn một dòng SỐNG cho khoá, mà tệp Drive của nó đang nằm trong thùng rác
 * của Shared Drive — ai đó đã cho nó vào thùng rác ngoài CRM, hay một lượt xoá media trong transaction
 * đã rollback sau khi thư viện media gọi thùng rác (kế hoạch M14, "Những chỗ … sẽ cắn").
 *
 * Adapter Drive đặt lớp này làm ngoại lệ GỐC (`previous`) của `UnableToProvideChecksum` khi Google báo
 * `trashed = true`, để `vkcrm:storage:verify` (M14 Task 6) xếp tệp vào nhóm "đã vào thùng rác" mà
 * không phải so câu chữ của thông điệp. Thông điệp chỉ mang khoá mờ, không mã tệp Drive; chỉ dành cho
 * log và đầu ra lệnh vận hành.
 */
class StoredFileTrashed extends RuntimeException
{
    public function __construct(public readonly string $key, string $message)
    {
        parent::__construct($message);
    }

    public static function forKey(string $key): self
    {
        return new self($key, __('storage.stored_file_trashed', ['key' => $key]));
    }
}
