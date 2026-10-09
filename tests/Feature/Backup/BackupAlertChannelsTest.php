<?php

use App\Actions\Backup\PushBackupArchiveToRclone;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Filament\Admin\Widgets\SystemHealthWidget;
use App\Models\OutboundMessage;
use App\Models\SystemHealth;
use App\Models\User;
use App\Notifications\Backup\BackupHasFailedNotification;
use App\Notifications\Backup\CleanupHasFailedNotification;
use App\Notifications\Backup\UnhealthyBackupWasFoundNotification;
use App\Support\Backup\BackupDisks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;
use Spatie\Backup\Notifications\EventHandler;

/*
|--------------------------------------------------------------------------
| Làn fc (kiểm tra nghiệp vụ 2026-10-09, mục B "cảnh báo sao lưu chỉ có một kênh")
|--------------------------------------------------------------------------
|
| Trước làn này cảnh báo sao lưu đi đúng MỘT đường: ba thông báo của gói xếp hàng một thư, thư đó thử
| đúng một lần (không khai `$tries`, `queue.drain` chạy `queue:work` không `--tries`), không gửi lại
| được, và trang chủ không có gì. SMTP chết đúng đêm sao lưu hỏng là không ai biết. Nay:
|
|  - ba lớp thông báo thử 5 lần với cùng backoff 60/300/900/3600 giây như mọi job thư khác;
|  - lượt đẩy lên Google Drive ĐÃ XÁC MINH ghi `system_health.last_offsite_backup_at`;
|  - dải sức khoẻ trên trang chủ `/admin` (người có `settings.manage`) có một dòng đỏ khi đã cấu hình
|    Google Drive mà bản gần nhất lên đó cũ hơn ngưỡng 36 giờ (hay chưa từng có), và khi một thư báo
|    lỗi sao lưu ở trạng thái `failed` trong 7 ngày qua — không phụ thuộc thư có đi được hay không.
|
| Hàm toàn cục mang tiền tố `bac…`.
*/

beforeEach(function () {
    EventHandler::enable();
    $this->seed(RolesAndPermissionsSeeder::class);
    // Cột timestamp lưu tới giây: đứng yên ở đầu một giây để so bằng được.
    $this->freezeSecond();
});

afterEach(fn () => EventHandler::enable());

it('ba thông báo sao lưu thử 5 lần với backoff tăng dần, như mọi job thư khác', function (object $event, string $notificationClass) {
    Queue::fake();

    event($event);

    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof $notificationClass
        && $job->tries === 5
        && $job->backoff() === [60, 300, 900, 3600]);
})->with([
    'sao lưu hỏng' => [fn () => new BackupHasFailed(new Exception('Ổ đĩa đầy'), 'local_backups', 'VK-CRM'), BackupHasFailedNotification::class],
    'dọn hỏng' => [fn () => new CleanupHasFailed(new Exception('Không xoá được'), 'local_backups', 'VK-CRM'), CleanupHasFailedNotification::class],
    'không lành mạnh' => [fn () => new UnhealthyBackupWasFound(
        diskName: 'local_backups',
        backupName: 'VK-CRM',
        failureMessages: new Collection([['check' => 'MaximumAgeInDays', 'message' => 'Bản mới nhất đã 3 ngày tuổi.']]),
    ), UnhealthyBackupWasFoundNotification::class],
]);

/** Một archive vừa tạo trên local_backups, và rclone giả: copy thoát 0, lsjson trả về `$listed`. */
function bacPush(?Closure $listed = null): void
{
    config([
        'vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups',
        'backup.backup.destination.filename_prefix' => 'vk-crm-',
    ]);
    Storage::fake(BackupDisks::DEFAULT_DISK);
    $content = 'noi-dung-archive';
    $name = 'vk-crm-'.now()->format('Y-m-d-H-i-s').'.zip';
    Storage::disk(BackupDisks::DEFAULT_DISK)->put('VK-CRM/'.$name, $content);

    Process::fake(function ($process) use ($listed, $name, $content) {
        if (in_array('lsjson', $process->command, true)) {
            $entries = $listed !== null ? $listed($name, $content) : [['Name' => $name, 'Size' => strlen($content), 'ModTime' => now()->toIso8601String(), 'IsDir' => false]];

            return Process::result(output: json_encode($entries));
        }

        return Process::result(exitCode: 0);
    });

    app(PushBackupArchiveToRclone::class)->handle(new BackupWasSuccessful(BackupDisks::DEFAULT_DISK, 'VK-CRM'));
}

it('lượt đẩy lên Google Drive đã xác minh ghi thời điểm vào system_health', function () {
    Queue::fake();

    bacPush();

    expect(SystemHealth::current()->fresh()->last_offsite_backup_at?->equalTo(now()))->toBeTrue();
});

it('lượt đẩy hỏng xác minh (remote không có tệp) không ghi thời điểm', function () {
    Queue::fake();
    SystemHealth::current()->forceFill(['last_offsite_backup_at' => now()->subDays(3)])->save();

    bacPush(fn (): array => []);

    expect(SystemHealth::current()->fresh()->last_offsite_backup_at->equalTo(now()->subDays(3)))->toBeTrue();
});

/** Widget như admin hay vai khác thấy nó trên trang chủ. */
function bacWidgetAs(Role $role): Testable
{
    Filament::setCurrentPanel('admin');
    // Dải lịch "chưa từng chạy" luôn hiện: widget có một phần tử gốc để Livewire render (một widget
    // im lặng không có gốc nào), và dòng sao lưu là phần tử RIÊNG (`data-widget="backup-health"`).
    SystemHealth::current()->forceFill(['last_schedule_run_at' => null])->save();
    test()->actingAs(User::factory()->withRole($role)->create(), 'web');

    return Livewire::test(SystemHealthWidget::class);
}

it('admin thấy dòng đỏ khi đã cấu hình Google Drive mà CHƯA có bản nào lên đó', function () {
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);

    bacWidgetAs(Role::Admin)
        ->assertSeeHtml('data-widget="backup-health"')
        ->assertSee(__('ops_checks.widget.offsite_never'));
});

it('admin thấy dòng đỏ khi bản gần nhất trên Google Drive cũ hơn 36 giờ, kèm giờ của nó', function () {
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);
    $at = now()->subHours(37);
    SystemHealth::current()->forceFill(['last_offsite_backup_at' => $at])->save();

    bacWidgetAs(Role::Admin)
        ->assertSeeHtml('data-widget="backup-health"')
        ->assertSee(__('ops_checks.widget.offsite_stale', [
            'hours' => 36,
            'at' => $at->copy()->timezone(config('app.timezone'))->format('H:i d/m/Y'),
        ]));
});

it('bản gần nhất trong 36 giờ: không dòng sao lưu', function () {
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);
    SystemHealth::current()->forceFill(['last_offsite_backup_at' => now()->subHours(35)])->save();

    bacWidgetAs(Role::Admin)->assertDontSeeHtml('data-widget="backup-health"');
});

it('chưa cấu hình Google Drive (máy dev): không dòng "chưa có bản nào"', function () {
    config(['vkcrm.backup.rclone.remote' => null]);

    bacWidgetAs(Role::Admin)->assertDontSeeHtml('data-widget="backup-health"');
});

it('một thư báo lỗi sao lưu ở trạng thái failed trong 7 ngày qua là dòng đỏ, dù bản trên Drive còn mới', function () {
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);
    SystemHealth::current()->forceFill(['last_offsite_backup_at' => now()->subHour()])->save();

    OutboundMessage::factory()->create(['template' => 'staff.backup_alert.backup_failed', 'status' => OutboundStatus::Failed, 'created_at' => now()->subDays(2)]);
    OutboundMessage::factory()->create(['template' => 'staff.backup_alert.unhealthy', 'status' => OutboundStatus::Failed, 'created_at' => now()->subDay()]);
    // Không tính: thư cũ hơn 7 ngày, thư sao lưu đã gửi, thư hỏng của mẫu khác.
    OutboundMessage::factory()->create(['template' => 'staff.backup_alert.backup_failed', 'status' => OutboundStatus::Failed, 'created_at' => now()->subDays(8)]);
    OutboundMessage::factory()->sent()->create(['template' => 'staff.backup_alert.cleanup_failed']);
    OutboundMessage::factory()->create(['template' => 'client.stage_update', 'status' => OutboundStatus::Failed]);

    bacWidgetAs(Role::Admin)
        ->assertSeeHtml('data-widget="backup-health"')
        ->assertSee(__('ops_checks.widget.alert_mail_failed', ['count' => 2]));
});

it('người không có settings.manage không thấy dòng sao lưu', function (Role $role) {
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);
    OutboundMessage::factory()->create(['template' => 'staff.backup_alert.backup_failed', 'status' => OutboundStatus::Failed]);

    bacWidgetAs($role)->assertDontSeeHtml('data-widget="backup-health"');
})->with([Role::Manager, Role::Lawyer, Role::Assistant, Role::Accountant]);

it('dòng sao lưu dùng biến màu Filament đã đăng ký và không có class viết tay', function () {
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);
    SystemHealth::current()->forceFill(['last_schedule_run_at' => now()->subMinute()])->save();
    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    $markup = view('filament.admin.widgets.system-health', (new SystemHealthWidget)->getViewData())->render();

    expect($markup)->toContain('data-widget="backup-health"')
        ->and(unregisteredColourVariables($markup))->toBe([])
        ->and($markup)->not->toContain('class=');
});
