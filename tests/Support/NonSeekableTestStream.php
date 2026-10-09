<?php

namespace Tests\Support;

/**
 * Một stream wrapper chỉ đọc một chiều: không `stream_seek`, `stream_stat` trả `false` (nên `fstat()`
 * trả `false`) — như luồng HTTP hay ống, thứ không biết trước cỡ. PHP vẫn báo luồng userspace là
 * `seekable`, nên điều phân biệt ở đây là `fstat()`. Dùng để đo nhánh "chép sang bộ nhớ tạm trước khi
 * tải lên" của `DriveAdapter::writeStream()`.
 *
 * Đăng ký: `stream_wrapper_register('vkcrm-khong-tua', NonSeekableTestStream::class)`, đặt
 * {@see self::$content}, rồi `fopen('vkcrm-khong-tua://tep', 'rb')`.
 */
final class NonSeekableTestStream
{
    public static string $content = '';

    /** @var resource|null gán bởi PHP */
    public $context;

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$content, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$content);
    }

    public function stream_stat(): array|false
    {
        return false;
    }
}
