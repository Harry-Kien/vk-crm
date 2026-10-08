<?php

use Illuminate\Filesystem\Filesystem;

/**
 * M13 Task 1, R13 — số liệu theo dõi đội ngũ và hiệu suất theo người không bao giờ ra ngoài hệ
 * thống qua máy chủ MCP của M11. Đây là dữ liệu cá nhân của nhân sự (R14, Luật 91/2025/QH15); bảng
 * R4 của kế hoạch M11 có thêm dòng "Số liệu theo dõi đội ngũ và hiệu suất theo người
 * (`performance_snapshots`, mọi lớp dưới `App\Actions\Performance` và `App\Support\Performance`) —
 * không bao giờ; không tool".
 *
 * Test biến dòng đó thành một phép quét mã nguồn: KHÔNG tệp PHP nào dưới các thư mục MCP tham chiếu
 * không gian tên `Performance`, model/bảng ảnh chụp, hay các lớp quy người của M13 (`TeamRoster`,
 * `DeadlineHolderAtDue`, `RequestHolderAt`, `LeadAt`, `DeadlineOutcome`).
 *
 * Hôm nay (M13 Task 1, nhánh `m13-team-performance`) các thư mục MCP CHƯA tồn tại — M11 chưa gộp —
 * nên test xanh vì rỗng; nó canh từ lúc M11 gộp. Hàm quét nhận thư mục làm tham số và có cặp dương
 * trên một fixture, để "xanh vì rỗng" không thể bị nhầm với "xanh vì hàm quét hỏng". Khuôn chép từ
 * `tests/Feature/Intake/IntakeMcpBoundaryTest.php` của làn M10 (tên hàm/hằng mang tiền tố `m13`,
 * vì sau khi gộp M10 hai tệp cùng tồn tại trong một tiến trình Pest).
 *
 * Quét bằng TOKEN chứ không bằng grep: tên nằm trong chú thích/docblock (như chính lời giải thích
 * này) không phải một tham chiếu; tên nằm trong tên lớp, `use` (kể cả `use` nhóm), hay chuỗi ký tự
 * (`'App\Models\PerformanceSnapshot'`, `'performance_snapshots'`) thì là.
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

/** @return list<string> Thư mục MCP mà kế hoạch M11 đặt tool, presenter và Action đọc. */
function m13McpRoots(): array
{
    return [app_path('Mcp'), app_path('Support/Mcp'), app_path('Actions/Mcp')];
}

/**
 * @param  list<string>  $roots
 * @return list<string> "đường dẫn tương đối: từ khoá" cho mỗi tham chiếu tìm thấy.
 */
function m13McpReferences(array $roots): array
{
    $found = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach ((new Filesystem)->allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (! is_array($token)) {
                    continue;
                }

                [$id, $text] = $token;

                if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_INLINE_HTML], true)) {
                    continue;
                }

                foreach (M13_MCP_FORBIDDEN as $word) {
                    if (str_contains($text, $word)) {
                        $found[] = str_replace('\\', '/', $file->getRelativePathname()).': '.$word;
                    }
                }
            }
        }
    }

    return array_values(array_unique($found));
}

it('finds no reference to team or performance numbers in any MCP tool, presenter or reader Action', function () {
    expect(m13McpReferences(m13McpRoots()))->toBe([]);
});

/**
 * Hôm nay các thư mục chưa tồn tại nên phép quét ở trên rỗng dù danh sách thư mục sai; ghim danh
 * sách để một lần sửa nó không im lặng (kế hoạch M11: tool ở `app/Mcp`, presenter ở
 * `app/Support/Mcp`, Action đọc ở `app/Actions/Mcp`).
 */
it('watches every directory the M11 plan puts MCP code in', function () {
    expect(m13McpRoots())->toBe([app_path('Mcp'), app_path('Support/Mcp'), app_path('Actions/Mcp')]);
});

it('does detect a reference in a namespace, a use statement or a string, and ignores comments', function () {
    $dir = sys_get_temp_dir().'/vkcrm-m13-mcp-fixture-'.bin2hex(random_bytes(4));
    mkdir($dir.'/Presenters', 0777, true);

    file_put_contents($dir.'/Comment.php', "<?php\n// TeamRoster ở đây chỉ là chú thích\n/** PerformanceSnapshot, performance_snapshots, LeadAt */\nclass A {}\n");
    file_put_contents($dir.'/UseStatement.php', "<?php\nuse App\\Support\\Performance\\TeamRoster;\nclass B {}\n");
    file_put_contents($dir.'/GroupUse.php', "<?php\nuse App\\Support\\Performance\\{LeadAt, RequestHolderAt};\nclass C {}\n");
    file_put_contents($dir.'/Presenters/Str.php', "<?php\nclass D { const T = 'performance_snapshots'; }\n");
    file_put_contents($dir.'/Morph.php', "<?php\nclass E { public function m() { return Relation::getMorphedModel('performance_snapshot'); } }\n");
    file_put_contents($dir.'/Outcome.php', "<?php\nclass F { public function m() { return \\App\\Enums\\DeadlineOutcome::Missed; } }\n");
    file_put_contents($dir.'/Clean.php', "<?php\nuse App\\Models\\Matter;\nclass G { public string \$team = 'team'; public string \$lead = 'lead_lawyer_id'; }\n");

    try {
        $found = m13McpReferences([$dir]);
    } finally {
        (new Filesystem)->deleteDirectory($dir);
    }

    expect($found)->toContain('UseStatement.php: Performance')
        ->and($found)->toContain('UseStatement.php: TeamRoster')
        ->and($found)->toContain('GroupUse.php: LeadAt')
        ->and($found)->toContain('GroupUse.php: RequestHolderAt')
        ->and($found)->toContain('Presenters/Str.php: performance_snapshots')
        ->and($found)->toContain('Morph.php: performance_snapshot')
        ->and($found)->toContain('Outcome.php: DeadlineOutcome')
        ->and(implode(' ', $found))->not->toContain('Comment.php')
        ->and(implode(' ', $found))->not->toContain('Clean.php');
});
