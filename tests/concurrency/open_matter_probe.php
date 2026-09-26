<?php

use App\Actions\OpenMatter;
use App\Enums\PartyRole;
use App\Exceptions\ConflictBlocked;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Script hỗ trợ MỘT test duy nhất: tests/Feature/Actions/OpenMatterConcurrencyTest.php (R13g,
 * conflict-11, M6.5 Task 8). KHÔNG phải một tệp Pest — đây là một tiến trình PHP con thật, chạy
 * bằng `php` trần (không qua artisan), để hai lần gọi OpenMatter xảy ra trên hai tiến trình HĐH
 * riêng, hai kết nối DB riêng — điều một tệp Pest chạy trong một tiến trình PHP duy nhất không
 * dựng lại được. Test cha spawn CHÍNH XÁC hai tiến trình chạy tệp này, mỗi bên mở một vụ việc đối
 * nhau ("X kiện W" và "W kiện X") cho hai khách hàng CHƯA từng có vụ nào — đúng kịch bản
 * `conflict-11` mô tả.
 *
 * Tham số dòng lệnh, theo thứ tự: <own_client_id> <opposing_id_number> <actor_user_id>
 * <matter_type_id> <title> <barrier_file>. Bên "own" là khách hàng chính (nguyên đơn) của vụ việc
 * tiến trình này mở. Bên đối lập là BỊ ĐƠN GÕ TAY mang đúng số căn cước thật của khách hàng kia —
 * CỐ Ý không đánh dấu `is_our_client`/`client_id`: nếu đánh dấu, `OpenMatter::lockClients()` sẽ
 * khoá CẢ hai dòng `clients` (chính mình VÀ bên đối lập) ở mỗi tiến trình, và vì cả hai tiến trình
 * khoá đúng CÙNG hai dòng theo cùng thứ tự, bản thân việc khoá dòng đó đã vô tình tuần tự hoá hai
 * tiến trình — che mất tác dụng thật của khoá `Cache::lock('conflict-check')` mà test này tồn tại
 * để đo. Đúng hình dạng gốc mà `conflict-11` mô tả: "A khoá X, B khoá Y" — không dòng nào chung.
 * `barrier_file`: tiến trình BUSY-WAIT (dò mỗi 1ms, tối đa 5 giây) cho tới khi tệp này xuất
 * hiện rồi mới gọi `OpenMatter::handle()` — test cha khởi động cả hai tiến trình TRƯỚC, rồi mới
 * tạo tệp này, để việc bootstrap Laravel (chậm và không đều giữa hai tiến trình) không tự nới rộng
 * khoảng cách giữa hai lượt gọi ra ngoài cửa sổ hẹp mà `conflict-11` mô tả — không có rào chắn
 * này, một tiến trình có thể bootstrap xong và gọi `handle()` (rồi COMMIT xong hoàn toàn) trước cả
 * khi tiến trình kia bắt đầu, khiến lỗ hổng gốc (vốn có thật, nhưng cửa sổ hẹp) không tái hiện được
 * ổn định — mutation probe của test này (tắt khoá rồi chạy lại) mới lộ ra RÕ mức cần thiết của rào
 * chắn này: không có nó, cùng một mã nguồn KHÔNG có khoá đôi khi vẫn tình cờ chạy tuần tự.
 *
 * In ra ĐÚNG MỘT dòng ra stdout, một trong ba giá trị: "GREEN <matter_code>", "RED", hoặc
 * "ERROR <thông điệp>" — test cha đọc dòng này để biết kết quả.
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[$script, $ownClientId, $opposingIdNumber, $actorId, $matterTypeId, $title, $barrierFile] = $argv;

$deadline = microtime(true) + 5;
while (! file_exists($barrierFile) && microtime(true) < $deadline) {
    usleep(1_000);
}

try {
    $actor = User::query()->findOrFail((int) $actorId);

    $opening = app(OpenMatter::class)->handle(
        $actor,
        [
            'client_id' => (int) $ownClientId,
            'client_role' => PartyRole::Plaintiff,
            'matter_type_id' => (int) $matterTypeId,
            'title' => $title,
            'lead_lawyer_id' => $actor->getKey(),
        ],
        [[
            'role' => PartyRole::Defendant->value,
            'name' => 'Bên đối lập (đồng thời)',
            'is_our_client' => false,
            'id_number' => $opposingIdNumber,
        ]],
    );

    fwrite(STDOUT, 'GREEN '.$opening->matter->code.PHP_EOL);
} catch (ConflictBlocked $exception) {
    fwrite(STDOUT, 'RED'.PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDOUT, 'ERROR '.$exception::class.': '.$exception->getMessage().PHP_EOL);
}
