<?php

use App\Enums\Role;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * `stage/stage-05` (Review Focus 4, M6.5 Task 10): "Bấm hai lần, hai tab, hai người cùng lúc ...
 * Áp cho chuyển giai đoạn ... Không sinh dòng trùng, không lịch sử tự mâu thuẫn ... test cần khoá
 * thật chạy dưới `bin/dev test:mariadb`."
 *
 * Trước bản sửa này, `TransitionMatterStage::handle()` đọc `$matter->stage` từ instance CALLER
 * đưa vào, không `lockForUpdate()`, không đọc lại dưới khoá. Hai lần gọi gần như đồng thời trên
 * CÙNG một vụ việc (hai tiến trình, hai kết nối DB — điều DUY NHẤT tái hiện được thứ khoá dòng
 * thật sự chặn, xem `OpenMatterConcurrencyTest` cho cùng lý lẽ) đều đọc giai đoạn GỐC trước khi
 * bên kia commit, đều vượt qua kiểm tra `allowed_next`, và đều ghi một `StageLog` — hai dòng
 * append-only mâu thuẫn nhau (SPEC §4.8: nhật ký tiến độ là bảng quan trọng nhất, chỉ thêm).
 *
 * Bản sửa: `lockForUpdate()` là câu lệnh ĐẦU TIÊN trong transaction, và giai đoạn đọc lại dưới
 * khoá được so với giai đoạn caller cầm trong tay TRƯỚC transaction — khác nhau thì từ chối bằng
 * `MatterStageChanged` (xem docblock lớp `TransitionMatterStage` và của chính exception đó).
 *
 * **`DB::commit()` giữa bài test — cố ý, cùng hình dạng và cùng lý do với
 * `OpenMatterConcurrencyTest`** (đọc docblock lớp ở đó cho lý lẽ đầy đủ): hai tiến trình con mở
 * KẾT NỐI DB RIÊNG, nên dữ liệu chuẩn bị bên trong transaction bọc bài test này (RefreshDatabase)
 * phải COMMIT THẬT thì hai tiến trình mới nhìn thấy được. `RefreshDatabaseState::$migrated = false`
 * được đặt TƯỜNG MINH ngay trước commit (khác `OpenMatterConcurrencyTest`, vốn để framework tự
 * phát hiện qua `beforeApplicationDestroyed`) — theo đúng chỉ dẫn của brief task này: "Leave no
 * committed residue: trước bất kỳ commit nào bên trong test, đặt `RefreshDatabaseState::$migrated
 * = false`", để lần chạy `RefreshDatabase` KẾ TIẾP (bài test khác trong cùng lượt `test:mariadb`)
 * chắc chắn `migrate:fresh` lại, không tình cờ tái dùng một schema đã bị commit sớm làm "bẩn".
 */
it('serializes two concurrent transitions of the same matter — only one applies, the other gets a Vietnamese "stage already changed" error', function () {
    (new RolesAndPermissionsSeeder)->run();

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matterType = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->for($matterType, 'matterType')->atStage('intake')->create([
        'lead_lawyer_id' => $lawyer->id,
    ]);

    RefreshDatabaseState::$migrated = false;

    // Xem docblock lớp: phải commit thật để hai tiến trình con (kết nối DB riêng) nhìn thấy được
    // dữ liệu vừa dựng — RefreshDatabase vẫn giữ bài test này trong một transaction chưa commit.
    DB::commit();

    $script = base_path('tests/concurrency/transition_matter_stage_probe.php');
    $barrierFile = sys_get_temp_dir().'/vkcrm-concurrency-barrier-'.uniqid().'.txt';

    $processA = Process::timeout(30)->start([
        'php', $script, (string) $matter->id, (string) $lawyer->id, 'collecting_documents', $barrierFile,
    ]);
    $processB = Process::timeout(30)->start([
        'php', $script, (string) $matter->id, (string) $lawyer->id, 'collecting_documents', $barrierFile,
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

    // Đúng MỘT trong hai ra GREEN, đúng MỘT ra STAGE_CHANGED — không cả hai xanh (dòng trùng),
    // không cả hai đỏ (một transition hợp lệ bị chặn oan).
    expect([$outputA, $outputB])->toContain('STAGE_CHANGED')
        ->and(collect([$outputA, $outputB])->filter(fn (string $o): bool => str_starts_with($o, 'GREEN'))->count())->toBe(1);

    expect($matter->fresh()->stage)->toBe('collecting_documents')
        ->and(StageLog::query()->where('matter_id', $matter->id)->count())->toBe(1);
})->skip(
    fn (): bool => config('database.default') !== 'mariadb',
    'chỉ có ý nghĩa dưới MariaDB thật (bin/dev test:mariadb / wt-dev lane test:mariadb) — SQLite trong bộ nhớ không dựng lại được hai kết nối song song',
);
