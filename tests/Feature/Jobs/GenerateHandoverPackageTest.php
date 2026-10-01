<?php

use App\Actions\Matter\BuildHandoverPackage;
use App\Actions\Matter\RequestHandoverPackage;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\HandoverPackageStatus;
use App\Enums\Role;
use App\Jobs\GenerateHandoverPackage;
use App\Jobs\SendHandoverPackageReady;
use App\Mail\Staff\HandoverPackageReady;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * `GenerateHandoverPackage` (M7 Task 4, R9): cấu hình hàng đợi riêng, việc ghi lỗi và báo luật sư,
 * và một vòng đi thật qua hàng đợi `database` bằng `queue:work`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    // Thư mục tạm RIÊNG của mỗi test: tên thư mục làm việc cố định theo (vụ, dấu yêu cầu), mà các
    // tiến trình test song song đánh id vụ từ 1 — dùng chung một gốc thì hai test có thể đụng nhau.
    $this->workRoot = storage_path('framework/testing/handover-'.Str::random(16));
    config(['vkcrm.handover.work_dir' => $this->workRoot]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'closed_at' => now()->subDay()->toDateString(),
    ]);
    $this->archive = MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'archived_by' => $this->lawyer->id,
        'handover_status' => HandoverPackageStatus::Generating,
        'handover_requested_at' => now()->startOfSecond(),
        'handover_requested_by' => $this->lawyer->id,
    ]);
    $this->token = $this->archive->fresh()->handover_requested_at->getTimestamp();
});

afterEach(fn () => File::deleteDirectory($this->workRoot));

function ghpJob(object $test, ?int $token = null): GenerateHandoverPackage
{
    return new GenerateHandoverPackage($test->matter->id, $token ?? $test->token);
}

function ghpRun(GenerateHandoverPackage $job): void
{
    app()->call([$job, 'handle']);
}

function ghpNotifications(User $user): Collection
{
    return DB::table('notifications')->where('notifiable_id', $user->id)->get()
        ->map(fn ($row) => json_decode($row->data, true));
}

// ---------------------------------------------------------------------------------------------
// Cấu hình tường minh (R9).
// ---------------------------------------------------------------------------------------------

it('khai báo tường minh $timeout, $tries, failOnTimeout, kết nối và hàng handover', function () {
    $job = ghpJob($this);

    expect($job->timeout)->toBe(600)
        ->and($job->timeout)->toBe(GenerateHandoverPackage::TIMEOUT_SECONDS)
        ->and($job->tries)->toBe(2)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->connection)->toBe('handover')
        ->and($job->queue)->toBe('handover')
        ->and($job->backoff())->toBe([120]);
});

// ---------------------------------------------------------------------------------------------
// handle(): thành công và lỗi có tên.
// ---------------------------------------------------------------------------------------------

it('handle() dựng gói và xếp việc báo luật sư', function () {
    Queue::fake();

    ghpRun(ghpJob($this));

    $archive = $this->archive->fresh();

    expect($archive->handover_status)->toBe(HandoverPackageStatus::Ready)
        ->and($archive->handover_document_id)->not->toBeNull();

    Queue::assertPushed(SendHandoverPackageReady::class, 1);
});

it('lỗi có tên: ghi failed kèm câu tiếng Việt, ghi nhật ký, báo luật sư, và job KHÔNG ném lỗi', function () {
    Queue::fake(SendHandoverPackageReady::class);

    $broken = Document::factory()->create([
        'matter_id' => $this->matter->id, 'group' => DocumentGroup::Issued, 'status' => DocumentStatus::SignedFiled,
        'title' => 'Đơn khởi kiện bị mất tệp',
    ]);
    $broken->addMediaFromString('x')->usingFileName('mat.pdf')->toMediaCollection('file');
    Storage::disk('private')->delete($broken->getFirstMedia('file')->getPathRelativeToRoot());

    ghpRun(ghpJob($this));

    $archive = $this->archive->fresh();

    expect($archive->handover_status)->toBe(HandoverPackageStatus::Failed)
        ->and($archive->handover_error)->toContain('Đơn khởi kiện bị mất tệp')
        ->and($archive->handover_document_id)->toBeNull();

    $log = Activity::query()->where('event', 'handover_package_failed')->sole();

    expect($log->properties['matter_id'])->toBe($this->matter->id);

    $notes = ghpNotifications($this->lawyer);

    expect($notes)->toHaveCount(1)
        ->and($notes[0]['title'])->toBe('Không sinh được gói bàn giao')
        ->and($notes[0]['body'])->toContain($this->matter->code)
        ->and($notes[0]['body'])->toContain('Đơn khởi kiện bị mất tệp');

    Queue::assertNotPushed(SendHandoverPackageReady::class);

    // Bấm sinh lại được: trạng thái failed không khoá nút.
    Queue::fake();
    expect(app(RequestHandoverPackage::class)->handle($this->matter->id, $this->lawyer)->handover_status)
        ->toBe(HandoverPackageStatus::Generating);
});

it('gói vượt trần MỘT tệp của kho hồ sơ: ghi failed với câu chỉ cách nâng trần, báo luật sư, job KHÔNG ném lỗi (không thử lại)', function () {
    Queue::fake(SendHandoverPackageReady::class);

    $scan = Document::factory()->create([
        'matter_id' => $this->matter->id, 'group' => DocumentGroup::Issued, 'status' => DocumentStatus::SignedFiled,
        'title' => 'Bản scan hồ sơ',
    ]);
    $scan->addMediaFromString(random_bytes(1536 * 1024))->usingFileName('scan.pdf')->toMediaCollection('file');

    // Trần 1 MB, hạ SAU khi tài liệu nguồn (1,5 MB) đã vào kho. Trước vòng sửa 1, `FileIsTooBig`
    // của medialibrary lọt khỏi `handle()`: job thử lại sau 120 giây (nén lại tất cả), hỏng y hệt,
    // rồi `failed()` ghi câu chung "lỗi hệ thống".
    config(['media-library.max_file_size' => 1024 * 1024]);

    ghpRun(ghpJob($this));

    $archive = $this->archive->fresh();

    expect($archive->handover_status)->toBe(HandoverPackageStatus::Failed)
        ->and($archive->handover_error)->toContain('vượt trần 1 MB')
        ->and($archive->handover_error)->toContain('MEDIA_MAX_FILE_SIZE_MB')
        ->and($archive->handover_error)->not->toContain('lỗi hệ thống')
        ->and($archive->handover_document_id)->toBeNull();

    $notes = ghpNotifications($this->lawyer);

    expect($notes)->toHaveCount(1)
        ->and($notes[0]['body'])->toContain('MEDIA_MAX_FILE_SIZE_MB');

    Queue::assertNotPushed(SendHandoverPackageReady::class);
});

// ---------------------------------------------------------------------------------------------
// failed(): lỗi lạ sau khi hết lượt thử.
// ---------------------------------------------------------------------------------------------

it('failed() với lỗi lạ: ghi câu chung, không lộ thông điệp thô của exception', function () {
    ghpJob($this)->failed(new RuntimeException('SQLSTATE[HY000]: /var/www/html/storage/secret/path.zip'));

    $archive = $this->archive->fresh();

    expect($archive->handover_status)->toBe(HandoverPackageStatus::Failed)
        ->and($archive->handover_error)->toContain('lỗi hệ thống')
        ->and($archive->handover_error)->not->toContain('SQLSTATE')
        ->and($archive->handover_error)->not->toContain('/var/www');

    expect(json_encode(ghpNotifications($this->lawyer)))->not->toContain('SQLSTATE');
});

it('failed() — kể cả khi job bị giết vì hết giờ — xoá thư mục tạm dở dang của CHÍNH lần yêu cầu đó, không đụng lần khác', function () {
    // Một worker hết `$timeout` tự giết tiến trình ngay sau khi gọi `failed()`: `finally` của lần
    // dựng không bao giờ chạy, và zip dở (có thể vài trăm MB) ở lại trên đĩa nếu không ai dọn.
    $mine = BuildHandoverPackage::workDirectory($this->matter->id, $this->token);
    $other = BuildHandoverPackage::workDirectory($this->matter->id, $this->token - 60);

    foreach ([$mine, $other] as $directory) {
        File::ensureDirectoryExists($directory);
        File::put($directory.DIRECTORY_SEPARATOR.'package.zip', 'ZIP-DO-DANG');
    }

    ghpJob($this)->failed(new TimeoutExceededException('Job has timed out.'));

    expect(is_dir($mine))->toBeFalse()
        ->and(is_file($other.DIRECTORY_SEPARATOR.'package.zip'))->toBeTrue()
        ->and($this->archive->fresh()->handover_status)->toBe(HandoverPackageStatus::Failed);

    // Job cũ thất bại muộn: không ghi đè trạng thái (test ngay dưới), nhưng vẫn dọn thư mục của nó.
    ghpJob($this, $this->token - 60)->failed(new RuntimeException('cũ'));

    expect(is_dir($other))->toBeFalse();
});

it('failed() của một job cũ (dấu yêu cầu lệch) không đè lên lần yêu cầu mới hơn', function () {
    ghpJob($this, $this->token - 60)->failed(new RuntimeException('cũ'));

    expect($this->archive->fresh()->handover_status)->toBe(HandoverPackageStatus::Generating)
        ->and(ghpNotifications($this->lawyer))->toHaveCount(0)
        ->and(Activity::query()->where('event', 'handover_package_failed')->count())->toBe(0);
});

it('failed() báo cả người đã bấm nút lẫn luật sư phụ trách khi họ là hai người khác nhau', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->archive->update(['handover_requested_by' => $manager->id]);

    ghpJob($this)->failed(new RuntimeException('x'));

    expect(ghpNotifications($this->lawyer))->toHaveCount(1)
        ->and(ghpNotifications($manager))->toHaveCount(1);
});

it('failed() không đè trạng thái ready của một lần đã thành công', function () {
    $this->archive->update(['handover_status' => HandoverPackageStatus::Ready]);

    ghpJob($this)->failed(new RuntimeException('muộn'));

    expect($this->archive->fresh()->handover_status)->toBe(HandoverPackageStatus::Ready)
        ->and(ghpNotifications($this->lawyer))->toHaveCount(0);
});

it('báo lỗi chỉ tới người xem được vụ: vụ restricted không báo cho manager, người nhận thay thế là admin', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $restricted = Matter::factory()->restricted()->create([
        'lead_lawyer_id' => $this->lawyer->id, 'closed_at' => now()->subDay()->toDateString(),
    ]);
    MatterArchive::factory()->create([
        'matter_id' => $restricted->id, 'handover_status' => HandoverPackageStatus::Generating,
        'handover_requested_at' => now()->startOfSecond(),
    ]);
    $token = MatterArchive::query()->where('matter_id', $restricted->id)->value('handover_requested_at');

    // Luật sư phụ trách nghỉ việc → chuỗi dự phòng; manager không xem được vụ restricted.
    $this->lawyer->update(['is_active' => false]);

    (new GenerateHandoverPackage($restricted->id, Carbon::parse($token)->getTimestamp()))
        ->failed(new RuntimeException('x'));

    expect(ghpNotifications($admin))->toHaveCount(1)
        ->and(ghpNotifications($manager))->toHaveCount(0)
        ->and(ghpNotifications($this->lawyer))->toHaveCount(0);
});

// ---------------------------------------------------------------------------------------------
// Một vòng đi thật qua hàng đợi database.
// ---------------------------------------------------------------------------------------------

it('đi thật qua hàng đợi: dispatch lên kết nối handover rồi queue:work handover chạy nó', function () {
    Mail::fake();

    $archive = MatterArchive::query()->whereKey($this->archive->id)->first();
    $archive->update(['handover_status' => null, 'handover_requested_at' => null]);

    app(RequestHandoverPackage::class)->handle($this->matter->id, $this->lawyer);

    expect(DB::table('jobs')->where('queue', 'handover')->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'default')->count())->toBe(0);

    Artisan::call('queue:work', [
        'connection' => 'handover', '--queue' => 'handover', '--stop-when-empty' => true, '--max-time' => 50, '--timeout' => 600,
    ]);

    $archive = $this->archive->fresh();

    expect($archive->handover_status)->toBe(HandoverPackageStatus::Ready)
        ->and($archive->handover_document_id)->not->toBeNull()
        ->and(DB::table('jobs')->where('queue', 'handover')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    // Việc báo kết quả chạy trên hàng chính (sync trong test) và xếp thư qua Mail::queue.
    Mail::assertQueued(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($this->lawyer->email));
});
