<?php

use App\Models\Matter;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Symfony\Component\Finder\Finder;

/**
 * M9 Task 12 — khung `time_entries` (SPEC §15, giai đoạn 2). Bảng, model, quan hệ, alias morph,
 * factory. Không Action, không màn hình, không con số nào trên dashboard đọc bảng này ở M9 — xem
 * test grep ở cuối tệp này, thứ giữ cho task này XOÁ ĐƯỢC nếu chủ văn phòng nói "không".
 */
it('belongs to a matter and a user, both ways', function () {
    $matter = Matter::factory()->create();
    $user = User::factory()->create();

    $entry = TimeEntry::factory()->for($matter)->for($user)->create();

    expect($entry->matter->is($matter))->toBeTrue()
        ->and($entry->user->is($user))->toBeTrue()
        ->and($matter->timeEntries->pluck('id')->all())->toBe([$entry->id])
        ->and($user->timeEntries->pluck('id')->all())->toBe([$entry->id]);
});

it('casts its billing-shape columns', function () {
    $entry = TimeEntry::factory()->create([
        'worked_on' => '2026-09-20',
        'minutes' => 90,
        'is_billable' => true,
        'hourly_rate' => 500_000,
    ]);

    expect($entry->worked_on->toDateString())->toBe('2026-09-20')
        ->and($entry->minutes)->toBe(90)
        ->and($entry->is_billable)->toBeTrue()
        ->and($entry->hourly_rate)->toBe(500_000);
});

it('records who wrote it through HasBlameable', function () {
    $author = User::factory()->create();
    $this->actingAs($author, 'web');

    $entry = TimeEntry::factory()->create();

    expect($entry->created_by)->toBe($author->id);
});

it('has the strict morph alias time_entry', function () {
    expect((new TimeEntry)->getMorphClass())->toBe('time_entry')
        ->and(Relation::getMorphedModel('time_entry'))->toBe(TimeEntry::class);
});

/**
 * Chốt chặn thật của "TimeEntry không có nghiệp vụ nào ở M9" — không phải một lời hứa trong
 * docblock, mà một phép quét mã nguồn. Danh sách MIỄN TRỪ là chính xác danh sách tệp Task 12 liệt
 * kê; thêm một tệp khác tham chiếu `TimeEntry` mà không thêm vào danh sách này là dấu hiệu phạm vi
 * đã lan ra ngoài "khung".
 */
it('is referenced only by the exact set of files Task 12 lists, nowhere else in the codebase', function () {
    $exempt = [
        'app/Models/TimeEntry.php',
        'app/Models/Matter.php',
        'app/Models/User.php',
        'app/Policies/TimeEntryPolicy.php',
        'app/Providers/AppServiceProvider.php',
        'database/factories/TimeEntryFactory.php',
        'database/migrations/2026_09_25_000005_create_time_entries_table.php',
        'tests/Feature/Models/TimeEntryTest.php',
        'tests/Feature/Authorization/TimeEntryPolicyTest.php',
        // M11 Task 17 (SPEC §5 "Mang sang M11"): hai phép canh CẤM MCP chạm tới tiền của vụ — phép quét
        // cấu trúc nêu tên `TimeEntry` trong mẫu cấm, bộ dữ liệu quét đặt một dòng giờ làm có kim. Không
        // tệp nào trong đó là nghiệp vụ của `TimeEntry`.
        'tests/Feature/Mcp/MoneyMcpBoundaryTest.php',
        'tests/Support/McpSweep.php',
    ];

    $scannedDirectories = ['app', 'database', 'tests', 'config', 'routes', 'lang'];

    $offenders = [];

    foreach ($scannedDirectories as $directory) {
        $base = base_path($directory);

        if (! is_dir($base)) {
            continue;
        }

        foreach (Finder::create()->files()->name('*.php')->in($base) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $relativeFromRoot = $directory.'/'.$relative;

            if (in_array($relativeFromRoot, $exempt, true)) {
                continue;
            }

            if (str_contains($file->getContents(), 'TimeEntry')) {
                $offenders[] = $relativeFromRoot;
            }
        }
    }

    expect($offenders)->toBe([]);
});
