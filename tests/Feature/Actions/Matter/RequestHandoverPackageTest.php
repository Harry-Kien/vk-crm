<?php

use App\Actions\Matter\BuildHandoverPackage;
use App\Actions\Matter\RequestHandoverPackage;
use App\Actions\TransitionMatterStage;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\HandoverPackageStatus;
use App\Enums\Role;
use App\Exceptions\HandoverPackageBusy;
use App\Exceptions\HandoverPackageUnavailable;
use App\Jobs\GenerateHandoverPackage;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/**
 * `RequestHandoverPackage` (M7 Task 4, R9) — cửa duy nhất xếp hàng job sinh gói: tự động khi vụ
 * đóng (đúng một lần) và thủ công qua nút. Trạng thái, khoá, phân quyền và việc dispatch sau commit.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'closed_at' => now()->subDay()->toDateString(),
    ]);
    $this->archive = MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'archived_by' => $this->lawyer->id,
    ]);
});

function rhpTransition(Matter $matter, User $admin, string $to): void
{
    app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $admin,
        toStage: $to,
        occurredAt: now(),
        internalNote: null,
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );
}

function rhpRequest(object $test, ?User $actor = null, bool $automatic = false): ?MatterArchive
{
    return app(RequestHandoverPackage::class)->handle($test->matter->id, $actor, $automatic);
}

// ---------------------------------------------------------------------------------------------
// Tự động: đúng một lần.
// ---------------------------------------------------------------------------------------------

it('tự động: đặt generating, ghi dấu yêu cầu và xếp job lên kết nối/hàng handover', function () {
    Queue::fake();

    $archive = rhpRequest($this, automatic: true);

    expect($archive->handover_status)->toBe(HandoverPackageStatus::Generating)
        ->and($archive->handover_requested_at)->not->toBeNull()
        ->and($archive->handover_requested_by)->toBeNull()
        ->and($archive->handover_error)->toBeNull();

    Queue::assertPushed(GenerateHandoverPackage::class, function (GenerateHandoverPackage $job) use ($archive): bool {
        return $job->matterId === $this->matter->id
            && $job->requestedAt === $archive->fresh()->handover_requested_at->getTimestamp()
            && $job->connection === 'handover'
            && $job->queue === 'handover'
            && $job->afterCommit === true;
    });

    $log = Activity::query()->where('event', 'handover_package_requested')->sole();

    expect($log->properties['matter_id'])->toBe($this->matter->id)
        ->and($log->properties['automatic'])->toBeTrue();
});

it('tự động: chỉ khi handover_status còn NULL — đã yêu cầu một lần thì không tự sinh lại', function (HandoverPackageStatus $status) {
    Queue::fake();
    $this->archive->update(['handover_status' => $status, 'handover_requested_at' => now()->subMinutes(5)]);

    expect(rhpRequest($this, automatic: true))->toBeNull();

    Queue::assertNothingPushed();
    expect($this->archive->fresh()->handover_status)->toBe($status);
})->with([
    'đã sẵn sàng' => HandoverPackageStatus::Ready,
    'đã lỗi' => HandoverPackageStatus::Failed,
    'đang sinh' => HandoverPackageStatus::Generating,
]);

it('tự động: vụ chưa đóng hoặc chưa có bản ghi lưu trữ thì không làm gì, không ném lỗi', function () {
    Queue::fake();

    $this->matter->update(['closed_at' => null]);
    expect(rhpRequest($this, automatic: true))->toBeNull();

    $this->matter->update(['closed_at' => now()->toDateString()]);
    $this->archive->forceDelete();
    expect(rhpRequest($this, automatic: true))->toBeNull();

    Queue::assertNothingPushed();
});

it('đóng vụ qua TransitionMatterStage xếp đúng một job; mở lại rồi đóng lại không tự xếp thêm', function () {
    Queue::fake();

    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('first_instance')->create(['lead_lawyer_id' => $this->lawyer->id]);

    rhpTransition($matter, $admin, 'closed');

    Queue::assertPushed(GenerateHandoverPackage::class, 1);

    $archive = MatterArchive::query()->where('matter_id', $matter->id)->sole();

    expect($archive->handover_status)->toBe(HandoverPackageStatus::Generating)
        ->and($archive->handover_requested_by)->toBeNull();

    // Admin mở lại (đường bỏ qua allowed_next) rồi đóng lại: bản ghi lưu trữ đồng bộ lại nhưng gói
    // không tự sinh lần hai — sinh lại là quyết định của người, bằng nút.
    rhpTransition($matter->fresh(), $admin, 'appeal');
    rhpTransition($matter->fresh(), $admin, 'closed');

    Queue::assertPushed(GenerateHandoverPackage::class, 1);
});

it('chuyển sang giai đoạn KHÔNG kết thúc thì không xếp job', function () {
    Queue::fake();

    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $this->lawyer->id]);
    $admin = User::factory()->withRole(Role::Admin)->create();

    rhpTransition($matter, $admin, 'collecting_documents');

    Queue::assertNothingPushed();
});

it('một hàng đợi trục trặc khi xếp job không làm hỏng lần đóng vụ đã commit', function () {
    $matter = Matter::factory()->atStage('first_instance')->create(['lead_lawyer_id' => $this->lawyer->id]);
    $admin = User::factory()->withRole(Role::Admin)->create();

    // Kết nối `handover` trỏ vào một driver không tồn tại: `dispatch()` ném lỗi.
    config(['queue.connections.handover' => ['driver' => 'khong-ton-tai']]);

    rhpTransition($matter, $admin, 'closed');

    expect($matter->fresh()->closed_at)->not->toBeNull()
        ->and(MatterArchive::query()->where('matter_id', $matter->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Thủ công.
// ---------------------------------------------------------------------------------------------

it('thủ công: người có document.publish và xem được vụ xếp được job, dấu người bấm được ghi', function () {
    Queue::fake();

    $archive = rhpRequest($this, $this->lawyer);

    expect($archive->handover_status)->toBe(HandoverPackageStatus::Generating)
        ->and($archive->handover_requested_by)->toBe($this->lawyer->id);

    Queue::assertPushed(GenerateHandoverPackage::class, 1);

    $log = Activity::query()->where('event', 'handover_package_requested')->sole();

    expect($log->causer_id)->toBe($this->lawyer->id)
        ->and($log->properties['automatic'])->toBeFalse();
});

it('thủ công: sinh lại được sau khi đã sẵn sàng hoặc đã lỗi, và xoá câu lỗi cũ', function (HandoverPackageStatus $status) {
    Queue::fake();
    $this->archive->update([
        'handover_status' => $status,
        'handover_requested_at' => now()->subHour(),
        'handover_error' => 'Lỗi cũ.',
    ]);

    $archive = rhpRequest($this, $this->lawyer);

    expect($archive->handover_status)->toBe(HandoverPackageStatus::Generating)
        ->and($archive->handover_error)->toBeNull();

    Queue::assertPushed(GenerateHandoverPackage::class, 1);
})->with([
    'đã sẵn sàng' => HandoverPackageStatus::Ready,
    'đã lỗi' => HandoverPackageStatus::Failed,
]);

it('thủ công: đang sinh thì bấm lại bị chặn, không xếp thêm job', function () {
    Queue::fake();
    $this->archive->update(['handover_status' => HandoverPackageStatus::Generating, 'handover_requested_at' => now()->subMinutes(5)]);

    expect(fn () => rhpRequest($this, $this->lawyer))->toThrow(HandoverPackageBusy::class, 'đang được sinh');

    Queue::assertNothingPushed();
});

/**
 * Rà soát cuối M7, I2 ("một đường rút duy nhất", Task 7). Gói hiện tại đang công bố cho khách thì
 * sinh lại bị từ chối bằng câu chỉ tới nút "Rút lại" — cùng mẫu với "chuyển vào nhóm D" và "xoá" của
 * Task 7 — thay vì để job lặng lẽ gỡ gói khỏi cổng. Vế dương: gói chưa công bố, hay đã rút, thì sinh
 * lại được.
 */
it('thủ công: gói hiện tại đang công bố cho khách thì sinh lại bị từ chối, câu chỉ tới nút Rút lại', function (array $package, bool $refused) {
    Queue::fake();

    $document = Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create($package);
    $this->archive->update([
        'handover_status' => HandoverPackageStatus::Ready,
        'handover_requested_at' => now()->subDay(),
        'handover_document_id' => $document->id,
    ]);

    if ($refused) {
        expect(fn () => rhpRequest($this, $this->lawyer))
            ->toThrow(HandoverPackageUnavailable::class, __('handover.exceptions.released'));

        expect($this->archive->fresh()->handover_status)->toBe(HandoverPackageStatus::Ready)
            ->and(__('handover.exceptions.released'))->toContain('Rút lại');
        Queue::assertNothingPushed();

        return;
    }

    expect(rhpRequest($this, $this->lawyer)->handover_status)->toBe(HandoverPackageStatus::Generating);
    Queue::assertPushed(GenerateHandoverPackage::class, 1);
})->with([
    'đang công bố' => [['status' => DocumentStatus::Published, 'client_can_view' => true, 'client_can_download' => true, 'published_at' => now()], true],
    'chưa công bố' => [['status' => DocumentStatus::SignedFiled], false],
    'đã rút' => [['status' => DocumentStatus::Retracted, 'published_at' => now()->subDay(), 'retracted_at' => now(), 'retraction_reason' => 'Gói thiếu bản án phúc thẩm, sẽ gửi gói mới.'], false],
]);

it('thủ công: một lần generating KẸT quá STALE_AFTER_MINUTES thì mở khoá được', function () {
    Queue::fake();
    $stale = now()->subMinutes(RequestHandoverPackage::STALE_AFTER_MINUTES + 1);
    $this->archive->update(['handover_status' => HandoverPackageStatus::Generating, 'handover_requested_at' => $stale]);

    expect(RequestHandoverPackage::isStuck($this->archive->fresh()))->toBeTrue()
        ->and(RequestHandoverPackage::isRunning($this->archive->fresh()))->toBeFalse();

    $archive = rhpRequest($this, $this->lawyer);

    expect($archive->handover_requested_at->gt($stale))->toBeTrue();
    Queue::assertPushed(GenerateHandoverPackage::class, 1);
});

it('isRunning/isStuck: đúng ở hai phía của ngưỡng', function () {
    $fresh = $this->archive->fill(['handover_status' => HandoverPackageStatus::Generating, 'handover_requested_at' => now()->subMinutes(RequestHandoverPackage::STALE_AFTER_MINUTES - 1)]);

    expect(RequestHandoverPackage::isRunning($fresh))->toBeTrue()
        ->and(RequestHandoverPackage::isStuck($fresh))->toBeFalse();

    $fresh->handover_status = HandoverPackageStatus::Ready;

    expect(RequestHandoverPackage::isRunning($fresh))->toBeFalse()
        ->and(RequestHandoverPackage::isStuck($fresh))->toBeFalse();
});

it('thủ công: vụ chưa kết thúc hay chưa có bản ghi lưu trữ bị từ chối bằng thông điệp có tên', function () {
    Queue::fake();

    $this->matter->update(['closed_at' => null]);
    expect(fn () => rhpRequest($this, $this->lawyer))->toThrow(HandoverPackageUnavailable::class, 'chưa kết thúc');

    $this->matter->update(['closed_at' => now()->toDateString()]);
    $this->archive->forceDelete();
    expect(fn () => rhpRequest($this, $this->lawyer))->toThrow(HandoverPackageUnavailable::class, 'chưa có bản ghi lưu trữ');

    Queue::assertNothingPushed();
});

it('thủ công: trợ lý (không có document.publish) và người không xem được vụ restricted bị từ chối', function () {
    Queue::fake();

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->team()->attach($assistant->id, ['role_in_matter' => 'assistant']);

    expect(fn () => rhpRequest($this, $assistant))->toThrow(AuthorizationException::class);

    $restricted = Matter::factory()->restricted()->create([
        'lead_lawyer_id' => User::factory()->withRole(Role::Lawyer)->create()->id,
        'closed_at' => now()->subDay()->toDateString(),
    ]);
    MatterArchive::factory()->create(['matter_id' => $restricted->id]);
    $manager = User::factory()->withRole(Role::Manager)->create();

    expect(fn () => app(RequestHandoverPackage::class)->handle($restricted->id, $manager))
        ->toThrow(AuthorizationException::class);

    Queue::assertNothingPushed();
    expect(MatterArchive::query()->where('matter_id', $restricted->id)->value('handover_status'))->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// Sau commit.
// ---------------------------------------------------------------------------------------------

/**
 * Hàng `database` cho phép `INSERT INTO jobs` nằm CHUNG transaction với dữ liệu, nên rollback tự xoá
 * dòng job và test dưới đây không phân biệt được có `->afterCommit()` hay không. Kết nối `handover`
 * được đổi sang `sync` (chạy job ngay khi dispatch) và `BuildHandoverPackage` được thay bằng một bản
 * ghi lại lời gọi: nếu bỏ `->afterCommit()`, job chạy NGAY trong transaction ngoài — trước khi nó
 * rollback — và bản ghi lại thấy một lời gọi cho một lần yêu cầu sắp biến mất.
 */
it('job chạy SAU khi transaction ngoài commit, không bao giờ trước — kể cả với một hàng đợi không nằm trong transaction', function () {
    config(['queue.connections.handover' => ['driver' => 'sync']]);

    $calls = new ArrayObject;

    $this->app->bind(BuildHandoverPackage::class, fn () => new class($calls) extends BuildHandoverPackage
    {
        public function __construct(private ArrayObject $calls) {}

        public function handle(int $matterId, int $requestedAt): ?Document
        {
            $this->calls[] = $matterId;

            return null;
        }
    });

    try {
        DB::transaction(function (): void {
            rhpRequest($this, $this->lawyer);

            throw new RuntimeException('Huỷ có chủ đích.');
        });
    } catch (RuntimeException) {
        // Mong đợi.
    }

    expect($calls)->toHaveCount(0);

    DB::transaction(function () use ($calls): void {
        rhpRequest($this, $this->lawyer);

        // Chưa commit: job chưa chạy.
        expect($calls)->toHaveCount(0);
    });

    expect($calls)->toHaveCount(1)
        ->and($calls[0])->toBe($this->matter->id);
});

it('job chỉ vào hàng đợi SAU khi transaction ngoài commit — rollback thì không có job', function () {
    try {
        DB::transaction(function (): void {
            rhpRequest($this, $this->lawyer);

            throw new RuntimeException('Huỷ có chủ đích.');
        });
    } catch (RuntimeException) {
        // Mong đợi.
    }

    expect(DB::table('jobs')->where('queue', 'handover')->count())->toBe(0)
        ->and($this->archive->fresh()->handover_status)->toBeNull();

    DB::transaction(fn () => rhpRequest($this, $this->lawyer));

    expect(DB::table('jobs')->where('queue', 'handover')->count())->toBe(1);
});
