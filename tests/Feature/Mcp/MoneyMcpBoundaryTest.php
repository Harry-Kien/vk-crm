<?php

use Illuminate\Filesystem\Filesystem;
use Tests\Support\McpSourceScan;

/**
 * SPEC §5, "Mang sang M11" (M9 Task 3, viết lại ở M9 Task 13) — tiền của vụ việc không bao giờ rời
 * hệ thống qua MCP: `contracts`, `instalments`, `payments`, `contract_amendments` và `time_entries`,
 * kể cả với người được xem tiền đó trên web (dữ liệu "tài chính" là dữ liệu nhạy cảm theo Nghị định
 * 356/2025). Đây là việc (3) của đoạn đó: một test cấu trúc khẳng định không tool, presenter hay
 * Action MCP nào tham chiếu năm model ấy. Việc (1) là dòng "Tiền của vụ việc" của bảng R4 trong kế
 * hoạch M11; việc (2) là presenter theo danh sách cho phép, nên không liệt kê là đủ. Lượt quét hành vi
 * cùng ranh giới nằm ở `SensitiveDataSweepTest` (kim tiền trên vụ mở, `Tests\Support\McpSweep`).
 *
 * Phạm vi rộng hơn hai thư mục SPEC nêu tên: MỌI chỗ làn M11 đặt mã MCP, cùng danh sách với phép quét
 * tiếp nhận ({@see McpSourceScan::roots()}, {@see McpSourceScan::files()}).
 *
 * Quét bằng token, không bằng grep: chú thích không phải tham chiếu. Thứ bị cấm, khớp CHÍNH XÁC từng
 * đoạn tên (không phải chuỗi con, vì `Illuminate\Contracts\…` có mặt khắp nơi và không dính gì tới tiền):
 *  - tên lớp: một đoạn của tên (tách theo `\`) là `Contract`, `ContractAmendment`, `Instalment`,
 *    `Payment`, `TimeEntry`, hay bắt đầu bằng một trong chúng rồi tới một chữ HOA (`ContractPolicy`,
 *    `InstalmentStatus`, `PaymentMethod`, `TimeEntryResource`) — `Contracts` thì không;
 *  - tên quan hệ, cột, bảng, bí danh morph, biến: {@see MONEY_MCP_FORBIDDEN_NAMES}, so phân biệt hoa thường;
 *  - trong chuỗi ký tự và văn bản Blade: hai dạng trên đứng như một từ riêng (`'payment.record'`,
 *    `'App\Models\Contract'`, `{{ $matter->contract }}`).
 */
const MONEY_MCP_FORBIDDEN_NAMES = [
    'contract', 'contracts', 'contract_amendment', 'contract_amendments', 'amendments',
    'instalment', 'instalments', 'payment', 'payments',
    'time_entry', 'time_entries', 'timeEntry', 'timeEntries', 'billing',
];

const MONEY_MCP_CLASS_PATTERN = '/^(Contract|ContractAmendment|Instalment|Payment|TimeEntry)([A-Z][A-Za-z0-9_]*)?$/';

/** Một từ tiền trong văn bản tự do (chuỗi ký tự, Blade). */
function moneyMcpWordPattern(): string
{
    $names = implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), MONEY_MCP_FORBIDDEN_NAMES));

    return '/(?<![A-Za-z0-9_])((?:Contract|ContractAmendment|Instalment|Payment|TimeEntry)(?:[A-Z][A-Za-z0-9_]*)?|'.$names.')(?![A-Za-z0-9_])/';
}

/**
 * @param  list<string>  $roots
 * @param  list<string>  $files
 * @return list<string> "đường dẫn: tên" cho mỗi tham chiếu tìm thấy.
 */
function moneyMcpReferences(array $roots, array $files = []): array
{
    $identifiers = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_VARIABLE];
    $found = [];

    foreach (McpSourceScan::scannedFiles($roots, $files) as $path) {
        foreach (McpSourceScan::texts($path) as [$type, $text]) {
            if (in_array($type, $identifiers, true)) {
                foreach (explode('\\', ltrim($text, '$\\')) as $segment) {
                    if (preg_match(MONEY_MCP_CLASS_PATTERN, $segment) === 1 || in_array($segment, MONEY_MCP_FORBIDDEN_NAMES, true)) {
                        $found[] = McpSourceScan::shown($path).': '.$segment;
                    }
                }

                continue;
            }

            if (in_array($type, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML, McpSourceScan::BLADE], true)
                && preg_match_all(moneyMcpWordPattern(), $text, $matches) > 0) {
                foreach ($matches[1] as $word) {
                    $found[] = McpSourceScan::shown($path).': '.$word;
                }
            }
        }
    }

    return array_values(array_unique($found));
}

it('finds no reference to contracts, instalments, payments, amendments or time entries in any MCP file', function () {
    expect(moneyMcpReferences(McpSourceScan::roots(), McpSourceScan::files()))->toBe([]);
});

it('scans the same MCP directories and files as the intake boundary, and every one of them has files', function () {
    foreach (McpSourceScan::roots() as $root) {
        expect(McpSourceScan::scannedFiles([$root]))->not->toBe([], $root);
    }

    expect(count(McpSourceScan::scannedFiles(McpSourceScan::roots(), McpSourceScan::files())))->toBeGreaterThan(100)
        ->and(McpSourceScan::roots())->toContain(app_path('Mcp'), app_path('Support/Mcp'), app_path('Actions/Mcp'));
});

it('detects a money class, relation, column, morph alias or permission in code, strings and Blade, and ignores comments and Illuminate\Contracts', function () {
    $dir = sys_get_temp_dir().'/vkcrm-mcp-money-'.bin2hex(random_bytes(4));
    mkdir($dir.'/Presenters', 0777, true);

    $fixtures = [
        'Comment.php' => "<?php\n// Contract, Payment ở đây chỉ là chú thích\n/** \$matter->contract, time_entries */\nclass A {}\n",
        'Contracts.php' => "<?php\nuse Illuminate\\Contracts\\JsonSchema\\JsonSchema;\nuse Laravel\\Mcp\\Server\\Contracts\\Annotation;\nclass B { const T = 'Illuminate\\Contracts\\Auth'; }\n",
        'UseModel.php' => "<?php\nuse App\\Models\\Instalment;\nclass C {}\n",
        'GroupUse.php' => "<?php\nuse App\\Models\\{Matter, TimeEntry};\nclass D {}\n",
        'Policy.php' => "<?php\nclass E { public function f() { return \\App\\Policies\\ContractPolicy::class; } }\n",
        'Relation.php' => "<?php\nclass F { public function f(\$matter) { return \$matter->contract?->code; } }\n",
        'Variable.php' => "<?php\nclass G { public function f(\$payments) { return 1; } }\n",
        'Morph.php' => "<?php\nclass H { public function f() { return Relation::getMorphedModel('contract_amendment'); } }\n",
        'Permission.php' => "<?php\nclass I { public function f(\$u) { return \$u->can('payment.record'); } }\n",
        'Table.php' => "<?php\nclass J { const T = \"select * from instalments\"; }\n",
        'Clean.php' => "<?php\nuse App\\Models\\Matter;\nclass K { public string \$contractor = 'payable'; }\n",
        'view.blade.php' => "<div>{{ \$matter->contract->total_amount }}</div>\n",
        'quiet.blade.php' => "{{-- Payment chỉ là chú thích --}}<div>{{ \$matter->code }}</div>\n",
    ];

    foreach ($fixtures as $name => $source) {
        file_put_contents($dir.'/'.$name, $source);
    }

    file_put_contents($dir.'/Presenters/Str.php', "<?php\nclass L { const T = 'App\\Models\\Payment'; }\n");

    try {
        $found = moneyMcpReferences([$dir.'/Presenters'], array_map(fn (string $name): string => $dir.'/'.$name, array_keys($fixtures)));
    } finally {
        (new Filesystem)->deleteDirectory($dir);
    }

    $all = implode(' ', $found);

    expect($all)->toContain('UseModel.php: Instalment')
        ->and($all)->toContain('GroupUse.php: TimeEntry')
        ->and($all)->toContain('Policy.php: ContractPolicy')
        ->and($all)->toContain('Relation.php: contract')
        ->and($all)->toContain('Variable.php: payments')
        ->and($all)->toContain('Morph.php: contract_amendment')
        ->and($all)->toContain('Permission.php: payment')
        ->and($all)->toContain('Table.php: instalments')
        ->and($all)->toContain('Str.php: Payment')
        ->and($all)->toContain('view.blade.php: contract')
        ->and($all)->not->toContain('Comment.php')
        ->and($all)->not->toContain('Contracts.php')
        ->and($all)->not->toContain('Clean.php')
        ->and($all)->not->toContain('quiet.blade.php');
});
