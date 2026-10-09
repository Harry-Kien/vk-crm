<?php

namespace App\Support\Storage;

use App\Exceptions\DocumentStorageMisconfigured;
use App\Providers\DocumentStorageServiceProvider;
use App\Support\Storage\GoogleDrive\DriveAdapter;
use Closure;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;

/**
 * Adapter Flysystem cho đĩa `documents_remote` khi kho không dùng được vì cấu hình: MỌI lời gọi ném
 * {@see DocumentStorageMisconfigured}, không lời gọi nào chạm mạng hay đĩa (kế hoạch M14, R7).
 *
 * Vì sao một adapter ném lỗi thay vì ném ngay lúc dựng đĩa: đĩa `documents_remote` luôn có trong
 * `config/filesystems.php` (R7). Ném lúc dựng thì mọi chỗ chỉ LẤY đĩa mà chưa dùng đều hỏng chỉ vì
 * kho chưa cấu hình, kể cả khi công tắc là `local`. Ném lúc DÙNG thì chỉ đúng thao tác cần kho mới
 * hỏng, với lý do tiếng Việt.
 *
 * Driver `google-drive` ({@see DocumentStorageServiceProvider}) trả adapter này khi thiếu một trong ba
 * khoá cấu hình của kho (đường khoá tài khoản dịch vụ, Shared Drive, thư mục gốc); đủ thì trả
 * {@see DriveAdapter} thật (M14 Task 2).
 *
 * Cài cả {@see ChecksumProvider}, như {@see DriveAdapter} (R4): `checksum()` ném thẳng từ adapter,
 * không qua đường Flysystem tự mở luồng đọc để tính md5.
 */
final class MisconfiguredDriveAdapter implements ChecksumProvider, FilesystemAdapter
{
    /** @param  Closure(): DocumentStorageMisconfigured  $reason  dựng MỘT ngoại lệ mới cho mỗi lời gọi */
    public function __construct(private readonly Closure $reason) {}

    public function fileExists(string $path): bool
    {
        throw ($this->reason)();
    }

    public function directoryExists(string $path): bool
    {
        throw ($this->reason)();
    }

    public function write(string $path, string $contents, Config $config): void
    {
        throw ($this->reason)();
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        throw ($this->reason)();
    }

    public function read(string $path): string
    {
        throw ($this->reason)();
    }

    public function readStream(string $path)
    {
        throw ($this->reason)();
    }

    public function delete(string $path): void
    {
        throw ($this->reason)();
    }

    public function deleteDirectory(string $path): void
    {
        throw ($this->reason)();
    }

    public function createDirectory(string $path, Config $config): void
    {
        throw ($this->reason)();
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw ($this->reason)();
    }

    public function visibility(string $path): FileAttributes
    {
        throw ($this->reason)();
    }

    public function mimeType(string $path): FileAttributes
    {
        throw ($this->reason)();
    }

    public function lastModified(string $path): FileAttributes
    {
        throw ($this->reason)();
    }

    public function fileSize(string $path): FileAttributes
    {
        throw ($this->reason)();
    }

    public function listContents(string $path, bool $deep): iterable
    {
        throw ($this->reason)();
    }

    public function move(string $source, string $destination, Config $config): void
    {
        throw ($this->reason)();
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        throw ($this->reason)();
    }

    public function checksum(string $path, Config $config): string
    {
        throw ($this->reason)();
    }
}
