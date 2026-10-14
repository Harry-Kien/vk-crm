<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Models\Client;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fa, mục A4 của đợt kiểm tra nghiệp vụ 2026-10-09 — một tài liệu tải lên nhầm. Trước bản sửa không
 * có cách nào sửa tiêu đề, ngày ban hành hay đầu mục của một tài liệu, và không có nút Xoá (luật
 * `DocumentPolicy::delete` đã có mà không màn hình nào dùng): tệp của khách X tải nhầm vào vụ của khách
 * Y nằm lại đó mãi; một bản án công bố với ngày ban hành gõ sai chỉ còn đường rút lại rồi tải lên lại.
 * Mọi thứ đi qua tab Tài liệu (Livewire). Hàm toàn cục mang tiền tố `dcx…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->for(Client::factory())->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->team()->attach($this->assistant->id, ['role_in_matter' => MatterRole::Assistant->value]);
});

function dcxScreen(Matter $matter)
{
    return test()->livewire(DocumentsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
}

function dcxDocument(Matter $matter, array $attributes = []): Document
{
    return Document::factory()->for($matter)->group(DocumentGroup::Authority)->create([
        'title' => 'Thông báo thụ lý',
        'status' => DocumentStatus::InternalDraft,
        'issued_at' => '2026-09-01',
        ...$attributes,
    ]);
}

function dcxPublished(Matter $matter): Document
{
    return dcxDocument($matter, [
        'title' => 'Bản án số 12/2026',
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
        'published_at' => now()->subDay(),
    ]);
}

it('lets a lawyer correct the title and the issue date of a judgment already published, with old and new in the audit row', function () {
    $document = dcxPublished($this->matter);
    $this->actingAs($this->lawyer, 'web');

    dcxScreen($this->matter)
        ->assertActionVisible(TestAction::make('editDetails')->table($document))
        ->callAction(TestAction::make('editDetails')->table($document), data: [
            'title' => 'Bản án số 12/2026/DS-ST',
            'issued_at' => '2026-09-15',
            'matter_checklist_item_id' => null,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified(__('document_corrections.edit.success'));

    $fresh = $document->fresh();
    $row = Activity::query()->where('event', 'document_details_updated')->sole();

    expect($fresh->title)->toBe('Bản án số 12/2026/DS-ST')
        ->and($fresh->issued_at->toDateString())->toBe('2026-09-15')
        ->and($fresh->status)->toBe(DocumentStatus::Published)
        ->and($row->causer_id)->toBe($this->lawyer->id)
        ->and($row->properties['changes']['title'])->toBe(['from' => 'Bản án số 12/2026', 'to' => 'Bản án số 12/2026/DS-ST'])
        ->and($row->properties['changes']['issued_at'])->toBe(['from' => '2026-09-01', 'to' => '2026-09-15']);
});

it('lets an assistant correct a draft, but not a document the client already sees', function () {
    $draft = dcxDocument($this->matter);
    $published = dcxPublished($this->matter);
    $this->actingAs($this->assistant, 'web');

    dcxScreen($this->matter)
        ->assertActionHidden(TestAction::make('editDetails')->table($published))
        ->callAction(TestAction::make('editDetails')->table($draft), data: [
            'title' => 'Thông báo thụ lý vụ án',
            'issued_at' => '2026-09-01',
            'matter_checklist_item_id' => null,
        ])
        ->assertHasNoActionErrors();

    expect($draft->fresh()->title)->toBe('Thông báo thụ lý vụ án')
        ->and($published->fresh()->title)->toBe('Bản án số 12/2026');
});

it('moves an authority document to another checklist item of the same matter, but never a client-provided document out of its submission chain', function () {
    $item = MatterChecklistItem::factory()->for($this->matter)->create(['name' => 'Thông báo của toà']);
    $authority = dcxDocument($this->matter);
    $clientProvided = Document::factory()->for($this->matter)->pendingReview()->create(['title' => 'CCCD']);
    $this->actingAs($this->lawyer, 'web');

    dcxScreen($this->matter)
        ->callAction(TestAction::make('editDetails')->table($authority), data: [
            'title' => 'Thông báo thụ lý',
            'issued_at' => '2026-09-01',
            'matter_checklist_item_id' => $item->id,
        ])
        ->assertHasNoActionErrors();

    expect($authority->fresh()->matter_checklist_item_id)->toBe($item->id);

    dcxScreen($this->matter)
        ->callAction(TestAction::make('editDetails')->table($clientProvided), data: [
            'title' => 'CCCD',
            'issued_at' => null,
            'matter_checklist_item_id' => $item->id,
        ])
        ->assertHasActionErrors(['matter_checklist_item_id']);

    expect($clientProvided->fresh()->matter_checklist_item_id)->not->toBe($item->id);
});

it('refuses a blank title and a title longer than its column, with the error on the field', function (string $title) {
    $document = dcxDocument($this->matter);
    $this->actingAs($this->lawyer, 'web');

    dcxScreen($this->matter)
        ->callAction(TestAction::make('editDetails')->table($document), data: [
            'title' => $title,
            'issued_at' => '2026-09-01',
            'matter_checklist_item_id' => null,
        ])
        ->assertHasActionErrors(['title']);

    expect($document->fresh()->title)->toBe('Thông báo thụ lý');
})->with(['blank' => ['   '], 'too long' => [str_repeat('Ạ', 251)]]);

it('deletes a draft uploaded to the wrong matter, with a reason, out of the tab and into the audit', function () {
    $document = dcxDocument($this->matter, ['title' => 'Thông báo thụ lý của khách X']);
    $this->actingAs($this->lawyer, 'web');

    dcxScreen($this->matter)
        ->assertActionVisible(TestAction::make('deleteDocument')->table($document))
        ->callAction(TestAction::make('deleteDocument')->table($document), data: [
            'delete_reason' => 'Tải nhầm vào vụ của khách khác',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified(__('document_corrections.delete.success'));

    $row = Activity::query()->where('event', 'document_deleted')->sole();

    expect($document->fresh()->trashed())->toBeTrue()
        ->and($row->causer_id)->toBe($this->lawyer->id)
        ->and($row->properties['reason'])->toBe('Tải nhầm vào vụ của khách khác')
        ->and($row->properties['matter_id'])->toBe($this->matter->id);

    dcxScreen($this->matter)->assertCanNotSeeTableRecords([$document]);
});

it('tells the truth in the delete modal: no screen restores a deleted document, only a technical administrator on the database', function () {
    // Fix vòng 1, I2: câu cũ hứa "xoá mềm, quản trị khôi phục được" nhưng không màn hình nào khôi phục tài liệu.
    $document = dcxDocument($this->matter);
    $this->actingAs($this->lawyer, 'web');

    $description = __('document_corrections.delete.description');

    dcxScreen($this->matter)->assertActionExists(
        TestAction::make('deleteDocument')->table($document),
        fn (Action $action): bool => $action->getModalDescription() === $description,
    );

    expect($description)->not->toContain('quản trị khôi phục được')
        ->and($description)->toContain('chưa có màn hình nào khôi phục')
        ->and($description)->toContain('quản trị kỹ thuật');
});

it('requires a reason of at least ten characters to delete', function () {
    $document = dcxDocument($this->matter);
    $this->actingAs($this->lawyer, 'web');

    dcxScreen($this->matter)
        ->callAction(TestAction::make('deleteDocument')->table($document), data: ['delete_reason' => 'nhầm'])
        ->assertHasActionErrors(['delete_reason']);

    expect($document->fresh()->trashed())->toBeFalse();
});

it('offers no delete on a document the client sees, and none to an assistant', function () {
    $published = dcxPublished($this->matter);
    $draft = dcxDocument($this->matter);

    $this->actingAs($this->lawyer, 'web');
    dcxScreen($this->matter)->assertActionHidden(TestAction::make('deleteDocument')->table($published));

    $this->actingAs($this->assistant, 'web');
    dcxScreen($this->matter)->assertActionHidden(TestAction::make('deleteDocument')->table($draft));
});
