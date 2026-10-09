<?php

use App\Actions\Document\PublishDocument;
use App\Actions\Document\UploadStaffDocument;
use App\Actions\Matter\RequestHandoverPackage;
use App\Actions\TransitionMatterStage;
use App\Enums\DocumentGroup;
use App\Enums\Role;
use App\Exceptions\MatterRecordDestroyed;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Làn fm, mục B2 (kiểm tra nghiệp vụ 2026-10-09): hồ sơ đã ghi quyết định tiêu huỷ là trạng thái khoá —
 * không sinh lại gói bàn giao, không mở lại/chuyển giai đoạn (kể cả đường bỏ qua của quản trị viên),
 * không công bố hay tải lên tài liệu mới.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
});

/** @return array{0: User, 1: Matter} */
function fmDestroyedMatter(): array
{
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('closed')->create(['closed_at' => now()->subYears(11)]);
    MatterArchive::factory()->create([
        'matter_id' => $matter->id,
        'client_access_until' => now()->subYears(11)->addDays(90)->toDateString(),
        'retention_until' => now()->subYear()->toDateString(),
        'destroyed_at' => now()->subMonth(),
        'destroyed_by' => $admin->id,
        'destruction_reason' => 'Hết hạn lưu trữ, tiêu huỷ theo quyết định của văn phòng.',
        'destruction_record_no' => 'BB-01/2026',
    ]);

    return [$admin, $matter->fresh()];
}

function fmDestroyedDocument(Matter $matter): Document
{
    $document = Document::factory()->for($matter)->group(DocumentGroup::Authority)->create();
    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung that'))
        ->usingName('Ban an.pdf')
        ->usingFileName('01k5g7q8wz0000000000000002.pdf')
        ->toMediaCollection('file');

    return $document->refresh();
}

it('refuses every stage move on a destroyed record, including the admin bypass', function () {
    [$admin, $matter] = fmDestroyedMatter();

    expect(fn () => app(TransitionMatterStage::class)->handle(
        $matter, $admin, 'intake', today(), 'Mở lại hồ sơ đã tiêu huỷ để kiểm tra lại.', null, null, null, null, false,
    ))->toThrow(MatterRecordDestroyed::class);

    expect($matter->fresh()->stage)->toBe('closed')
        ->and(StageLog::query()->where('matter_id', $matter->id)->exists())->toBeFalse();
});

it('refuses to regenerate the handover package of a destroyed record', function () {
    Queue::fake();
    [$admin, $matter] = fmDestroyedMatter();

    expect(fn () => app(RequestHandoverPackage::class)->handle($matter->getKey(), $admin))
        ->toThrow(MatterRecordDestroyed::class);

    expect(app(RequestHandoverPackage::class)->handle($matter->getKey(), null, automatic: true))->toBeNull();

    Queue::assertNothingPushed();
});

it('refuses to publish or upload documents on a destroyed record', function () {
    [$admin, $matter] = fmDestroyedMatter();
    $document = fmDestroyedDocument($matter);

    expect(fn () => app(PublishDocument::class)->handle(
        document: $document, actor: $admin, clientCanView: true, clientCanDownload: true,
        expectedClientCanView: false, expectedClientCanDownload: false, expectedIsReleased: false,
    ))->toThrow(MatterRecordDestroyed::class);

    expect(fn () => app(UploadStaffDocument::class)->handle(
        matter: $matter,
        actor: $admin,
        file: UploadedFile::fake()->createWithContent('moi.pdf', '%PDF-1.4 tai lieu moi'),
        title: 'Tài liệu bổ sung',
        group: DocumentGroup::Internal,
    ))->toThrow(MatterRecordDestroyed::class);
});

it('hides the handover, stage and document buttons on a destroyed record', function () {
    [$admin, $matter] = fmDestroyedMatter();
    $document = fmDestroyedDocument($matter);

    $this->actingAs($admin, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertActionHidden('generateHandoverPackage')
        ->assertSee('BB-01/2026');

    $this->livewire(StageLogsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->assertTableActionHidden('transitionStage')
        ->assertTableActionHidden('addUpdate');

    $this->livewire(DocumentsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->assertActionHidden(TestAction::make('upload')->table())
        ->assertActionHidden(TestAction::make('publish')->table($document));
});

it('keeps the buttons on a closed record that is not destroyed', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('closed')->create(['closed_at' => now()->subMonth()]);
    MatterArchive::factory()->create(['matter_id' => $matter->id]);
    $document = fmDestroyedDocument($matter);

    $this->actingAs($admin, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertActionVisible('generateHandoverPackage');

    $this->livewire(StageLogsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->assertTableActionVisible('transitionStage');

    $this->livewire(DocumentsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->assertActionVisible(TestAction::make('upload')->table())
        ->assertActionVisible(TestAction::make('publish')->table($document));
});
