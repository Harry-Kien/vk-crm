<?php

use Illuminate\Filesystem\Filesystem;
use Tests\Support\McpSourceScan;

/**
 * M10 Task 1, R7d — câu chuyện và danh tính của người CHƯA thành khách không bao giờ ra ngoài
 * hệ thống qua máy chủ MCP của M11 (Luật Luật sư Điều 25 giữ bí mật cả với người chưa thành khách;
 * bên thứ ba không thể đồng ý; Nghị định 356/2025 xếp nội dung vụ việc vào dữ liệu nhạy cảm).
 *
 * Bảng R4 của kế hoạch M11 dùng presenter theo danh sách cho phép, nên hai model mới mặc định không
 * ra ngoài. Test này biến "mặc định" thành một phép quét mã nguồn: KHÔNG tệp nào của máy chủ MCP
 * tham chiếu `IntakeRequest`, `IntakeParty`, bí danh morph hay tên bảng của chúng.
 *
 * M11 Task 14 (phán quyết C3 của controller làn m11) nới phạm vi từ ba thư mục kế hoạch M11 đặt tên
 * ra MỌI chỗ làn M11 thật sự đặt mã MCP: thêm middleware, controller, response và view của luồng
 * OAuth/MCP, tệp route `routes/ai.php`, cấu hình `config/mcp.php`, hai trang "Kết nối AI" cùng view
 * của chúng, view khối nháp AI, và mọi tệp PHP khác trong `app/` có `Mcp` trong tên ({@see
 * intakeMcpFiles()}: tệp mới mang tên đó tự vào phép quét). Tiền đề không rỗng: phép quét phải thấy
 * tệp ở mọi chỗ trong danh sách. M11 Task 17: danh sách và cách đọc tệp chuyển sang
 * `Tests\Support\McpSourceScan`, dùng chung với phép quét tiền của vụ việc (`MoneyMcpBoundaryTest`).
 *
 * Quét bằng TOKEN chứ không bằng grep: tên nằm trong chú thích/docblock (như chính lời giải thích
 * này) không phải một tham chiếu; tên nằm trong tên lớp, `use` (kể cả `use` nhóm), hay chuỗi ký tự
 * (`'App\Models\IntakeRequest'`, `'intake_requests'`) thì là. View Blade quét cả phần HTML (sau khi
 * bỏ chú thích `{{-- … --}}`): với bộ tách token của PHP, `{{ $intake_request->story }}` chỉ là HTML.
 */
const INTAKE_MCP_FORBIDDEN = ['IntakeRequest', 'IntakeParty', 'intake_request', 'intake_party', 'intake_requests', 'intake_parties'];

/**
 * @return list<string> Thư mục chứa mã MCP của M11 — một danh sách chung với phép quét tiền của vụ
 *                      việc ({@see McpSourceScan::roots()}).
 */
function intakeMcpRoots(): array
{
    return McpSourceScan::roots();
}

/** @return list<string> Tệp MCP ngoài các thư mục trên ({@see McpSourceScan::files()}). */
function intakeMcpFiles(): array
{
    return McpSourceScan::files();
}

/**
 * @param  list<string>  $roots
 * @param  list<string>  $files
 * @return list<string>
 */
function intakeMcpScannedFiles(array $roots, array $files = []): array
{
    return McpSourceScan::scannedFiles($roots, $files);
}

/**
 * @param  list<string>  $roots
 * @param  list<string>  $files
 * @return list<string> "đường dẫn: từ khoá" cho mỗi tham chiếu tìm thấy.
 */
function intakeMcpReferences(array $roots, array $files = []): array
{
    $found = [];

    foreach (intakeMcpScannedFiles($roots, $files) as $path) {
        foreach (McpSourceScan::texts($path) as [, $text]) {
            foreach (INTAKE_MCP_FORBIDDEN as $word) {
                if (str_contains($text, $word)) {
                    $found[] = McpSourceScan::shown($path).': '.$word;
                }
            }
        }
    }

    return array_values(array_unique($found));
}

it('finds no reference to intake models in any MCP tool, presenter, Action, HTTP layer, view or MCP-named file', function () {
    expect(intakeMcpReferences(intakeMcpRoots(), intakeMcpFiles()))->toBe([]);
});

it('watches every directory the M11 lane puts MCP code in, and every one of them has files', function () {
    expect(intakeMcpRoots())->toBe([
        app_path('Mcp'), app_path('Support/Mcp'), app_path('Actions/Mcp'),
        app_path('Http/Middleware/Mcp'), app_path('Http/Controllers/Mcp'), app_path('Http/Responses/Mcp'),
        resource_path('views/mcp'),
    ]);

    // Tiền đề không rỗng: mỗi thư mục và mỗi tệp đặt tên đều có thật (một đường dẫn gõ sai làm phép
    // quét xanh vì rỗng), và phép quét đi qua đủ nhiều tệp.
    foreach (intakeMcpRoots() as $root) {
        expect(intakeMcpScannedFiles([$root]))->not->toBe([], $root);
    }

    foreach (intakeMcpFiles() as $file) {
        expect(is_file($file))->toBeTrue($file);
    }

    expect(count(intakeMcpScannedFiles(intakeMcpRoots(), intakeMcpFiles())))->toBeGreaterThan(100)
        ->and(intakeMcpFiles())->toContain(app_path('Models/McpConfirmation.php'))
        ->and(intakeMcpFiles())->toContain(app_path('Enums/McpToolOutcome.php'));
});

it('does detect a reference in a class name, a use statement, a string or a Blade view, and ignores comments', function () {
    $dir = sys_get_temp_dir().'/vkcrm-mcp-fixture-'.bin2hex(random_bytes(4));
    mkdir($dir.'/Presenters', 0777, true);

    file_put_contents($dir.'/Comment.php', "<?php\n// IntakeRequest ở đây chỉ là chú thích\n/** IntakeParty, intake_requests */\nclass A {}\n");
    file_put_contents($dir.'/UseStatement.php', "<?php\nuse App\\Models\\IntakeRequest;\nclass B {}\n");
    file_put_contents($dir.'/GroupUse.php', "<?php\nuse App\\Models\\{Client, IntakeParty};\nclass C {}\n");
    file_put_contents($dir.'/Presenters/Str.php', "<?php\nclass D { const T = 'intake_requests'; }\n");
    file_put_contents($dir.'/Morph.php', "<?php\nclass E { public function m() { return Relation::getMorphedModel('intake_party'); } }\n");
    file_put_contents($dir.'/Clean.php', "<?php\nuse App\\Models\\Client;\nclass F { public string \$intake = 'intake'; }\n");
    file_put_contents($dir.'/view.blade.php', "<div>{{ \$intake_request->story }}</div>\n");
    file_put_contents($dir.'/quiet.blade.php', "{{-- IntakeParty chỉ là chú thích --}}<div>{{ \$matter->code }}</div>\n");

    try {
        $found = intakeMcpReferences([$dir.'/Presenters'], [
            $dir.'/Comment.php', $dir.'/UseStatement.php', $dir.'/GroupUse.php', $dir.'/Morph.php', $dir.'/Clean.php',
            $dir.'/view.blade.php', $dir.'/quiet.blade.php',
        ]);
    } finally {
        (new Filesystem)->deleteDirectory($dir);
    }

    $all = implode(' ', $found);

    expect($all)->toContain('UseStatement.php: IntakeRequest')
        ->and($all)->toContain('GroupUse.php: IntakeParty')
        ->and($all)->toContain('Str.php: intake_requests')
        ->and($all)->toContain('Morph.php: intake_party')
        ->and($all)->toContain('view.blade.php: intake_request')
        ->and($all)->not->toContain('Comment.php')
        ->and($all)->not->toContain('Clean.php')
        ->and($all)->not->toContain('quiet.blade.php');
});
