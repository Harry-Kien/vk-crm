<?php

use App\Exceptions\DocumentStorageMisconfigured;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| M14 Task 1 — đĩa `documents_remote` (kế hoạch M14, R3, R7, R8)
|--------------------------------------------------------------------------
|
| Đĩa kho LUÔN có trong cấu hình, kể cả khi công tắc là `local`: media đã đẩy lên kho vẫn phải đọc
| được sau khi ai đó tắt công tắc. Nó không bao giờ phát ra một đường tới tệp ngoài route tải ký
| của CRM: không `serve`, không `url`, không URL tạm. Adapter dựng lười: thiếu cấu hình thì chỉ
| hỏng LÚC DÙNG, với `DocumentStorageMisconfigured`, không hỏng lúc khởi động ứng dụng.
|
| Task 1 cài một adapter GIỮ CHỖ ném `DocumentStorageMisconfigured` ở mọi lời gọi; Task 2 thay
| bằng adapter Drive thật. Không gì ở đây gọi mạng.
|
| Các test đọc đĩa "thật" dựng nó bằng `Storage::build()` từ cấu hình — cùng lý do với
| `PrivateDiskTest`: `tests/Pest.php` thay `documents_remote` bằng một đĩa giả cho mọi test, và
| câu hỏi ở đây là về đĩa mà máy chủ thật dùng.
*/

/**
 * Đĩa kho dựng thẳng từ cấu hình. Tự kiểm cấu hình trước: `Storage::build(null)` dựng một đĩa
 * `local` không gốc thay vì báo lỗi, và mọi test dưới đây sẽ xanh vì một đĩa khác hẳn.
 */
function realDocumentsRemoteDisk(): Filesystem
{
    $config = config('filesystems.disks.documents_remote');

    expect($config)->toBeArray()
        ->and($config['driver'] ?? null)->toBe('google-drive');

    return Storage::build($config);
}

it('đĩa documents_remote dùng driver google-drive và ném lỗi ra ngoài (throw), không thành false lặng lẽ', function () {
    expect(config('filesystems.disks.documents_remote.driver'))->toBe('google-drive')
        ->and(config('filesystems.disks.documents_remote.throw'))->toBeTrue();
});

it('đĩa documents_remote không có serve, url hay root: không route /storage, không URL công khai', function () {
    $disk = config('filesystems.disks.documents_remote');

    expect($disk)->toBeArray()
        ->and($disk)->not->toHaveKey('serve')
        ->and($disk)->not->toHaveKey('url')
        ->and($disk)->not->toHaveKey('root')
        ->and(Route::has('storage.documents_remote'))->toBeFalse();
});

it('đĩa documents_remote thật không phát ra URL nào, kể cả URL tạm', function () {
    $disk = realDocumentsRemoteDisk();

    expect(fn () => $disk->url('18/01k6xq0f9m2y7c4w8r3t5v6n1b.pdf'))->toThrow(RuntimeException::class)
        ->and(fn () => $disk->temporaryUrl('18/01k6xq0f9m2y7c4w8r3t5v6n1b.pdf', now()->addMinutes(5)))
        ->toThrow(RuntimeException::class);
});

it('dựng đĩa documents_remote không ném lỗi dù chưa cấu hình gì (adapter dựng lười)', function () {
    config([
        'vkcrm.storage.google_drive.credentials_path' => null,
        'vkcrm.storage.google_drive.shared_drive_id' => null,
        'vkcrm.storage.google_drive.root_folder_id' => null,
    ]);

    expect(fn () => realDocumentsRemoteDisk())->not->toThrow(Throwable::class);
});

it('adapter giữ chỗ ném DocumentStorageMisconfigured ở mọi lời gọi', function (Closure $call) {
    expect(fn () => $call(realDocumentsRemoteDisk()))->toThrow(DocumentStorageMisconfigured::class);
})->with([
    'exists' => [fn (Filesystem $disk) => $disk->exists('18/a.pdf')],
    // `exists()` hỏi `has()` = `fileExists() || directoryExists()`: chỉ `fileExists` ném thì `exists`
    // vẫn đỏ nhờ `directoryExists`. Hỏi thẳng `fileExists()` để mỗi phương thức của adapter có lời
    // gọi riêng (mutation probe P28 của Task 1 sống sót khi thiếu dòng này).
    'fileExists' => [fn (Filesystem $disk) => $disk->fileExists('18/a.pdf')],
    'missing' => [fn (Filesystem $disk) => $disk->missing('18/a.pdf')],
    'get' => [fn (Filesystem $disk) => $disk->get('18/a.pdf')],
    'readStream' => [fn (Filesystem $disk) => $disk->readStream('18/a.pdf')],
    'put' => [fn (Filesystem $disk) => $disk->put('18/a.pdf', 'x')],
    'writeStream' => [fn (Filesystem $disk) => $disk->writeStream('18/a.pdf', fopen('php://memory', 'r'))],
    'delete' => [fn (Filesystem $disk) => $disk->delete('18/a.pdf')],
    'deleteDirectory' => [fn (Filesystem $disk) => $disk->deleteDirectory('18/')],
    'allFiles' => [fn (Filesystem $disk) => $disk->allFiles('18/')],
    'files' => [fn (Filesystem $disk) => $disk->files('18/')],
    'directories' => [fn (Filesystem $disk) => $disk->directories('18/')],
    'directoryExists' => [fn (Filesystem $disk) => $disk->directoryExists('18')],
    'makeDirectory' => [fn (Filesystem $disk) => $disk->makeDirectory('18')],
    'size' => [fn (Filesystem $disk) => $disk->size('18/a.pdf')],
    'mimeType' => [fn (Filesystem $disk) => $disk->mimeType('18/a.pdf')],
    'lastModified' => [fn (Filesystem $disk) => $disk->lastModified('18/a.pdf')],
    'checksum' => [fn (Filesystem $disk) => $disk->checksum('18/a.pdf')],
    'copy' => [fn (Filesystem $disk) => $disk->copy('18/a.pdf', '18/b.pdf')],
    'move' => [fn (Filesystem $disk) => $disk->move('18/a.pdf', '18/b.pdf')],
    'getVisibility' => [fn (Filesystem $disk) => $disk->getVisibility('18/a.pdf')],
    'setVisibility' => [fn (Filesystem $disk) => $disk->setVisibility('18/a.pdf', 'private')],
]);

/**
 * Nhân chứng của hook `Storage::fake('documents_remote')` trong `tests/Pest.php` (khuôn
 * `PrivateDiskTest`). Tệp này KHÔNG tự gọi `Storage::fake()`, nên `Storage::disk(
 * 'documents_remote')` ở đây là đúng cái hook đặt ra. Gỡ hook thì đĩa này là adapter giữ chỗ (rồi
 * adapter Drive thật từ Task 2), và test đỏ ngay ở `path()`.
 */
it('đĩa documents_remote trong test luôn là đĩa giả, kể cả ở tệp test không tự gọi Storage::fake()', function () {
    expect(Storage::disk('documents_remote')->path('18/ho-so.pdf'))
        ->toStartWith(storage_path('framework/testing/disks'))
        ->and(Storage::disk('documents_remote')->put('18/ho-so.pdf', 'x'))->toBeTrue()
        ->and(Storage::disk('documents_remote')->get('18/ho-so.pdf'))->toBe('x');
});
