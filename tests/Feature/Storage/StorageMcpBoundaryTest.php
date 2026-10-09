<?php

use Illuminate\Filesystem\Filesystem;
use Tests\Support\McpSourceScan;

/**
 * Gộp M14 vào `main` (sau M11) — kho tài liệu Google Drive không bao giờ ra ngoài hệ thống qua máy chủ
 * MCP. Bảng R4 của M11 (SPEC §16.4) đã có dòng "Nội dung tệp, đường tải — chỉ metadata, không URL ký";
 * M14 thêm một nơi tệp nằm (Shared Drive "Kho") và dữ liệu mới về nơi đó: chỉ mục `drive_objects`,
 * `drive_folders`, bốn cột kho của `media`, biên nhận văn phòng. SPEC §16.4 có thêm dòng "Kho tài liệu
 * (M14)", và test này biến dòng đó thành một phép quét mã nguồn: KHÔNG tệp nào chứa mã MCP tham chiếu
 * đĩa, kho, Drive, luồng tệp hay medialibrary — tức `list_documents`/`fetch` không thể trả mã tệp Drive,
 * URL Drive hay nội dung tệp, vì mã MCP không chạm được tới chúng.
 *
 * Cùng tập mã nguồn và cùng cách quét bằng TOKEN với phép quét tiền, tiếp nhận và số liệu đội ngũ
 * ({@see McpSourceScan}): tên nằm trong chú thích không phải tham chiếu; tên trong `use`, tên lớp, chuỗi
 * hay view Blade thì là. Không có từ thường `drive` (khớp `driver`, như guard `passport`); `drive_` bắt
 * tên bảng và cột.
 */
const M14_MCP_FORBIDDEN = [
    'Drive',
    'drive_',
    'documents_remote',
    'DocumentStore',
    'StoredFile',
    'Storage',
    'Media',
    'media',
    'OfficeReceipt',
    'office_receipt',
];

/**
 * @param  list<string>  $roots
 * @param  list<string>  $files
 * @return list<string> "đường dẫn: từ khoá" cho mỗi tham chiếu tìm thấy.
 */
function m14McpReferences(array $roots, array $files = []): array
{
    $found = [];

    foreach (McpSourceScan::scannedFiles($roots, $files) as $path) {
        foreach (McpSourceScan::texts($path) as [, $text]) {
            foreach (M14_MCP_FORBIDDEN as $word) {
                if (str_contains($text, $word)) {
                    $found[] = McpSourceScan::shown($path).': '.$word;
                }
            }
        }
    }

    return array_values(array_unique($found));
}

it('finds no reference to the document store, Drive, file streams or medialibrary in any MCP tool, presenter, Action, HTTP layer, view or MCP-named file', function () {
    expect(m14McpReferences(McpSourceScan::roots(), McpSourceScan::files()))->toBe([]);

    // Tiền đề: phép quét đọc đúng tập mã MCP, không xanh vì rỗng.
    expect(count(McpSourceScan::scannedFiles(McpSourceScan::roots(), McpSourceScan::files())))->toBeGreaterThan(100);
});

it('does detect a store reference in a use statement, a string or a Blade view, and ignores comments and "driver"', function () {
    $dir = sys_get_temp_dir().'/vkcrm-m14-mcp-fixture-'.bin2hex(random_bytes(4));
    mkdir($dir.'/Presenters', 0777, true);

    file_put_contents($dir.'/Comment.php', "<?php\n// DriveObject, documents_remote ở đây chỉ là chú thích\n/** OpenStoredFile, getFirstMedia */\nclass A {}\n");
    file_put_contents($dir.'/UseStatement.php', "<?php\nuse App\\Actions\\Storage\\OpenStoredFile;\nclass B {}\n");
    file_put_contents($dir.'/Presenters/Str.php', "<?php\nclass C { const T = 'drive_objects'; }\n");
    file_put_contents($dir.'/Disk.php', "<?php\nclass D { public function m() { return \\Illuminate\\Support\\Facades\\Storage::disk('documents_remote'); } }\n");
    file_put_contents($dir.'/MediaCall.php', "<?php\nclass E { public function m(\$d) { return \$d->getFirstMedia('file'); } }\n");
    file_put_contents($dir.'/Clean.php', "<?php\nclass G { public string \$guard = 'driver'; public string \$code = 'matter'; }\n");
    file_put_contents($dir.'/view.blade.php', "<div>{{ \$row->drive_file_id }}</div>\n");
    file_put_contents($dir.'/quiet.blade.php', "{{-- DocumentStore chỉ là chú thích --}}<div>{{ \$matter->code }}</div>\n");

    try {
        $found = m14McpReferences([$dir.'/Presenters'], [
            $dir.'/Comment.php', $dir.'/UseStatement.php', $dir.'/Disk.php', $dir.'/MediaCall.php',
            $dir.'/Clean.php', $dir.'/view.blade.php', $dir.'/quiet.blade.php',
        ]);
    } finally {
        (new Filesystem)->deleteDirectory($dir);
    }

    $all = implode(' ', $found);

    expect($all)->toContain('UseStatement.php: Storage')
        ->and($all)->toContain('UseStatement.php: StoredFile')
        ->and($all)->toContain('Str.php: drive_')
        ->and($all)->toContain('Disk.php: Storage')
        ->and($all)->toContain('Disk.php: documents_remote')
        ->and($all)->toContain('MediaCall.php: Media')
        ->and($all)->toContain('view.blade.php: drive_')
        ->and($all)->not->toContain('Comment.php')
        ->and($all)->not->toContain('Clean.php')
        ->and($all)->not->toContain('quiet.blade.php');
});
