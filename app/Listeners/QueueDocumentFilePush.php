<?php

namespace App\Listeners;

use App\Jobs\PushDocumentFile;
use App\Providers\DocumentStorageServiceProvider;
use App\Support\Storage\DocumentStore;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Tệp mới vào vùng đệm → xếp job đẩy nó lên kho, SAU commit (kế hoạch M14, R2).
 *
 * Đăng ký TƯỜNG MINH trên sự kiện Eloquent `eloquent.created: <lớp Media>` trong
 * {@see DocumentStorageServiceProvider}. Laravel tự dò `app/Listeners` theo kiểu tham số của
 * `handle()`, nên lớp này cũng được đăng ký cho "sự kiện" tên lớp `Media` — thứ không bao giờ được phát;
 * không dựa vào đường đó. Test qua màn hình thật chứng minh listener chạy.
 *
 * - Chỉ khi kho đã BẬT ({@see DocumentStore::pushesNewFiles()}: công tắc `google_drive` VÀ có mốc) và
 *   media nằm ở vùng đệm `private`. Sự kiện bắn lúc TẠO, nên listener tự nó chỉ thấy tệp tạo sau khi
 *   bật; tệp cũ đi qua lệnh chuyển ngoài giờ.
 * - `->afterCommit()`: ba Action ghi tệp gọi `addMedia()` bên trong transaction của chúng. Rollback
 *   thì job bị bỏ cùng transaction, không job nào đẩy một tệp mà không dòng `media` nào trỏ tới. Và
 *   `created` bắn khi dòng `media` vừa chèn, TRƯỚC khi thư viện chép tệp vào đĩa; sau commit thì tệp đã
 *   nằm trong vùng đệm. Mọi nơi gọi `addMedia()` hôm nay đều trong transaction. Một nơi gọi sau này
 *   ngoài transaction vẫn an toàn: job vào hàng `storage` (không chạy đồng bộ) và worker nhặt nó sau;
 *   nếu worker nhanh hơn lượt chép tệp, lượt đẩy thấy vùng đệm thiếu tệp, không đổi đĩa, và hàng đợi
 *   thử lại.
 * - Ở chế độ `local` (mặc định) câu trả lời có ngay, không truy vấn nào: `pushesNewFiles()` hỏi công
 *   tắc trước bảng `settings`.
 */
class QueueDocumentFilePush
{
    public function handle(Media $media): void
    {
        if ($media->disk !== DocumentStore::STAGING_DISK || ! DocumentStore::pushesNewFiles()) {
            return;
        }

        PushDocumentFile::dispatch((int) $media->getKey())->afterCommit();
    }
}
