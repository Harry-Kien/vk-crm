<?php

namespace App\Actions\Storage;

use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Exceptions\StoredFileMissing;
use App\Http\Controllers\DocumentDownloadController;
use App\Support\Storage\StoredFileStream;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * MỞ luồng đọc tệp của một media, trên đĩa mà dòng `media` ghi (`private` hay kho `documents_remote`) —
 * bước thứ hai trong thứ tự của kế hoạch M14, R3: kiểm quyền → **mở luồng** → ghi nhật ký → stream.
 * Với Drive đó là đúng MỘT `GET …?alt=media` (chỉ mục trả mã tệp, không gọi mạng); nội dung chưa được
 * đọc. Không kiểm quyền: nơi gọi ({@see DocumentDownloadController}) đã kiểm chữ ký, người nhận và
 * policy TRƯỚC khi gọi lớp này, và không được gọi nó sớm hơn (một id đoán mò không được tốn một lệnh
 * gọi Google).
 *
 * Kết quả mang `size` và `mime_type` của DÒNG `media` (R3: header không hỏi kho).
 *
 * Lỗi, cho nơi gọi phân loại (R3, R9):
 *  - {@see DocumentStorageUnavailable}: kho tạm thời không trả lời. Adapter Drive ném nó thẳng (không
 *    bọc trong `UnableToReadFile`), nên nó đi qua lớp này nguyên vẹn — người tải thấy trang 503;
 *  - {@see DocumentStorageMisconfigured}: cấu hình kho hỏng, cũng đi qua nguyên vẹn — cũng trang 503
 *    (`bootstrap/app.php`), lỗi được báo cáo vào log;
 *  - {@see StoredFileMissing}: nơi chứa không còn tệp dù dòng `media` (và chỉ mục) nói có — Drive trả
 *    404 hay chỉ mục không có khoá (adapter ném `UnableToReadFile`), hay đĩa cục bộ không mở được
 *    (`readStream()` trả `null`). Ghi log `critical` (mã media, đĩa, khoá mờ — không tiêu đề, không mã
 *    tệp Drive) rồi ném; route tải trả 404 như một tệp thiếu hôm nay. Không 503: thử lại không làm tệp
 *    quay về.
 */
final class OpenStoredFile
{
    public function handle(Media $media): StoredFileStream
    {
        $key = $media->getPathRelativeToRoot();

        try {
            $resource = Storage::disk($media->disk)->readStream($key);
        } catch (UnableToReadFile) {
            $resource = null;
        }

        if (! is_resource($resource)) {
            Log::critical(__('storage.read.log.missing'), [
                'media_id' => $media->getKey(),
                'disk' => $media->disk,
                'key' => $key,
            ]);

            throw StoredFileMissing::forKey($key);
        }

        return new StoredFileStream($resource, (int) $media->size, $media->mime_type);
    }
}
