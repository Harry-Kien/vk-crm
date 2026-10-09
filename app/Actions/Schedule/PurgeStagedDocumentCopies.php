<?php

namespace App\Actions\Schedule;

use App\Models\DriveObject;
use App\Support\Scopes\ClientPortalScope;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\StagedCopy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Tác vụ `storage.purge-staged`, mỗi giờ (kế hoạch M14, R10): xoá bản trong vùng đệm
 * `private/<media_id>/` của media đã lên kho, CHỈ KHI đã có một bản NGOÀI Google — bản ở máy chủ văn
 * phòng, chứng minh bằng biên nhận từng tệp.
 *
 * Bất biến: mỗi tệp luôn có ít nhất hai bản (vùng đệm + kho, hoặc kho + văn phòng). Một media chỉ được
 * dọn khi CẢ BỐN điều đúng ({@see self::purgeable()}):
 *
 *  1. `media.disk = 'documents_remote'` — một media đã quay lui về `private` mà còn mang mốc cũ thì
 *     bản trong vùng đệm là bản DUY NHẤT mà dòng đó trỏ tới;
 *  2. `local_purge_after` có và đã qua (ân hạn sau khi đẩy; lệnh chuyển tệp cũ đặt 30 ngày);
 *  3. có dòng `drive_objects` SỐNG của đúng khoá đó, trên Shared Drive đang cấu hình
 *     (`GOOGLE_DRIVE_SHARED_DRIVE_ID`), với `md5 = media.checksum_md5`;
 *  4. dòng đó có `office_copied_at <= now − office.purge_margin_hours` (24): biên độ che lệch đồng hồ
 *     giữa hai máy, và cho người vận hành một ngày để thấy một biên nhận sai trước khi nó có hậu quả.
 *
 * Chưa có máy văn phòng thì không dòng nào có `office_copied_at`, và không gì được dọn — có chủ đích.
 *
 * # Lọc, khoá, đọc lại
 *
 * - Câu truy vấn ứng viên chỉ dùng điều 1 và 2 (chỉ mục `(disk, local_purge_after)`); điều 3 và 4 hỏi
 *   bảng `drive_objects` theo LÔ (một câu cho mỗi 200 media). Media không đạt cả bốn ở bước lọc này thì
 *   không bao giờ bị khoá: trước khi có máy văn phòng, mọi media đã đẩy đều quá ân hạn, và lượt dọn
 *   mỗi giờ không được xin hàng nghìn khoá để giữ lại tất cả.
 * - Media đạt bước lọc: lấy `DocumentStore::pushLock($id)` (không chờ; không lấy được → bỏ qua tới
 *   lượt sau), rồi ĐỌC LẠI dòng `media` và dòng chỉ mục dưới khoá và hỏi lại cả bốn điều. Giữa lúc lọc
 *   và lúc khoá, lệnh quay lui có thể đã đổi media về `private`.
 * - Đạt → xoá thư mục vùng đệm ({@see StagedCopy}), rồi `UPDATE media SET local_purge_after = NULL
 *   WHERE id = ? AND disk = 'documents_remote'`. Bản cục bộ đã mất từ trước thì chỉ đặt lại cột.
 *
 * # Không bao giờ chạm kho
 *
 * Không đĩa kho, không HTTP, không lớp nào của `GoogleDrive`: chỉ đọc chỉ mục bằng model (bỏ
 * `ClientPortalScope` như mọi mã của kho, để kết quả không phụ thuộc guard đang mở). Có test cấu trúc.
 * Không DB transaction: mỗi media là một lần xoá thư mục và một câu UPDATE, dưới khoá của riêng nó.
 */
class PurgeStagedDocumentCopies
{
    private const CHUNK = 200;

    /** @return array{purged: int, kept: int, locked: int, failed: int} */
    public function __invoke(): array
    {
        return $this->handle();
    }

    /** @return array{purged: int, kept: int, locked: int, failed: int} */
    public function handle(): array
    {
        $now = CarbonImmutable::now();
        $receiptBefore = $now->subHours((int) config('vkcrm.storage.office.purge_margin_hours'));
        $driveId = (string) config('vkcrm.storage.google_drive.shared_drive_id');
        $counts = ['purged' => 0, 'kept' => 0, 'locked' => 0, 'failed' => 0];

        Media::query()
            ->where('disk', DocumentStore::REMOTE_DISK)
            ->whereNotNull('local_purge_after')
            ->where('local_purge_after', '<=', $now)
            ->chunkById(self::CHUNK, function (Collection $candidates) use ($now, $receiptBefore, $driveId, &$counts): void {
                $objects = $this->liveObjects($candidates->map(fn (Media $media): string => $media->getPathRelativeToRoot())->all());

                foreach ($candidates as $candidate) {
                    $key = $candidate->getPathRelativeToRoot();

                    if (! $this->purgeable($candidate, $objects->get($key), $now, $receiptBefore, $driveId)) {
                        continue;
                    }

                    $counts[$this->purgeUnderLock((int) $candidate->getKey(), $now, $receiptBefore, $driveId)]++;
                }
            });

        return $counts;
    }

    /** @return 'purged'|'kept'|'locked'|'failed' */
    private function purgeUnderLock(int $mediaId, CarbonImmutable $now, CarbonImmutable $receiptBefore, string $driveId): string
    {
        $lock = DocumentStore::pushLock($mediaId);

        if (! $lock->get()) {
            return 'locked';
        }

        try {
            $media = Media::query()->find($mediaId);
            $object = $media === null ? null : $this->liveObjects([$media->getPathRelativeToRoot()])->first();

            if ($media === null || ! $this->purgeable($media, $object, $now, $receiptBefore, $driveId)) {
                return 'kept';
            }

            if (! StagedCopy::discard($media)) {
                Log::warning(__('storage.push.log.staged_discard_failed'), ['media_id' => $mediaId]);

                return 'failed';
            }

            Media::query()
                ->whereKey($mediaId)
                ->where('disk', DocumentStore::REMOTE_DISK)
                ->update(['local_purge_after' => null]);

            return 'purged';
        } catch (Throwable $e) {
            report($e);

            return 'failed';
        } finally {
            $lock->release();
        }
    }

    /**
     * Bốn điều của R10 trên MỘT dòng `media` và dòng chỉ mục sống của khoá nó (`null` = không có dòng
     * sống). `checksum_md5` NULL không bao giờ bằng `drive_objects.md5` (cột không nullable), và
     * `drive_id` của một dòng không bao giờ rỗng, nên cấu hình thiếu mã Shared Drive cũng là "giữ".
     * Cột thời gian của `media` không có cast trên model của thư viện: chuỗi `Y-m-d H:i:s` theo giờ của
     * ứng dụng.
     */
    private function purgeable(Media $media, ?DriveObject $object, CarbonImmutable $now, CarbonImmutable $receiptBefore, string $driveId): bool
    {
        $purgeAfter = $media->getAttribute('local_purge_after');

        return $media->disk === DocumentStore::REMOTE_DISK
            && $purgeAfter !== null
            && CarbonImmutable::parse($purgeAfter, config('app.timezone'))->lessThanOrEqualTo($now)
            && $object !== null
            && $object->drive_id === $driveId
            && $object->md5 === $media->getAttribute('checksum_md5')
            && $object->office_copied_at !== null
            && $object->office_copied_at->lessThanOrEqualTo($receiptBefore);
    }

    /**
     * Dòng chỉ mục SỐNG của các khoá đã cho, theo khoá (`object_key` là unique; dòng đã rời có
     * `object_key = NULL` nên không bao giờ khớp).
     *
     * @param  list<string>  $keys
     * @return Collection<string, DriveObject>
     */
    private function liveObjects(array $keys): Collection
    {
        return DriveObject::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereIn('object_key', $keys)
            ->get()
            ->keyBy('object_key');
    }
}
