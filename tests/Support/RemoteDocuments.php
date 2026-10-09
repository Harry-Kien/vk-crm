<?php

namespace Tests\Support;

use App\Actions\Storage\PushDocumentFileToRemote;
use App\Enums\PushOutcome;
use App\Models\Document;
use App\Models\Setting;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\StagedCopy;
use Illuminate\Support\Facades\DB;
use LogicException;
use Pest\TestSuite;
use PHPUnit\Framework\TestCase;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * M14 Task 4 — hai trợ giúp test của kế hoạch (R3, R12) và cách chạy lại bộ test HIỆN CÓ với media đã
 * nằm trên kho.
 *
 * - {@see self::pushToRemote()}: đẩy một media lên kho bằng ACTION THẬT của Task 3
 *   (`PushDocumentFileToRemote`) trên đĩa giả, rồi dọn bản trong vùng đệm như lượt dọn theo biên nhận
 *   (R10) — từ đó tệp CHỈ còn trên kho, nên một test xanh ở chế độ này là xanh vì đọc được từ kho, không
 *   vì bản cục bộ còn đó.
 * - {@see self::bindRealDriveAdapter()}: đĩa `documents_remote` THẬT (adapter Drive của Task 2) trên
 *   máy chủ Drive giả (`FakeGoogleDrive`: `Http::fake()` + `Http::preventStrayRequests()`), token cố định
 *   không gọi HTTP, chỉ mục gieo sẵn — gieo KHÔNG gửi request nào, nên `Http::assertNothingSent()` sau
 *   đó đo đúng những gì mã dưới test gửi.
 *
 * # Chạy lại bộ test hiện có ở hai chế độ
 *
 * Tệp test thêm, TRƯỚC `beforeEach` của nó:
 *
 *     beforeEach(fn () => RemoteDocuments::adopt($this))->with(RemoteDocuments::MODES);
 *
 * Pest gắn dataset của một `beforeEach` cấp tệp vào MỌI test của tệp (đứng trước dataset riêng của
 * test, nên test có dataset riêng nhận chế độ làm đối số đầu). Trợ giúp tạo tài liệu của tệp gọi
 * {@see self::settle()} sau khi gắn tệp: ở chế độ `remote` media được đẩy lên kho, ở `local` không gì.
 *
 * Một lớp chứ không phải hàm Pest: cùng lý do với `StagingFixtures`.
 */
final class RemoteDocuments
{
    public const LOCAL = 'local';

    public const REMOTE = 'remote';

    /** Dataset hai chế độ, cho `beforeEach(...)->with(RemoteDocuments::MODES)`. */
    public const MODES = [self::LOCAL, self::REMOTE];

    /**
     * Ghi chế độ của test đang chạy (đối số đầu của dataset). Ở chế độ `remote` trên SQLite, đẩy chuỗi
     * id của `media` tới một số ngẫu nhiên: khoá trên kho là `<media_id>/<tên>` không tiền tố (khuôn R4
     * không nhận `media-library.prefix`), nên thiếu bước này mọi test của tệp ghi, xoá rồi ghi lại đúng
     * đường `1/<tên>` trên cùng gốc đĩa giả — chuỗi thao tác mà `DocumentDownloadTest` đã đo là không ổn
     * định trên bind mount Docker của Windows (lý do của tiền tố riêng mỗi test ở chế độ `local`).
     */
    public static function adopt(TestCase $test): void
    {
        $test->storageMode = $test->providedData()[0] ?? self::LOCAL;

        if ($test->storageMode === self::REMOTE && DB::connection()->getDriverName() === 'sqlite') {
            DB::table('sqlite_sequence')->updateOrInsert(['name' => 'media'], ['seq' => random_int(1_000, 9_000_000)]);
        }
    }

    /** Chế độ của test đang chạy; tệp chưa gọi {@see self::adopt()} là `local`. */
    public static function mode(): string
    {
        $test = TestSuite::getInstance()->test;

        return isset($test->storageMode) ? $test->storageMode : self::LOCAL;
    }

    public static function remote(): bool
    {
        return self::mode() === self::REMOTE;
    }

    /** Tiền tố thư viện media cho chế độ hiện tại: rỗng ở `remote` (khuôn khoá R4), riêng mỗi test ở `local`. */
    public static function mediaPrefix(string $local): string
    {
        return self::remote() ? '' : $local;
    }

    /** Ở chế độ `remote`: đẩy MỌI tệp của tài liệu lên kho. Trả tài liệu đã nạp lại. */
    public static function settle(Document $document): Document
    {
        if (self::remote()) {
            foreach ($document->refresh()->getMedia('file') as $media) {
                self::pushToRemote($media);
            }
        }

        return $document->refresh();
    }

    /**
     * Đẩy `$media` lên kho bằng Action thật, rồi dọn vùng đệm. Công tắc và mốc bật kho chỉ bật trong
     * lúc đẩy rồi trả về như cũ: tệp tạo SAU đó trong test không bị listener xếp job đẩy.
     *
     * Ném {@see LogicException} khi Action không trả `Pushed` (tên tệp không đúng khuôn R4, …): một test
     * chế độ `remote` không bao giờ được lặng lẽ chạy trên đĩa cục bộ.
     */
    public static function pushToRemote(Media $media): Media
    {
        $driver = config('vkcrm.storage.driver');
        $enabledAt = Setting::query()->where('key', DocumentStore::REMOTE_ENABLED_AT_KEY)->value('value');

        StagingFixtures::enableRemote();

        try {
            $outcome = app(PushDocumentFileToRemote::class)->handle($media->getKey());
        } finally {
            config(['vkcrm.storage.driver' => $driver]);

            $enabledAt === null
                ? Setting::query()->where('key', DocumentStore::REMOTE_ENABLED_AT_KEY)->delete()
                : Setting::query()->where('key', DocumentStore::REMOTE_ENABLED_AT_KEY)->update(['value' => $enabledAt]);
        }

        if ($outcome !== PushOutcome::Pushed) {
            throw new LogicException("pushToRemote: media {$media->getKey()} ({$media->file_name}) không lên kho: {$outcome->value}.");
        }

        $media->refresh();
        StagedCopy::discard($media);
        Media::query()->toBase()->where('id', $media->getKey())->update(['local_purge_after' => null]);

        return $media->refresh();
    }

    /**
     * Đĩa `documents_remote` THẬT trên Drive giả, với chỉ mục gieo sẵn. Mỗi phần tử của `$indexRows`:
     *  - một `Media` còn ở vùng đệm: tệp của nó được gieo lên Drive giả đúng khoá, dòng `media` đổi sang
     *    kho bằng câu UPDATE có điều kiện như Action đẩy, bản trong vùng đệm bị dọn;
     *  - hoặc `khoá => nội dung`: chỉ gieo tệp và dòng chỉ mục.
     *
     * @param  array<int|string, Media|string>  $indexRows
     */
    public static function bindRealDriveAdapter(array $indexRows = []): FakeGoogleDrive
    {
        $drive = FakeGoogleDrive::install();

        foreach ($indexRows as $key => $row) {
            if (! $row instanceof Media) {
                $drive->seed((string) $key, $row);

                continue;
            }

            $path = $row->getPathRelativeToRoot();
            $content = (string) DocumentStore::staging()->get($path);
            $drive->seed($path, $content, mime: $row->mime_type);

            Media::query()->toBase()
                ->where('id', $row->getKey())
                ->where('disk', DocumentStore::STAGING_DISK)
                ->update([
                    'disk' => DocumentStore::REMOTE_DISK,
                    'conversions_disk' => DocumentStore::REMOTE_DISK,
                    'remote_pushed_at' => now(),
                    'checksum_md5' => md5($content),
                    'checksum_sha256' => hash('sha256', $content),
                ]);

            StagedCopy::discard($row->refresh());
        }

        $drive->disk();

        return $drive;
    }
}
