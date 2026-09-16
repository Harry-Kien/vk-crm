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
it('ghi qua disk private thì disk local không đọc lại được — hai gốc thư mục thật sự rời nhau', function () {
    $relativePath = 'kiem-tra-tach-disk/'.Str::random(16).'.txt';

    Storage::disk('private')->put($relativePath, 'hồ sơ mật');

    try {
        expect(Storage::disk('private')->exists($relativePath))->toBeTrue()
            ->and(Storage::disk('local')->exists($relativePath))->toBeFalse()
            ->and(config('filesystems.disks.private.root'))
            ->not->toBe(config('filesystems.disks.local.root'));
    } finally {
        Storage::disk('private')->deleteDirectory('kiem-tra-tach-disk');
    }
});

it('gốc của disk local không nằm trong gốc của disk private và ngược lại', function () {
    // Lồng nhau cũng là chung: nếu gốc của `local` là thư mục CHA của `private` thì
    // `Storage::disk('local')->get('documents/x.pdf')` vẫn chạm tới tệp.
    $private = rtrim(str_replace('\\', '/', (string) config('filesystems.disks.private.root')), '/');
    $local = rtrim(str_replace('\\', '/', (string) config('filesystems.disks.local.root')), '/');

    expect(str_starts_with($private.'/', $local.'/'))->toBeFalse()
        ->and(str_starts_with($local.'/', $private.'/'))->toBeFalse();
});

it('không disk local nào tự đăng ký route /storage/{path} phục vụ tệp hồ sơ', function () {
    expect(config('filesystems.disks.local.serve'))->not->toBeTrue()
        ->and(config('filesystems.disks.private.serve'))->not->toBeTrue()
        ->and(Route::has('storage.local'))->toBeFalse()
        ->and(Route::has('storage.private'))->toBeFalse();
});

it('không disk nào phát ra được một URL tạm thời tới tệp hồ sơ', function () {
    // `temporaryUrl()` trên một local disk chỉ hoạt động khi `serve => true`; nếu nó ký được một
    // URL thì nghĩa là route tự động kia đang sống lại.
    foreach (['local', 'private'] as $disk) {
        expect(fn () => Storage::disk($disk)->temporaryUrl('bat-ky.pdf', now()->addMinutes(5)))
            ->toThrow(RuntimeException::class);
    }
});

it('thư mục tệp hồ sơ có .htaccess chặn máy chủ web phục vụ trực tiếp (SPEC §10.4)', function () {
    $htaccess = storage_path('app/private/.htaccess');

    expect(file_exists($htaccess))->toBeTrue()
        ->and(strtolower((string) file_get_contents($htaccess)))->toContain('deny');
});
