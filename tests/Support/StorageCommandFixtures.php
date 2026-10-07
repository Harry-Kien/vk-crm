<?php

namespace Tests\Support;

use App\Actions\Storage\PushDocumentFileToRemote;
use App\Enums\PushOutcome;
use App\Support\Files\FreeSpace;
use App\Support\Storage\CredentialFileInspector;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\GoogleDrive\DriveTokenProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use LogicException;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * M14 Task 6 — đồ nghề dùng chung của test các lệnh vận hành kho (`vkcrm:storage:enable`, `migrate`,
 * `rollback`, `verify`, `reindex`, `orphans`, `destruction-list`).
 *
 * Lớp tĩnh chứ không hàm Pest toàn cục: cùng lý do với {@see DocumentStoreFixtures}.
 *
 * - {@see self::ready()}: kiểm tra sẵn sàng XANH thật — máy chủ Drive giả (`FakeGoogleDrive`: client
 *   và adapter THẬT trên `Http::fake()` + `Http::preventStrayRequests()`), khoá tài khoản dịch vụ hợp
 *   lệ về hình dạng và quyền tệp (`FakeCredentialFile`), chia sẻ đúng luật. Đĩa `documents_remote`
 *   vẫn là đĩa GIẢ của `tests/Pest.php` trừ khi test gọi `$drive->disk()`.
 * - {@see self::artisan()}: mã thoát và đầu ra của một lệnh.
 * - {@see self::push()}: đẩy một media bằng Action thật mà GIỮ bản trong vùng đệm (khác
 *   `RemoteDocuments::pushToRemote()`, vốn dọn nó).
 */
final class StorageCommandFixtures
{
    /**
     * `$drive`: máy chủ giả đã cài (ví dụ bởi `RemoteDocuments::bindRealDriveAdapter()`). Không cài
     * lần hai: Laravel hỏi các stub `Http::fake()` theo thứ tự đăng ký, nên máy chủ cài sau không bao
     * giờ được hỏi.
     */
    public static function ready(?FakeGoogleDrive $drive = null): FakeGoogleDrive
    {
        $drive ??= FakeGoogleDrive::install();
        app()->instance(DriveTokenProvider::class, FakeGoogleDrive::tokenProvider());
        app()->instance(CredentialFileInspector::class, new FakeCredentialFile);
        app()->instance(FreeSpace::class, new FreeSpace(fn (string $path) => 50 * 1024 ** 3));
        $drive->drive['restrictions']['domainUsersOnly'] = true;

        return $drive;
    }

    /** @return array{0: int, 1: string} */
    public static function artisan(string $command, array $parameters = []): array
    {
        $exit = Artisan::call($command, $parameters);

        return [$exit, Artisan::output()];
    }

    /** Đẩy bằng Action thật (công tắc + mốc phải đã bật); bản trong vùng đệm còn nguyên. */
    public static function push(Media $media): Media
    {
        $outcome = app(PushDocumentFileToRemote::class)->handle($media->getKey());

        if ($outcome !== PushOutcome::Pushed) {
            throw new LogicException("push: media {$media->getKey()} không lên kho: {$outcome->value}.");
        }

        return $media->refresh();
    }

    /** Media đang ở vùng đệm hay kho (đọc thẳng bảng). */
    public static function disk(int $mediaId): ?string
    {
        return StagingFixtures::row($mediaId)?->disk;
    }

    /** @return list<Activity> */
    public static function audits(string $event): array
    {
        return Activity::query()->where('event', $event)->get()->all();
    }

    /** @return list<Request> các request tới máy chủ Drive giả có URL chứa `$contains` (và phương thức, nếu có) */
    public static function requests(string $contains, ?string $method = null): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), $contains)
            && ($method === null || $request->method() === $method))
            ->map(fn (array $pair) => $pair[0])
            ->values()
            ->all();
    }

    /** Request GHI hay XOÁ tới Drive giả (tải lên, thùng rác, đổi tên, chép, tạo thư mục). */
    public static function writeRequests(): array
    {
        return Http::recorded(fn (Request $request) => str_starts_with($request->url(), 'https://www.googleapis.com/')
            && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true))
            ->map(fn (array $pair) => $pair[0]->method().' '.$pair[0]->url())
            ->values()
            ->all();
    }

    /**
     * Tên trên Drive của mọi phiên tải lên đã mở (`POST` tới endpoint upload), theo thứ tự.
     * `$withoutProbes`: bỏ tệp thăm dò `preflight~…` của kiểm tra sẵn sàng.
     *
     * @return list<string>
     */
    public static function uploadedNames(bool $withoutProbes = false): array
    {
        return collect(self::requests('/upload/drive/v3/files', 'POST'))
            ->map(fn (Request $request): string => (string) (json_decode($request->body(), true)['name'] ?? ''))
            ->reject(fn (string $name): bool => $withoutProbes && str_starts_with($name, 'preflight~'))
            ->values()
            ->all();
    }

    /** Thư mục tháng `<YYYY-MM>` trên Drive giả, dưới thư mục gốc; trả mã của nó. */
    public static function monthFolder(FakeGoogleDrive $drive, string $name = '2026-10'): string
    {
        return $drive->putFile($name, '', [FakeGoogleDrive::ROOT_FOLDER_ID], 'application/vnd.google-apps.folder');
    }

    public static function remoteFiles(): array
    {
        return DocumentStore::remote()->allFiles();
    }
}
