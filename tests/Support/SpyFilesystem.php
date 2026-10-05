<?php

namespace Tests\Support;

use App\Support\Storage\DocumentStore;
use Closure;
use Illuminate\Filesystem\FilesystemAdapter as IlluminateFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;

/**
 * M14 Task 4 — đĩa GIÁN ĐIỆP (kế hoạch M14, R3, cách khoá (a)): một decorator ở tầng adapter Flysystem,
 * bọc adapter của một đĩa giả và ĐẾM mọi lời gọi tới nó, mọi loại — `fileExists`, `readStream`,
 * `fileSize`, `mimeType`, `checksum`, cả ghi, xoá, liệt kê. Mọi phương thức của đĩa Laravel
 * (`exists`, `size`, `download`, `checksum`, `put`…) cuối cùng đều tới tầng này, nên "0 lời gọi" ở đây
 * nghĩa là 0 lời gọi tới kho, bất kể mã gọi qua phương thức nào. (Chỉ `path()`/`url()` không tới
 * adapter: chúng dựng chuỗi từ cấu hình — test cấu trúc của Task 4 cấm chúng trên đĩa kho.)
 *
 * Vì sao cần nó: `Storage::fake()` không bao giờ gửi HTTP, nên `Http::assertNothingSent()` trên đĩa giả
 * trần là test KHÔNG ĐỎ ĐƯỢC (R3). Đĩa gián điệp đếm thẳng lời gọi tới kho.
 *
 * Móc (`$hooks`, khoá = tên phương thức của adapter): chạy TRƯỚC khi chuyển tiếp, nhận đúng đối số của
 * lời gọi. Móc ném ngoại lệ là cách dựng "kho sập" (`DocumentStorageUnavailable`) hay "kho mất tệp".
 */
final class SpyFilesystem implements ChecksumProvider, FilesystemAdapter
{
    /** @var list<array{0: string, 1: string}> [phương thức, đường dẫn] theo thứ tự */
    public array $calls = [];

    /** @param  array<string, Closure>  $hooks */
    public function __construct(private readonly FilesystemAdapter $inner, public array $hooks = []) {}

    /**
     * Đặt đĩa gián điệp làm `$disk` (mặc định `documents_remote`) bằng `Storage::set()`, bọc adapter
     * của đĩa đang có (đĩa giả của `tests/Pest.php`). Cấu hình (`throw`, `root`) giữ nguyên.
     *
     * @param  array<string, Closure>  $hooks
     */
    public static function install(string $disk = DocumentStore::REMOTE_DISK, array $hooks = []): self
    {
        /** @var IlluminateFilesystemAdapter $current */
        $current = Storage::disk($disk);
        $config = $current->getConfig();
        $spy = new self($current->getAdapter(), $hooks);

        Storage::set($disk, new IlluminateFilesystemAdapter(new Filesystem($spy, $config), $spy, $config));

        return $spy;
    }

    /** Số lời gọi, của một phương thức hay của mọi phương thức (`null`). */
    public function count(?string $method = null): int
    {
        return count(array_filter($this->calls, fn (array $call): bool => $method === null || $call[0] === $method));
    }

    /** @return list<string> tên phương thức theo thứ tự gọi */
    public function methods(): array
    {
        return array_map(fn (array $call): string => $call[0], $this->calls);
    }

    private function spy(string $method, string $path, mixed ...$arguments): void
    {
        $this->calls[] = [$method, $path];

        if (isset($this->hooks[$method])) {
            ($this->hooks[$method])($path, ...$arguments);
        }
    }

    public function fileExists(string $path): bool
    {
        $this->spy('fileExists', $path);

        return $this->inner->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        $this->spy('directoryExists', $path);

        return $this->inner->directoryExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->spy('write', $path);
        $this->inner->write($path, $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->spy('writeStream', $path);
        $this->inner->writeStream($path, $contents, $config);
    }

    public function read(string $path): string
    {
        $this->spy('read', $path);

        return $this->inner->read($path);
    }

    public function readStream(string $path)
    {
        $this->spy('readStream', $path);

        return $this->inner->readStream($path);
    }

    public function delete(string $path): void
    {
        $this->spy('delete', $path);
        $this->inner->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->spy('deleteDirectory', $path);
        $this->inner->deleteDirectory($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->spy('createDirectory', $path);
        $this->inner->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->spy('setVisibility', $path);
        $this->inner->setVisibility($path, $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        $this->spy('visibility', $path);

        return $this->inner->visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        $this->spy('mimeType', $path);

        return $this->inner->mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        $this->spy('lastModified', $path);

        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        $this->spy('fileSize', $path);

        return $this->inner->fileSize($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $this->spy('listContents', $path);

        return $this->inner->listContents($path, $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->spy('move', $source);
        $this->inner->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->spy('copy', $source);
        $this->inner->copy($source, $destination, $config);
    }

    public function checksum(string $path, Config $config): string
    {
        $this->spy('checksum', $path);

        if ($this->inner instanceof ChecksumProvider) {
            return $this->inner->checksum($path, $config);
        }

        $stream = $this->inner->readStream($path);

        try {
            return hash($config->get('checksum_algo', 'md5'), (string) stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }
}
