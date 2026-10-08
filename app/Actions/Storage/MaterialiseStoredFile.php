<?php

namespace App\Actions\Storage;

use App\Actions\Matter\BuildHandoverPackage;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Exceptions\StoredFileChanged;
use App\Exceptions\StoredFileMissing;
use App\Support\Storage\DocumentStore;
use Exception;
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
 *    (mã media, khoá mờ — không tiêu đề, không mã tệp Drive), rồi ném theo số byte đọc được (rà soát
 *    cuối vòng sửa 1, I7): THIẾU byte → {@see DocumentStorageUnavailable} (một lượt tải đứt hay đứng
 *    giữa chừng là lỗi tạm, lần sau đọc lại); ĐỦ hay THỪA byte mà vẫn lệch → {@see StoredFileChanged}
 *    (bản trên kho đã bị đổi, lệch mãi; `vkcrm:storage:verify` là nơi báo nó). {@see StoredFileChanged}
 *    kế thừa {@see DocumentStorageUnavailable}, nên nơi bắt nào không phân biệt thì vẫn như cũ.
 *
 * Kho hỏng GIỮA lúc đọc thân tệp (vòng sửa 1 của Task 4): mọi ngoại lệ của `feof`/`fread` trên luồng
 * nguồn — cảnh báo của socket (TLS đứt) mà Laravel đổi thành `ErrorException`, hay ngoại lệ của một
 * luồng khác — xoá bản tải dở, log `error` (mã media, khoá mờ, số byte đã đọc, LỚP của lỗi), rồi thành
 * {@see DocumentStorageUnavailable}. (`RuntimeException` của `GuzzleHttp\Psr7\Stream::read()` không tới
 * đây: `StreamWrapper` của Guzzle đổi nó thành `fread` trả `false`.) Một lần đọc trả `false` hay `''`
 * — lỗi đã bị nuốt, hay luồng đứng vì hết `read_timeout` (R9) mà `feof` vẫn false — dừng vòng chép;
 * bản tải về thiếu byte thì kiểm cỡ ở trên bắt. Không dựa vào `stream_get_meta_data()['timed_out']`:
 * luồng của Drive là luồng userspace của Guzzle (`guzzle://stream`), cờ đó không phản ánh socket bên
 * dưới.
 *
 * Lỗi khác đi qua nguyên vẹn cho nơi gọi phân loại: {@see DocumentStorageUnavailable} và
 * {@see DocumentStorageMisconfigured} (kho sập, cấu hình hỏng lúc MỞ tệp); {@see StoredFileMissing} (kho
 * trả 404 hay chỉ mục không có khoá — adapter ném `UnableToReadFile`). Lỗi GHI ở `$targetPath` (đĩa
 * đầy) là cảnh báo PHP, Laravel đổi thành `ErrorException`, cũng đi qua nguyên vẹn: `fwrite` nằm ngoài
 * khối bắt lỗi đọc.
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
        $readFailure = null;

        try {
            while (true) {
                try {
                    if (feof($source)) {
                        break;
                    }

                    $chunk = fread($source, self::CHUNK_BYTES);
                } catch (Exception $exception) {
                    $readFailure = $exception;

                    break;
                }

                // Lỗi đọc mà luồng nuốt (`false`), hay luồng ĐỨNG: hết `read_timeout` trả '' mà `feof`
                // vẫn false — quay tiếp chỉ chờ thêm 60 giây mỗi vòng tới hết giờ của job. Thoát cả
                // hai; kiểm cỡ và md5 dưới đây quyết định bản tải về dùng được hay không.
                if ($chunk === false || $chunk === '') {
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

        if ($readFailure !== null) {
            File::delete($targetPath);

            Log::error(__('storage.read.log.read_failed'), [
                'media_id' => $media->getKey(),
                'key' => $key,
                'size' => $bytes,
                'exception' => $readFailure::class,
            ]);

            throw DocumentStorageUnavailable::temporarily($readFailure);
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

            if ($bytes < (int) $media->size) {
                throw DocumentStorageUnavailable::temporarily(new RuntimeException(__('storage.read.checksum_mismatch', ['key' => $key])));
            }

            throw StoredFileChanged::forKey($key);
        }

        return $targetPath;
    }
}
