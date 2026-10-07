<?php

use App\Actions\Document\RetractDocument;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * M7 Task 7 — nút "Rút lại" trên tab Tài liệu, đi qua Livewire (không gọi thẳng Action), và "một
 * đường rút duy nhất" ở màn hình "Chuyển nhóm".
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Rút Lại']);
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
});

function retractionScreen(Matter $matter)
{
    return test()->livewire(DocumentsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

function retractionScreenDocument(Matter $matter, array $attributes = []): Document
{
    $document = Document::factory()->for($matter)->group(DocumentGroup::Issued)->create([
        'title' => 'Đơn khởi kiện bản công bố nhầm',
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
        'published_at' => now()->subDay(),
        ...$attributes,
    ]);

    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung that'))
        ->usingName('Don khoi kien.pdf')
        ->usingFileName('01k5g7q8wz00000000000000r2.pdf')
        ->toMediaCollection('file');

    return $document->refresh();
}

/** Mọi thông báo đã gửi, đọc MỘT lần (đọc là rút khỏi session — xem `DocumentsRelationManagerTest`). */
function retractionNotifications(): array
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications
        ->map(fn (Notification $notification): array => [
            'title' => $notification->getTitle(),
            'body' => (string) $notification->getBody(),
        ])
        ->all();
}

it('luật sư rút lại được qua màn hình: trạng thái hiện rõ, lượt tải cũ còn nguyên', function () {
    $document = retractionScreenDocument($this->matter);
    DocumentDownload::factory()->create([
        'document_id' => $document->id,
        'downloader_type' => $this->clientUser->getMorphClass(),
        'downloader_id' => $this->clientUser->id,
    ]);

    $this->actingAs($this->lawyer, 'web');

    retractionScreen($this->matter)
        ->assertActionVisible(TestAction::make('retract')->table($document))
        ->callAction(TestAction::make('retract')->table($document), data: [
            'retraction_reason' => 'Tài liệu thuộc vụ khác, đã công bố nhầm',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified(__('retraction.action.success'));

    $fresh = $document->fresh();

    expect($fresh->status)->toBe(DocumentStatus::Retracted)
        ->and($fresh->client_can_view)->toBeFalse()
        ->and($fresh->retracted_by)->toBe($this->lawyer->id)
        ->and($fresh->retraction_reason)->toBe('Tài liệu thuộc vụ khác, đã công bố nhầm')
        ->and(DocumentDownload::query()->where('document_id', $document->id)->count())->toBe(1)
        ->and(Activity::query()->where('event', 'document_retracted')->sole()->properties['client_downloads'])->toBe(1);

    // Trạng thái hiện rõ trên tab: nhãn, cột "Khách thấy", và chú thích kèm lý do + người rút.
    retractionScreen($this->matter)
        ->assertSee(DocumentStatus::Retracted->label())
        ->assertSee(__('retraction.tab.client_access'))
        ->assertSee('Tài liệu thuộc vụ khác, đã công bố nhầm')
        ->assertSee('Luật sư Rút Lại')
        // Tài liệu đã rút không còn nút "Rút lại" lẫn nút "Công bố" (không công bố lại được).
        ->assertActionHidden(TestAction::make('retract')->table($fresh))
        ->assertActionHidden(TestAction::make('publish')->table($fresh));
});

it('trợ lý không thấy nút Rút lại, và ép gọi thì tài liệu không đổi', function () {
    $document = retractionScreenDocument($this->matter);
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    $screen = retractionScreen($this->matter)
        ->assertActionHidden(TestAction::make('retract')->table($document));

    // Ép gọi bằng request Livewire thô — `callAction()` của bộ test từ chối ngay một action ẩn
    // trước khi gửi gì, nên nó không đo được đường này (cùng thành ngữ RecordMatterDestructionActionTest).
    retractionForcedCall($screen, $document, 'Trợ lý cố rút một tài liệu đang công bố');

    expect($document->fresh()->status)->toBe(DocumentStatus::Published)
        ->and($document->fresh()->client_can_view)->toBeTrue()
        ->and(Activity::query()->where('event', 'document_retracted')->exists())->toBeFalse();
});

/**
 * Đối chứng của test trên: CÙNG chuỗi request thô, do luật sư gửi, rút được. Không có nó, một
 * đường ép gọi hỏng (tên action, state path, ngữ cảnh bảng sai) cũng để test trên xanh mà không đo
 * gì.
 */
it('đối chứng: cùng chuỗi request thô do luật sư gửi thì rút được', function () {
    $document = retractionScreenDocument($this->matter);

    $this->actingAs($this->lawyer, 'web');

    retractionForcedCall(retractionScreen($this->matter), $document, 'Tài liệu thuộc vụ khác, đã công bố nhầm');

    expect($document->fresh()->status)->toBe(DocumentStatus::Retracted);
});

function retractionForcedCall($screen, Document $document, string $reason): void
{
    $screen->call('mountAction', 'retract', [], ['table' => true, 'recordKey' => (string) $document->getKey()])
        ->set('mountedActions.0.data.retraction_reason', $reason)
        ->call('callMountedAction');
}

it('lý do 19 ký tự có dấu bị từ chối ngay ở ô lý do', function () {
    $document = retractionScreenDocument($this->matter);
    $nineteen = 'Công bố nhầm vụ khá';

    expect(mb_strlen($nineteen))->toBe(19)
        ->and(strlen($nineteen))->toBeGreaterThan(20);

    $this->actingAs($this->lawyer, 'web');

    retractionScreen($this->matter)
        ->callAction(TestAction::make('retract')->table($document), data: [
            'retraction_reason' => $nineteen,
        ])
        ->assertHasActionErrors(['retraction_reason']);

    expect($document->fresh()->status)->toBe(DocumentStatus::Published);
});

it('nút Rút lại chỉ hiện trên tài liệu đang ra tới khách', function () {
    $draft = retractionScreenDocument($this->matter, [
        'status' => DocumentStatus::InternalDraft, 'client_can_view' => false, 'client_can_download' => false, 'published_at' => null,
    ]);
    $released = retractionScreenDocument($this->matter);

    $this->actingAs($this->lawyer, 'web');

    retractionScreen($this->matter)
        ->assertActionHidden(TestAction::make('retract')->table($draft))
        ->assertActionVisible(TestAction::make('retract')->table($released));
});

it('chuyển một tài liệu đang ra tới khách vào nhóm D qua màn hình bị từ chối, câu chỉ tới nút Rút lại', function () {
    $document = retractionScreenDocument($this->matter);
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    retractionScreen($this->matter)
        ->callAction(TestAction::make('regroup')->table($document), data: [
            'group' => DocumentGroup::Internal->value,
        ]);

    expect(array_column(retractionNotifications(), 'body'))
        ->toContain(__('retraction.blocked.regroup_to_internal'));

    expect($document->fresh()->group)->toBe(DocumentGroup::Issued)
        ->and($document->fresh()->isReleasedToPortal())->toBeTrue();
});

it('chú thích trạng thái nói thẳng khi tài khoản người rút đã bị xoá', function () {
    $document = retractionScreenDocument($this->matter);
    $other = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Người đã nghỉ việc']);
    $this->matter->addTeamMember($other, MatterRole::Associate);

    app(RetractDocument::class)->handle(
        document: $document,
        actor: $other,
        reason: 'Tài liệu thuộc vụ khác, đã công bố nhầm',
    );
    $other->delete();

    $tooltip = DocumentsRelationManager::retractionTooltip($document->fresh());

    expect($tooltip)->toContain(__('retraction.tab.unknown_actor'))
        ->and($tooltip)->toContain('Tài liệu thuộc vụ khác, đã công bố nhầm')
        ->and($tooltip)->not->toContain('Người đã nghỉ việc')
        ->and(DocumentsRelationManager::retractionTooltip(retractionScreenDocument($this->matter)))->toBeNull();
});

it('chọn nhóm D cho một tài liệu đã rút qua màn hình bị từ chối, dòng rút của khách giữ nguyên', function () {
    $document = retractionScreenDocument($this->matter);

    app(RetractDocument::class)->handle(
        document: $document,
        actor: $this->lawyer,
        reason: 'Tài liệu thuộc vụ khác, đã công bố nhầm',
    );

    $this->actingAs($this->lawyer, 'web');

    retractionScreen($this->matter)
        ->callAction(TestAction::make('regroup')->table($document->fresh()), data: [
            'group' => DocumentGroup::Internal->value,
        ]);

    expect(array_column(retractionNotifications(), 'body'))
        ->toContain(__('retraction.blocked.regroup_retracted_to_internal'));

    expect($document->fresh()->group)->toBe(DocumentGroup::Issued)
        ->and($document->fresh()->status)->toBe(DocumentStatus::Retracted);
});
