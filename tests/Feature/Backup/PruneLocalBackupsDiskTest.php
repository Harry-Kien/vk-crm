<?php

use App\Actions\Backup\PruneLocalBackupsDisk;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| §10.8 — dọn disk local_backups xuống còn BACKUP_LOCAL_KEEP bản mới nhất (M8a Task 2, Ruling 2)
|--------------------------------------------------------------------------
|
| Chỉ chạy SAU một lượt đẩy rclone đã XÁC MINH thành công — bài test này kiểm riêng phần "dọn theo
| đúng số lượng", tách khỏi việc gọi nó đúng lúc (bài đó ở PushBackupArchiveToRcloneTest).
*/

function putFakeArchives(Filesystem $disk, string $folder, int $count): void
{
    for ($age = $count; $age >= 1; $age--) {
        $path = "{$folder}/vk-crm-fake-age-{$age}.zip";
        $disk->put($path, 'noi-dung-gia-lap');
        touch($disk->path($path), now()->copy()->subMinutes($age)->getTimestamp());
    }
}

it('§10.8 không xoá gì khi số bản còn dưới hoặc bằng BACKUP_LOCAL_KEEP', function () {
    config(['vkcrm.backup.local_keep' => 7]);
    Storage::fake('prune_local_disk_1');
    $disk = Storage::disk('prune_local_disk_1');

    putFakeArchives($disk, 'VK-CRM', 7);

    app(PruneLocalBackupsDisk::class)->handle($disk, 'VK-CRM');

    expect($disk->allFiles('VK-CRM'))->toHaveCount(7);
});

it('§10.8 xoá đúng các bản CŨ HƠN, giữ lại N bản mới nhất', function () {
    config(['vkcrm.backup.local_keep' => 7]);
    Storage::fake('prune_local_disk_2');
    $disk = Storage::disk('prune_local_disk_2');

    putFakeArchives($disk, 'VK-CRM', 10);

    app(PruneLocalBackupsDisk::class)->handle($disk, 'VK-CRM');

    $remaining = $disk->allFiles('VK-CRM');
    expect($remaining)->toHaveCount(7);

    // 10 bản, tuổi 1..10 phút — còn lại phải là 7 bản MỚI NHẤT (tuổi 1..7), không phải 7 bản đầu
    // theo tên tệp hay theo thứ tự liệt kê.
    foreach (range(1, 7) as $age) {
        expect($remaining)->toContain("VK-CRM/vk-crm-fake-age-{$age}.zip");
    }
    foreach (range(8, 10) as $age) {
        expect($remaining)->not->toContain("VK-CRM/vk-crm-fake-age-{$age}.zip");
    }
});

it('§10.8 không bao giờ xoá bản MỚI NHẤT dù BACKUP_LOCAL_KEEP cấu hình sai (0)', function () {
    // `config/vkcrm.php` đã chặn 0 bằng `max(1, ...)`, nhưng bài này kiểm PHÒNG THỦ THỨ HAI ngay
    // trong chính Action — đặt thẳng giá trị 0 vào config runtime, bỏ qua lớp chặn ở tệp cấu hình.
    config(['vkcrm.backup.local_keep' => 0]);
    Storage::fake('prune_local_disk_3');
    $disk = Storage::disk('prune_local_disk_3');

    putFakeArchives($disk, 'VK-CRM', 3);

    app(PruneLocalBackupsDisk::class)->handle($disk, 'VK-CRM');

    // max(1, 0) = 1: giữ đúng 1 bản, và đó phải là bản MỚI NHẤT (tuổi 1 phút).
    expect($disk->allFiles('VK-CRM'))->toBe(['VK-CRM/vk-crm-fake-age-1.zip']);
});
