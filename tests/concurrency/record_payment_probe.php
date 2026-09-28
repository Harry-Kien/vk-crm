<?php

use App\Actions\Billing\RecordPayment;
use App\Enums\PaymentMethod;
use App\Exceptions\PaymentExceedsInstalment;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Script hỗ trợ MỘT test duy nhất: tests/Feature/Actions/Billing/RecordPaymentConcurrencyTest.php
 * (lượt sửa thứ hai sau rà soát cuối M9, N1). KHÔNG phải một tệp Pest — một tiến trình PHP con
 * thật, chạy bằng `php` trần, để hai phiên làm việc trên CÙNG một đợt chạy trên hai tiến trình HĐH
 * riêng, hai KẾT NỐI DB riêng: `lockForUpdate()` chỉ khoá thật giữa hai kết nối, và ảnh chụp
 * REPEATABLE READ của InnoDB chỉ "đóng băng" được trong một transaction của một kết nối KHÁC với
 * kết nối vừa ghi. Cùng hình dạng với `tests/concurrency/open_matter_probe.php` và
 * `transition_matter_stage_probe.php` của M6.5 (đọc docblock ở đó cho lý lẽ về rào chắn).
 *
 * Hai vai, chọn bằng tham số đầu tiên:
 *
 *  - `hold <matter_id> <instalment_id> <amount> <actor_id> <signal_file> <hold_ms>` — phiên A:
 *    mở transaction, KHOÁ hàng `matters` (đúng khoá đầu tiên của mọi Action tiền), ghi một khoản
 *    thu `<amount>` cho đợt, rồi tạo `<signal_file>` báo "đang giữ khoá, khoản thu chưa commit",
 *    ngủ `<hold_ms>` mili giây, rồi COMMIT. In "HELD <payment_id>".
 *  - `record <actor_id> <instalment_id> <amount> <signal_file>` — phiên B: đọc người dùng và đợt
 *    TRƯỚC (như một request thật đọc trước khi vào Action), đợi `<signal_file>` xuất hiện, rồi gọi
 *    `RecordPayment::handle()` với `<amount>` trên CÙNG đợt — tức lúc A đang giữ khoá và khoản thu
 *    của A đã ghi mà CHƯA commit. In MỘT dòng: "GREEN <payment_id> <ms>", "OVERPAYMENT <ms>",
 *    "REFUSED <ms> <câu lỗi>" (một `ValidationException`, ví dụ câu "thử lại" khi MariaDB báo
 *    1020/1213) hoặc "ERROR <lớp>: <thông điệp>". `<ms>` là thời gian `handle()` chạy — test cha
 *    dùng nó để chứng minh B THẬT SỰ đã phải đợi khoá của A (không thì bài test chứng minh rỗng).
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$role = $argv[1] ?? '';

/** Đợi tệp tín hiệu, tối đa 20 giây (bootstrap hai tiến trình song song trong container 2 CPU). */
function waitForSignal(string $signalFile): void
{
    $deadline = microtime(true) + 20;

    while (! file_exists($signalFile) && microtime(true) < $deadline) {
        usleep(1_000);
    }
}

try {
    if ($role === 'hold') {
        [, , $matterId, $instalmentId, $amount, $actorId, $signalFile, $holdMs] = $argv;

        $actor = User::query()->findOrFail((int) $actorId);

        $paymentId = DB::transaction(function () use ($matterId, $instalmentId, $amount, $actor, $signalFile, $holdMs): int {
            Matter::query()->withoutGlobalScope(ClientPortalScope::class)->withTrashed()
                ->whereKey((int) $matterId)->lockForUpdate()->firstOrFail();

            $payment = new Payment([
                'instalment_id' => (int) $instalmentId,
                'amount' => (int) $amount,
                'paid_on' => today()->toDateString(),
                'method' => PaymentMethod::BankTransfer,
                'attributed_lawyer_id' => $actor->id,
            ]);
            $payment->blameOn($actor)->save();

            file_put_contents($signalFile, '1');
            usleep((int) $holdMs * 1_000);

            return (int) $payment->id;
        });

        fwrite(STDOUT, 'HELD '.$paymentId.PHP_EOL);
    } elseif ($role === 'record') {
        [, , $actorId, $instalmentId, $amount, $signalFile] = $argv;

        $actor = User::query()->findOrFail((int) $actorId);
        $instalment = Instalment::query()->withoutGlobalScope(ClientPortalScope::class)->findOrFail((int) $instalmentId);

        waitForSignal($signalFile);

        $started = microtime(true);
        $elapsed = fn (): int => (int) round((microtime(true) - $started) * 1000);

        try {
            $payment = app(RecordPayment::class)->handle(
                $actor, $instalment, (int) $amount, today(), PaymentMethod::BankTransfer, null, null, null,
            );

            fwrite(STDOUT, 'GREEN '.$payment->id.' '.$elapsed().PHP_EOL);
        } catch (PaymentExceedsInstalment) {
            fwrite(STDOUT, 'OVERPAYMENT '.$elapsed().PHP_EOL);
        } catch (ValidationException $exception) {
            fwrite(STDOUT, 'REFUSED '.$elapsed().' '.collect($exception->errors())->flatten()->first().PHP_EOL);
        }
    } else {
        fwrite(STDOUT, 'ERROR unknown role ['.$role.']'.PHP_EOL);
    }
} catch (Throwable $exception) {
    fwrite(STDOUT, 'ERROR '.$exception::class.': '.$exception->getMessage().PHP_EOL);
}
