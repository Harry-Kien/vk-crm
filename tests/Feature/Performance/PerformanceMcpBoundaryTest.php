<?php

use Illuminate\Filesystem\Filesystem;
use Tests\Support\McpSourceScan;

/**
 * M13 Task 1, R13 — số liệu theo dõi đội ngũ và hiệu suất theo người không bao giờ ra ngoài hệ
 * thống qua máy chủ MCP của M11. Đây là dữ liệu cá nhân của nhân sự (R14, Luật 91/2025/QH15); bảng
 * R4 của kế hoạch M11 có thêm dòng "Số liệu theo dõi đội ngũ và hiệu suất theo người
 * (`performance_snapshots`, mọi lớp dưới `App\Actions\Performance` và `App\Support\Performance`) —
 * không bao giờ; không tool" (SPEC §16.4).
 *
 * Test biến dòng đó thành một phép quét mã nguồn: KHÔNG tệp nào chứa mã MCP tham chiếu không gian tên
 * `Performance`, model/bảng ảnh chụp, hay các lớp quy người của M13 (`TeamRoster`,
 * `DeadlineHolderAtDue`, `RequestHolderAt`, `LeadAt`, `DeadlineOutcome`).
 *
 * Từ lúc gộp M13 vào làn M11 (vòng sửa 1 của rà soát cuối M11), danh sách chỗ đặt mã MCP là
 * MỘT danh sách dùng chung với phép quét tiếp nhận và phép quét tiền: `Tests\Support\McpSourceScan`
 * (thư mục tool, presenter, Action, HTTP, view, cộng các tệp MCP đặt tên và mọi tệp `app/` có `Mcp`
 * trong tên). Trước đó tệp này chỉ canh ba thư mục mà kế hoạch M11 nêu, khi chúng còn chưa tồn tại.
 * Hàm quét nhận thư mục và tệp làm tham số và có cặp dương trên một fixture, để "xanh vì rỗng" không
 * thể bị nhầm với "xanh vì hàm quét hỏng".
 *
 * Quét bằng TOKEN chứ không bằng grep (`McpSourceScan::texts()`): tên nằm trong chú thích/docblock
 * (như chính lời giải thích này) không phải một tham chiếu; tên nằm trong tên lớp, `use` (kể cả `use`
 * nhóm), chuỗi ký tự (`'App\Models\PerformanceSnapshot'`, `'performance_snapshots'`) hay một view
 * Blade thì là.
 */
const M13_MCP_FORBIDDEN = [
    'Performance',
    'performance_snapshot',
    'performance_snapshots',
    'TeamRoster',
    'DeadlineHolderAtDue',
    'RequestHolderAt',
    'LeadAt',
    'DeadlineOutcome',
];

/** @return list<string> Thư mục chứa mã MCP của M11 ({@see McpSourceScan::roots()}). */
function m13McpRoots(): array
{
    return McpSourceScan::roots();
}

/** @return list<string> Tệp MCP ngoài các thư mục trên ({@see McpSourceScan::files()}). */
function m13McpFiles(): array
{
    return McpSourceScan::files();
}

/**
 * @param  list<string>  $roots
 * @param  list<string>  $files
 * @return list<string> "đường dẫn: từ khoá" cho mỗi tham chiếu tìm thấy.
 */
function m13McpReferences(array $roots, array $files = []): array
{
    $found = [];

    foreach (McpSourceScan::scannedFiles($roots, $files) as $path) {
        foreach (McpSourceScan::texts($path) as [, $text]) {
            foreach (M13_MCP_FORBIDDEN as $word) {
                if (str_contains($text, $word)) {
                    $found[] = McpSourceScan::shown($path).': '.$word;
                }
            }
        }
    }

    return array_values(array_unique($found));
}

it('finds no reference to team or performance numbers in any MCP tool, presenter, Action, HTTP layer, view or MCP-named file', function () {
    expect(m13McpReferences(m13McpRoots(), m13McpFiles()))->toBe([]);
});

/**
 * Ghim danh sách để một lần sửa nó không im lặng, và đòi mỗi thư mục có tệp: một đường dẫn gõ sai làm
 * phép quét xanh vì rỗng.
 */
it('watches every directory the M11 lane puts MCP code in, and every one of them has files', function () {
    expect(m13McpRoots())->toBe([
        app_path('Mcp'), app_path('Support/Mcp'), app_path('Actions/Mcp'),
        app_path('Http/Middleware/Mcp'), app_path('Http/Controllers/Mcp'), app_path('Http/Responses/Mcp'),
        resource_path('views/mcp'),
    ]);

    foreach (m13McpRoots() as $root) {
        expect(McpSourceScan::scannedFiles([$root]))->not->toBe([], $root);
    }

    expect(count(McpSourceScan::scannedFiles(m13McpRoots(), m13McpFiles())))->toBeGreaterThan(100);
});

it('does detect a reference in a namespace, a use statement, a string or a Blade view, and ignores comments', function () {
    $dir = sys_get_temp_dir().'/vkcrm-m13-mcp-fixture-'.bin2hex(random_bytes(4));
    mkdir($dir.'/Presenters', 0777, true);

    file_put_contents($dir.'/Comment.php', "<?php\n// TeamRoster ở đây chỉ là chú thích\n/** PerformanceSnapshot, performance_snapshots, LeadAt */\nclass A {}\n");
    file_put_contents($dir.'/UseStatement.php', "<?php\nuse App\\Support\\Performance\\TeamRoster;\nclass B {}\n");
    file_put_contents($dir.'/GroupUse.php', "<?php\nuse App\\Support\\Performance\\{LeadAt, RequestHolderAt};\nclass C {}\n");
    file_put_contents($dir.'/Presenters/Str.php', "<?php\nclass D { const T = 'performance_snapshots'; }\n");
    file_put_contents($dir.'/Morph.php', "<?php\nclass E { public function m() { return Relation::getMorphedModel('performance_snapshot'); } }\n");
    file_put_contents($dir.'/Outcome.php', "<?php\nclass F { public function m() { return \\App\\Enums\\DeadlineOutcome::Missed; } }\n");
    file_put_contents($dir.'/Clean.php', "<?php\nuse App\\Models\\Matter;\nclass G { public string \$team = 'team'; public string \$lead = 'lead_lawyer_id'; }\n");
    file_put_contents($dir.'/view.blade.php', "<div>{{ \$row->overdueDeadlines }} {{ \$row->performance_snapshot_id }}</div>\n");
    file_put_contents($dir.'/quiet.blade.php', "{{-- TeamRoster chỉ là chú thích --}}<div>{{ \$matter->code }}</div>\n");

    try {
        $found = m13McpReferences([$dir.'/Presenters'], [
            $dir.'/Comment.php', $dir.'/UseStatement.php', $dir.'/GroupUse.php', $dir.'/Morph.php',
            $dir.'/Outcome.php', $dir.'/Clean.php', $dir.'/view.blade.php', $dir.'/quiet.blade.php',
        ]);
    } finally {
        (new Filesystem)->deleteDirectory($dir);
    }

    $all = implode(' ', $found);

    expect($all)->toContain('UseStatement.php: Performance')
        ->and($all)->toContain('UseStatement.php: TeamRoster')
        ->and($all)->toContain('GroupUse.php: LeadAt')
        ->and($all)->toContain('GroupUse.php: RequestHolderAt')
        ->and($all)->toContain('Str.php: performance_snapshots')
        ->and($all)->toContain('Morph.php: performance_snapshot')
        ->and($all)->toContain('Outcome.php: DeadlineOutcome')
        ->and($all)->toContain('view.blade.php: performance_snapshot')
        ->and($all)->not->toContain('Comment.php')
        ->and($all)->not->toContain('Clean.php')
        ->and($all)->not->toContain('quiet.blade.php');
});
