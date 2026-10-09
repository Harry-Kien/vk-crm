<?php

use App\Actions\Storage\StorageReadiness;
use App\Support\Files\FreeSpace;
use App\Support\Storage\CredentialFileInspector;
use App\Support\Storage\GoogleDrive\DriveTokenProvider;
use Tests\Support\FakeCredentialFile;
use Tests\Support\FakeGoogleDrive;

/*
|--------------------------------------------------------------------------
| M14 Task 5 — hướng dẫn chủ văn phòng và dòng kiểm của nó viết CÙNG một task (kế hoạch Task 5)
|--------------------------------------------------------------------------
|
| "Mỗi bước của Phụ lục A ghi tên dòng kiểm nó." Test đọc CHÍNH tài liệu rồi so với mã: mọi khoá
| trong cột "Dòng kiểm" là một dòng mà `vkcrm:storage:check` thật sự in (hoặc mục lịch
| `storage.health`), và mọi dòng sẵn sàng đều được ít nhất một bước kiểm. Đổi tên một dòng mà quên
| tài liệu, hay thêm một dòng mà không nói bước nào của chủ văn phòng nó kiểm, đều đỏ ở đây.
*/

function t5GuideRows(): array
{
    $drive = FakeGoogleDrive::install();
    app()->instance(DriveTokenProvider::class, FakeGoogleDrive::tokenProvider());
    app()->instance(CredentialFileInspector::class, new FakeCredentialFile);
    app()->instance(FreeSpace::class, new FreeSpace(fn (string $path) => 1024 ** 3));
    config(['app.env' => 'production', 'vkcrm.storage.driver' => 'google_drive']);

    $readiness = app(StorageReadiness::class);

    return [
        array_column($readiness->rows(), 'key'),
        array_column($readiness->stateRows(), 'key'),
    ];
}

/** @return array<int, string> số bước → ô "Dòng kiểm" của bảng Phụ lục A */
function t5AppendixAChecks(): array
{
    $guide = (string) file_get_contents(base_path('docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md'));
    $section = (string) str($guide)->after('## Phụ lục A')->before("\n## ");

    preg_match_all('/^\|\s*(\d+)\s*\|.*\|\s*([^|]*?)\s*\|\s*$/mu', $section, $matches, PREG_SET_ORDER);

    $checks = [];

    foreach ($matches as $match) {
        $checks[(int) $match[1]] = $match[2];
    }

    return $checks;
}

it('Phụ lục A có đủ 15 bước (0–14), mỗi bước có ô dòng kiểm', function () {
    expect(array_keys(t5AppendixAChecks()))->toBe(range(0, 14));
});

it('mọi khoá trong cột "Dòng kiểm" là một dòng thật của vkcrm:storage:check, hoặc mục lịch storage.health', function () {
    [$readiness, $state] = t5GuideRows();
    $known = [...$readiness, ...$state, 'storage.health'];

    foreach (t5AppendixAChecks() as $step => $cell) {
        preg_match_all('/`([a-z_.]+)`/', $cell, $keys);

        if (trim($cell) === '—') {
            continue;
        }

        expect($keys[1])->not->toBeEmpty("Bước {$step} không ghi dòng kiểm nào.");

        foreach ($keys[1] as $key) {
            expect(in_array($key, $known, true))->toBeTrue("Bước {$step} ghi dòng kiểm `{$key}` không có trong vkcrm:storage:check.");
        }
    }
});

it('mọi dòng sẵn sàng đều có ít nhất một bước của Phụ lục A kiểm nó', function () {
    [$readiness] = t5GuideRows();
    $cells = implode("\n", t5AppendixAChecks());

    foreach ($readiness as $key) {
        expect($cells)->toContain("`{$key}`");
    }
});

it('tài liệu kho có Phụ lục C (sổ tay chuyển đổi) và sổ tay huỷ tệp của R15', function () {
    $guide = (string) file_get_contents(base_path('docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md'));

    expect($guide)->toContain('## Phụ lục C')
        ->toContain('Huỷ tệp của hồ sơ đã quá hạn lưu')
        ->toContain('vkcrm:storage:destruction-list')
        ->toContain('PENDING OWNER');
});

it('dàn ý hồ sơ chuyển dữ liệu ra nước ngoài có đủ 11 mục và câu "không phải tư vấn pháp lý"', function () {
    $outline = (string) file_get_contents(base_path('docs/PHAP-LY-LUU-TRU-NUOC-NGOAI.md'));

    preg_match_all('/^(\d+)\. \*\*/mu', $outline, $items);

    expect($outline)->toContain('không phải tư vấn pháp lý')
        ->and(array_map('intval', $items[1]))->toBe(range(1, 11));
});
