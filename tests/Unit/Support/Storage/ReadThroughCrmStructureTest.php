<?php

use App\Actions\Matter\BuildHandoverPackage;
use App\Actions\Matter\CollectHandoverEntries;
use App\Actions\Storage\MaterialiseStoredFile;
use App\Actions\Storage\OpenStoredFile;
use App\Http\Controllers\DocumentDownloadController;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| M14 Task 4 — test cấu trúc: đọc tệp hồ sơ CHỈ qua CRM (kế hoạch M14, R3, R12)
|--------------------------------------------------------------------------
|
| - Không chỗ nào trong `app/` (mã) hay `resources/views` gọi `temporaryUrl(`/`getTemporaryUrl(`:
|   một URL tạm của kho là một đường tới tệp không qua route tải ký.
| - Không câu lệnh nào nhắc tới đĩa kho (`documents_remote`, `DocumentStore::REMOTE_DISK`,
|   `DocumentStore::remote()`) mà gọi `->url(`, `->path(` hay `->temporaryUrl(` trong cùng câu.
| - Một đĩa chọn LÚC CHẠY (`Storage::disk($media->disk)`, có thể là kho) chỉ được hỏi `->path(` hay
|   `->url(` ở đúng những chỗ đã rà: `$disk->path()` trên đĩa không cục bộ trả một chuỗi không tồn
|   tại mà không báo lỗi ("Những chỗ … sẽ cắn"), và `ZipArchive` chỉ hỏng lúc `close()`.
| - Bốn lớp trên đường đọc (route tải, mở luồng, chọn tệp của gói, dựng gói) không hỏi `->path(` của
|   một đĩa, trừ gốc đĩa vùng đệm `private` để đo chỗ trống.
| - `resources/views` không nhắc tới link Drive (Task 2 đã khoá chuỗi trong `app/`).
|
| Quét TOKEN của mã, không quét chú thích: docblock được phép giải thích vì sao.
*/

/**
 * Câu lệnh của một tệp PHP: danh sách token có nghĩa (bỏ khoảng trắng, chú thích), cắt ở `;`, `{`,
 * `}`. Mỗi câu kèm dòng bắt đầu.
 *
 * @return list<array{line: int, tokens: list<string>}>
 */
function rtcStatements(string $path): array
{
    $statements = [];
    $current = [];
    $line = 1;

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }

            if ($current === []) {
                $line = $token[2];
            }

            $current[] = $token[1];

            continue;
        }

        if (in_array($token, [';', '{', '}'], true)) {
            if ($current !== []) {
                $statements[] = ['line' => $line, 'tokens' => $current];
            }

            $current = [];

            continue;
        }

        $current[] = $token;
    }

    if ($current !== []) {
        $statements[] = ['line' => $line, 'tokens' => $current];
    }

    return $statements;
}

/** @return list<string> đường tuyệt đối của mọi tệp PHP dưới `app/` */
function rtcAppFiles(): array
{
    static $files = null;

    return $files ??= array_values(array_map(
        fn ($file) => $file->getRealPath(),
        iterator_to_array(Finder::create()->files()->in(app_path())->name('*.php'), false),
    ));
}

/** Câu lệnh có gọi phương thức `$name` (`->name(`, `?->name(` hay `::name(`). */
function rtcCalls(array $tokens, string $name): bool
{
    foreach ($tokens as $i => $token) {
        if (strcasecmp($token, $name) === 0
            && in_array($tokens[$i - 1] ?? null, ['->', '?->', '::'], true)
            && ($tokens[$i + 1] ?? null) === '(') {
            return true;
        }
    }

    return false;
}

function rtcMentionsRemoteDisk(array $tokens): bool
{
    $text = implode(' ', $tokens);

    return str_contains($text, "'documents_remote'")
        || str_contains($text, '"documents_remote"')
        || str_contains($text, 'REMOTE_DISK')
        || str_contains($text, 'DocumentStore :: remote');
}

/** `Storage::disk(<không phải chuỗi cố định, không phải hằng>)` — một đĩa chọn lúc chạy. */
function rtcRuntimeDisk(array $tokens): bool
{
    foreach ($tokens as $i => $token) {
        if ($token === 'Storage' && ($tokens[$i + 1] ?? null) === '::' && ($tokens[$i + 2] ?? null) === 'disk' && ($tokens[$i + 3] ?? null) === '(') {
            $argument = $tokens[$i + 4] ?? '';

            if (! preg_match('/^[\'"]/', $argument) && $argument !== 'DocumentStore' && $argument !== ')') {
                return true;
            }
        }
    }

    return false;
}

/**
 * @param  callable(array{line: int, tokens: list<string>}): bool  $match
 * @param  list<string>  $files
 * @return list<string> `tệp:dòng` của các câu khớp
 */
function rtcFind(array $files, callable $match): array
{
    $hits = [];

    foreach ($files as $file) {
        foreach (rtcStatements($file) as $statement) {
            if ($match($statement)) {
                $hits[] = str_replace('\\', '/', substr($file, strlen(base_path()) + 1)).':'.$statement['line'];
            }
        }
    }

    return $hits;
}

function rtcRelative(string $class): string
{
    return str_replace('\\', '/', substr((string) (new ReflectionClass($class))->getFileName(), strlen(base_path()) + 1));
}

it('không chỗ nào trong app/ gọi temporaryUrl hay getTemporaryUrl', function () {
    expect(rtcFind(rtcAppFiles(), fn (array $s): bool => rtcCalls($s['tokens'], 'temporaryUrl') || rtcCalls($s['tokens'], 'getTemporaryUrl')))->toBe([]);
});

it('không view nào gọi temporaryUrl hay nhắc tới link Drive', function (string $needle) {
    $hits = [];

    foreach (Finder::create()->files()->in(resource_path('views'))->name('*.php') as $file) {
        if (stripos($file->getContents(), $needle) !== false) {
            $hits[] = $file->getRelativePathname();
        }
    }

    expect($hits)->toBe([]);
})->with(['temporaryUrl(', 'getTemporaryUrl(', 'webViewLink', 'webContentLink', 'drive.google.com']);

it('không câu lệnh nào trên đĩa kho gọi url, path hay temporaryUrl', function () {
    $hits = rtcFind(rtcAppFiles(), fn (array $s): bool => rtcMentionsRemoteDisk($s['tokens'])
        && (rtcCalls($s['tokens'], 'url') || rtcCalls($s['tokens'], 'path') || rtcCalls($s['tokens'], 'temporaryUrl')));

    expect($hits)->toBe([]);
});

it('đĩa chọn lúc chạy (có thể là kho) chỉ bị hỏi path/url ở đúng một chỗ đã rà: nhánh cục bộ của MaterialiseStoredFile', function () {
    $hits = rtcFind(rtcAppFiles(), fn (array $s): bool => rtcRuntimeDisk($s['tokens'])
        && (rtcCalls($s['tokens'], 'url') || rtcCalls($s['tokens'], 'path')));

    // Nhánh đó chạy SAU lần kiểm `$media->disk !== DocumentStore::REMOTE_DISK` (MaterialiseStoredFile).
    expect(array_map(fn (string $hit): string => explode(':', $hit)[0], $hits))->toBe([rtcRelative(MaterialiseStoredFile::class)]);
});

it('route tải, mở luồng, chọn tệp của gói và dựng gói không hỏi ->path() của đĩa, trừ gốc vùng đệm để đo chỗ trống', function (string $class) {
    $hits = rtcFind([(string) (new ReflectionClass($class))->getFileName()], fn (array $s): bool => rtcCalls($s['tokens'], 'path')
        && ! str_contains(implode(' ', $s['tokens']), 'Storage :: disk ( DocumentStore :: STAGING_DISK ) -> path ( \'\' )'));

    expect($hits)->toBe([]);
})->with([
    DocumentDownloadController::class,
    OpenStoredFile::class,
    CollectHandoverEntries::class,
    BuildHandoverPackage::class,
]);

it('tự kiểm: bộ quét bắt được các câu nó phải bắt', function () {
    $probe = tempnam(sys_get_temp_dir(), 'rtc').'.php';
    file_put_contents($probe, <<<'PHP'
        <?php
        // Storage::disk('documents_remote')->url('x'); — chú thích, không tính
        $a = Storage::disk(DocumentStore::REMOTE_DISK)->url('x');
        $b = Storage::disk($media->disk)->path($key);
        $c = $disk->temporaryUrl('x', now());
        $d = DocumentStore::remote()->path('x');
        PHP);

    try {
        $statements = rtcStatements($probe);

        expect(count(array_filter($statements, fn (array $s): bool => rtcMentionsRemoteDisk($s['tokens']) && (rtcCalls($s['tokens'], 'url') || rtcCalls($s['tokens'], 'path')))))->toBe(2)
            ->and(count(array_filter($statements, fn (array $s): bool => rtcRuntimeDisk($s['tokens']))))->toBe(1)
            ->and(count(array_filter($statements, fn (array $s): bool => rtcCalls($s['tokens'], 'temporaryUrl'))))->toBe(1);
    } finally {
        @unlink($probe);
    }
});
