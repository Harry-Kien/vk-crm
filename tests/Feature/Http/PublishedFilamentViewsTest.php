<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * SPEC §10 mục 2, phán quyết R4 — ba view Filament GIỮ RIÊNG ở `resources/views/vendor/` để gắn
 * nonce cho thẻ `<script>` nội tuyến (số đo và lý do: docs/research/2026-09-26-csp-khao-sat.md).
 *
 * Một view giữ riêng là một bản sao đóng băng: Filament nâng cấp view gốc, bản sao vẫn là bản cũ
 * và KHÔNG ai được báo. Các test này là lời báo đó:
 *  - "view gốc chưa đổi": băm sha256 của view gốc trong `vendor/` phải đúng băm lúc giữ riêng.
 *    Đỏ nghĩa là Filament đã đổi view gốc — chép lại bản gốc mới, gắn lại nonce cho MỌI thẻ
 *    `<script>`, rồi cập nhật băm ở đây;
 *  - "chỉ khác ở nonce": bỏ khối chú thích đầu tệp và các thuộc tính nonce đi thì bản giữ riêng
 *    phải trùng từng byte với bản gốc — không ai được lén sửa gì khác vào bản sao;
 *  - "Laravel nạp bản giữ riêng": tên view trỏ đúng vào tệp trong `resources/views/vendor/`;
 *  - "đúng 3 view": không có view giữ riêng nào nằm ngoài danh sách được canh ở đây.
 */
const PUBLISHED_FILAMENT_VIEWS = [
    'filament-panels::components.layout.base' => [
        'published' => 'resources/views/vendor/filament-panels/components/layout/base.blade.php',
        'original' => 'vendor/filament/filament/resources/views/components/layout/base.blade.php',
        'sha256' => 'b95a450ddaf68af8b5c71760bcb30a295ec71e7f11fcbd2fe9fc13f25ae26968',
    ],
    'filament-panels::livewire.sidebar' => [
        'published' => 'resources/views/vendor/filament-panels/livewire/sidebar.blade.php',
        'original' => 'vendor/filament/filament/resources/views/livewire/sidebar.blade.php',
        'sha256' => '9d53c25551beb97b9642a04d34cd34a33805de3664bf8d17abbf1ad4b8017a6b',
    ],
    'filament::assets' => [
        'published' => 'resources/views/vendor/filament/assets.blade.php',
        'original' => 'vendor/filament/support/resources/views/assets.blade.php',
        'sha256' => '26bfb0666a21c4456d5ba16d5a4f34e5995b7d470737a0d4969ff95a6d9c8a83',
    ],
];

const CSP_NONCE_ATTRIBUTE = ' nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"';

function normalisedLines(string $path): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(base_path($path)));
}

it('§10.2 giữ riêng đúng 3 view Filament (phán quyết R4: tối đa 8)', function () {
    $published = collect(File::allFiles(resource_path('views/vendor')))
        ->map(fn (SplFileInfo $file): string => 'resources/views/vendor/'.str_replace('\\', '/', $file->getRelativePathname()))
        ->sort()
        ->values()
        ->all();

    expect($published)->toBe(collect(PUBLISHED_FILAMENT_VIEWS)->pluck('published')->sort()->values()->all());
});

it('§10.2 view gốc của Filament chưa đổi kể từ lúc giữ riêng', function (string $view) {
    $entry = PUBLISHED_FILAMENT_VIEWS[$view];

    expect(hash_file('sha256', base_path($entry['original'])))->toBe(
        $entry['sha256'],
        "Filament đã đổi {$entry['original']}. Chép lại bản gốc mới vào {$entry['published']}, gắn lại nonce cho mọi thẻ <script>, rồi cập nhật băm ở đây.",
    );
})->with(array_keys(PUBLISHED_FILAMENT_VIEWS));

it('§10.2 bản giữ riêng chỉ khác bản gốc ở thuộc tính nonce của thẻ script', function (string $view) {
    $entry = PUBLISHED_FILAMENT_VIEWS[$view];
    $published = normalisedLines($entry['published']);
    $withoutHeader = (string) preg_replace('/\A\{\{--.*?--\}\}\n/s', '', $published, 1);

    expect($published)->toStartWith('{{--')
        ->and(substr_count($withoutHeader, '<script'))->toBeGreaterThan(0)
        ->and(substr_count($withoutHeader, '<script'.CSP_NONCE_ATTRIBUTE))->toBe(substr_count($withoutHeader, '<script'))
        ->and(str_replace(CSP_NONCE_ATTRIBUTE, '', $withoutHeader))->toBe(normalisedLines($entry['original']));
})->with(array_keys(PUBLISHED_FILAMENT_VIEWS));

it('§10.2 Laravel nạp bản giữ riêng chứ không phải bản trong vendor', function (string $view) {
    $resolved = str_replace('\\', '/', view()->getFinder()->find($view));

    expect($resolved)->toEndWith(PUBLISHED_FILAMENT_VIEWS[$view]['published']);
})->with(array_keys(PUBLISHED_FILAMENT_VIEWS));
