<?php

use App\Actions\Document\SubmitDocumentForApproval;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\DocumentLifecycleNotAllowed;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * "Trình duyệt" — bước ĐẦU của vòng đời văn bản nhóm B (SPEC §4.11, phán quyết R9). Trước Task 16
 * (`docs/docs-1`, critical) không có Action nào ghi được `pending_approval`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function submitMatter(User $owner): Matter
{
    return Matter::factory()->create(['lead_lawyer_id' => $owner->id]);
}

function submitDocument(Matter $matter, DocumentGroup $group = DocumentGroup::Issued, DocumentStatus $status = DocumentStatus::InternalDraft): Document
{
    return Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => $group,
        'status' => $status,
        'client_can_view' => false,
        'client_can_download' => false,
    ]);
}

function submitForApprovalAs(Document $document, User $actor): Document
{
    return app(SubmitDocumentForApproval::class)->handle(document: $document, actor: $actor);
}

it('luật sư trình duyệt được một bản thảo nhóm B, và Action ghi một dòng nhật ký', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = submitMatter($lawyer);
    $document = submitDocument($matter);

    $submitted = submitForApprovalAs($document, $lawyer);

    expect($submitted->status)->toBe(DocumentStatus::PendingApproval);

    $activity = Activity::query()->where('event', 'document_submitted_for_approval')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($lawyer))->toBeTrue()
        ->and($activity->subject?->is($document))->toBeTrue()
        ->and($activity->properties->get('from_status'))->toBe(DocumentStatus::InternalDraft->value)
        ->and($activity->properties->get('to_status'))->toBe(DocumentStatus::PendingApproval->value);
});

/**
 * R9: "Trình duyệt" đòi `document.update`, không `document.publish` — trợ lý làm được, khác hẳn
 * "Đã ký, đã nộp" (xem `MarkDocumentSignedFiledTest`).
 */
it('trợ lý trong đội ngũ cũng trình duyệt được — R9 đặt bước này ở document.update, không document.publish', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = submitMatter($lawyer);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    $document = submitDocument($matter);

    expect($assistant->can(Permission::DocumentPublish->value))->toBeFalse();

    $submitted = submitForApprovalAs($document, $assistant);

    expect($submitted->status)->toBe(DocumentStatus::PendingApproval);
});

it('luật sư ngoài đội ngũ không trình duyệt được tài liệu của vụ việc đó', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = submitMatter($lawyer);
    $document = submitDocument($matter);

    expect(fn () => submitForApprovalAs($document, $outsider))->toThrow(AuthorizationException::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::InternalDraft);
});

/** Mutation probe: xoá điều kiện `$fresh->group !== DocumentGroup::Issued` làm test này đỏ. */
it('chỉ nhóm B mới trình duyệt được — nhóm C không có gì để trình duyệt', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = submitMatter($lawyer);
    $document = submitDocument($matter, group: DocumentGroup::Authority);

    expect(fn () => submitForApprovalAs($document, $lawyer))->toThrow(DocumentLifecycleNotAllowed::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::InternalDraft);
});

/** Mutation probe: xoá điều kiện `$fresh->status !== DocumentStatus::InternalDraft` làm test này đỏ. */
it('một bản thảo đã trình duyệt rồi không trình duyệt lại được', function (DocumentStatus $status) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = submitMatter($lawyer);
    $document = submitDocument($matter, status: $status);

    expect(fn () => submitForApprovalAs($document, $lawyer))->toThrow(DocumentLifecycleNotAllowed::class);

    expect($document->fresh()->status)->toBe($status);
})->with([
    'pending_approval' => [DocumentStatus::PendingApproval],
    'signed_filed' => [DocumentStatus::SignedFiled],
    'published' => [DocumentStatus::Published],
]);

/**
 * Cổng "vụ việc đã xoá mềm" ở đây đứng TRƯỚC `Gate`, cùng lý lẽ với `PublishDocument`: nó nói về
 * TRẠNG THÁI bản ghi chứ không về người hỏi, nên nó ném `DocumentLifecycleNotAllowed` (một câu
 * tiếng Việt cụ thể — "hồ sơ đã bị xoá") chứ không phải `AuthorizationException` chung chung.
 * Khác `RegroupDocument`, thứ không có cổng riêng cho trường hợp này và vì vậy chỉ ném được
 * `AuthorizationException` từ chính `Gate::authorize('update', ...)`.
 */
it('không trình duyệt được cho tài liệu của một vụ việc đã xoá mềm', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = submitMatter($admin);
    $document = submitDocument($matter);
    $matter->delete();

    expect(fn () => submitForApprovalAs($document->fresh(), $admin))->toThrow(DocumentLifecycleNotAllowed::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::InternalDraft);
});

it('bản ghi bị xoá cứng giữa chừng thì nhận một lời từ chối, không phải lỗi 500', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = submitMatter($lawyer);
    $document = submitDocument($matter);

    DB::table('documents')->where('id', $document->id)->delete();

    expect(fn () => submitForApprovalAs($document, $lawyer))->toThrow(DocumentLifecycleNotAllowed::class);
});

/**
 * Action không để guard đang mở quyết định nó đọc thấy gì (cùng bài học `PublishDocument`/
 * `RegroupDocument` ở M4/M5): một tài liệu nhóm B còn `internal_draft` là vô hình dưới guard
 * `client`, nên lần đọc lại phải bỏ qua `ClientPortalScope` tường minh.
 */
it('trình duyệt được dưới guard khách, vì Action không đọc dữ liệu bằng con mắt của guard đó', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->create(['lead_lawyer_id' => $lawyer->id]);
    $document = submitDocument($matter);

    ClientPortalScope::actingAs($clientUser, function () use ($document): void {
        expect(Document::query()->whereKey($document->getKey())->exists())->toBeFalse();
    });

    $submitted = ClientPortalScope::actingAs(
        $clientUser,
        fn (): Document => submitForApprovalAs($document, $lawyer),
    );

    expect($submitted->status)->toBe(DocumentStatus::PendingApproval);
});
