<?php

use Illuminate\Filesystem\Filesystem;

/**
 * M10 Task 1, R7d — câu chuyện và danh tính của người CHƯA thành khách không bao giờ ra ngoài
 * hệ thống qua máy chủ MCP của M11 (Luật Luật sư Điều 25 giữ bí mật cả với người chưa thành khách;
 * bên thứ ba không thể đồng ý; Nghị định 356/2025 xếp nội dung vụ việc vào dữ liệu nhạy cảm).
 *
 * Bảng R4 của kế hoạch M11 dùng presenter theo danh sách cho phép, nên hai model mới mặc định không
 * ra ngoài. Test này biến "mặc định" thành một phép quét mã nguồn: KHÔNG tệp PHP nào dưới các thư
 * mục MCP tham chiếu `IntakeRequest`, `IntakeParty`, bí danh morph hay tên bảng của chúng.
 *
 * Hôm nay (M10) các thư mục này CHƯA tồn tại — M11 dựng SAU — nên test xanh vì rỗng; nó canh từ
 * lúc M11 thêm tệp đầu tiên. Hàm quét nhận thư mục làm tham số và có cặp dương trên một fixture,
 * để "xanh vì rỗng" không thể bị nhầm với "xanh vì hàm quét hỏng".
 *
 * Quét bằng TOKEN chứ không bằng grep: tên nằm trong chú thích/docblock (như chính lời giải thích
 * này) không phải một tham chiếu; tên nằm trong tên lớp, `use` (kể cả `use` nhóm), hay chuỗi ký tự
 * (`'App\Models\IntakeRequest'`, `'intake_requests'`) thì là.
 */
const INTAKE_MCP_FORBIDDEN = ['IntakeRequest', 'IntakeParty', 'intake_request', 'intake_party', 'intake_requests', 'intake_parties'];

/** @return list<string> Thư mục MCP mà kế hoạch M11 đặt tool, presenter và Action đọc. */
function intakeMcpRoots(): array
{
    return [app_path('Mcp'), app_path('Support/Mcp'), app_path('Actions/Mcp')];
}

/**
 * @param  list<string>  $roots
 * @return list<string> "đường dẫn tương đối: từ khoá" cho mỗi tham chiếu tìm thấy.
 */
function intakeMcpReferences(array $roots): array
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

                foreach (INTAKE_MCP_FORBIDDEN as $word) {
                    if (str_contains($text, $word)) {
                        $found[] = $file->getRelativePathname().': '.$word;
                    }
                }
            }
        }
    }

    return array_values(array_unique($found));
}

it('finds no reference to intake models in any MCP tool, presenter or reader Action', function () {
    expect(intakeMcpReferences(intakeMcpRoots()))->toBe([]);
});

/**
 * Hôm nay các thư mục chưa tồn tại nên phép quét ở trên rỗng dù danh sách thư mục sai; ghim danh
 * sách để một lần sửa nó không im lặng (kế hoạch M11: tool ở `app/Mcp`, presenter ở
 * `app/Support/Mcp`, Action đọc ở `app/Actions/Mcp`).
 */
it('watches every directory the M11 plan puts MCP code in', function () {
    expect(intakeMcpRoots())->toBe([app_path('Mcp'), app_path('Support/Mcp'), app_path('Actions/Mcp')]);
});

it('does detect a reference in a class name, a use statement or a string, and ignores comments', function () {
    $dir = sys_get_temp_dir().'/vkcrm-mcp-fixture-'.bin2hex(random_bytes(4));
    mkdir($dir.'/Presenters', 0777, true);

    file_put_contents($dir.'/Comment.php', "<?php\n// IntakeRequest ở đây chỉ là chú thích\n/** IntakeParty, intake_requests */\nclass A {}\n");
    file_put_contents($dir.'/UseStatement.php', "<?php\nuse App\\Models\\IntakeRequest;\nclass B {}\n");
    file_put_contents($dir.'/GroupUse.php', "<?php\nuse App\\Models\\{Client, IntakeParty};\nclass C {}\n");
    file_put_contents($dir.'/Presenters/Str.php', "<?php\nclass D { const T = 'intake_requests'; }\n");
    file_put_contents($dir.'/Morph.php', "<?php\nclass E { public function m() { return Relation::getMorphedModel('intake_party'); } }\n");
    file_put_contents($dir.'/Clean.php', "<?php\nuse App\\Models\\Client;\nclass F { public string \$intake = 'intake'; }\n");

    try {
        $found = intakeMcpReferences([$dir]);
    } finally {
        (new Filesystem)->deleteDirectory($dir);
    }

    expect($found)->toContain('UseStatement.php: IntakeRequest')
        ->and($found)->toContain('GroupUse.php: IntakeParty')
        ->and($found)->toContain('Presenters/Str.php: intake_requests')
        ->and($found)->toContain('Morph.php: intake_party')
        ->and(implode(' ', $found))->not->toContain('Comment.php')
        ->and(implode(' ', $found))->not->toContain('Clean.php');
});
