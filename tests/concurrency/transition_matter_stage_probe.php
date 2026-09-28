<?php

use App\Actions\TransitionMatterStage;
use App\Exceptions\MatterStageChanged;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Script hỗ trợ MỘT test duy nhất: tests/Feature/Actions/TransitionMatterStageConcurrencyTest.php
 * (`stage/stage-05`, Review Focus 4, M6.5 Task 10). KHÔNG phải một tệp Pest — một tiến trình PHP
 * con thật, chạy bằng `php` trần, để hai lần gọi `TransitionMatterStage::handle()` xảy ra trên hai
 * tiến trình HĐH riêng, hai kết nối DB riêng — điều một tệp Pest chạy trong một tiến trình PHP duy
 * nhất không dựng lại được (`lockForUpdate()` chỉ khoá thật giữa hai KẾT NỐI, không giữa hai
 * transaction lồng nhau trên cùng một kết nối). Cùng hình dạng với
 * `tests/concurrency/open_matter_probe.php` (M6.5 Task 8) — đọc docblock ở đó cho lý lẽ đầy đủ về
 * rào chắn khởi động; ở đây chỉ tóm tắt phần khác biệt.
 *
 * Cả hai tiến trình chuyển CÙNG một vụ việc sang CÙNG một `to_stage` — mô phỏng đúng kịch bản
 * "bấm hai lần"/"hai tab" của Review Focus 4: cả hai đọc `$matter` (findOrFail, không khoá) trước
 * rào chắn, nên cả hai cầm trong tay CÙNG một snapshot "giai đoạn gốc". Tiến trình chạy TRƯỚC
 * commit thành công; tiến trình chạy SAU phải bị `MatterStageChanged` từ chối vì giai đoạn đã đổi
 * dưới chân nó trong lúc đợi khoá — không phải vì `to_stage` không hợp lệ.
 *
 * Tham số dòng lệnh, theo thứ tự: <matter_id> <actor_id> <to_stage> <barrier_file>.
 *
 * In ra ĐÚNG MỘT dòng ra stdout, một trong ba giá trị: "GREEN <stage_log_id>",
 * "STAGE_CHANGED", hoặc "ERROR <thông điệp>" — test cha đọc dòng này để biết kết quả.
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[$script, $matterId, $actorId, $toStage, $barrierFile] = $argv;

// Đọc TRƯỚC rào chắn, TRƯỚC transaction của handle() — đúng snapshot "giai đoạn gốc" mà cả hai
// tiến trình cùng cầm, cùng hình dạng với hai request thật đọc $matter trước khi vào Action.
$actor = User::query()->findOrFail((int) $actorId);
$matter = Matter::query()->findOrFail((int) $matterId);

$deadline = microtime(true) + 5;
while (! file_exists($barrierFile) && microtime(true) < $deadline) {
    usleep(1_000);
}

try {
    $stageLog = app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $actor,
        toStage: $toStage,
        occurredAt: now(),
        internalNote: null,
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );

    fwrite(STDOUT, 'GREEN '.$stageLog->id.PHP_EOL);
} catch (MatterStageChanged $exception) {
    fwrite(STDOUT, 'STAGE_CHANGED'.PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDOUT, 'ERROR '.$exception::class.': '.$exception->getMessage().PHP_EOL);
}
