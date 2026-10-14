<?php

use App\Actions\Matter\BuildHandoverPackage;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\HandoverIndexImpact;
use App\Enums\HandoverPackageStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Jobs\GenerateHandoverPackage;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\PdfText;

/*
 * Làn fm, Task 1, vòng sửa 1 (I1): rút một dòng tiến độ KHÔNG đổi được mục lục (MUC-LUC.pdf) của một gói
 * bàn giao đã dựng trước lần rút — gói tự sinh khi vụ kết thúc và chờ luật sư công bố. Ca rò rỉ: luật sư
 * đăng cập nhật của khách A lên vụ của khách B, đóng vụ (gói tự sinh, mục lục có dòng đó), rút dòng, rồi
 * công bố gói — khách B tải mục lục mang dữ liệu của A. Hộp thoại "Rút khỏi cổng" nay nói đúng điều sẽ
 * xảy ra với gói, và khi gói chưa ra tới khách, hệ thống tự xếp sinh lại gói.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Queue::fake();

    config(['media-library.prefix' => 'test-'.Str::random(16)]);
    $this->workRoot = storage_path('framework/testing/handover-'.Str::random(16));
    config(['vkcrm.handover.work_dir' => $this->workRoot]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'is_published_to_portal' => true,
        'closed_at' => now()->subDay()->toDateString(),
    ]);
    $this->line = StageLog::factory()->for($this->matter)->create([
        'public_content' => 'KHACH-A-MA-SO-THUE-0101234567 — cập nhật của khách A đăng nhầm sang vụ này.',
        'is_published' => true,
        'published_at' => now()->subHours(2),
        'created_by' => $this->lawyer->id,
    ]);
});

afterEach(fn () => File::deleteDirectory($this->workRoot));

const FM_HANDOVER_RETRACT_REASON = 'Đăng nhầm cập nhật của khách A sang vụ của khách B.';

function fmTimeline(object $test): Testable
{
    $test->actingAs($test->lawyer, 'web');

    return $test->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $test->matter,
        'pageClass' => ViewMatter::class,
    ]);
}

/** Lời của hộp thoại "Rút khỏi cổng" khi người dùng mở nó trên dòng đăng nhầm. */
function fmDialog(object $test): string
{
    $component = fmTimeline($test)->mountTableAction('retractStageLog', $test->line);

    return (string) $component->instance()->getMountedAction()->getModalDescription();
}

function fmRetract(object $test): Testable
{
    return fmTimeline($test)
        ->callTableAction('retractStageLog', $test->line, data: ['retraction_reason' => FM_HANDOVER_RETRACT_REASON])
        ->assertHasNoTableActionErrors();
}

/** Dòng lưu trữ có gói ở trạng thái `ready`; `$released` = gói đang công bố cho khách. */
function fmArchiveWithPackage(object $test, bool $released = false): MatterArchive
{
    $package = Document::factory()->for($test->matter)->group(DocumentGroup::Issued)->create([
        'title' => 'Gói bàn giao hồ sơ '.$test->matter->code,
        'status' => $released ? DocumentStatus::Published : DocumentStatus::SignedFiled,
        'client_can_view' => $released,
        'client_can_download' => $released,
        'published_at' => $released ? now()->subHour() : null,
    ]);

    return MatterArchive::factory()->create([
        'matter_id' => $test->matter->id,
        'archived_by' => $test->lawyer->id,
        'handover_status' => HandoverPackageStatus::Ready,
        'handover_document_id' => $package->id,
        'handover_requested_at' => now()->subDay()->startOfSecond(),
        'handover_generated_at' => now()->subDay(),
    ]);
}

function fmIsRetracted(StageLog $line): bool
{
    return StageLog::query()->withoutGlobalScope(ClientPortalScope::class)->find($line->id)->isRetracted();
}

it('closed matter with an unreleased package: the dialog says the package will be regenerated, and retracting queues it', function () {
    $archive = fmArchiveWithPackage($this);
    $before = $archive->handover_requested_at;

    expect(fmDialog($this))
        ->toContain(HandoverIndexImpact::Regenerated->label())
        ->not->toContain(HandoverIndexImpact::None->label());

    fmRetract($this);

    $archive->refresh();

    expect(fmIsRetracted($this->line))->toBeTrue()
        ->and($archive->handover_status)->toBe(HandoverPackageStatus::Generating)
        ->and($archive->handover_requested_at->gt($before))->toBeTrue()
        // Người đã rút là người gây ra lần sinh lại — khối gói không ghi sai "Tự động khi vụ kết thúc".
        ->and($archive->handover_requested_by)->toBe($this->lawyer->id);

    Queue::assertPushed(GenerateHandoverPackage::class, 1);
    Queue::assertPushed(
        GenerateHandoverPackage::class,
        fn (GenerateHandoverPackage $job): bool => $job->matterId === $this->matter->id
            && $job->requestedAt === $archive->handover_requested_at->getTimestamp(),
    );

    $activity = Activity::query()->where('event', 'handover_package_requested')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['trigger'] ?? null)->toBe('stage_log_retracted');
});

it('a package generation still pending is superseded, even when it was requested in the same second', function () {
    // Lần tự sinh lúc đóng vụ đang chờ hàng (job có thể đã đọc dòng sai): rút dòng phải thay dấu yêu
    // cầu để job cũ thoát lặng lẽ — dấu là giây tròn, nên "cùng giây" phải vẫn ra một dấu khác.
    // Đồng hồ đứng yên: lần yêu cầu cũ và lần rút rơi vào ĐÚNG một giây.
    $this->freezeSecond();
    $archive = MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'archived_by' => $this->lawyer->id,
        'handover_status' => HandoverPackageStatus::Generating,
        'handover_requested_at' => now()->startOfSecond(),
        'handover_requested_by' => null,
    ]);
    $before = $archive->fresh()->handover_requested_at;

    expect(fmDialog($this))
        ->toContain(HandoverIndexImpact::Regenerated->label());

    fmRetract($this);

    expect($archive->fresh()->handover_requested_at->gt($before))->toBeTrue();
    Queue::assertPushed(GenerateHandoverPackage::class, 1);
});

it('a package released to the client: the dialog says to retract the package first, and nothing is regenerated', function () {
    $archive = fmArchiveWithPackage($this, released: true);

    expect(fmDialog($this))
        ->toContain(HandoverIndexImpact::Released->label())
        ->not->toContain(HandoverIndexImpact::Regenerated->label());

    fmRetract($this);

    expect(fmIsRetracted($this->line))->toBeTrue()
        ->and($archive->fresh()->handover_status)->toBe(HandoverPackageStatus::Ready)
        ->and(Document::query()->find($archive->handover_document_id)->isReleasedToPortal())->toBeTrue();

    Queue::assertNothingPushed();
});

it('a reopened matter with an old unreleased package: the dialog warns the package is stale, and nothing is queued', function () {
    $archive = fmArchiveWithPackage($this);
    $this->matter->update(['closed_at' => null]);

    expect(fmDialog($this))
        ->toContain(HandoverIndexImpact::Stale->label());

    fmRetract($this);

    expect($archive->fresh()->handover_status)->toBe(HandoverPackageStatus::Ready);
    Queue::assertNothingPushed();
});

it('a reopened matter whose first generation is still pending has no package to warn about', function () {
    // Lần sinh đang chờ sẽ tự hỏng (vụ đã mở lại) — không có gói cũ nào mang dòng này.
    MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'archived_by' => $this->lawyer->id,
        'handover_status' => HandoverPackageStatus::Generating,
        'handover_requested_at' => now()->subMinute()->startOfSecond(),
    ]);
    $this->matter->update(['closed_at' => null]);

    expect(fmDialog($this))
        ->toContain(HandoverIndexImpact::None->label())
        ->not->toContain(HandoverIndexImpact::Stale->label());

    fmRetract($this);

    Queue::assertNothingPushed();
});

it('no package yet: the dialog says later packages leave the line out, and nothing is queued', function () {
    // Vụ đã kết thúc, có dòng lưu trữ nhưng chưa ai yêu cầu sinh gói.
    MatterArchive::factory()->create(['matter_id' => $this->matter->id, 'archived_by' => $this->lawyer->id]);

    expect(fmDialog($this))
        ->toContain(HandoverIndexImpact::None->label())
        ->not->toContain(HandoverIndexImpact::Regenerated->label());

    fmRetract($this);

    Queue::assertNothingPushed();
});

it('a destroyed record: no regeneration is attempted, and the retraction still goes through', function () {
    $archive = fmArchiveWithPackage($this);
    $archive->update([
        'destroyed_at' => now()->subDay(),
        'destroyed_by' => $this->lawyer->id,
        'destruction_reason' => 'Hết hạn lưu trữ, tiêu huỷ theo quyết định của văn phòng.',
        'destruction_record_no' => 'BB-02/2026',
    ]);

    expect(fmDialog($this))
        ->toContain(HandoverIndexImpact::None->label());

    fmRetract($this);

    expect(fmIsRetracted($this->line))->toBeTrue()
        ->and($archive->fresh()->handover_status)->toBe(HandoverPackageStatus::Ready);
    Queue::assertNothingPushed();
});

it('the regenerated package index no longer carries the retracted line, while the package built before did', function () {
    // Gói tự sinh lúc đóng vụ: dựng THẬT, mục lục có dòng đăng nhầm.
    $archive = MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'archived_by' => $this->lawyer->id,
        'handover_status' => HandoverPackageStatus::Generating,
        'handover_requested_at' => now()->startOfSecond(),
    ]);
    $first = app(BuildHandoverPackage::class)->handle($this->matter->id, $archive->fresh()->handover_requested_at->getTimestamp());
    $indexOf = function (Document $package): string {
        $zip = new ZipArchive;
        expect($zip->open($package->getFirstMedia('file')->getPath(), ZipArchive::RDONLY))->toBeTrue();
        $pdf = $zip->getFromName('MUC-LUC.pdf');
        $zip->close();

        return PdfText::squash(PdfText::extract($pdf));
    };
    $marker = PdfText::squash('KHACH-A-MA-SO-THUE-0101234567');

    expect($indexOf($first))->toContain($marker);

    fmRetract($this);

    // Chạy đúng job đã được xếp (dấu yêu cầu của nó), như worker sẽ chạy.
    $pushed = Queue::pushed(GenerateHandoverPackage::class);
    expect($pushed)->toHaveCount(1);
    $second = app(BuildHandoverPackage::class)->handle($this->matter->id, $pushed->first()->requestedAt);

    expect($second)->not->toBeNull()
        ->and($second->id)->not->toBe($first->id)
        ->and($archive->fresh()->handover_document_id)->toBe($second->id)
        ->and($indexOf($second))->not->toContain($marker);
});
