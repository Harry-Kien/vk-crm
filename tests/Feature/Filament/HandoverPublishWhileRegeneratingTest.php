<?php

use App\Actions\Document\PublishDocument;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\HandoverPackageStatus;
use App\Enums\Role;
use App\Exceptions\DocumentNotPublishable;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Làn fi, A4 — rút một dòng tiến độ xếp sinh lại gói bàn giao chưa công bố (`handover_status = generating`).
 * Trong lúc chờ, gói CŨ (mục lục còn dòng đã rút) không được công bố từ tab Tài liệu: `PublishDocument`
 * từ chối mọi version của gói khi dòng lưu trữ đang `generating`, đọc dưới khoá `matters` → `matter_archives`
 * → `documents`. Tài liệu khác của vụ vẫn công bố được. Hàm toàn cục tiền tố `hpr…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Storage::fake('private');
    Queue::fake();
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->atStage('closed')->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'is_published_to_portal' => true,
        'closed_at' => now()->subDay()->toDateString(),
    ]);
    $this->line = StageLog::factory()->for($this->matter)->create([
        'public_content' => 'Cập nhật đăng nhầm sang vụ này.',
        'is_published' => true,
        'published_at' => now()->subHours(2),
        'created_by' => $this->lawyer->id,
    ]);

    $this->oldVersion = hprDocument($this->matter, 'Gói bàn giao v1');
    $this->package = hprDocument($this->matter, 'Gói bàn giao v2', ['version' => 2, 'parent_document_id' => $this->oldVersion->id]);
    $this->archive = MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'archived_by' => $this->lawyer->id,
        'handover_status' => HandoverPackageStatus::Ready,
        'handover_document_id' => $this->package->id,
        'handover_requested_at' => now()->subDay()->startOfSecond(),
        'handover_generated_at' => now()->subDay(),
    ]);
});

function hprDocument(Matter $matter, string $title, array $attributes = []): Document
{
    $document = Document::factory()->for($matter)->group(DocumentGroup::Issued)->create([
        'title' => $title,
        'status' => DocumentStatus::SignedFiled,
        'client_can_view' => false,
        'client_can_download' => false,
        ...$attributes,
    ]);
    $document->addMedia(UploadedFile::fake()->createWithContent('goi.pdf', '%PDF-1.4 goi ban giao'))
        ->usingFileName(Str::lower((string) Str::ulid()).'.pdf')
        ->toMediaCollection('file');

    return $document->refresh();
}

function hprDocumentsTab(object $test)
{
    $test->actingAs($test->lawyer, 'web');

    return $test->livewire(DocumentsRelationManager::class, ['ownerRecord' => $test->matter, 'pageClass' => ViewMatter::class]);
}

function hprRetractLine(object $test): void
{
    $test->actingAs($test->lawyer, 'web');

    $test->livewire(StageLogsRelationManager::class, ['ownerRecord' => $test->matter, 'pageClass' => ViewMatter::class])
        ->callTableAction('retractStageLog', $test->line, data: ['retraction_reason' => 'Đăng nhầm cập nhật của khách khác sang vụ này.'])
        ->assertHasNoTableActionErrors();
}

function hprPublish(object $test, Document $document)
{
    return hprDocumentsTab($test)->callTableAction('publish', $document, data: ['client_can_view' => true, 'client_can_download' => true]);
}

function hprIsPublished(Document $document): bool
{
    return Document::query()->withoutGlobalScope(ClientPortalScope::class)->find($document->id)->isReleasedToPortal();
}

it('refuses to publish the old package from the Documents tab while it is being regenerated after a retraction', function () {
    hprRetractLine($this);

    expect($this->archive->fresh()->handover_status)->toBe(HandoverPackageStatus::Generating);

    hprPublish($this, $this->package)->assertNotified(__('actions.failed_title'));

    expect(hprIsPublished($this->package))->toBeFalse();

    // Version cũ hơn của cùng gói cũng không ra được.
    hprPublish($this, $this->oldVersion)->assertNotified(__('actions.failed_title'));

    expect(hprIsPublished($this->oldVersion))->toBeFalse();
});

it('names the reason: wait for the new package', function () {
    $this->archive->update(['handover_status' => HandoverPackageStatus::Generating]);

    expect(fn () => app(PublishDocument::class)->handle($this->package, $this->lawyer, true, true, false, false, false))
        ->toThrow(DocumentNotPublishable::class, __('documents.publish.handover_regenerating'));
});

it('still publishes another document of the matter while the package is being regenerated', function () {
    hprRetractLine($this);
    $other = hprDocument($this->matter, 'Bản án phúc thẩm');

    hprPublish($this, $other)->assertHasNoTableActionErrors();

    expect(hprIsPublished($other))->toBeTrue();
});

it('publishes the package once it is ready', function () {
    hprPublish($this, $this->package)->assertHasNoTableActionErrors();

    expect(hprIsPublished($this->package))->toBeTrue();
});
