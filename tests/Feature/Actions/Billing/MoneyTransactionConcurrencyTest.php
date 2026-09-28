<?php

use App\Enums\InstalmentStatus;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Lượt sửa thứ ba sau rà soát cuối M9 — I-1 và M-1: hai Action tiền trên HAI VỤ VIỆC KHÁC NHAU,
 * chạy chồng lên nhau, phải CÙNG thành công. Không ai chạm vào vụ việc của người kia, nên không
 * ai được nhận câu "Có người vừa thay đổi khoản này".
 *
 *  - I-1: `SUM … for update` trên `payments` (chốt chặn thu vượt, lượt sửa thứ hai) lấy khoá KHE
 *    dưới REPEATABLE READ. Hai đợt MỚI của hai hợp đồng khác nhau cùng rơi vào một khe của chỉ
 *    mục `instalment_id`: A và B cùng giữ khoá khe, rồi `insert into payments` của mỗi bên đợi
 *    khoá khe của bên kia — MariaDB phá deadlock bằng 1213 cho một bên.
 *  - M-1: `DraftContract` → `CodeSequence::next()` khoá hàng DÙNG CHUNG `contract:{năm}` sau khi
 *    transaction đã đọc thường (ảnh chụp đã mở) — 1213 hoặc 1020 cho một bên.
 *
 * Phán quyết: giữ các lần đọc có khoá; `moneyTransaction()` chạy lại CẢ transaction ở tầng ngoài
 * cùng (`DB::transaction($work, 3)`, sau một lần rollback trọn vẹn). Bên thua lần đầu chạy lại và
 * thành công. `tries=2` ở đúng một bên là bằng chứng cuộc chồng lấn THẬT SỰ xảy ra (nếu không,
 * bài test xanh mà không chứng minh gì); rào chắn trong `tests/concurrency/money_overlap_probe.php`
 * bảo đảm điều đó.
 *
 * `DB::commit()` giữa bài test — cố ý, cùng lý do và cùng hình dạng `RecordPaymentConcurrencyTest`.
 * Chỉ chạy dưới MariaDB thật (`test:mariadb` của công cụ làn), một mình, không song song.
 */
function runOverlappingMoneyProbes(string $role, array $argsA, array $argsB): array
{
    $script = base_path('tests/concurrency/money_overlap_probe.php');
    $fileA = sys_get_temp_dir().'/vkcrm-overlap-'.uniqid().'-a.txt';
    $fileB = sys_get_temp_dir().'/vkcrm-overlap-'.uniqid().'-b.txt';

    $processA = Process::timeout(90)->start(['php', $script, $role, ...array_map('strval', $argsA), $fileA, $fileB]);
    $processB = Process::timeout(90)->start(['php', $script, $role, ...array_map('strval', $argsB), $fileB, $fileA]);

    try {
        $resultA = $processA->wait();
        $resultB = $processB->wait();
    } finally {
        @unlink($fileA);
        @unlink($fileB);
    }

    return [trim($resultA->output().$resultA->errorOutput()), trim($resultB->output().$resultB->errorOutput())];
}

/** `tries=N` của một dòng kết quả. */
function overlapTries(string $output): int
{
    return preg_match('/tries=(\d+)/', $output, $matches) === 1 ? (int) $matches[1] : 0;
}

it('records the first payments of two fresh instalments on two different matters concurrently, both succeeding', function () {
    (new RolesAndPermissionsSeeder)->run();

    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $instalments = collect([1, 2])->map(function (): Instalment {
        $lead = User::factory()->withRole(Role::Lawyer)->create();
        $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
        $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 10_000_000]);

        return Instalment::factory()->for($contract)->create([
            'amount' => 10_000_000,
            'due_date' => today()->addDays(10)->toDateString(),
            'status' => InstalmentStatus::Pending,
        ]);
    });

    expect(Payment::query()->count())->toBe(0);

    RefreshDatabaseState::$migrated = false;
    DB::commit();

    [$outputA, $outputB] = runOverlappingMoneyProbes(
        'record',
        [$accountant->id, $instalments[0]->id, 4_000_000],
        [$accountant->id, $instalments[1]->id, 4_000_000],
    );

    expect($outputA)->toStartWith('GREEN')
        ->and($outputB)->toStartWith('GREEN');

    $tries = [overlapTries($outputA), overlapTries($outputB)];
    sort($tries);
    expect($tries)->toBe([1, 2]);

    foreach ($instalments as $instalment) {
        expect((int) Payment::query()->where('instalment_id', $instalment->id)->sum('amount'))->toBe(4_000_000);
    }
})->skip(
    fn (): bool => config('database.default') !== 'mariadb',
    'chỉ có ý nghĩa dưới MariaDB thật (test:mariadb của công cụ làn, chạy một mình) — SQLite không có hai kết nối song song và không có khoá khe',
);

it('drafts contracts on two different matters concurrently, both succeeding with distinct codes', function () {
    (new RolesAndPermissionsSeeder)->run();

    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $matterA = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    $matterB = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    RefreshDatabaseState::$migrated = false;
    DB::commit();

    [$outputA, $outputB] = runOverlappingMoneyProbes(
        'draft',
        [$lead->id, $matterA->id, 10_000_000],
        [$lead->id, $matterB->id, 10_000_000],
    );

    expect($outputA)->toStartWith('GREEN')
        ->and($outputB)->toStartWith('GREEN');

    $tries = [overlapTries($outputA), overlapTries($outputB)];
    sort($tries);
    expect($tries)->toBe([1, 2]);

    $codes = Contract::query()->whereIn('matter_id', [$matterA->id, $matterB->id])->pluck('code');
    expect($codes)->toHaveCount(2)
        ->and($codes->unique())->toHaveCount(2);
})->skip(
    fn (): bool => config('database.default') !== 'mariadb',
    'chỉ có ý nghĩa dưới MariaDB thật (test:mariadb của công cụ làn, chạy một mình) — SQLite không có hai kết nối song song',
);
