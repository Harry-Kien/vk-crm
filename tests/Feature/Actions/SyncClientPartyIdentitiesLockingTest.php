<?php

use App\Actions\SyncClientPartyIdentities;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/**
 * Final review wave 2, phụ lục (mẫu lỗi làn M9 tìm ra): một lần đọc THƯỜNG (không khoá) là câu đầu
 * tiên bên trong `DB::transaction()` đóng băng READ VIEW của InnoDB REPEATABLE READ. MariaDB 11.8
 * của dự án chạy `innodb_snapshot_isolation = ON`, nên khi transaction đó SAU ĐÓ ghi (UPDATE) một
 * dòng mà một phiên khác đã sửa và commit sau lúc đóng băng, câu ghi ném ERROR 1020 ("Record has
 * changed since last read") — một trang lỗi 500 cho người vừa sửa hồ sơ khách hàng.
 *
 * `SyncClientPartyIdentities::handle()` (chạy từ `Client::updated`) từng mở transaction bằng một
 * lần đọc thường `matter_parties` rồi ghi từng dòng. Kịch bản dựng bằng hai phiên CSDL thật (cùng
 * bộ khung `pcntl_fork()` của `TriageClientRequestTest` — đọc docblock đó cho lý do từng chi
 * tiết): tiến trình CON khoá và sửa dòng bên (như một `UpdateMatterParty` đang chạy), đợi XÁC
 * MINH cho tới khi phiên của CHA đang bị chặn trên `matter_parties`, rồi commit.
 *
 * Trước bản sửa: cha đọc thường (đóng băng ảnh chụp, không bị chặn), rồi UPDATE bị chặn; con
 * commit; UPDATE của cha ném 1020. Sau bản sửa: câu đầu tiên là `lockForUpdate()` — cha bị chặn
 * ngay ở câu đọc có khoá, đọc bản mới nhất sau khi con commit, và ghi bình thường.
 */
it('re-syncs a client\'s parties while another session holds and changes one of them, without ERROR 1020 (MariaDB, two connections)', function () {
    $driver = DB::connection()->getDriverName();

    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        test()->markTestSkipped("Cần MariaDB thật (test:mariadb): đo READ VIEW của InnoDB. Đang chạy trên: [{$driver}].");
    }

    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        test()->markTestSkipped('Cần pcntl và posix để giả lập hai phiên CSDL thật đồng thời.');
    }

    (new RolesAndPermissionsSeeder)->run();

    config([
        'database.connections.mariadb_b' => config('database.connections.mariadb'),
        'database.connections.mariadb_child' => config('database.connections.mariadb'),
    ]);

    $client = Client::factory()->create(['phone' => '0911000111']);
    $matter = Matter::factory()->create(['client_id' => $client->id]);
    $party = MatterParty::factory()->for($matter)->ourClient($client)->create();

    RefreshDatabaseState::$migrated = false;
    DB::commit();

    $partyId = $party->id;
    $parentConnectionId = (int) DB::connection('mariadb_b')->selectOne('select connection_id() as id')->id;

    $filePrefix = sys_get_temp_dir().'/vkcrm_sync_lock_test_'.getmypid();
    $lockHeldSignal = $filePrefix.'.signal';
    $childOutcomeFile = $filePrefix.'.outcome';
    @unlink($lockHeldSignal);
    @unlink($childOutcomeFile);

    $pid = pcntl_fork();

    if ($pid === -1) {
        test()->fail('pcntl_fork() thất bại.');
    }

    if ($pid === 0) {
        $outcome = 'error: tiến trình con dừng trước khi ghi kết quả';

        try {
            $child = DB::connection('mariadb_child');
            $child->beginTransaction();
            $child->table('matter_parties')->where('id', $partyId)->lockForUpdate()->first();
            $child->table('matter_parties')->where('id', $partyId)->update(['address' => 'Đổi bởi phiên khác']);

            touch($lockHeldSignal);

            $deadline = microtime(true) + 10.0;
            $parentIsBlocked = false;

            while (! $parentIsBlocked && microtime(true) < $deadline) {
                $parentIsBlocked = $child->table('information_schema.PROCESSLIST')
                    ->where('ID', $parentConnectionId)
                    ->whereIn('COMMAND', ['Query', 'Execute'])
                    ->whereRaw("LOWER(INFO) LIKE '%`matter_parties`%'")
                    ->whereRaw("(LOWER(INFO) LIKE '%for update%' OR LOWER(INFO) LIKE 'update%')")
                    ->exists();

                if (! $parentIsBlocked) {
                    usleep(10_000);
                }
            }

            if ($parentIsBlocked) {
                $child->commit();
                $outcome = 'committed-while-parent-blocked';
            } else {
                $child->rollBack();
                $outcome = 'timeout: phiên #'.$parentConnectionId.' không bị chặn trên matter_parties sau 10 giây';
            }
        } catch (Throwable $exception) {
            $outcome = 'error: '.$exception->getMessage();
        } finally {
            file_put_contents($childOutcomeFile, $outcome);
            posix_kill(posix_getpid(), SIGKILL);
        }
    }

    $childReaped = false;

    try {
        $deadline = microtime(true) + 5.0;

        while (! file_exists($lockHeldSignal)) {
            if (microtime(true) > $deadline) {
                test()->fail('Tiến trình con không báo đã giữ khoá trong 5 giây.');
            }

            usleep(10_000);
        }

        DB::setDefaultConnection('mariadb_b');

        $client = Client::query()->findOrFail($client->id);
        $client->phone = '0922000222';

        $error = null;

        try {
            app(SyncClientPartyIdentities::class)->handle($client);
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        pcntl_waitpid($pid, $status);
        $childReaped = true;

        $childOutcome = (string) @file_get_contents($childOutcomeFile);

        if ($childOutcome !== 'committed-while-parent-blocked') {
            test()->fail('Không dựng được cuộc đua cần đo — tiến trình con báo: ['.$childOutcome.'].');
        }

        $fresh = DB::connection('mariadb_b')->table('matter_parties')->where('id', $partyId)->first();

        expect($error)->toBeNull()
            ->and($fresh->phone_normalized)->toBe(Normalizer::phone('0922000222'))
            ->and($fresh->address)->toBe('Đổi bởi phiên khác');
    } finally {
        if (! $childReaped) {
            pcntl_waitpid($pid, $status);
        }

        DB::setDefaultConnection('mariadb');
        DB::purge('mariadb_b');
        @unlink($lockHeldSignal);
        @unlink($childOutcomeFile);
    }
})->group('mariadb-locking');
