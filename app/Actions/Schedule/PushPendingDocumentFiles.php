<?php

namespace App\Actions\Schedule;

use App\Jobs\PushDocumentFile;
use App\Models\Setting;
use App\Support\Audit;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\PushBackoff;
use Carbon\CarbonImmutable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Tác vụ quét `storage.push-pending`, 15 phút một lần (kế hoạch M14, R2): xếp lại job đẩy cho media
 * còn ở vùng đệm mà lẽ ra đã lên kho — lượt dispatch sau commit bị mất (tiến trình chết giữa commit và
 * lúc chèn job), hay job đã hết lượt thử khi kho sập lâu.
 *
 * Chỉ xếp media thoả CẢ BA:
 *
 * - `disk = private`;
 * - **cận dưới** `created_at >= mốc bật kho`. Tệp tạo trước mốc (tệp cũ, kể cả tệp đã quay lui) chỉ đi
 *   qua lệnh chuyển ngoài giờ `vkcrm:storage:migrate`: không có cận này thì đổi công tắc là đẩy cả kho
 *   tệp cũ trong giờ làm việc, và quay lui tự đảo ngược trong vòng 15 phút. Mốc (ISO-8601, có múi) được
 *   đổi sang múi của ứng dụng trước khi so: cột `created_at` không mang múi, và câu so sánh chuỗi với
 *   một mốc UTC lệch 7 giờ.
 * - **cận trên** `created_at <= now − 10 phút`: job sau commit của listener còn đang chạy với tệp vừa
 *   tạo.
 *
 * Công tắc không còn là `google_drive` (đổi về `local`, hay gõ sai) mà mốc còn: xoá mốc, ghi nhật ký
 * `document_store_disabled_observed`, KHÔNG xếp gì. Đổi về `local` là TẮT; bật lại phải chạy lệnh bật,
 * và tệp tạo trong lúc tắt thành tệp cũ. Công tắc `google_drive` mà chưa có mốc: không làm gì (lệnh bật
 * chưa chạy; kiểm tra sẵn sàng báo ĐỎ).
 *
 * Kho đang dừng sau một lượt đẩy hỏng ({@see PushBackoff}: 60 phút sau lỗi cấu hình, 15 phút sau lỗi
 * kho không trả lời) thì không xếp gì. Media vừa làm hỏng một lượt xếp CUỐI, sau các media khác theo
 * `id`, để một tệp hỏng vì chính nó không đứng đầu mọi lượt (rà soát cuối vòng sửa 1, I4).
 *
 * Job đẩy là `ShouldBeUnique` theo media: media còn job trong hàng thì lần xếp này không thêm job thứ
 * hai, nên `queued` đếm số media được ĐƯA RA xếp, không phải số job mới.
 */
class PushPendingDocumentFiles
{
    public const MINIMUM_AGE_MINUTES = 10;

    /** @return array{queued: int, disabled_observed: bool} */
    public function __invoke(): array
    {
        return $this->handle();
    }

    /** @return array{queued: int, disabled_observed: bool} */
    public function handle(): array
    {
        $enabledAt = DocumentStore::remoteEnabledAt();

        if (! DocumentStore::usesRemote()) {
            if ($enabledAt === null) {
                return ['queued' => 0, 'disabled_observed' => false];
            }

            $this->forgetEnabledAt($enabledAt);

            return ['queued' => 0, 'disabled_observed' => true];
        }

        if ($enabledAt === null) {
            return ['queued' => 0, 'disabled_observed' => false];
        }

        if (PushBackoff::paused()) {
            return ['queued' => 0, 'disabled_observed' => false];
        }

        $first = [];
        $last = [];

        Media::query()
            ->select('id')
            ->where('disk', DocumentStore::STAGING_DISK)
            ->where('created_at', '>=', $enabledAt->setTimezone(config('app.timezone')))
            ->where('created_at', '<=', now()->subMinutes(self::MINIMUM_AGE_MINUTES))
            ->chunkById(500, function ($chunk) use (&$first, &$last): void {
                $ids = $chunk->map(fn (Media $media): int => (int) $media->getKey())->all();
                $failed = PushBackoff::recentlyFailed($ids);

                array_push($first, ...array_diff($ids, $failed));
                array_push($last, ...$failed);
            });

        foreach ([...$first, ...$last] as $mediaId) {
            PushDocumentFile::dispatch($mediaId);
        }

        return ['queued' => count($first) + count($last), 'disabled_observed' => false];
    }

    private function forgetEnabledAt(CarbonImmutable $enabledAt): void
    {
        Setting::query()->where('key', DocumentStore::REMOTE_ENABLED_AT_KEY)->delete();

        Audit::record('document_store_disabled_observed', null, [
            'remote_enabled_at' => $enabledAt->toIso8601String(),
        ]);
    }
}
