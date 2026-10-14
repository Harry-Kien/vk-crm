<?php

use App\Actions\Document\ChecklistProgress;
use App\Actions\Document\ReviewChecklistItem;
use App\Actions\Document\SubmitClientDocument;
use App\Actions\Document\UploadStaffDocument;
use App\Actions\Matter\CollectHandoverEntries;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Events\ChecklistItemRejected;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Handover\HandoverEntry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fa, mục A6 và A7 của đợt kiểm tra nghiệp vụ 2026-10-09 — version của một đầu mục danh mục.
 *
 * A6: khách (hay người nhà dùng chung tài khoản) gửi thêm một ảnh mờ vào đầu mục văn phòng ĐÃ NHẬN.
 * Trước bản sửa văn phòng chỉ có hai lựa chọn sai: nhận v2 (ảnh mờ thay v1 trong gói bàn giao) hay từ
 * chối v2 (khách nhận thư "bị từ chối" về giấy văn phòng đã có, gói bàn giao bỏ hẳn mục). Nay có
 * "Từ chối bản mới, giữ bản đã nhận".
 *
 * A7: rút lại tài liệu nhóm A đang là bản hiện tại của một đầu mục không đụng tới đầu mục — mục vẫn
 * "Đã nhận", thư nhắc không gửi, gói bàn giao không có tệp nào. Nay đầu mục quay về "Còn thiếu" (hoặc
 * về bản đã nhận trước đó, nếu bản bị rút là bản gửi thêm chưa ai nhận).
 *
 * Dựng cảnh bằng Action (khách nộp, văn phòng duyệt); hành vi cần chứng minh đi qua màn hình. Hàm toàn
 * cục mang tiền tố `cvx…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'is_published_to_portal' => true,
    ]);
    $this->item = MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Giấy chứng nhận quyền sử dụng đất',
        'is_required' => true,
        'status' => ChecklistItemStatus::Missing,
    ]);
});

function cvxPdf(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% {$name}\n");
}

/** @return Collection<int, Document> */
function cvxClientSubmits(string ...$names): Collection
{
    return app(SubmitClientDocument::class)->handle(
        checklistItem: test()->item->fresh(),
        actor: test()->clientUser,
        files: array_map(fn (string $name): UploadedFile => cvxPdf($name), $names),
    );
}

function cvxOfficeAccepts(): void
{
    app(ReviewChecklistItem::class)->handle(test()->item->fresh(), test()->lawyer, ChecklistItemStatus::Accepted);
}

function cvxChecklist()
{
    return test()->livewire(ChecklistRelationManager::class, ['ownerRecord' => test()->matter, 'pageClass' => ViewMatter::class]);
}

function cvxDocuments()
{
    return test()->livewire(DocumentsRelationManager::class, ['ownerRecord' => test()->matter, 'pageClass' => ViewMatter::class]);
}

/** @return list<int> id các tài liệu nhóm A mà gói bàn giao sẽ mang. */
function cvxHandoverDocumentIds(): array
{
    return app(CollectHandoverEntries::class)->handle(test()->matter->fresh(), new MatterArchive)
        ->map(fn (HandoverEntry $entry): int => $entry->documentId)
        ->values()
        ->all();
}

it('records the accepted version when the office accepts', function () {
    cvxClientSubmits('so-do-v1.pdf');
    $this->actingAs($this->lawyer, 'web');

    cvxChecklist()
        ->callAction(TestAction::make('accept')->table($this->item->fresh()))
        ->assertHasNoActionErrors();

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::Accepted)
        ->and($this->item->fresh()->accepted_version)->toBe(1);
});

it('rejects the newer submission and keeps the accepted one: no rejection mail, the client reads why, the handover carries the accepted file', function () {
    Event::fake([ChecklistItemRejected::class]);
    $v1 = cvxClientSubmits('so-do-ro.pdf')->sole();
    cvxOfficeAccepts();
    $v2 = cvxClientSubmits('so-do-mo.pdf')->sole();

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview)
        ->and($v2->version)->toBe(2);

    $this->actingAs($this->lawyer, 'web');
    cvxChecklist()
        ->assertActionVisible(TestAction::make('keepAccepted')->table($this->item->fresh()))
        ->callAction(TestAction::make('keepAccepted')->table($this->item->fresh()), data: [
            'reason' => 'Ảnh gửi thêm bị mờ; văn phòng giữ bản đã nhận trước đó.',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified(__('document_corrections.keep_accepted.success'));

    $item = $this->item->fresh();

    expect($item->status)->toBe(ChecklistItemStatus::Accepted)
        ->and($item->accepted_version)->toBe(1)
        ->and($item->reviewed_by)->toBe($this->lawyer->id)
        ->and($item->rejection_reason)->toBeNull()
        ->and($v1->fresh()->status)->toBe(DocumentStatus::Published)
        ->and($v2->fresh()->status)->toBe(DocumentStatus::Retracted)
        ->and($v2->fresh()->client_can_view)->toBeFalse()
        ->and($v2->fresh()->retraction_reason)->toBe('Ảnh gửi thêm bị mờ; văn phòng giữ bản đã nhận trước đó.')
        ->and(ReviewChecklistItem::currentDocumentIds($item))->toBe([$v1->id])
        ->and(cvxHandoverDocumentIds())->toBe([$v1->id])
        ->and(Activity::query()->where('event', 'checklist_item_kept_accepted_version')->sole()->properties['retracted_document_ids'])->toBe([$v2->id]);

    Event::assertNotDispatched(ChecklistItemRejected::class);
});

it('offers "keep the accepted one" only on a pending item that had an accepted version before', function () {
    cvxClientSubmits('lan-dau.pdf');
    $this->actingAs($this->lawyer, 'web');

    cvxChecklist()->assertActionHidden(TestAction::make('keepAccepted')->table($this->item->fresh()));

    cvxOfficeAccepts();
    cvxChecklist()->assertActionHidden(TestAction::make('keepAccepted')->table($this->item->fresh()));
});

it('asks for a reason the client can read before keeping the accepted version', function () {
    cvxClientSubmits('so-do-ro.pdf');
    cvxOfficeAccepts();
    $v2 = cvxClientSubmits('so-do-mo.pdf')->sole();
    $this->actingAs($this->lawyer, 'web');

    cvxChecklist()
        ->callAction(TestAction::make('keepAccepted')->table($this->item->fresh()), data: ['reason' => 'mờ'])
        ->assertHasActionErrors(['reason']);

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview)
        ->and($v2->fresh()->status)->toBe(DocumentStatus::Published);
});

it('puts the item back to missing when the office retracts the only file it had handed in for the client', function () {
    Event::fake([ChecklistItemRejected::class]);
    $document = app(UploadStaffDocument::class)->handle(
        matter: $this->matter,
        actor: $this->lawyer,
        file: cvxPdf('cccd-nguoi-khac.pdf'),
        group: DocumentGroup::ClientProvided,
        title: 'Giấy chứng nhận',
        checklistItem: $this->item,
    );

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::Accepted)
        ->and($this->item->fresh()->accepted_version)->toBe(1);

    $this->actingAs($this->lawyer, 'web');
    cvxDocuments()
        ->callAction(TestAction::make('retract')->table($document), data: ['retraction_reason' => 'Tải nhầm giấy tờ của người khác vào đây'])
        ->assertHasNoActionErrors();

    $item = $this->item->fresh();
    $row = Activity::query()->where('event', 'checklist_item_reopened')->sole();

    expect($item->status)->toBe(ChecklistItemStatus::Missing)
        ->and($item->accepted_version)->toBeNull()
        ->and($row->properties['previous_status'])->toBe(ChecklistItemStatus::Accepted->value)
        ->and($row->properties['retracted_document_id'])->toBe($document->id)
        ->and(ChecklistProgress::outstandingRequiredItems($this->matter)->pluck('id')->all())->toBe([$this->item->id]);

    Event::assertNotDispatched(ChecklistItemRejected::class);
});

it('returns the item to the accepted version when the office retracts a newer submission nobody accepted', function () {
    $v1 = cvxClientSubmits('so-do-ro.pdf')->sole();
    cvxOfficeAccepts();
    $v2 = cvxClientSubmits('so-do-mo.pdf')->sole();
    $this->actingAs($this->lawyer, 'web');

    cvxDocuments()
        ->callAction(TestAction::make('retract')->table($v2), data: ['retraction_reason' => 'Ảnh mờ, văn phòng giữ bản trước đó'])
        ->assertHasNoActionErrors();

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::Accepted)
        ->and($this->item->fresh()->accepted_version)->toBe(1)
        ->and(cvxHandoverDocumentIds())->toBe([$v1->id]);
});

it('leaves the item alone when one side of a two-sided submission is retracted and the other still stands', function () {
    $sides = cvxClientSubmits('mat-truoc.pdf', 'mat-sau.pdf');
    cvxOfficeAccepts();
    $this->actingAs($this->lawyer, 'web');

    cvxDocuments()
        ->callAction(TestAction::make('retract')->table($sides->first()), data: ['retraction_reason' => 'Mặt trước tải trùng hai lần, rút một bản'])
        ->assertHasNoActionErrors();

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::Accepted)
        ->and(Activity::query()->where('event', 'checklist_item_reopened')->count())->toBe(0);
});

it('leaves the item alone when an issued document filed under it is retracted', function () {
    $this->item->forceFill(['status' => ChecklistItemStatus::Accepted])->save();
    $issued = Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create([
        'matter_checklist_item_id' => $this->item->id,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'published_at' => now(),
    ]);
    $this->actingAs($this->lawyer, 'web');

    cvxDocuments()
        ->callAction(TestAction::make('retract')->table($issued), data: ['retraction_reason' => 'Văn bản công bố nhầm, rút lại để sửa'])
        ->assertHasNoActionErrors();

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::Accepted);
});

it('forgets the accepted version when the office rejects, or marks the item not needed', function () {
    cvxClientSubmits('so-do-ro.pdf');
    cvxOfficeAccepts();
    cvxClientSubmits('so-do-mo.pdf');
    $this->actingAs($this->lawyer, 'web');

    cvxChecklist()
        ->callAction(TestAction::make('reject')->table($this->item->fresh()), data: [
            'rejection_reason' => 'Ảnh gửi thêm bị mờ ở góc trên bên trái',
        ])
        ->assertHasNoActionErrors();

    expect($this->item->fresh()->accepted_version)->toBeNull();

    $this->item->forceFill(['status' => ChecklistItemStatus::Accepted, 'accepted_version' => 2])->save();

    cvxChecklist()
        ->callAction(TestAction::make('markNotApplicable')->table($this->item->fresh()))
        ->assertHasNoActionErrors();

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::NotApplicable)
        ->and($this->item->fresh()->accepted_version)->toBeNull();
});

it('leaves a pending resubmission alone when only one of its files is retracted, and writes no item row', function () {
    cvxClientSubmits('so-do-ro.pdf');
    cvxOfficeAccepts();
    $resubmission = cvxClientSubmits('trang-1.pdf', 'trang-2.pdf');
    $this->actingAs($this->lawyer, 'web');

    cvxDocuments()
        ->callAction(TestAction::make('retract')->table($resubmission->first()), data: ['retraction_reason' => 'Trang một bị trùng, rút một bản'])
        ->assertHasNoActionErrors();

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview)
        ->and(Activity::query()->whereIn('event', ['checklist_item_reopened', 'checklist_item_kept_accepted_version'])->count())->toBe(0);
});
