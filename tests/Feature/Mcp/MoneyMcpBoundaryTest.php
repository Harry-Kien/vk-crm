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
 * Quét bằng token, không bằng grep: chú thích không phải tham chiếu. Đơn vị so là từng ĐOẠN tên — tên
 * lớp tách theo `\` (`App\Support\Billing\BillingSummary` → `App`, `Support`, `Billing`,
 * `BillingSummary`), tên hàm, quan hệ, hằng, biến nguyên khối; chuỗi ký tự và văn bản Blade tách thành
 * các từ `[A-Za-z0-9_]+` (`'stageLogs.triggeredInstalments'` → `stageLogs`, `triggeredInstalments`).
 * Một đoạn bị cấm khi nó CHỨA, ở bất kỳ vị trí nào và không phân biệt hoa thường, một từ của
 * {@see MONEY_MCP_WORDS}. Nên bị bắt cùng một luật:
 *  - tên lớp tiền: `Contract`, `PaymentMethod`, `TimeEntryResource`, `ClientBillingStatement`,
 *    `Receivables`, `RevenueFilters`;
 *  - không gian tên tiền: `App\Actions\Billing`, `App\Support\Billing` — mọi `use` một lớp ở đó, kể cả
 *    lớp tên trung tính như `Money`, `Vat`;
 *  - quan hệ và hàm mang từ tiền ở giữa: `triggeredInstalments`, `paymentReceipts`,
 *    `contractAmendments`, `attributedPayments`, `isReferencedByBillingRecord`;
 *  - cột, bảng, bí danh morph, quyền, biến: `instalments`, `contract_amendment`, `'payment.record'`,
 *    `$payments`, `{{ $matter->contract }}`.
 *
 * Miễn trừ DUY NHẤT ({@see MONEY_MCP_EXEMPT_SEGMENT}): đoạn đúng bằng `Contracts`, so phân biệt hoa
 * thường — không gian tên giao diện của Laravel và laravel/mcp (`Illuminate\Contracts\…`,
 * `Laravel\Mcp\Server\Contracts\…`) có mặt khắp nơi và không dính gì tới tiền. Cái giá của phép so
 * chuỗi con là một tên vô hại đôi khi cũng bị bắt (`$contractor`); mã MCP chỉ cần đặt tên khác — một
 * lần bỏ sót tiền đắt hơn nhiều.
 */
const MONEY_MCP_WORDS = ['contract', 'instalment', 'installment', 'payment', 'amendment', 'time_entr', 'timeentr', 'billing', 'revenue', 'receivable'];

const MONEY_MCP_EXEMPT_SEGMENT = 'Contracts';

/** Đoạn tên (hay từ trong chuỗi/Blade) có chứa một từ tiền hay không. */
function isMoneyMcpSegment(string $segment): bool
{
    if ($segment === MONEY_MCP_EXEMPT_SEGMENT) {
        return false;
    }

    $lower = strtolower($segment);

    foreach (MONEY_MCP_WORDS as $word) {
        if (str_contains($lower, $word)) {
            return true;
        }
    }

    return false;
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
                    if (isMoneyMcpSegment($segment)) {
                        $found[] = McpSourceScan::shown($path).': '.$segment;
                    }
                }

                continue;
            }

            if (in_array($type, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML, McpSourceScan::BLADE], true)
                && preg_match_all('/[A-Za-z0-9_]+/', $text, $matches) > 0) {
                foreach ($matches[0] as $word) {
                    if (isMoneyMcpSegment($word)) {
                        $found[] = McpSourceScan::shown($path).': '.$word;
                    }
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
        'Clean.php' => "<?php\nuse App\\Models\\Matter;\nclass K { public string \$contact = 'payable'; }\n",
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

it('detects a money word anywhere inside a name — Billing/Revenue/Receivable classes and namespaces, relations like triggeredInstalments — and still ignores the Contracts namespace', function () {
    $dir = sys_get_temp_dir().'/vkcrm-mcp-money-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    $fixtures = [
        'Outstanding.php' => "<?php\nuse App\Support\Billing\BillingSummary;\nclass M { public function f(\$m) { return ['outstanding' => BillingSummary::outstandingForMatter(\$m->id)['amount']]; } }\n",
        'Statement.php' => "<?php\nclass N { public function f() { return ClientBillingStatement::class; } }\n",
        'Receivables.php' => "<?php\nclass O { public function f() { return new Receivables; } }\n",
        'MoneyUse.php' => "<?php\nuse App\Support\Billing\Money;\nclass P {}\n",
        'Revenue.php' => "<?php\nclass Q { public function f() { return RevenueFilters::make(); } }\n",
        'Record.php' => "<?php\nclass R { public function f() { return \App\Actions\Billing\RecordPayment::class; } }\n",
        'Triggered.php' => "<?php\nclass S { public function f(\$stageLog) { return \$stageLog->triggeredInstalments; } }\n",
        'Receipts.php' => "<?php\nclass T { public function f(\$document) { return \$document->paymentReceipts()->count(); } }\n",
        'Amendments.php' => "<?php\nclass U { public function f(\$document) { return \$document->contractAmendments; } }\n",
        'Attributed.php' => "<?php\nclass V { public function f(\$user) { return \$user->attributedPayments; } }\n",
        'Referenced.php' => "<?php\nclass W { public function f(\$document) { return \$document->isReferencedByBillingRecord(); } }\n",
        'Eager.php' => "<?php\nclass X { public function f(\$q) { return \$q->with('stageLogs.triggeredInstalments'); } }\n",
        'triggered.blade.php' => "<div>{{ \$log->triggeredInstalments->count() }}</div>\n",
        'AmendmentVar.php' => "<?php\nclass AA { public function f(\$amendments) { return 1; } }\n",
        'Installment.php' => "<?php\nclass AB { public function f() { return InstallmentPlan::class; } }\n",
        'TimeTable.php' => "<?php\nclass AC { public function f() { return DB::table('time_entries')->count(); } }\n",
        'Contractor.php' => "<?php\nclass Z { public function f(\$contractor) { return 1; } }\n",
        'Namespaces.php' => "<?php\nuse Illuminate\Contracts\Auth\Authenticatable;\nuse Illuminate\Contracts\{Foundation\Application};\nclass Y { const T = 'Illuminate\Contracts\Auth'; }\n",
    ];

    foreach ($fixtures as $name => $source) {
        file_put_contents($dir.'/'.$name, $source);
    }

    try {
        $found = moneyMcpReferences([], array_map(fn (string $name): string => $dir.'/'.$name, array_keys($fixtures)));
    } finally {
        (new Filesystem)->deleteDirectory($dir);
    }

    $all = implode(' ', $found);

    expect($all)->toContain('Outstanding.php: Billing')
        ->and($all)->toContain('Outstanding.php: BillingSummary')
        ->and($all)->toContain('Statement.php: ClientBillingStatement')
        ->and($all)->toContain('Receivables.php: Receivables')
        ->and($all)->toContain('MoneyUse.php: Billing')
        ->and($all)->toContain('Revenue.php: RevenueFilters')
        ->and($all)->toContain('Record.php: RecordPayment')
        ->and($all)->toContain('Triggered.php: triggeredInstalments')
        ->and($all)->toContain('Receipts.php: paymentReceipts')
        ->and($all)->toContain('Amendments.php: contractAmendments')
        ->and($all)->toContain('Attributed.php: attributedPayments')
        ->and($all)->toContain('Referenced.php: isReferencedByBillingRecord')
        ->and($all)->toContain('Eager.php: triggeredInstalments')
        ->and($all)->toContain('triggered.blade.php: triggeredInstalments')
        ->and($all)->toContain('AmendmentVar.php: amendments')
        ->and($all)->toContain('Installment.php: InstallmentPlan')
        ->and($all)->toContain('TimeTable.php: time_entries')
        ->and($all)->toContain('Contractor.php: contractor')
        ->and($all)->not->toContain('Namespaces.php');
});
