<?php

use App\Actions\Billing\DraftContract;
use App\Actions\Billing\RecordPayment;
use App\Enums\PaymentMethod;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Script hỗ trợ MỘT tệp test: tests/Feature/Actions/Billing/MoneyTransactionConcurrencyTest.php
 * (lượt sửa thứ ba sau rà soát cuối M9, I-1/M-1). KHÔNG phải một tệp Pest — một tiến trình PHP
 * con thật, một KẾT NỐI DB riêng, cùng hình dạng `record_payment_probe.php`.
 *
 * Hai Action tiền trên HAI VỤ VIỆC KHÁC NHAU không bao giờ tranh nhau khoá `matters`, nhưng vẫn
 * chạm nhau ở chỗ khác: khoá khe (gap/next-key lock) của `SUM … for update` trên `payments` khi
 * hai đợt mới cùng rơi vào một khe của chỉ mục `instalment_id`, và hàng dùng chung
 * `code_sequences` (`contract:{năm}`) của `DraftContract`. Để hai phiên CHẮC CHẮN chồng lên nhau
 * đúng chỗ đó (không phó mặc cho may rủi của thời gian khởi động), mỗi tiến trình dừng ở một RÀO
 * CHẮN ngay TRƯỚC câu SQL ghi đầu tiên vào chỗ chung (`insert into payments` /
 * `insert ignore into code_sequences`): tạo tệp của mình, đợi tệp của bên kia (tối đa 20 giây),
 * rồi mới chạy câu đó. Rào chắn chỉ bật MỘT lần — lần thử lại (nếu có) chạy thẳng.
 *
 * Hai vai:
 *  - `record <actor_id> <instalment_id> <amount> <my_file> <their_file>` — `RecordPayment`.
 *  - `draft <actor_id> <matter_id> <amount> <my_file> <their_file>` — `DraftContract`, một đợt.
 *
 * In MỘT dòng: "GREEN <id hoặc mã hợp đồng> tries=<số lần câu ghi chung đã chạy>", "REFUSED
 * <câu lỗi>" (một `ValidationException` — ví dụ câu "thử lại" sau 1213/1020/1205) hoặc
 * "ERROR <lớp>: <thông điệp>". `tries` = 2 nghĩa là lần đầu thua deadlock/1020 và Laravel đã
 * chạy lại cả transaction — bằng chứng cuộc chồng lấn THẬT SỰ xảy ra.
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $role, $actorId, $targetId, $amount, $myFile, $theirFile] = $argv;

$sharedWrite = $role === 'record'
    ? '/^insert into\W+payments\W/i'
    : '/^insert ignore into\W+code_sequences\W/i';

$tries = 0;
$armed = true;

DB::beforeExecuting(function (string $sql) use ($sharedWrite, &$tries, &$armed, $myFile, $theirFile): void {
    if (preg_match($sharedWrite, $sql) !== 1) {
        return;
    }

    $tries++;

    if (! $armed) {
        return;
    }

    $armed = false;
    file_put_contents($myFile, '1');

    $deadline = microtime(true) + 20;
    while (! file_exists($theirFile) && microtime(true) < $deadline) {
        usleep(1_000);
    }
});

try {
    $actor = User::query()->findOrFail((int) $actorId);

    if ($role === 'record') {
        $instalment = Instalment::query()->withoutGlobalScope(ClientPortalScope::class)->findOrFail((int) $targetId);
        $payment = app(RecordPayment::class)->handle(
            $actor, $instalment, (int) $amount, today(), PaymentMethod::BankTransfer, null, null, null,
        );

        fwrite(STDOUT, 'GREEN '.$payment->id.' tries='.$tries.PHP_EOL);
    } elseif ($role === 'draft') {
        $matter = Matter::query()->withoutGlobalScope(ClientPortalScope::class)->findOrFail((int) $targetId);
        $contract = app(DraftContract::class)->handle($actor, $matter, ['total_amount' => (int) $amount], [
            ['name' => 'Trọn gói', 'amount' => (int) $amount, 'trigger_type' => 'on_signing'],
        ]);

        fwrite(STDOUT, 'GREEN '.$contract->code.' tries='.$tries.PHP_EOL);
    } else {
        fwrite(STDOUT, 'ERROR unknown role ['.$role.']'.PHP_EOL);
    }
} catch (ValidationException $exception) {
    fwrite(STDOUT, 'REFUSED tries='.$tries.' '.collect($exception->errors())->flatten()->first().PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDOUT, 'ERROR '.$exception::class.': '.$exception->getMessage().PHP_EOL);
}
