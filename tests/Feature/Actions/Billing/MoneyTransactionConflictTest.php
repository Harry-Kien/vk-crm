<?php

use App\Actions\Billing\AmendContract;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\VoidPayment;
use App\Actions\Billing\WaiveInstalment;
use App\Enums\InstalmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Exceptions\PaymentExceedsInstalment;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Lượt sửa thứ hai sau rà soát cuối M9, N1, lớp 3: MariaDB báo `1020` (`Record has changed since
 * last read`, `innodb_snapshot_isolation=1`) hoặc `1213` (deadlock) giữa một Action tiền — đó là
 * "có người vừa thay đổi khoản này", không phải một sự cố hạ tầng. Nó phải tới người dùng thành
 * MỘT câu tiếng Việt "thử lại" (`ValidationException`, `ReportsActionFailures` đưa lên bằng
 * thông báo), không phải trang 500, và không gì được ghi.
 *
 * Không dựng lại được một 1020/1213 thật trong một tiến trình (cần hai kết nối, và sau bản sửa
 * đường tiền không còn gây ra 1020 nữa — `RecordPaymentConcurrencyTest`). Nên lỗi được GIẢ đúng
 * hình dạng PDO thật (`errorInfo[1]` = mã của driver, thông điệp đúng câu của MariaDB) bằng
 * `DB::beforeExecuting()`, ném ra NGAY TRƯỚC câu SQL quyết định của Action — đúng chỗ MariaDB ném
 * lỗi thật. Chạy trên cả SQLite lẫn MariaDB.
 *
 * Lượt sửa thứ ba (I-1): ở transaction NGOÀI CÙNG, lỗi đó được chạy lại trọn transaction tới 3
 * lần trước khi thành câu "thử lại" (1205 cũng vậy). Các test chạy lại dưới đây rời transaction
 * bọc bài test của `RefreshDatabase` trước ({@see leaveRefreshDatabaseTransaction()}), vì Laravel
 * không chạy lại một transaction LỒNG — đúng như trong sản xuất, nơi màn hình gọi Action ở tầng
 * ngoài cùng. Cùng tệp: chốt đóng khi hàng con vừa khoá không còn trỏ đúng hàng cha đã thăm dò.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);
    $this->instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'due_date' => today()->addDays(5)->toDateString(),
        'status' => InstalmentStatus::Pending,
    ]);
});

/** Một `QueryException` mang đúng `errorInfo` mà PDO của MariaDB trả cho mã lỗi này. */
function mariadbQueryException(int $driverCode, string $sql): QueryException
{
    [$sqlState, $message] = match ($driverCode) {
        1020 => ['HY000', "Record has changed since last read in table 'payments'"],
        1205 => ['HY000', 'Lock wait timeout exceeded; try restarting transaction'],
        1213 => ['40001', 'Deadlock found when trying to get lock; try restarting transaction'],
        1062 => ['23000', "Duplicate entry '1' for key 'PRIMARY'"],
    };

    $pdo = new PDOException("SQLSTATE[{$sqlState}]: General error: {$driverCode} {$message}");
    $pdo->errorInfo = [$sqlState, $driverCode, $message];

    return new QueryException('mariadb', $sql, [], $pdo);
}

/** Ném lỗi của driver ngay trước câu SQL đầu tiên khớp `$pattern` (không phân biệt hoa thường). */
function failNextStatementMatching(string $pattern, int $driverCode): void
{
    $armed = true;

    DB::beforeExecuting(function (string $sql) use (&$armed, $pattern, $driverCode): void {
        if ($armed && preg_match($pattern, $sql) === 1) {
            $armed = false;

            throw mariadbQueryException($driverCode, $sql);
        }
    });
}

function recordSixMillion(User $actor, Instalment $instalment): Payment
{
    return app(RecordPayment::class)->handle($actor, $instalment, 6_000_000, today(), PaymentMethod::BankTransfer, null, null, null);
}

it('turns a record-changed error (1020) during RecordPayment into the Vietnamese retry message, and records nothing', function () {
    failNextStatementMatching('/sum\(.?amount.?\).*from\W+payments/i', 1020);

    try {
        recordSixMillion($this->accountant, $this->instalment);
        $this->fail('RecordPayment phải từ chối bằng câu "thử lại".');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['concurrency' => ['Có người vừa thay đổi khoản này, anh/chị thử lại.']]);
    }

    expect(Payment::query()->where('instalment_id', $this->instalment->id)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'payment_recorded')->count())->toBe(0);
});

it('turns a deadlock (1213) during VoidPayment into the same retry message, and voids nothing', function () {
    $payment = Payment::factory()->for($this->instalment)->create(['amount' => 4_000_000, 'attributed_lawyer_id' => $this->lead->id]);

    failNextStatementMatching('/^update\W+payments/i', 1213);

    expect(fn () => app(VoidPayment::class)->handle($this->accountant, $payment, str_repeat('a', 20)))
        ->toThrow(ValidationException::class, 'Có người vừa thay đổi khoản này, anh/chị thử lại.');

    expect($payment->fresh()->voided_at)->toBeNull();
});

it('turns a record-changed error (1020) while amending into the retry message, and changes nothing', function () {
    failNextStatementMatching('/from\W+instalments/i', 1020);

    expect(fn () => app(AmendContract::class)->handle(
        $this->lead,
        $this->contract,
        12_000_000,
        [['action' => AmendContract::UPDATE, 'instalment_id' => $this->instalment->id, 'amount' => 12_000_000]],
        str_repeat('a', 20),
        today()->toDateString(),
    ))->toThrow(ValidationException::class, 'Có người vừa thay đổi khoản này, anh/chị thử lại.');

    expect($this->contract->fresh()->total_amount)->toBe(10_000_000)
        ->and($this->instalment->fresh()->amount)->toBe(10_000_000);
});

it('lets any other database error through unchanged — only 1020, 1205 and 1213 mean "someone changed it, try again"', function () {
    failNextStatementMatching('/^insert\W+into\W+payments/i', 1062);

    expect(fn () => recordSixMillion($this->accountant, $this->instalment))
        ->toThrow(QueryException::class, 'Duplicate entry');
});

it('turns a lock wait timeout (1205) into the same retry message, and records nothing', function () {
    failNextStatementMatching('/sum\(.?amount.?\).*from\W+payments/i', 1205);

    expect(fn () => recordSixMillion($this->accountant, $this->instalment))
        ->toThrow(ValidationException::class, 'Có người vừa thay đổi khoản này, anh/chị thử lại.');

    expect(Payment::query()->where('instalment_id', $this->instalment->id)->count())->toBe(0);
});

// --- Chạy lại ở transaction ngoài cùng (lượt sửa thứ ba, I-1) ---------------------------------------

/**
 * Rời transaction mà `RefreshDatabase` bọc quanh bài test, để Action mở transaction NGOÀI CÙNG như
 * trong sản xuất (Laravel chỉ chạy lại transaction ngoài cùng). Dữ liệu dựng ở `beforeEach` được
 * commit thật; `$migrated = false` để bài test kế tiếp dựng lại schema sạch (cùng thành ngữ
 * `RecordPaymentConcurrencyTest`).
 */
function leaveRefreshDatabaseTransaction(): void
{
    RefreshDatabaseState::$migrated = false;
    DB::commit();

    expect(DB::transactionLevel())->toBe(0);
}

it('runs the whole money transaction again after losing once, and records the payment exactly once', function () {
    leaveRefreshDatabaseTransaction();

    // Thua ở câu ghi CUỐI (dòng nhật ký), sau khi dòng `payments` của lần đầu đã ghi: lần chạy lại
    // chỉ để lại MỘT khoản thu và MỘT dòng nhật ký — lần thua đã được rollback trọn vẹn.
    $attempts = 0;
    DB::beforeExecuting(function (string $sql) use (&$attempts): void {
        if (preg_match('/^insert\W+into\W+activity_log/i', $sql) === 1 && ++$attempts === 1) {
            throw mariadbQueryException(1213, $sql);
        }
    });

    $payment = recordSixMillion($this->accountant, $this->instalment);

    expect($attempts)->toBe(2)
        ->and(Payment::query()->where('instalment_id', $this->instalment->id)->pluck('id')->all())->toBe([$payment->id])
        ->and(Activity::query()->where('event', 'payment_recorded')->count())->toBe(1);
});

it('gives up after three attempts that all lose, with the Vietnamese retry message and nothing written — never a 500', function (int $driverCode) {
    leaveRefreshDatabaseTransaction();

    $attempts = 0;
    DB::beforeExecuting(function (string $sql) use (&$attempts, $driverCode): void {
        if (preg_match('/^insert\W+into\W+payments/i', $sql) === 1) {
            $attempts++;

            throw mariadbQueryException($driverCode, $sql);
        }
    });

    expect(fn () => recordSixMillion($this->accountant, $this->instalment))
        ->toThrow(ValidationException::class, 'Có người vừa thay đổi khoản này, anh/chị thử lại.');

    expect($attempts)->toBe(3)
        ->and(Payment::query()->where('instalment_id', $this->instalment->id)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'payment_recorded')->count())->toBe(0);
})->with([
    'deadlock 1213' => [1213],
    'record changed 1020' => [1020],
    'lock wait timeout 1205' => [1205],
]);

it('does not run a money transaction again for an ordinary refusal', function () {
    leaveRefreshDatabaseTransaction();

    $locks = 0;
    DB::beforeExecuting(function (string $sql) use (&$locks): void {
        if (preg_match('/^select \* from\W+matters\W/i', $sql) === 1) {
            $locks++;
        }
    });

    expect(fn () => app(RecordPayment::class)->handle($this->accountant, $this->instalment, 20_000_000, today(), PaymentMethod::BankTransfer, null, null, null))
        ->toThrow(PaymentExceedsInstalment::class);

    expect($locks)->toBe(1);
});

// --- Chốt đóng: hàng con vừa khoá phải còn trỏ đúng hàng cha đã thăm dò ------------------------------

/**
 * Đổi một cột khoá ngoại NGAY TRƯỚC lần khoá `matters` đầu tiên — tức giữa lần thăm dò (ngoài
 * transaction) và lần khoá. Không đường ghi nào của ứng dụng làm vậy; đây là đúng cái "không bao
 * giờ xảy ra" mà chốt đóng tồn tại để không TIN.
 */
function moveRowBeforeFirstMatterLock(Closure $move): void
{
    $armed = true;

    DB::beforeExecuting(function (string $sql) use (&$armed, $move): void {
        if ($armed && preg_match('/^select \* from\W+matters\W/i', $sql) === 1) {
            $armed = false;
            $move();
        }
    });
}

it('refuses, writing nothing, when the contract no longer belongs to the matter it probed', function () {
    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);

    moveRowBeforeFirstMatterLock(fn () => DB::table('contracts')->where('id', $this->contract->id)->update(['matter_id' => $otherMatter->id]));

    expect(fn () => recordSixMillion($this->accountant, $this->instalment))
        ->toThrow(LogicException::class, "contracts #{$this->contract->id}");

    expect(Payment::query()->count())->toBe(0);
});

it('refuses, writing nothing, when the instalment no longer belongs to the contract it probed', function () {
    $otherContract = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]))->active()->create(['total_amount' => 10_000_000]);

    moveRowBeforeFirstMatterLock(fn () => DB::table('instalments')->where('id', $this->instalment->id)->update(['contract_id' => $otherContract->id]));

    expect(fn () => app(WaiveInstalment::class)->handle($this->lead, $this->instalment, str_repeat('m', 20)))
        ->toThrow(LogicException::class, "instalments #{$this->instalment->id}");

    expect($this->instalment->fresh()->status)->toBe(InstalmentStatus::Pending);
});

it('refuses, writing nothing, when the payment no longer belongs to the instalment it probed', function () {
    $payment = Payment::factory()->for($this->instalment)->create(['amount' => 4_000_000, 'attributed_lawyer_id' => $this->lead->id]);
    $otherContract = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]))->active()->create(['total_amount' => 10_000_000]);
    $otherInstalment = Instalment::factory()->for($otherContract)->create(['amount' => 10_000_000, 'status' => InstalmentStatus::Pending]);

    moveRowBeforeFirstMatterLock(fn () => DB::table('payments')->where('id', $payment->id)->update(['instalment_id' => $otherInstalment->id]));

    expect(fn () => app(VoidPayment::class)->handle($this->accountant, $payment, str_repeat('v', 20)))
        ->toThrow(LogicException::class, "payments #{$payment->id}");

    expect($payment->fresh()->voided_at)->toBeNull();
});
