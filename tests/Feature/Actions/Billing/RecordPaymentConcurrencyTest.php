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
 * Lượt sửa thứ hai sau rà soát cuối M9, **N1 (Critical)**: lần đọc thăm dò KHÔNG khoá chạy BÊN
 * TRONG transaction, trước khoá đầu tiên, đóng băng ảnh chụp REPEATABLE READ của InnoDB — mọi lần
 * đọc thường sau đó (tổng đã thu của chốt chặn thu vượt) thấy dữ liệu như TRƯỚC lúc đợi khoá.
 *
 * Kịch bản của phán quyết, dựng bằng hai tiến trình con thật (hai kết nối DB — điều DUY NHẤT tái
 * hiện được thứ khoá hàng và ảnh chụp giao dịch thật sự làm; cùng hình dạng
 * `TransitionMatterStageConcurrencyTest`/`OpenMatterConcurrencyTest` của M6.5), trên một đợt 10
 * triệu:
 *
 *  - Phiên A khoá hàng `matters` của vụ, ghi một khoản thu 6 triệu, GIỮ khoá ~3 giây rồi commit.
 *  - Phiên B, đúng lúc A đang giữ khoá (khoản thu của A đã ghi, chưa commit), gọi
 *    `RecordPayment` 6 triệu trên CÙNG đợt.
 *
 * Trước bản sửa: lần thăm dò `instalments.contract_id` của B mở ảnh chụp khi khoản thu của A chưa
 * commit; B đợi khoá `matters`; A commit; B lấy được khoá nhưng SUM() đọc thường vẫn trên ảnh chụp
 * cũ → thấy 0 → cho qua → 12 triệu trên một đợt 10 triệu. Sau bản sửa: thăm dò chạy TRƯỚC khi
 * transaction mở, câu đầu tiên trong transaction là một lần đọc CÓ KHOÁ, và tổng đã thu đọc bằng
 * một lần đọc có khoá → B thấy 6 triệu → bị từ chối thu vượt, tổng vẫn 6 triệu.
 *
 * `<ms>` mà B in ra phải ≥ 1,5 giây: chứng minh B THẬT SỰ đã đợi khoá của A (nếu B chạy sau khi A
 * commit xong thì bài test xanh mà không chứng minh gì).
 *
 * **`DB::commit()` giữa bài test — cố ý**, cùng lý do và cùng hình dạng với
 * `TransitionMatterStageConcurrencyTest` của M6.5: hai tiến trình con mở kết nối DB riêng, nên dữ
 * liệu dựng bên trong transaction bọc bài test (RefreshDatabase) phải COMMIT thật thì chúng mới
 * thấy. `RefreshDatabaseState::$migrated = false` đặt ngay trước commit để bài test KẾ TIẾP trong
 * cùng lượt chạy `migrate:fresh` lại, không tái dùng một schema đã có dữ liệu commit.
 *
 * Chỉ chạy dưới MariaDB thật (`test:mariadb` của công cụ làn), MỘT MÌNH, không song song: SQLite
 * trong bộ nhớ không có hai kết nối và bỏ qua khoá hàng.
 */
it('refuses a concurrent overpayment: a RecordPayment waiting on another session\'s matter lock sees the payment that session committed', function () {
    (new RolesAndPermissionsSeeder)->run();

    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($contract)->create([
        'amount' => 10_000_000,
        'due_date' => today()->addDays(10)->toDateString(),
        'status' => InstalmentStatus::Pending,
    ]);

    RefreshDatabaseState::$migrated = false;

    // Xem docblock: phải commit thật để hai tiến trình con (kết nối DB riêng) thấy dữ liệu vừa dựng.
    DB::commit();

    $script = base_path('tests/concurrency/record_payment_probe.php');
    $signalFile = sys_get_temp_dir().'/vkcrm-record-payment-held-'.uniqid().'.txt';

    $sessionA = Process::timeout(60)->start([
        'php', $script, 'hold', (string) $matter->id, (string) $instalment->id, '6000000', (string) $accountant->id, $signalFile, '3000',
    ]);
    $sessionB = Process::timeout(60)->start([
        'php', $script, 'record', (string) $accountant->id, (string) $instalment->id, '6000000', $signalFile,
    ]);

    try {
        $resultA = $sessionA->wait();
        $resultB = $sessionB->wait();
    } finally {
        @unlink($signalFile);
    }

    $outputA = trim($resultA->output().$resultA->errorOutput());
    $outputB = trim($resultB->output().$resultB->errorOutput());

    expect($outputA)->toStartWith('HELD')
        ->and($outputB)->toStartWith('OVERPAYMENT');

    $waitedMs = (int) (explode(' ', $outputB)[1] ?? 0);
    expect($waitedMs)->toBeGreaterThanOrEqual(1_500);

    $live = Payment::query()->where('instalment_id', $instalment->id)->whereNull('voided_at');

    expect((int) (clone $live)->sum('amount'))->toBe(6_000_000)
        ->and((clone $live)->count())->toBe(1)
        ->and($instalment->fresh()->status)->toBe(InstalmentStatus::Pending);
})->skip(
    fn (): bool => config('database.default') !== 'mariadb',
    'chỉ có ý nghĩa dưới MariaDB thật (test:mariadb của công cụ làn, chạy một mình) — SQLite trong bộ nhớ không dựng lại được hai kết nối song song và bỏ qua khoá hàng',
);
