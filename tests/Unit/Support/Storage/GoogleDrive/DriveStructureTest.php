<?php

use App\Support\Storage\GoogleDrive\DriveAdapter;
use App\Support\Storage\GoogleDrive\DriveClient;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| M14 Task 2 — test cấu trúc: không quyền chia sẻ, không link Drive (kế hoạch M14, R3, R5)
|--------------------------------------------------------------------------
|
| - Mã không bao giờ tạo, sửa hay xoá quyền chia sẻ: chuỗi `/permissions` chỉ nằm trong
|   `DriveClient::drivePermissions()`, và phương thức đó chỉ gửi `GET`.
| - Không link Drive nào rời máy chủ: không chuỗi nào trong `app/` nhắc tới `webViewLink`,
|   `webContentLink`, `thumbnailLink`, `exportLinks`, `drive.google.com`; adapter không phát URL.
|
| Quét CHUỖI trong mã (token `T_CONSTANT_ENCAPSED_STRING`, `T_ENCAPSED_AND_WHITESPACE`), không quét
| chú thích: docblock được phép giải thích vì sao không bao giờ xin các trường đó.
*/

/**
 * Mọi chuỗi trong mã PHP dưới `app/`, kèm tệp và dòng.
 *
 * @return list<array{file: string, line: int, text: string}>
 */
function appStringLiterals(): array
{
    static $found = null;

    if ($found !== null) {
        return $found;
    }

    $found = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        foreach (token_get_all($file->getContents()) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $found[] = ['file' => $file->getRealPath(), 'line' => $token[2], 'text' => $token[1]];
            }
        }
    }

    return $found;
}

it('chuỗi /permissions chỉ xuất hiện trong DriveClient::drivePermissions()', function () {
    $method = new ReflectionMethod(DriveClient::class, 'drivePermissions');
    $hits = array_values(array_filter(appStringLiterals(), fn (array $literal) => str_contains($literal['text'], '/permissions')));

    expect($hits)->not->toBeEmpty();

    foreach ($hits as $hit) {
        expect($hit['file'])->toBe(realpath($method->getFileName()))
            ->and($hit['line'])->toBeGreaterThanOrEqual($method->getStartLine())
            ->and($hit['line'])->toBeLessThanOrEqual($method->getEndLine());
    }
});

it('drivePermissions() chỉ gửi GET', function () {
    $method = new ReflectionMethod(DriveClient::class, 'drivePermissions');
    $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

    expect($source)->toContain("'GET'")
        ->and($source)->not->toMatch("/'(POST|PATCH|PUT|DELETE)'/")
        ->and($source)->not->toMatch('/->(post|patch|put|delete)\(/i');
});

it('không chuỗi nào trong app/ nhắc tới link Drive', function (string $needle) {
    $hits = array_filter(appStringLiterals(), fn (array $literal) => stripos($literal['text'], $needle) !== false);

    expect(array_map(fn (array $hit) => $hit['file'].':'.$hit['line'], $hits))->toBe([]);
})->with(['webViewLink', 'webContentLink', 'thumbnailLink', 'exportLinks', 'drive.google.com', 'docs.google.com', 'alternateLink']);

it('DriveAdapter không phát URL: không PublicUrlGenerator, không TemporaryUrlGenerator, không getUrl/getTemporaryUrl', function () {
    $adapter = new ReflectionClass(DriveAdapter::class);

    expect($adapter->implementsInterface(PublicUrlGenerator::class))->toBeFalse()
        ->and($adapter->implementsInterface(TemporaryUrlGenerator::class))->toBeFalse();

    foreach (['getUrl', 'getTemporaryUrl', 'publicUrl', 'temporaryUrl', 'url'] as $name) {
        expect($adapter->hasMethod($name))->toBeFalse($name);
    }
});

/*
 * Rà soát Task 1, m6(b): `phpunit.xml` ghim công tắc và các khoá Google, để `.env` cục bộ (gitignored)
 * của máy chạy test — có thể đặt `DOCUMENT_STORAGE=google_drive` hay một đường khoá thật — không lọt
 * vào cấu hình mặc định của tiến trình test.
 */
it('cấu hình mặc định của tiến trình test: kho cục bộ, không khoá Google nào', function () {
    expect(config('vkcrm.storage.driver'))->toBe('local')
        ->and(config('vkcrm.storage.google_drive.credentials_path'))->toBeNull()
        ->and(config('vkcrm.storage.google_drive.shared_drive_id'))->toBeNull()
        ->and(config('vkcrm.storage.google_drive.root_folder_id'))->toBeNull()
        ->and(config('vkcrm.storage.google_drive.allowed_members'))->toBeNull();
});

it('DriveClient không có phương thức nào tạo hay xoá quyền chia sẻ', function () {
    $methods = array_map(fn (ReflectionMethod $method) => strtolower($method->getName()), (new ReflectionClass(DriveClient::class))->getMethods());

    foreach ($methods as $name) {
        expect($name)->not->toMatch('/(share|permission(?!s$))/', $name);
    }

    expect($methods)->toContain('drivepermissions');
});
