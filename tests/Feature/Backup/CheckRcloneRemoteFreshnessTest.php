<?php

use App\Actions\Backup\CheckRcloneRemoteFreshness;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Spatie\Backup\Events\BackupHasFailed;

/*
|--------------------------------------------------------------------------
| §10.8 — fix I4 (lượt rà soát cuối M8a): giám sát 08:00 kiểm cả độ TƯƠI của bản trên Google Drive
|--------------------------------------------------------------------------
|
| `backup:monitor` của gói chỉ nhìn các đĩa Laravel trong `BACKUP_DISKS` — nó không biết đích rclone
| tồn tại. Một lượt đẩy hỏng lặng lẽ nhiều đêm liền (token Google hết hạn mà thư báo lỗi bị bỏ
| qua, tiến trình bị giết trước khi kịp báo) để Google Drive dừng ở một bản cũ mà không ai biết.
| Nay: khi đã cấu hình remote, bản mới nhất trong thư mục của môi trường phải dưới 36 giờ tuổi,
| tính theo mốc trong TÊN tệp (một bản cũ được tải lên lại không được tính là "tươi").
*/

const FRESHNESS_REMOTE = 'gdrive:VK-CRM-backups';
const FRESHNESS_FOLDER = 'gdrive:VK-CRM-backups/vk-crm';

beforeEach(function () {
    $this->freezeTime();
    Event::fake([BackupHasFailed::class]);
    config([
        'backup.backup.name' => 'VK-CRM',
        'backup.backup.destination.filename_prefix' => 'vk-crm-',
        'vkcrm.backup.rclone.remote' => FRESHNESS_REMOTE,
    ]);
});

function freshnessArchive(int $ageInHours, ?string $modTime = null): array
{
    return [
        'Name' => 'vk-crm-'.now()->copy()->subHours($ageInHours)->format('Y-m-d-H-i-s').'.zip',
        'Size' => 1000,
        'ModTime' => $modTime ?? now()->copy()->subHours($ageInHours)->toIso8601String(),
        'IsDir' => false,
    ];
}

function fakeFreshnessListing(array $entries): void
{
    Process::fake(fn ($process) => in_array('lsjson', $process->command, true)
        ? Process::result(output: json_encode($entries))
        : Process::result(exitCode: 1, errorOutput: 'lệnh không mong đợi'));
}

function assertFreshnessAlert(string $messageContains): void
{
    Event::assertDispatched(BackupHasFailed::class, fn (BackupHasFailed $event) => $event->diskName === 'rclone:'.FRESHNESS_REMOTE
        && str_contains($event->exception->getMessage(), $messageContains));
}

it('§10.8 BACKUP_RCLONE_REMOTE rỗng: không chạy rclone, không báo', function () {
    config(['vkcrm.backup.rclone.remote' => null]);

    app(CheckRcloneRemoteFreshness::class)->handle();

    Process::assertNothingRan();
    Event::assertNotDispatched(BackupHasFailed::class);
});

it('§10.8 bản mới nhất 35 giờ tuổi: không báo; chỉ liệt kê thư mục của môi trường', function () {
    fakeFreshnessListing([freshnessArchive(59), freshnessArchive(35), freshnessArchive(83)]);

    app(CheckRcloneRemoteFreshness::class)->handle();

    Process::assertRan(fn ($process) => $process->command === ['rclone', 'lsjson', FRESHNESS_FOLDER]);
    Event::assertNotDispatched(BackupHasFailed::class);
});

it('§10.8 bản mới nhất 37 giờ tuổi: báo, nêu tên tệp và số giờ', function () {
    $newest = freshnessArchive(37);
    fakeFreshnessListing([freshnessArchive(61), $newest]);

    app(CheckRcloneRemoteFreshness::class)->handle();

    assertFreshnessAlert($newest['Name']);
    assertFreshnessAlert('37 giờ');
});

it('§10.8 bản cũ được tải lên lại (ModTime mới tinh) không làm Google Drive trông "tươi"', function () {
    fakeFreshnessListing([freshnessArchive(50, modTime: now()->toIso8601String())]);

    app(CheckRcloneRemoteFreshness::class)->handle();

    assertFreshnessAlert('50 giờ');
});

it('§10.8 thư mục môi trường không có archive nào (chỉ tệp lạ, hay archive của môi trường khác): báo', function () {
    fakeFreshnessListing([
        ['Name' => 'ghi-chu.pdf', 'Size' => 10, 'ModTime' => now()->toIso8601String(), 'IsDir' => false],
        ['Name' => 'vk-crm-staging-'.now()->format('Y-m-d-H-i-s').'.zip', 'Size' => 10, 'ModTime' => now()->toIso8601String(), 'IsDir' => false],
    ]);

    app(CheckRcloneRemoteFreshness::class)->handle();

    assertFreshnessAlert(FRESHNESS_FOLDER);
});

it('§10.8 không liệt kê được remote (lỗi rclone): báo, kèm lỗi của rclone', function () {
    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'token Google đã hết hạn'));

    app(CheckRcloneRemoteFreshness::class)->handle();

    assertFreshnessAlert('token Google đã hết hạn');
});
