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
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;
use Spatie\Backup\Notifications\EventHandler;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

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
|    lỗi sao lưu HỎNG HẲN (job hết 5 lần thử, vào `failed_jobs`) trong 7 ngày qua — không phụ thuộc thư
|    có đi được hay không. Vòng sửa 1 (I1): không đếm dòng outbound `failed` — mỗi LẦN THỬ mở một dòng.
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

/**
 * Một dòng `failed_jobs` như hàng đợi `database-uuids` ghi khi một job hết sạch lượt thử: `displayName`
 * của `SendQueuedNotifications` là tên lớp THÔNG BÁO.
 */
function bacFailedJob(string $displayName, CarbonInterface $failedAt): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'uuid' => (string) Str::uuid(),
            'displayName' => $displayName,
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries' => 5,
            'data' => ['commandName' => SendQueuedNotifications::class, 'command' => 'O:0:""'],
        ]),
        'exception' => TransportException::class.': SMTP chết',
        'failed_at' => $failedAt,
    ]);
}

it('một thư báo lỗi sao lưu HỎNG HẲN (job vào failed_jobs) trong 7 ngày qua là dòng đỏ, dù bản trên Drive còn mới', function () {
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);
    SystemHealth::current()->forceFill(['last_offsite_backup_at' => now()->subHour()])->save();

    bacFailedJob(BackupHasFailedNotification::class, now()->subDays(2));
    bacFailedJob(UnhealthyBackupWasFoundNotification::class, now()->subDay());
    // Không tính: hỏng hẳn cũ hơn 7 ngày; job khác hỏng hẳn (kể cả một lớp có chung tên ngắn ở namespace
    // khác); dòng outbound `failed` của một LẦN THỬ (lần sau có thể đã gửi được — vòng sửa 1, I1).
    bacFailedJob(CleanupHasFailedNotification::class, now()->subDays(8));
    bacFailedJob('App\Jobs\SendStageUpdateNotification', now()->subDay());
    bacFailedJob('Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification', now()->subDay());
    OutboundMessage::factory()->create(['template' => 'staff.backup_alert.backup_failed', 'status' => OutboundStatus::Failed, 'created_at' => now()->subDay()]);

    bacWidgetAs(Role::Admin)
        ->assertSeeHtml('data-widget="backup-health"')
        ->assertSee(__('ops_checks.widget.alert_mail_failed', ['count' => 2]));
});

/**
 * SMTP giả lập: mọi lần gửi ném lỗi. Đi qua `OutboundLedgerTransport` như mọi mailer, nên mỗi lần thử
 * của job để lại một dòng `outbound_messages` `failed` — đúng như máy chủ thật.
 */
class BacFailingTransport implements TransportInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw new TransportException('SMTP giả lập chết (làn fc)');
    }

    public function __toString(): string
    {
        return 'bac-failing://';
    }
}

/** Một BackupHasFailed thật lên hàng đợi `database`, gửi tới một hộp thư cố định qua mailer hỏng. */
function bacQueueBackupFailedAlert(): void
{
    config([
        'queue.default' => 'database',
        'vkcrm.backup.notify_email' => 'ops@luatvukhang.com',
        'mail.mailers.bac_failing' => ['transport' => 'bac_failing'],
        'mail.default' => 'bac_failing',
    ]);
    Mail::extend('bac_failing', fn (): TransportInterface => new BacFailingTransport);

    event(new BackupHasFailed(new Exception('Ổ đĩa đầy'), 'local_backups', 'VK-CRM'));

    expect(DB::table('jobs')->count())->toBe(1);
}

function bacWorkOnce(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
}

/**
 * Vòng sửa 1 (I1): mỗi lần thử của job mở một dòng outbound MỚI, nên lần 1 hỏng để lại một dòng
 * `failed` dù lần 2 đã gửi được. Thư đã tới — dải phải im.
 *
 * Mutation probe: đếm lại dòng outbound `failed` của `staff.backup_alert.*` thay cho `failed_jobs` → ĐỎ.
 */
it('lần thử 1 của thư báo lỗi sao lưu hỏng, lần thử 2 gửi được: không có dòng sao lưu', function () {
    bacQueueBackupFailedAlert();

    bacWorkOnce();
    config(['mail.default' => 'array']);
    $this->travel(61)->seconds();
    bacWorkOnce();

    // Tự kiểm: đúng hình dạng sự cố — một dòng hỏng của lần 1, một dòng đã gửi của lần 2, hàng đợi trống.
    expect(OutboundMessage::query()->where('template', 'staff.backup_alert.backup_failed')->pluck('status')->all())
        ->toEqualCanonicalizing([OutboundStatus::Failed, OutboundStatus::Sent])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    bacWidgetAs(Role::Admin)->assertDontSeeHtml('data-widget="backup-health"');
});

/**
 * Vòng sửa 1 (I1): một thư hỏng hẳn là MỘT thư, không phải năm (một dòng outbound mỗi lần thử). Và nó
 * rời dải khi đã quá 7 ngày.
 *
 * Mutation probe: bỏ điều kiện `failed_at >= now - 7 ngày` → ĐỎ ở bước cuối.
 */
it('thư báo lỗi sao lưu hỏng cả 5 lần thử: dòng sao lưu nói 1 thư, và hết sau 7 ngày', function () {
    bacQueueBackupFailedAlert();

    bacWorkOnce();

    foreach ([61, 301, 901, 3601] as $delay) {
        $this->travel($delay)->seconds();
        bacWorkOnce();
    }

    expect(OutboundMessage::query()->where('template', 'staff.backup_alert.backup_failed')->where('status', OutboundStatus::Failed)->count())->toBe(5)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    bacWidgetAs(Role::Admin)
        ->assertSeeHtml('data-widget="backup-health"')
        ->assertSee(__('ops_checks.widget.alert_mail_failed', ['count' => 1]));

    $this->travel(SystemHealthWidget::BACKUP_MAIL_FAILED_WINDOW_DAYS)->days();
    $this->travel(1)->minutes();

    bacWidgetAs(Role::Admin)->assertDontSeeHtml('data-widget="backup-health"');
});

it('người không có settings.manage không thấy dòng sao lưu', function (Role $role) {
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);
    bacFailedJob(BackupHasFailedNotification::class, now()->subDay());

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
