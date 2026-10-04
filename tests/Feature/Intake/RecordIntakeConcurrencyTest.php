<?php

use App\Enums\Role;
use App\Models\IntakeRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

/**
 * M10 Task 2, R1: "hai tiếp nhận đồng thời đối nhau (dưới `test:mariadb`): lần thứ hai thấy lần thứ
 * nhất." Hai người nhận hai cuộc gọi đối nhau cùng lúc — A (nguyên đơn) khai B (bị đơn) và B khai A —
 * mà không có gì tuần tự hoá thì kiểm tra của mỗi bên chạy TRƯỚC khi bên kia commit, cả hai ra XANH, và
 * một xung đột lợi ích thật bị bỏ sót vì thời điểm chứ không phải vì dữ liệu. `RecordIntake` chạy dưới
 * `Cache::lock('conflict-check')` (cùng khoá `OpenMatter`) bao cả kiểm tra lẫn lưu, nên tiến trình chạy
 * sau luôn thấy bản ghi của tiến trình chạy trước qua NGUỒN DÒ THỨ HAI của `RunConflictCheck` và ra VÀNG.
 *
 * Chỉ một test THẬT, hai tiến trình HĐH, hai kết nối DB, trên MariaDB thật mới đo được điều đó. Hai tiến
 * trình con là `tests/concurrency/record_intake_probe.php`. Đọc docblock của
 * `tests/Feature/Actions/OpenMatterConcurrencyTest.php` cho "ghi nhận trung thực" về `DB::commit()` giữa
 * bài test và dữ liệu còn sót lại: hai bản ghi tiếp nhận (SĐT NGẪU NHIÊN mỗi lượt, để dữ liệu còn sót của
 * lượt trước không khớp lượt này) ở lại trong CSDL test MariaDB.
 */
it('makes the second of two opposing calls received at once see the first', function () {
    (new RolesAndPermissionsSeeder)->run();

    $phoneX = '09'.random_int(10_000_000, 99_999_999);
    $phoneW = '09'.random_int(10_000_000, 99_999_999);
    $actorA = User::factory()->withRole(Role::Assistant)->create();
    $actorB = User::factory()->withRole(Role::Assistant)->create();

    DB::commit();

    $script = base_path('tests/concurrency/record_intake_probe.php');
    $barrierFile = sys_get_temp_dir().'/vkcrm-intake-barrier-'.uniqid().'.txt';

    $processA = Process::timeout(30)->start([
        'php', $script, (string) $actorA->id, 'Người X (đồng thời '.uniqid().')', $phoneX, 'plaintiff', $phoneW, 'defendant', $barrierFile,
    ]);
    $processB = Process::timeout(30)->start([
        'php', $script, (string) $actorB->id, 'Người W (đồng thời '.uniqid().')', $phoneW, 'defendant', $phoneX, 'plaintiff', $barrierFile,
    ]);

    file_put_contents($barrierFile, '1');

    try {
        $resultA = $processA->wait();
        $resultB = $processB->wait();
    } finally {
        @unlink($barrierFile);
    }

    $outputA = trim($resultA->output());
    $outputB = trim($resultB->output());

    expect($outputA)->not->toStartWith('ERROR')
        ->and($outputB)->not->toStartWith('ERROR');

    // Dưới khoá dùng chung hai tiến trình bị TUẦN TỰ hoá: chạy sau luôn thấy chạy trước, nên không thể
    // cả hai cùng XANH — và nguồn thứ hai không bao giờ cho Đỏ.
    $levels = collect([$outputA, $outputB])->map(fn (string $line): string => strtok($line, ' '))->all();

    expect($levels)->toContain('YELLOW')
        ->and($levels)->not->toContain('RED');

    // Mỗi lần chạy đúng một dòng `conflict_check_run` chủ thể là bản ghi tiếp nhận của nó.
    foreach ([$outputA, $outputB] as $line) {
        $intake = IntakeRequest::query()->where('code', substr($line, strpos($line, ' ') + 1))->firstOrFail();

        expect(Activity::query()
            ->where('event', 'conflict_check_run')
            ->where('subject_type', $intake->getMorphClass())
            ->where('subject_id', $intake->getKey())
            ->count())->toBe(1);
    }
})->skip(
    fn (): bool => config('database.default') !== 'mariadb',
    'chỉ có ý nghĩa dưới MariaDB thật (wt-dev lane test:mariadb) — SQLite trong bộ nhớ không dựng lại được hai kết nối song song',
);
