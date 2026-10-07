<?php

use App\Actions\Storage\ImportOfficeReceipts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Spatie\Backup\Events\BackupHasFailed;
use Symfony\Component\Finder\Finder;
use Tests\Support\DocumentStoreFixtures;
use Tests\Support\OfficeReceiptFixtures as Receipts;

/*
|--------------------------------------------------------------------------
| M14 Task 7 — test cấu trúc của bản thứ hai ở máy văn phòng (kế hoạch R10, Review Focus 7)
|--------------------------------------------------------------------------
|
| 1. Chỉ `ImportOfficeReceipts` ghi `drive_objects.office_copied_at`: cột đó là điều kiện duy nhất
|    để vùng đệm được dọn, nên không đường nào khác được làm một dòng thành "dọn được".
| 2. Không lời gọi `rclone` nào của M14 mang lệnh huỷ hay đồng bộ (`sync`, `move`, `purge`,
|    `deletefile`, `rmdir`, `cleanup`, `--delete*`). `RcloneProcess::deleteFile()` của M8a (dọn 30
|    bản sao lưu) ở lại: quét chỉ áp cho mã ngoài `app/Actions/Backup` và `app/Support/Backup`.
| 3. `tools/backup/office-pull.sh` (chạy trên máy văn phòng, không chạy được ở đây — phán quyết C2):
|    kiểm tĩnh `bash -n`, `--immutable` ở mọi lượt chép, `--one-way` ở `cryptcheck`, khoá chống chạy
|    chồng, và cùng danh sách lệnh cấm vắng mặt trừ dòng chú thích nói chúng bị cấm.
| 4. `tools/backup/restore-drill.sh` có bước `vkcrm:storage:verify --sample=20` sau khi khôi phục CSDL.
*/

const T7_FORBIDDEN_RCLONE = ['sync', 'move', 'purge', 'deletefile', 'delete', 'rmdir', 'rmdirs', 'cleanup', 'dedupe'];

function t7OfficeCopiedAtWriters(): array
{
    $writers = [];
    // Một lần GHI cột: khoá mảng (`update([...])`, `insert`, `fill`, `forceFill`), gán thuộc tính, hay
    // `SET office_copied_at = …` trong SQL thô. Đọc (`whereNull`, `whereNotNull`, `<=`) và cast
    // `'datetime'` của model không khớp.
    $pattern = '/office_copied_at[\'"]?\s*(?:=>(?!\s*[\'"]datetime[\'"])|=(?![=>]))/';

    foreach ((new Finder)->files()->in(base_path('app'))->name('*.php') as $file) {
        if (preg_match($pattern, $file->getContents()) === 1) {
            $writers[] = str_replace('\\', '/', $file->getRelativePathname());
        }
    }

    sort($writers);

    return $writers;
}

function t7JoinedScriptLines(string $path): array
{
    $text = str_replace("\r\n", "\n", (string) file_get_contents($path));

    // Nối dòng tiếp nối `\` để một lệnh viết trên nhiều dòng được kiểm như một dòng.
    return explode("\n", preg_replace('/\\\\\n\s*/', ' ', $text));
}

function t7IsComment(string $line): bool
{
    return str_starts_with(ltrim($line), '#');
}

it('chỉ ImportOfficeReceipts ghi office_copied_at trong app/', function () {
    expect(t7OfficeCopiedAtWriters())->toBe(['Actions/Storage/ImportOfficeReceipts.php']);
});

it('bộ quét ghi office_copied_at bắt được các dạng ghi (cặp dương của test trên)', function () {
    $pattern = '/office_copied_at[\'"]?\s*(?:=>(?!\s*[\'"]datetime[\'"])|=(?![=>]))/';

    foreach ([
        "->update(['office_copied_at' => now()])",
        '$row->office_copied_at = now();',
        "DB::update('UPDATE drive_objects SET office_copied_at = ? WHERE id = ?')",
        '$row->forceFill(["office_copied_at" => now()])',
    ] as $write) {
        expect(preg_match($pattern, $write))->toBe(1, $write);
    }

    foreach ([
        "'office_copied_at' => 'datetime',",
        "->whereNotNull('drive_objects.office_copied_at')",
        "->where('office_copied_at', '<=', now())",
        'office_copied_at <= ?',
        'office_copied_at == null',
    ] as $read) {
        expect(preg_match($pattern, $read))->toBe(0, $read);
    }
});

it('argv của mọi lời gọi rclone của lượt nhập biên nhận chỉ là lsjson/cat, không lệnh huỷ hay đồng bộ nào', function () {
    Http::preventStrayRequests();
    Event::fake([BackupHasFailed::class]);
    Receipts::configure();
    // Sau tên biên nhận lớn nhất dưới đây: tên ở tương lai thì không được đọc, và ca "cat hỏng" mất.
    $this->travelTo(CarbonImmutable::parse('2026-10-09 08:00:00', 'Asia/Ho_Chi_Minh'));
    $key = Receipts::key();
    DocumentStoreFixtures::driveObject(['object_key' => $key, 'md5' => md5('x'), 'size' => 10]);

    $commands = [];

    foreach ([
        [[Receipts::fileName('20261006T010000Z') => Receipts::receipt([Receipts::line($key, md5('x'), 10)])], false, []],
        [[Receipts::fileName('20261007T010000Z') => '{khong phai json'], false, []],
        [[Receipts::fileName('20261008T010000Z') => Receipts::receipt([])], false, [Receipts::fileName('20261008T010000Z')]],
        [[], true, []],
    ] as [$files, $failList, $failCat]) {
        Receipts::fakeRclone($files, failList: $failList, failCat: $failCat);
        app(ImportOfficeReceipts::class)->handle();
        $commands = [...$commands, ...Receipts::$commands];
    }

    expect($commands)->not->toBe([]);

    foreach ($commands as $argv) {
        $verbs = array_values(array_intersect($argv, ['lsjson', 'cat']));
        expect($verbs)->toHaveCount(1, implode(' ', $argv));

        foreach ($argv as $argument) {
            expect(in_array($argument, T7_FORBIDDEN_RCLONE, true))->toBeFalse(implode(' ', $argv))
                ->and(str_starts_with($argument, '--delete'))->toBeFalse(implode(' ', $argv));
        }
    }
});

it('mã ngoài sao lưu M8a chỉ gọi RcloneProcess::listJson hoặc RcloneProcess::cat', function () {
    $offending = [];

    foreach ((new Finder)->files()->in(base_path('app'))->name('*.php') as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        if (str_starts_with($relative, 'Actions/Backup/') || str_starts_with($relative, 'Support/Backup/')) {
            continue;
        }

        preg_match_all('/RcloneProcess::(\w+)\s*\(/', $file->getContents(), $matches);

        foreach ($matches[1] as $method) {
            if (! in_array($method, ['listJson', 'cat'], true)) {
                $offending[] = "{$relative}: {$method}";
            }
        }
    }

    expect($offending)->toBe([])
        ->and(file_get_contents(app_path('Actions/Storage/ImportOfficeReceipts.php')))->toContain('RcloneProcess::cat(');
});

it('office-pull.sh: bash -n sạch', function () {
    $result = Process::path(base_path())->run(['bash', '-n', 'tools/backup/office-pull.sh']);

    expect($result->exitCode())->toBe(0, $result->errorOutput());
});

it('office-pull.sh: mọi lượt chép của rclone có --immutable', function () {
    $copies = array_values(array_filter(
        t7JoinedScriptLines(base_path('tools/backup/office-pull.sh')),
        fn (string $line) => ! t7IsComment($line) && preg_match('/"\$\{RCLONE\}"(?:\s+"\$\{RC_FLAGS\[@\]\}")?\s+copy(?:to)?\b/', $line) === 1,
    ));

    // kéo kho vào crypt, kéo archive CSDL, đẩy biên nhận
    expect(count($copies))->toBeGreaterThanOrEqual(3);

    foreach ($copies as $line) {
        expect($line)->toContain('--immutable');
    }
});

it('office-pull.sh: rclone chỉ được gọi qua "${RCLONE}", nên kiểm cờ ở trên không bỏ sót lời gọi nào', function () {
    foreach (t7JoinedScriptLines(base_path('tools/backup/office-pull.sh')) as $line) {
        if (t7IsComment($line)) {
            continue;
        }

        expect(preg_match('/(?<![\w$\{"-])rclone\s+(copy|copyto|cryptcheck|lsf|lsjson|config)\b/', $line))->toBe(0, $line);
    }
});

it('office-pull.sh: mọi cryptcheck có --one-way và báo tệp khớp bằng --match', function () {
    $checks = array_values(array_filter(
        t7JoinedScriptLines(base_path('tools/backup/office-pull.sh')),
        fn (string $line) => ! t7IsComment($line) && preg_match('/"\$\{RCLONE\}".*\scryptcheck\s/', $line) === 1,
    ));

    expect(count($checks))->toBeGreaterThanOrEqual(2);

    foreach ($checks as $line) {
        expect($line)->toContain('--one-way');
    }

    expect(implode("\n", $checks))->toContain('--match')
        ->and(implode("\n", $checks))->toContain('--files-from');
});

it('office-pull.sh: có khoá chống chạy chồng bằng mkdir, ghi PID, gỡ khoá của PID đã chết', function () {
    $script = (string) file_get_contents(base_path('tools/backup/office-pull.sh'));

    expect($script)->toContain('mkdir "${LOCK_DIR}"')
        ->and($script)->toContain('echo "$$" > "${LOCK_DIR}/pid"')
        ->and($script)->toContain('kill -0');
});

it('office-pull.sh: không lệnh huỷ hay đồng bộ nào, trừ dòng chú thích nói chúng bị cấm', function () {
    $offending = [];

    foreach (t7JoinedScriptLines(base_path('tools/backup/office-pull.sh')) as $number => $line) {
        $hit = preg_match('/\b(sync|move|delete|purge|deletefile|rmdir|rmdirs|cleanup|dedupe)\b|--delete/i', $line) === 1;

        if (! $hit) {
            continue;
        }

        if (t7IsComment($line) && str_contains(mb_strtolower($line), 'cấm')) {
            continue;
        }

        $offending[] = ($number + 1).': '.trim($line);
    }

    expect($offending)->toBe([]);
});

it('office-pull.sh: chỉ nối vào receipted.txt SAU khi đẩy biên nhận thành công', function () {
    $script = (string) file_get_contents(base_path('tools/backup/office-pull.sh'));

    $push = strpos($script, 'if "${RCLONE}" copyto ');
    $append = strpos($script, '>> "${RECEIPTED}"');

    // Nối vào receipted.txt đúng MỘT chỗ, và chỗ đó nằm trong nhánh thành công của lượt gửi.
    expect(substr_count($script, '>> "${RECEIPTED}"'))->toBe(1);

    expect($push)->not->toBeFalse()
        ->and($append)->not->toBeFalse()
        ->and($append)->toBeGreaterThan($push);
});

it('restore-drill.sh: bash -n sạch và có bước vkcrm:storage:verify --sample=20 sau khi kiểm bản khôi phục', function () {
    $result = Process::path(base_path())->run(['bash', '-n', 'tools/backup/restore-drill.sh']);
    $script = (string) file_get_contents(base_path('tools/backup/restore-drill.sh'));

    expect($result->exitCode())->toBe(0, $result->errorOutput())
        ->and($script)->toContain('vkcrm:storage:verify --sample=20')
        ->and(strpos($script, 'verify_document_store'))->toBeGreaterThan(0);

    // Một lời gọi `step "…" verify_document_store` THẬT (không phải dòng chú thích) trong main(), sau
    // bước kiểm bản khôi phục.
    $main = substr($script, (int) strpos($script, "\nmain() {"));
    expect(preg_match('/^\s*step "[^"]*" verify_restore$/m', $main, $restore, PREG_OFFSET_CAPTURE))->toBe(1)
        ->and(preg_match('/^\s*step "[^"]*" verify_document_store$/m', $main, $store, PREG_OFFSET_CAPTURE))->toBe(1)
        ->and($store[0][1])->toBeGreaterThan($restore[0][1]);
});
