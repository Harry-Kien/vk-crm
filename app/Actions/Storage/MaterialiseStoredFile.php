<?php

namespace App\Actions\Storage;

use App\Actions\Matter\BuildHandoverPackage;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Exceptions\StoredFileMissing;
use App\Support\Storage\DocumentStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Cho một media một ĐƯỜNG DẪN CỤC BỘ đọc được, đã kiểm (kế hoạch M14, R12) — cho những nơi cần một tệp
 * thật trên ổ đĩa, như `ZipArchive::addFile()` của gói bàn giao ({@see BuildHandoverPackage}).
 *
 *  - **Đĩa cục bộ** (vùng đệm `private`): trả thẳng đường dẫn của tệp, không chép. Tệp không còn →
 *    {@see StoredFileMissing}.
 *  - **Kho** (`documents_remote`): `$disk->path()` trên đĩa không cục bộ KHÔNG báo lỗi — nó trả một
 *    chuỗi không tồn tại, và `ZipArchive` chỉ hỏng lúc `close()` ("Những chỗ … sẽ cắn"). Vì thế tệp
 *    được STREAM về `$targetPath` (một lệnh `GET …alt=media`, từng khối 1 MiB, bộ nhớ tối đa một khối),
 *    vừa chép vừa tính md5, rồi so với dòng `media`: số byte phải bằng `media.size`, và md5 bằng
 *    `media.checksum_md5` khi cột đó có (cột do lượt đẩy ghi, R2). Lệch → xoá bản tải về, log `error`
 *    (mã media, khoá mờ — không tiêu đề, không mã tệp Drive), và ném
 *    {@see DocumentStorageUnavailable}: một lượt tải đứt giữa chừng là lỗi tạm, lần sinh lại sau đọc
 *    lại; một bản trên kho bị đổi thật sẽ lệch mãi, và `vkcrm:storage:verify` là nơi báo nó.
 *
 * Lỗi khác đi qua nguyên vẹn cho nơi gọi phân loại: {@see DocumentStorageUnavailable} và
 * {@see DocumentStorageMisconfigured} (kho sập, cấu hình hỏng); {@see StoredFileMissing} (kho trả 404
 * hay chỉ mục không có khoá — adapter ném `UnableToReadFile`). Lỗi GHI ở `$targetPath` (đĩa đầy) là
 * cảnh báo PHP, Laravel đổi thành `ErrorException`, cũng đi qua nguyên vẹn.
 *
 * Không kiểm quyền: chỉ chạy trong job đã qua mọi cổng của gói bàn giao. Không chạy trong transaction:
 * đây là I/O mạng tới kho (R2).
 */
final class MaterialiseStoredFile
{
    private const CHUNK_BYTES = 1024 * 1024;

    public function handle(Media $media, string $targetPath): string
    {
        $key = $media->getPathRelativeToRoot();

        if ($media->disk !== DocumentStore::REMOTE_DISK) {
            $path = Storage::disk($media->disk)->path($key);

            if (! is_file($path)) {
                throw StoredFileMissing::forKey($key);
            }

            return $path;
        }

        try {
            $source = DocumentStore::remote()->readStream($key);
        } catch (UnableToReadFile) {
            $source = null;
        }

        if (! is_resource($source)) {
            throw StoredFileMissing::forKey($key);
        }

        File::ensureDirectoryExists(dirname($targetPath));
        $target = fopen($targetPath, 'wb');
        $hash = hash_init('md5');
        $bytes = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);

                if ($chunk === false) {
                    break;
                }

                hash_update($hash, $chunk);
                $bytes += strlen($chunk);
                fwrite($target, $chunk);
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        $md5 = hash_final($hash);
        $expectedMd5 = $media->getAttribute('checksum_md5');

        if ($bytes !== (int) $media->size || (is_string($expectedMd5) && $expectedMd5 !== '' && $md5 !== $expectedMd5)) {
            File::delete($targetPath);

            Log::error(__('storage.read.log.checksum_mismatch'), [
                'media_id' => $media->getKey(),
                'key' => $key,
                'expected_size' => (int) $media->size,
                'size' => $bytes,
            ]);

            throw DocumentStorageUnavailable::temporarily(new RuntimeException(__('storage.read.checksum_mismatch', ['key' => $key])));
        }

        return $targetPath;
    }
}
