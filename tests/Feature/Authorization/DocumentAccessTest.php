<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);

    $this->foreignMatter = Matter::factory()->create();

    $this->item = MatterChecklistItem::factory()->for($this->matter)->create();
    $this->foreignItem = MatterChecklistItem::factory()->for($this->foreignMatter)->create();

    // Nhóm B đã công bố: đây là bản duy nhất khách được thấy trong cả tệp test này.
    $this->publishedDoc = Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create([
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    $this->internalDoc = Document::factory()->for($this->matter)->group(DocumentGroup::Internal)->create();
});

/**
 * Một tài liệu nhóm B đi qua ba trạng thái trước khi ra tới khách (SPEC §4.11). Cho tới bước
 * cuối, `client_can_view` bật lên không được đủ để khách thấy nó — đó chính là ràng buộc
 * "ngăn khách nhìn thấy một bản đơn mà toà chưa hề nhận được".
 */
dataset('unpublished statuses', [
    'internal_draft' => DocumentStatus::InternalDraft,
    'pending_approval' => DocumentStatus::PendingApproval,
    'signed_filed' => DocumentStatus::SignedFiled,
]);

it('hides a document from every portal query until it is published', function (DocumentStatus $status) {
    $draft = Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create([
        'status' => $status,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    $this->actingAs($this->clientUser, 'client');

    expect(Document::find($draft->id))->toBeNull()
        ->and(Document::pluck('id')->all())->toBe([$this->publishedDoc->id])
        ->and($this->matter->documents()->pluck('documents.id')->all())->toBe([$this->publishedDoc->id]);
})->with('unpublished statuses');

it('refuses the view and download abilities for a document that is not published yet', function (DocumentStatus $status) {
    $draft = Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create([
        'status' => $status,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    expect($this->clientUser->can('view', $draft))->toBeFalse()
        ->and($this->clientUser->can('download', $draft))->toBeFalse();
})->with('unpublished statuses');

/** Cặp dương của hai test trên: đúng một trạng thái mở cổng, và nó mở cả hai tầng. */
it('lets the client see and download the same document once it is published', function () {
    $this->actingAs($this->clientUser, 'client');

    expect(Document::find($this->publishedDoc->id))->not->toBeNull()
        ->and($this->clientUser->can('view', $this->publishedDoc))->toBeTrue()
        ->and($this->clientUser->can('download', $this->publishedDoc))->toBeTrue();
});

/** Nhân sự vẫn phải thấy bản nháp — nếu không thì "khách không thấy" đúng vì không ai thấy. */
it('still shows an unpublished document to the staff working on it', function () {
    expect(Document::count())->toBe(2)
        ->and($this->lead->can('view', $this->internalDoc))->toBeTrue();

    $draft = Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create([
        'status' => DocumentStatus::PendingApproval, 'client_can_view' => true,
    ]);

    expect($this->lead->can('view', $draft))->toBeTrue()
        ->and($this->assistant->can('view', $draft))->toBeTrue();
});

/**
 * Lý do `DocumentPolicy::view()` nói lại luật bằng thuộc tính bên cạnh `visibleToPortal()`.
 * Test này gây lỗi có chủ ý ở TẦNG TRUY VẤN — thay global scope của `Document` bằng một scope
 * rỗng, đúng hình dạng "ai đó quên một câu where" — rồi hỏi lại policy. Thiết kế ba tầng của M2
 * nói một chỗ quên không được thành một vụ rò rỉ; đây là chỗ khẳng định điều đó thay vì tin nó.
 *
 * Bỏ `isReleasedToPortal()` khỏi policy là test này đỏ.
 */
it('still refuses on the policy layer when the query layer forgets the rule', function () {
    $draft = Document::factory()->for($this->matter)->group(DocumentGroup::Issued)->create([
        'status' => DocumentStatus::SignedFiled,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    Document::addGlobalScope(ClientPortalScope::class, function (): void {});

    try {
        // Tầng truy vấn đã thủng: cả bản nháp lẫn nhóm D đều trả về dưới guard khách.
        $this->actingAs($this->clientUser, 'client');
        expect(Document::find($draft->id))->not->toBeNull()
            ->and(Document::find($this->internalDoc->id))->not->toBeNull();

        // Tầng policy thì không.
        expect($this->clientUser->can('view', $draft))->toBeFalse()
            ->and($this->clientUser->can('download', $draft))->toBeFalse()
            ->and($this->clientUser->can('view', $this->internalDoc))->toBeFalse()
            ->and($this->clientUser->can('view', $this->publishedDoc))->toBeTrue();
    } finally {
        Document::addGlobalScope(new ClientPortalScope);
    }
});

/**
 * SPEC §11 "Tài liệu nội bộ" nói tuyệt đối: nhóm D không xuất hiện trong BẤT KỲ truy vấn nào
 * dưới guard `client`. Test này đi thử từng đường có thể nghĩ ra — truy vấn thẳng, quan hệ từ
 * vụ việc, từ đầu mục danh mục, từ một yêu cầu của khách, từ bản trước/bản sau của một tài liệu
 * khách ĐƯỢC thấy, và tầng serialize.
 */
it('never reaches a group D document from the portal by any path', function () {
    // Mọi bản nhóm D ở đây đều đã `published` và bật cả hai cờ cho khách: nếu để chúng ở
    // `internal_draft` thì test vẫn xanh nhờ điều kiện trạng thái, và điều kiện nhóm — thứ test
    // này có mặt để canh — có thể bị gỡ mà không ai biết.
    $this->internalDoc->update([
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
        'matter_checklist_item_id' => $this->item->id,
    ]);

    // Bản nhóm D làm cha của bản khách được thấy, và làm bản kế tiếp của nó.
    $this->publishedDoc->update(['parent_document_id' => $this->internalDoc->id]);
    $childOfPublished = Document::factory()->for($this->matter)->group(DocumentGroup::Internal)
        ->create([
            'parent_document_id' => $this->publishedDoc->id,
            'status' => DocumentStatus::Published,
            'client_can_view' => true,
        ]);

    $request = ClientRequest::factory()->for($this->matter)->create(['client_user_id' => $this->clientUser->id]);

    $this->actingAs($this->clientUser, 'client');

    $visible = Document::find($this->publishedDoc->id);

    expect(Document::whereKey($this->internalDoc->id)->count())->toBe(0)
        ->and(Document::where('group', DocumentGroup::Internal->value)->count())->toBe(0)
        ->and($this->matter->documents()->whereKey($this->internalDoc->id)->count())->toBe(0)
        ->and($this->item->documents()->count())->toBe(0)
        ->and($request->matter->documents()->count())->toBe(1)
        ->and($visible->parent)->toBeNull()
        ->and($visible->newerVersions()->count())->toBe(0)
        ->and($this->clientUser->can('view', $this->internalDoc))->toBeFalse()
        ->and($this->clientUser->can('download', $this->internalDoc))->toBeFalse()
        ->and($this->clientUser->can('view', $childOfPublished))->toBeFalse();

    $serialized = json_encode([
        $this->item->load('documents')->toArray(),
        $visible->load(['parent', 'newerVersions'])->toArray(),
        Matter::with('documents')->find($this->matter->id)->toArray(),
    ], JSON_UNESCAPED_UNICODE);

    expect($serialized)->not->toContain($this->internalDoc->title)
        ->not->toContain($childOfPublished->title)
        ->toContain($this->publishedDoc->title);
});

/**
 * SPEC §5 portal: "Nộp tài liệu vào `matter_checklist_items` thuộc matter hợp lệ". Không có
 * đầu mục thì khách không có quyền tạo tài liệu — đó là toàn bộ nội dung của cái quyền không
 * có tên trong bảng §5.
 */
it('lets a client create a document only against a checklist item of a matter they can see', function () {
    expect($this->clientUser->can('create', [Document::class, $this->item]))->toBeTrue()
        ->and($this->clientUser->can('create', [Document::class, $this->foreignItem]))->toBeFalse()
        ->and($this->clientUser->can('create', [Document::class, $this->matter]))->toBeFalse()
        ->and($this->clientUser->can('create', [Document::class, $this->foreignMatter]))->toBeFalse();
});

it('refuses a client submitting into a matter that is no longer published to the portal', function () {
    $this->matter->update(['is_published_to_portal' => false]);

    expect($this->clientUser->can('create', [Document::class, $this->item->fresh()]))->toBeFalse();
});

it('gates staff document creation by matter.update and by seeing the matter', function () {
    expect($this->lead->can('create', [Document::class, $this->matter]))->toBeTrue()
        ->and($this->assistant->can('create', [Document::class, $this->matter]))->toBeTrue()
        ->and($this->lead->can('create', [Document::class, $this->item]))->toBeTrue()
        ->and($this->outsider->can('create', [Document::class, $this->matter]))->toBeFalse()
        ->and($this->accountant->can('create', [Document::class, $this->matter]))->toBeFalse()
        ->and($this->accountant->can('create', Document::class))->toBeFalse()
        ->and($this->lead->can('create', Document::class))->toBeTrue();
});

it('gates client requests by the matter they are raised on', function () {
    expect($this->clientUser->can('create', [ClientRequest::class, $this->matter]))->toBeTrue()
        ->and($this->clientUser->can('create', [ClientRequest::class, $this->foreignMatter]))->toBeFalse()
        ->and($this->lead->can('create', [ClientRequest::class, $this->matter]))->toBeTrue()
        ->and($this->outsider->can('create', [ClientRequest::class, $this->matter]))->toBeFalse()
        ->and($this->accountant->can('create', [ClientRequest::class, $this->matter]))->toBeFalse()
        ->and($this->accountant->can('create', ClientRequest::class))->toBeFalse();
});

/**
 * Việc mang sang: `update`/`delete` chỉ xét khả năng THẤY vụ việc. Trợ lý có `matter.view` nên
 * cũng có `matter.update` (bảng SPEC §5), vì vậy thêm `matter.update` một mình KHÔNG đổi gì —
 * trợ lý vẫn xoá được. Cái phân biệt được vòng đời tài liệu với công việc hồ sơ thường ngày là
 * `document.publish`: đúng nhóm vai trò mà SPEC §5 giao quyền quyết định tài liệu ra tới khách.
 */
it('lets the team edit a document but limits deleting it to the roles that publish', function () {
    expect($this->assistant->can('update', $this->publishedDoc))->toBeTrue()
        ->and($this->assistant->can('delete', $this->publishedDoc))->toBeFalse()
        ->and($this->lead->can('update', $this->publishedDoc))->toBeTrue()
        ->and($this->lead->can('delete', $this->publishedDoc))->toBeTrue()
        ->and($this->admin->can('delete', $this->publishedDoc))->toBeTrue()
        ->and($this->outsider->can('update', $this->publishedDoc))->toBeFalse()
        ->and($this->outsider->can('delete', $this->publishedDoc))->toBeFalse()
        ->and($this->accountant->can('update', $this->publishedDoc))->toBeFalse()
        ->and($this->accountant->can('delete', $this->publishedDoc))->toBeFalse()
        ->and($this->clientUser->can('update', $this->publishedDoc))->toBeFalse()
        ->and($this->clientUser->can('delete', $this->publishedDoc))->toBeFalse();
});

/** Không ai được xoá một bản ghi mình không có quyền đọc: trợ lý không thấy nhóm D. */
it('never lets anyone touch a group D document they are not allowed to read', function () {
    expect($this->assistant->can('view', $this->internalDoc))->toBeFalse()
        ->and($this->assistant->can('update', $this->internalDoc))->toBeFalse()
        ->and($this->assistant->can('delete', $this->internalDoc))->toBeFalse()
        ->and($this->lead->can('view', $this->internalDoc))->toBeTrue()
        ->and($this->lead->can('update', $this->internalDoc))->toBeTrue()
        ->and($this->lead->can('delete', $this->internalDoc))->toBeTrue();
});

it('stops editing documents and deadlines once the matter is soft deleted', function () {
    $deadline = Deadline::factory()->for($this->matter)->create(['responsible_user_id' => $this->lead->id]);

    expect($this->admin->can('update', $this->publishedDoc))->toBeTrue()
        ->and($this->admin->can('update', $deadline))->toBeTrue()
        ->and($this->admin->can('delete', $deadline))->toBeTrue();

    $this->matter->delete();

    $doc = $this->publishedDoc->fresh();
    $doc->setRelation('matter', $this->matter->fresh());
    $deadline->setRelation('matter', $this->matter->fresh());

    expect($this->admin->can('update', $doc))->toBeFalse()
        ->and($this->admin->can('delete', $doc))->toBeFalse()
        ->and($this->admin->can('update', $deadline))->toBeFalse()
        ->and($this->admin->can('delete', $deadline))->toBeFalse();
});

/**
 * Lưới hồi quy, không phải bằng chứng cho một lỗ hổng vừa vá: với bảng quyền SPEC §5 hôm
 * nay, đúng bốn vai trò có `matter.view` cũng có `matter.update`, nên mọi dòng dưới đây đã
 * xanh trước khi `DeadlinePolicy` đổi. Cái mà việc đổi thật sự thêm được nằm ở test vụ việc
 * đã xoá mềm ở trên — đó là dòng duy nhất đỏ khi hoàn nguyên `canUpdateMatter()` về
 * `canSeeMatter()`. Giữ tệp này cho ngày văn phòng cấp một vai trò chỉ-đọc.
 */
it('gates deadlines by matter.update and by seeing the matter', function () {
    $deadline = Deadline::factory()->for($this->matter)->create(['responsible_user_id' => $this->lead->id]);

    expect($this->assistant->can('update', $deadline))->toBeTrue()
        ->and($this->assistant->can('delete', $deadline))->toBeTrue()
        ->and($this->lead->can('update', $deadline))->toBeTrue()
        ->and($this->outsider->can('update', $deadline))->toBeFalse()
        ->and($this->outsider->can('delete', $deadline))->toBeFalse()
        ->and($this->accountant->can('update', $deadline))->toBeFalse()
        ->and($this->clientUser->can('update', $deadline))->toBeFalse()
        ->and($this->clientUser->can('delete', $deadline))->toBeFalse();
});
