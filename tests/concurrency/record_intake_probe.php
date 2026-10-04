<?php

use App\Actions\Intake\RecordIntake;
use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Script hỗ trợ MỘT test duy nhất: tests/Feature/Intake/RecordIntakeConcurrencyTest.php (M10 Task 2,
 * R1: "hai người nhận hai cuộc gọi đối nhau cùng lúc"). KHÔNG phải một tệp Pest — một tiến trình PHP
 * con thật (chạy bằng `php` trần), để hai lần gọi `RecordIntake` xảy ra trên hai tiến trình hệ điều
 * hành riêng với hai kết nối DB riêng; cùng khuôn `tests/concurrency/open_matter_probe.php`, đọc
 * docblock ở đó cho lý do của rào chắn khởi động.
 *
 * Tham số dòng lệnh, theo thứ tự: <actor_user_id> <contact_name> <contact_phone> <contact_role>
 * <opposing_phone> <opposing_role> <barrier_file>. Tiến trình A ghi "X (nguyên đơn) khai Y (bị đơn)",
 * tiến trình B ghi "Y (bị đơn) khai X (nguyên đơn)" — hai cuộc gọi đối nhau.
 *
 * In ra ĐÚNG MỘT dòng: "GREEN <code>", "YELLOW <code>", "RED <code>" hoặc "ERROR <thông điệp>".
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[$script, $actorId, $contactName, $contactPhone, $contactRole, $opposingPhone, $opposingRole, $barrierFile] = $argv;

$deadline = microtime(true) + 5;
while (! file_exists($barrierFile) && microtime(true) < $deadline) {
    usleep(1_000);
}

try {
    $actor = User::query()->findOrFail((int) $actorId);

    $result = app(RecordIntake::class)->handle(
        $actor,
        [
            'contact_name' => $contactName,
            'contact_phone' => $contactPhone,
            'contact_role' => PartyRole::from($contactRole),
            'source' => 'phone',
        ],
        [['name' => 'Bên đối lập (đồng thời)', 'role' => PartyRole::from($opposingRole), 'phone' => $opposingPhone]],
    );

    $label = match ($result->conflict->level) {
        ConflictLevel::Green => 'GREEN',
        ConflictLevel::Yellow => 'YELLOW',
        ConflictLevel::Red => 'RED',
    };

    fwrite(STDOUT, $label.' '.$result->intake->code.PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDOUT, 'ERROR '.$exception::class.': '.$exception->getMessage().PHP_EOL);
}
