<?php

namespace Tests\Support;

use App\Models\Document;
use App\Models\Setting;
use App\Support\Storage\DocumentStore;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * M14 Task 3 — đồ nghề dùng chung của các test "ghi qua vùng đệm, đẩy lên kho, dọn bản cục bộ".
 *
 * Một lớp chứ không phải hàm Pest: hàm khai báo trong một tệp test là hàm toàn cục, và hai tệp cùng
 * khai một tên thì cả bộ chết ở lần nạp thứ hai.
 *
 * - {@see self::media()}: một tài liệu có tệp thật trong vùng đệm `private`, tên tệp là ULID viết
 *   thường cộng đuôi — đúng khuôn khoá mà lượt đẩy nhận (R4).
 * - {@see self::enableRemote()}: công tắc `google_drive` CỘNG mốc bật kho, tức
 *   `DocumentStore::pushesNewFiles()` đúng (R2: công tắc chỉ cho phép, mốc mới bật).
 * - {@see self::hookRemote()} / {@see self::hookStaging()}: bọc đĩa giả bằng một lớp con của
 *   `FilesystemAdapter` để chen vào giữa các bước (sau khi ghi, khi hỏi md5, khi hỏi tồn tại) và đếm
 *   lời gọi. Lớp con chứ không decorator trần: route tải gõ kiểu `FilesystemAdapter` và gọi
 *   `download()`.
 */
final class StagingFixtures
{
    public const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    /** Tài liệu mới có tệp trong vùng đệm. `$fileName` null = ULID viết thường + `.pdf`. */
    public static function media(string $content = self::PDF, ?string $fileName = null, ?Document $document = null): Media
    {
        $document ??= Document::factory()->create();

        $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', $content))
            ->usingName('Ban sao giay to.pdf')
            ->usingFileName($fileName ?? Str::lower((string) Str::ulid()).'.pdf')
            ->toMediaCollection('file');

        return $document->refresh()->getFirstMedia('file');
    }

    /** Công tắc `google_drive` và mốc bật kho (mặc định: một giờ trước). */
    public static function enableRemote(?CarbonInterface $at = null): void
    {
        config(['vkcrm.storage.driver' => DocumentStore::DRIVER_GOOGLE_DRIVE]);

        Setting::query()->updateOrCreate(
            ['key' => DocumentStore::REMOTE_ENABLED_AT_KEY],
            ['value' => ($at ?? now()->subHour())->toIso8601String()],
        );
    }

    /** Dòng `media` đọc thẳng từ bảng (các cột M14 không có cast trên model của thư viện). */
    public static function row(int $mediaId): ?object
    {
        return Media::query()->toBase()->where('id', $mediaId)->first();
    }

    /**
     * Bọc đĩa giả `documents_remote`. Móc nhận được:
     *  - `afterWrite(string $path)`: chạy SAU khi `writeStream` ghi xong;
     *  - `checksum(string $path, string $real): string`: trả md5 thay cho md5 thật;
     *  - `size(string $path, int $real): int`: trả cỡ thay cho cỡ thật;
     *
     * Mọi lời gọi được ghi vào `$calls` (tên phương thức).
     *
     * @param  array<string, Closure>  $hooks
     */
    public static function hookRemote(array $hooks = []): FilesystemAdapter
    {
        return self::hook(DocumentStore::REMOTE_DISK, $hooks);
    }

    /**
     * Bọc đĩa giả `private`. Móc `exists(string $path)` chạy MỘT lần, ở lời gọi `exists()` đầu tiên,
     * TRƯỚC khi trả lời. Móc `afterDeleteDirectory(string $directory)` chạy SAU mỗi lần xoá thư mục.
     *
     * @param  array<string, Closure>  $hooks
     */
    public static function hookStaging(array $hooks = []): FilesystemAdapter
    {
        return self::hook(DocumentStore::STAGING_DISK, $hooks);
    }

    /** @param  array<string, Closure>  $hooks */
    private static function hook(string $disk, array $hooks): FilesystemAdapter
    {
        /** @var FilesystemAdapter $fake */
        $fake = Storage::disk($disk);

        $hooked = new class($fake, $hooks) extends FilesystemAdapter
        {
            /** @var list<string> */
            public array $calls = [];

            /** @param  array<string, Closure>  $hooks */
            public function __construct(FilesystemAdapter $fake, public array $hooks)
            {
                parent::__construct($fake->getDriver(), $fake->getAdapter(), $fake->getConfig());
            }

            public function exists($path)
            {
                $this->calls[] = 'exists';

                if (isset($this->hooks['exists'])) {
                    $hook = $this->hooks['exists'];
                    unset($this->hooks['exists']);
                    $hook($path);
                }

                return parent::exists($path);
            }

            public function fileExists($path)
            {
                $this->calls[] = 'fileExists';

                return parent::fileExists($path);
            }

            public function size($path)
            {
                $this->calls[] = 'size';
                $real = parent::size($path);

                return isset($this->hooks['size']) ? ($this->hooks['size'])($path, $real) : $real;
            }

            public function readStream($path)
            {
                $this->calls[] = 'readStream';

                return parent::readStream($path);
            }

            public function writeStream($path, $resource, array $options = [])
            {
                $this->calls[] = 'writeStream';
                $written = parent::writeStream($path, $resource, $options);

                if (isset($this->hooks['afterWrite'])) {
                    ($this->hooks['afterWrite'])($path);
                }

                return $written;
            }

            public function checksum(string $path, array $options = [])
            {
                $this->calls[] = 'checksum';
                $real = parent::checksum($path, $options);

                return isset($this->hooks['checksum']) ? ($this->hooks['checksum'])($path, $real) : $real;
            }

            public function delete($paths)
            {
                $this->calls[] = 'delete';

                return parent::delete($paths);
            }

            public function deleteDirectory($directory)
            {
                $this->calls[] = 'deleteDirectory:'.$directory;
                $deleted = parent::deleteDirectory($directory);

                if (isset($this->hooks['afterDeleteDirectory'])) {
                    ($this->hooks['afterDeleteDirectory'])($directory);
                }

                return $deleted;
            }
        };

        Storage::set($disk, $hooked);

        return $hooked;
    }
}
