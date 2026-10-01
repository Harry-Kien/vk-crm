<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Disk `private` là nơi mọi tệp hồ sơ nằm (SPEC §10.4), và cả milestone này được viết trên tiền
 * đề: đường duy nhất tới một tệp là route có chữ ký của `DocumentDownloadController`, route đó
 * vẫn kiểm tra policy và vẫn ghi `document_downloads`.
 *
 * Trước task rà soát này tiền đề đó SAI, dù bình luận cấu hình và commit message đều nói ngược
 * lại: `private` và `local` trỏ vào CÙNG một thư mục `storage/app/private`, còn `local` bật
 * `serve => true`. Cờ đó làm `FilesystemServiceProvider` tự đăng ký route `storage.local`
 * (`GET`/`PUT /storage/{path}`) phục vụ MỌI tệp trên disk chỉ với một chữ ký hợp lệ của
 * framework — không policy, không kiểm tra sở hữu, không dòng `document_downloads` nào.
 *
 * Các test dưới đây kiểm chính cái tiền đề, không kiểm câu chữ của bình luận: một bình luận nói
 * "hai disk đã tách" là thứ đọc thấy đúng cả khi nó sai.
 */
/**
 * **Test này cố ý đi RA NGOÀI đĩa giả toàn cục của `tests/Pest.php`, và đây là chỗ duy nhất
 * trong bộ test được phép làm vậy.** Câu hỏi nó hỏi là về CẤU HÌNH thật của hai đĩa; hỏi nó
 * trên `Storage::disk('private')` sau khi hook toàn cục đã thay đĩa đó bằng một gốc trong
 * `storage/framework/testing` là hỏi về một đĩa không tồn tại ngoài đời — và câu trả lời "local
 * không đọc được" sẽ đúng vì một lý do khác hẳn lý do cần đo.
 *
 * `Storage::build()` dựng đĩa thẳng từ mảng cấu hình, nên nó không đi qua `FilesystemManager`
 * và không thấy bản giả. Thư mục thử được xoá trong `finally`, nên test này không để lại tệp
 * nào trong kho hồ sơ thật.
 */
it('§10.4 ghi qua disk private thì disk local không đọc lại được — hai gốc thư mục thật sự rời nhau', function () {
    $relativePath = 'kiem-tra-tach-disk/'.Str::random(16).'.txt';

    $private = Storage::build(config('filesystems.disks.private'));
    $local = Storage::build(config('filesystems.disks.local'));

    $private->put($relativePath, 'hồ sơ mật');

    try {
        expect($private->exists($relativePath))->toBeTrue()
            ->and($local->exists($relativePath))->toBeFalse()
            ->and(config('filesystems.disks.private.root'))
            ->not->toBe(config('filesystems.disks.local.root'));
    } finally {
        $private->deleteDirectory('kiem-tra-tach-disk');
    }
});

/**
 * Nhân chứng của hook `Storage::fake('private')` trong `tests/Pest.php`.
 *
 * Tệp test này KHÔNG tự gọi `Storage::fake()` ở `beforeEach`, nên `Storage::disk('private')`
 * ở đây là đúng cái mà hook toàn cục đặt ra. Gỡ hook đi thì test này đỏ — đó là toàn bộ việc
 * của nó. Nó KHÔNG chứng minh được "không test nào ghi vào kho thật" (không test nào chứng
 * minh nổi câu đó từ bên trong bộ test); thứ chứng minh câu đó là chính cái hook, và đây là
 * thứ giữ cho cái hook không biến mất trong im lặng.
 */
it('đĩa private trong test luôn là đĩa giả, kể cả ở tệp test không tự gọi Storage::fake()', function () {
    expect(Storage::disk('private')->path('ho-so.pdf'))
        ->toStartWith(storage_path('framework/testing/disks'))
        ->and(storage_path('app/private'))
        ->not->toStartWith(storage_path('framework/testing/disks'));
});

it('§10.4 gốc của disk local không nằm trong gốc của disk private và ngược lại', function () {
    // Lồng nhau cũng là chung: nếu gốc của `local` là thư mục CHA của `private` thì
    // `Storage::disk('local')->get('documents/x.pdf')` vẫn chạm tới tệp.
    $private = rtrim(str_replace('\\', '/', (string) config('filesystems.disks.private.root')), '/');
    $local = rtrim(str_replace('\\', '/', (string) config('filesystems.disks.local.root')), '/');

    expect(str_starts_with($private.'/', $local.'/'))->toBeFalse()
        ->and(str_starts_with($local.'/', $private.'/'))->toBeFalse();
});

it('§10.4 không disk local nào tự đăng ký route /storage/{path} phục vụ tệp hồ sơ', function () {
    expect(config('filesystems.disks.local.serve'))->not->toBeTrue()
        ->and(config('filesystems.disks.private.serve'))->not->toBeTrue()
        ->and(Route::has('storage.local'))->toBeFalse()
        ->and(Route::has('storage.private'))->toBeFalse();
});

it('§10.4 không disk nào phát ra được một URL tạm thời tới tệp hồ sơ', function () {
    // `temporaryUrl()` trên một local disk chỉ hoạt động khi `serve => true`; nếu nó ký được một
    // URL thì nghĩa là route tự động kia đang sống lại.
    //
    // Dựng đĩa từ CẤU HÌNH chứ không lấy qua `Storage::disk()`, cùng lý do với test đầu tệp này:
    // hook toàn cục ở `tests/Pest.php` thay `private` bằng một đĩa giả, và đĩa giả của Laravel
    // thì CÓ phát ra URL tạm (đo được: `http://localhost/x.pdf?expiration=…`). Hỏi nó là hỏi
    // nhầm đối tượng — câu hỏi ở đây là về đĩa mà máy chủ thật đang chạy.
    foreach (['local', 'private'] as $disk) {
        $real = Storage::build(config('filesystems.disks.'.$disk));

        expect(fn () => $real->temporaryUrl('bat-ky.pdf', now()->addMinutes(5)))
            ->toThrow(RuntimeException::class);
    }
});

it('§10.4 thư mục tệp hồ sơ có .htaccess chặn máy chủ web phục vụ trực tiếp', function () {
    $htaccess = storage_path('app/private/.htaccess');

    expect(file_exists($htaccess))->toBeTrue()
        ->and(strtolower((string) file_get_contents($htaccess)))->toContain('deny');
});

/**
 * M8 Task 4 — "không có symlink nào khác phát tệp đó". Hai đường một symlink có thể mở kho tệp hồ
 * sơ ra document root: một liên kết khai ở `filesystems.links` (thứ `php artisan storage:link` tạo
 * ra) trỏ vào — hay trỏ vào thư mục CHA của — `storage/app/private`; hoặc một symlink ai đó tạo tay
 * dưới `public/`. Test đo cả hai trên cây thư mục thật (mọi thư mục của `public/` đều dưới 40 mục,
 * nên phép duyệt không vướng lỗi `rewinddir` của ổ 9p mà `bin/container-test` ghi lại).
 *
 * Tầng máy chủ web nằm ngoài tầm với của test: `vkcrm:preflight` dò `/storage/app/private/<tệp>`
 * và `/storage/<tệp>` qua `APP_URL` trên máy thật, và mẫu `tools/deploy/` chặn `/storage/` — đã chạy
 * thật trong container `nginx`/`httpd` chính thức (`tools/deploy/verify-storage-blocked.sh`, Ghi chú
 * M8, Task 4).
 */
it('§10.4 không liên kết storage:link nào và không symlink nào dưới public/ trỏ vào kho tệp hồ sơ', function () {
    $private = rtrim(str_replace('\\', '/', (string) realpath(storage_path('app/private'))), '/').'/';

    foreach ((array) config('filesystems.links') as $target) {
        $target = rtrim(str_replace('\\', '/', (string) $target), '/').'/';

        expect(str_starts_with($target, $private))->toBeFalse("storage:link trỏ vào kho tệp hồ sơ: {$target}")
            ->and(str_starts_with($private, $target))->toBeFalse("storage:link trỏ vào thư mục cha của kho tệp hồ sơ: {$target}");
    }

    $intoPrivate = [];
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(public_path(), FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($entries as $entry) {
        $real = $entry->isLink() ? realpath($entry->getPathname()) : false;

        // Không phải symlink, hoặc symlink gãy (không trỏ tới đâu, nên không phát được gì).
        if ($real === false) {
            continue;
        }

        $resolved = rtrim(str_replace('\\', '/', $real), '/').'/';

        if (str_starts_with($resolved, $private) || str_starts_with($private, $resolved)) {
            $intoPrivate[] = $entry->getPathname().' -> '.$resolved;
        }
    }

    expect($intoPrivate)->toBe([]);
});
