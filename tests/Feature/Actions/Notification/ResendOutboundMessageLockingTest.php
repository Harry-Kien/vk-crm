<?php

use App\Actions\Notification\ResendOutboundMessage;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Exceptions\OutboundMessageNotResendable;
use App\Jobs\ResendOutboundMessageJob;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * "Bấm hai lần, hai tab, hai người cùng lúc" (Review Focus 4) cho nút "Gửi lại" — với KHOÁ THẬT
 * trên MariaDB, hai phiên CSDL thật chạy đồng thời. Test tuần tự ở
 * `tests/Feature/Filament/ResendOutboundMessageTest.php` ("clicked twice") chỉ đo được lần bấm thứ
 * hai tới SAU khi lần thứ nhất đã commit xong; nó không phân biệt được một bản cài đặt đọc dấu "đã
 * yêu cầu gửi lại" TRƯỚC khi giành khoá (đọc thấy "chưa ai bấm", rồi đứng đợi khoá, rồi xếp hàng
 * một job thứ hai) với bản đúng.
 *
 * Cùng khuôn đã được rà soát của `TriageClientRequestTest` ("reads fresh matter_user membership
 * ... after waiting on a lock"): tiến trình CON đóng vai lần bấm thứ nhất đang ở giữa transaction
 * (giữ khoá dòng `matters` và dòng nhật ký thư, đã ghi dấu audit, CHƯA commit); tiến trình CHA gọi
 * `ResendOutboundMessage::handle()` THẬT trên một kết nối riêng và bị khoá `matters` chặn; con chỉ
 * commit sau khi XÁC MINH (qua `information_schema.PROCESSLIST`) rằng cha đang đứng ở câu `FOR
 * UPDATE` trên `matters`. Cha phải thấy dấu của con và từ chối, không xếp hàng job thứ hai.
 *
 * Vì sao cài đặt đúng thấy được dấu đó trên REPEATABLE READ: bên trong transaction, hai câu đầu
 * tiên là hai câu đọc CÓ khoá (`matters`, rồi `outbound_messages`) — chúng luôn đọc bản mới nhất và
 * không cố định READ VIEW; câu đọc KHÔNG khoá đầu tiên (tra `activity_log`) chạy sau khi đã giành
 * khoá, tức sau khi con commit, nên READ VIEW của nó chứa dấu của con.
 *
 * Mutation probe (chạy trên MariaDB lúc viết test ở Task 10, và chạy lại ở vòng sửa sau rà soát
 * cuối làn): đưa câu tra `activity_log` ra TRƯỚC `DB::transaction()` → test này ĐỎ (cha đọc "chưa
 * ai bấm" trước khi con commit, rồi xếp hàng một job thứ hai).
 *
 * Dọn CSDL: cùng lý do docblock test gốc (fix round 4, N1) — `RefreshDatabaseState::$migrated =
 * false` TRƯỚC lần commit đầu tiên, để bài test `RefreshDatabase` kế tiếp `migrate:fresh`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function requireMariadbForResendLocking(): void
{
    $driver = DB::connection()->getDriverName();

    if (in_array($driver, ['mysql', 'mariadb'], true)) {
        return;
    }

    test()->markTestSkipped(
        'Cần chạy trên chính MariaDB (test:mariadb): test này đo khoá hàng và READ VIEW của '
        ."REPEATABLE READ InnoDB, thứ SQLite không có. Đang chạy trên: [{$driver}]."
    );
}

it('refuses the second of two simultaneous resend clicks that waited on the first click\'s locks (MariaDB, two connections)', function () {
    requireMariadbForResendLocking();

    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        test()->markTestSkipped('Cần pcntl và posix để giả lập hai phiên CSDL thật đồng thời.');
    }

    config([
        'database.connections.mariadb_b' => config('database.connections.mariadb'),
        'database.connections.mariadb_child' => config('database.connections.mariadb'),
    ]);

    // Từ đây CSDL test bị coi là bẩn: bài test RefreshDatabase kế tiếp sẽ `migrate:fresh`.
    RefreshDatabaseState::$migrated = false;

    // Dữ liệu phải COMMIT THẬT thì phiên khác mới thấy.
    DB::commit();

    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $matter = Matter::factory()->create(['client_id' => $client->id]);
    $log = StageLog::factory()->create(['matter_id' => $matter->id]);
    $failed = OutboundMessage::factory()->create([
        'template' => 'client.stage_update',
        'related_type' => 'stage_log',
        'related_id' => $log->id,
        'recipient' => $account->email,
        'status' => OutboundStatus::Failed,
        'error' => 'TransportException',
    ]);

    $matterId = $matter->id;
    $messageId = $failed->id;
    $adminId = $admin->id;

    $parentConnectionId = (int) DB::connection('mariadb_b')->selectOne('select connection_id() as id')->id;

    $filePrefix = sys_get_temp_dir().'/vkcrm_resend_lock_test_'.getmypid();
    $lockHeldSignal = $filePrefix.'.signal';
    $childOutcomeFile = $filePrefix.'.outcome';
    @unlink($lockHeldSignal);
    @unlink($childOutcomeFile);

    $pid = pcntl_fork();

    if ($pid === -1) {
        test()->fail('pcntl_fork() thất bại — không giả lập được hai phiên CSDL đồng thời.');
    }

    if ($pid === 0) {
        // ---- TIẾN TRÌNH CON — lần bấm THỨ NHẤT, đang ở giữa transaction của nó. ----
        $outcome = 'error: tiến trình con dừng trước khi ghi kết quả';

        try {
            $child = DB::connection('mariadb_child');
            $child->beginTransaction();
            // Đúng thứ tự khoá của Action: dòng `matters` trước, rồi dòng nhật ký thư.
            $child->table('matters')->where('id', $matterId)->lockForUpdate()->first();
            $child->table('outbound_messages')->where('id', $messageId)->lockForUpdate()->first();

            // Dấu "đã yêu cầu gửi lại" mà lần bấm thứ nhất ghi (cùng hình dạng `Audit::record()`).
            $child->table('activity_log')->insert([
                'log_name' => 'default',
                'description' => 'outbound_message_resent',
                'event' => 'outbound_message_resent',
                'subject_type' => 'matter',
                'subject_id' => $matterId,
                'causer_type' => 'user',
                'causer_id' => $adminId,
                'properties' => json_encode([
                    'outbound_message_id' => $messageId,
                    'template' => 'client.stage_update',
                    'recipients' => 1,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            touch($lockHeldSignal);

            $deadline = microtime(true) + 10.0;
            $parentIsBlocked = false;

            while (! $parentIsBlocked && microtime(true) < $deadline) {
                $parentIsBlocked = $child->table('information_schema.PROCESSLIST')
                    ->where('ID', $parentConnectionId)
                    ->whereIn('COMMAND', ['Query', 'Execute'])
                    ->whereRaw("LOWER(INFO) LIKE '%from `matters`%for update%'")
                    ->exists();

                if (! $parentIsBlocked) {
                    usleep(10_000);
                }
            }

            if ($parentIsBlocked) {
                $child->commit(); // nhả khoá NGAY ĐÂY, trong lúc lần bấm thứ hai đang đợi nó.
                $outcome = 'committed-while-parent-blocked';
            } else {
                $lastSeen = $child->table('information_schema.PROCESSLIST')
                    ->where('ID', $parentConnectionId)
                    ->first(['COMMAND', 'STATE', 'INFO']);

                $child->rollBack();
                $outcome = 'timeout: sau 10 giây không thấy phiên #'.$parentConnectionId
                    .' đợi khoá matters; phiên đó đang: '.json_encode($lastSeen, JSON_UNESCAPED_UNICODE);
            }
        } catch (Throwable $exception) {
            $outcome = 'error: '.$exception->getMessage();
        } finally {
            file_put_contents($childOutcomeFile, $outcome);

            posix_kill(posix_getpid(), SIGKILL);
        }
    }

    // ---- TIẾN TRÌNH CHA — lần bấm THỨ HAI, gọi Action thật trên một phiên riêng. ----
    $childReaped = false;

    try {
        $deadline = microtime(true) + 5.0;

        while (! file_exists($lockHeldSignal)) {
            if (microtime(true) > $deadline) {
                test()->fail('Tiến trình con không báo hiệu đã giữ khoá trong 5 giây — bỏ test, không đoán kết quả.');
            }

            usleep(10_000);
        }

        DB::setDefaultConnection('mariadb_b');
        Queue::fake();

        $refusal = null;

        try {
            app(ResendOutboundMessage::class)->handle(
                User::query()->findOrFail($adminId),
                OutboundMessage::query()->withoutGlobalScopes()->findOrFail($messageId),
            );
        } catch (OutboundMessageNotResendable $exception) {
            $refusal = $exception;
        }

        pcntl_waitpid($pid, $status);
        $childReaped = true;

        $childOutcome = (string) @file_get_contents($childOutcomeFile);

        if ($childOutcome !== 'committed-while-parent-blocked') {
            test()->fail('Không dựng được cuộc đua cần đo — tiến trình con báo: ['.$childOutcome.'].');
        }

        expect($refusal)->toBeInstanceOf(OutboundMessageNotResendable::class)
            ->and($refusal->getMessage())->toStartWith(mb_substr(__('outbound.resend.refused.already_requested', ['time' => '']), 0, 30));

        Queue::assertNotPushed(ResendOutboundMessageJob::class);

        expect(DB::connection('mariadb_b')->table('activity_log')->where('event', 'outbound_message_resent')->count())->toBe(1);
    } finally {
        if (! $childReaped) {
            pcntl_waitpid($pid, $status);
        }

        if (DB::connection('mariadb_b')->transactionLevel() > 0) {
            DB::connection('mariadb_b')->rollBack();
        }

        DB::purge('mariadb_b');
        DB::setDefaultConnection('mariadb');
        @unlink($lockHeldSignal);
        @unlink($childOutcomeFile);
    }
})->group('mariadb-locking');
