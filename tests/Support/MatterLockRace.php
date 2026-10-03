<?php

namespace Tests\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rà soát cuối M7, I1 — dựng ĐÚNG cuộc đua mà READ VIEW của REPEATABLE READ (InnoDB, MariaDB 11.8
 * của dự án) làm sai, để đo nó trên mã sản phẩm thật. Cùng kỹ thuật với test "reads fresh
 * matter_user membership…" của `TriageClientRequestTest` (M6.5 fix round 3/4), gom lại thành một
 * chỗ để các Action khác dùng — đọc docblock của test đó cho đủ lý lẽ; tóm tắt:
 *
 *  - REPEATABLE READ cố định READ VIEW của một transaction ở LẦN ĐỌC KHÔNG KHOÁ ĐẦU TIÊN; câu đọc có
 *    khoá (`FOR UPDATE`) luôn đọc bản mới nhất và không cố định gì. Một câu đọc trần đứng TRƯỚC khoá
 *    `matters` bên trong transaction (hay một truy vấn con không khoá nằm ngay trong câu khoá) cố
 *    định READ VIEW TRƯỚC lúc phải đợi khoá; mọi câu đọc trần SAU khi được cấp khoá (Gate: đội ngũ,
 *    vai trò; người thực hiện đọc lại) vẫn thấy dữ liệu từ TRƯỚC lúc đợi.
 *  - Lỗi chỉ hiện khi Action THẬT SỰ phải đợi một transaction khác đang giữ khoá `matters`, rồi chạy
 *    tiếp ngay khi transaction kia commit. Nên tiến trình CON (`pcntl_fork()`) giữ khoá dòng
 *    `matters`, CHỜ CÓ XÁC MINH cho tới khi phiên CSDL của tiến trình CHA đang đứng ở câu
 *    `… from `matters` … for update` (đọc `information_schema.PROCESSLIST`, không đoán bằng thời
 *    gian), rồi mới chạy lần ghi đồng thời và COMMIT — nhả khoá đúng lúc cha đang bị nó chặn.
 *  - Con mở kết nối MỚI riêng và không bao giờ dùng hay đóng socket thừa hưởng từ cha; con tự kết
 *    thúc bằng `SIGKILL`. Cha chạy mã sản phẩm KHÔNG sửa đổi trên một kết nối mặc định thứ hai.
 *  - Hai phiên chỉ thấy dữ liệu của nhau khi đã COMMIT thật, nên harness `DB::commit()` transaction
 *    của `RefreshDatabase` và đặt `RefreshDatabaseState::$migrated = false` TRƯỚC lần commit đó:
 *    bài test `RefreshDatabase` kế tiếp `migrate:fresh` sạch CSDL test.
 *
 * Chỉ chạy trên MariaDB/MySQL có `pcntl` + `posix`; nơi khác test bị bỏ qua thành tiếng
 * (`markTestSkipped`), không bao giờ xanh im lặng. Không dựng được cuộc đua (con không thấy cha bị
 * chặn trong 10 giây) thì test `fail()`.
 */
final class MatterLockRace
{
    private const PARENT = 'mariadb_race_parent';

    private const CHILD = 'mariadb_race_child';

    /** Bỏ qua test (thành tiếng) khi không đo được READ VIEW InnoDB hay không fork được. */
    public static function requireMariadb(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            test()->markTestSkipped(
                'Cần chạy trên chính MariaDB (test:mariadb): test này đo READ VIEW của REPEATABLE READ '
                ."InnoDB, một hành vi SQLite không có. Đang chạy trên: [{$driver}]."
            );
        }

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            test()->markTestSkipped('Cần pcntl và posix để giả lập hai phiên CSDL thật đồng thời.');
        }
    }

    /**
     * Gọi SAU khi test đã dựng xong dữ liệu (dữ liệu đó được COMMIT thật ở đây).
     *
     * @param  int  $matterId  dòng `matters` mà con giữ khoá và cha phải đợi.
     * @param  Closure(Connection): void  $concurrentWrite  lần ghi đồng thời — chạy trong tiến trình
     *                                                      CON, trên kết nối riêng, trong transaction
     *                                                      đang giữ khoá `matters`; con commit ngay sau.
     * @param  Closure(): mixed  $productionCall  lời gọi mã sản phẩm — chạy trong tiến trình CHA, trên
     *                                            kết nối mặc định thứ hai; câu khoá `matters` của nó
     *                                            bị chặn thật cho tới khi con commit.
     * @return mixed giá trị `$productionCall` trả về (nó tự bắt exception nó chờ đợi).
     */
    public static function run(int $matterId, Closure $concurrentWrite, Closure $productionCall): mixed
    {
        self::requireMariadb();

        $default = DB::getDefaultConnection();
        $base = config('database.connections.'.$default);

        config([
            'database.connections.'.self::PARENT => $base,
            'database.connections.'.self::CHILD => $base,
        ]);

        // Từ đây CSDL test bị coi là bẩn — xem docblock lớp.
        RefreshDatabaseState::$migrated = false;
        DB::commit();

        $parentConnectionId = (int) DB::connection(self::PARENT)->selectOne('select connection_id() as id')->id;

        $prefix = sys_get_temp_dir().'/vkcrm_matter_lock_race_'.getmypid().'_'.bin2hex(random_bytes(4));
        $lockHeld = $prefix.'.signal';
        $outcomeFile = $prefix.'.outcome';

        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork() thất bại — không giả lập được hai phiên CSDL đồng thời.');
        }

        if ($pid === 0) {
            self::child($matterId, $parentConnectionId, $concurrentWrite, $lockHeld, $outcomeFile);
        }

        $reaped = false;

        try {
            $deadline = microtime(true) + 5.0;

            while (! file_exists($lockHeld)) {
                if (microtime(true) > $deadline) {
                    test()->fail('Tiến trình con không báo đã giữ khoá trong 5 giây — bỏ test, không đoán kết quả.');
                }

                usleep(10_000);
            }

            DB::setDefaultConnection(self::PARENT);

            $result = $productionCall();

            pcntl_waitpid($pid, $status);
            $reaped = true;

            $outcome = (string) @file_get_contents($outcomeFile);

            if ($outcome !== 'written-while-parent-blocked') {
                test()->fail('Không dựng được cuộc đua cần đo — tiến trình con báo: ['.$outcome.'].');
            }

            return $result;
        } finally {
            if (! $reaped) {
                pcntl_waitpid($pid, $status); // con luôn tự dừng trong ~10 giây.
            }

            $parent = DB::connection(self::PARENT);

            while ($parent->transactionLevel() > 0) {
                $parent->rollBack();
            }

            DB::purge(self::PARENT);
            DB::setDefaultConnection($default);
            @unlink($lockHeld);
            @unlink($outcomeFile);
        }
    }

    /** Tiến trình CON: giữ khoá, chờ cha bị chặn, ghi, commit, ghi kết quả, tự kết thúc. */
    private static function child(int $matterId, int $parentConnectionId, Closure $concurrentWrite, string $lockHeld, string $outcomeFile): never
    {
        $outcome = 'error: tiến trình con dừng trước khi ghi kết quả';

        try {
            $child = DB::connection(self::CHILD); // kết nối MỚI, không dùng socket thừa hưởng.
            $child->beginTransaction();
            $child->table('matters')->where('id', $matterId)->lockForUpdate()->first();

            touch($lockHeld);

            $deadline = microtime(true) + 10.0;
            $parentBlocked = false;

            while (! $parentBlocked && microtime(true) < $deadline) {
                $parentBlocked = $child->table('information_schema.PROCESSLIST')
                    ->where('ID', $parentConnectionId)
                    ->whereIn('COMMAND', ['Query', 'Execute'])
                    ->whereRaw("LOWER(INFO) LIKE '%from `matters`%for update%'")
                    ->exists();

                if (! $parentBlocked) {
                    usleep(10_000);
                }
            }

            if ($parentBlocked) {
                $concurrentWrite($child);
                $child->commit(); // nhả khoá matters NGAY ĐÂY, trong lúc cha đang đợi nó.
                $outcome = 'written-while-parent-blocked';
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
            file_put_contents($outcomeFile, $outcome);

            // Không đi qua shutdown handler/destructor nào: chúng sẽ đóng các socket chung với cha.
            posix_kill(posix_getpid(), SIGKILL);
        }

        exit(1); // không tới được — SIGKILL ở trên.
    }
}
