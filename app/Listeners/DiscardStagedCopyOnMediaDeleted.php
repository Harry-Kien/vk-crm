<?php

namespace App\Listeners;

use App\Providers\DocumentStorageServiceProvider;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\StagedCopy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Media đã lên kho bị xoá → xoá bản của nó trong vùng đệm `private/<media_id>/` (kế hoạch M14, R2).
 *
 * Thư viện media tự xoá tệp ở sự kiện `deleted`, nhưng chỉ trên `media.disk`: với media đã lên kho,
 * đó là kho, và bản trong vùng đệm (giữ trong thời gian ân hạn hay tới khi có biên nhận văn phòng) ở
 * lại mãi, không dòng nào trỏ tới. Đăng ký TƯỜNG MINH trên `eloquent.deleted: <lớp Media>` trong
 * {@see DocumentStorageServiceProvider} (lý do không dựa vào tự dò: docblock `QueueDocumentFilePush`).
 *
 * Ba điều kiện:
 *
 * - `media.disk` khác `private`: media còn ở vùng đệm thì thư viện đã tự xoá đúng thư mục này.
 * - SAU commit (`afterCommit` của kết nối của media; không transaction nào thì chạy ngay). Thư viện
 *   media xoá trên đĩa của nó KHÔNG chờ commit (vendor): một lượt xoá trong transaction bị rollback đã
 *   cho bản trên kho vào thùng rác trong khi dòng `media` sống lại. Bản trong vùng đệm khi đó là bản
 *   còn đọc được, và không được mất theo (khôi phục: "Những chỗ … sẽ cắn" của kế hoạch).
 * - Đọc lại: chỉ xoá khi dòng `media` thật sự không còn. Sự kiện `deleted` không tự chứng minh điều
 *   đó (một lớp media có xoá mềm, hay ai đó tự phát sự kiện).
 *
 * Không giữ khoá đẩy: không có gì để loại trừ — lượt đẩy không đổi đĩa một media đã mất dòng (UPDATE
 * có điều kiện, 0 dòng → cho bản trên kho vào thùng rác), và lượt dọn chỉ xoá đúng thư mục này. Lỗi
 * khi xoá chỉ log: request đã commit không được thành trang lỗi vì một thư mục còn sót.
 */
class DiscardStagedCopyOnMediaDeleted
{
    public function handle(Media $media): void
    {
        if ($media->disk === DocumentStore::STAGING_DISK) {
            return;
        }

        DB::connection($media->getConnectionName())->afterCommit(function () use ($media): void {
            if (Media::query()->whereKey($media->getKey())->exists()) {
                return;
            }

            try {
                if (! StagedCopy::discard($media)) {
                    Log::warning(__('storage.push.log.staged_discard_failed'), ['media_id' => $media->getKey()]);
                }
            } catch (Throwable $e) {
                Log::warning(__('storage.push.log.staged_discard_failed'), ['media_id' => $media->getKey(), 'exception' => $e::class]);
            }
        });
    }
}
