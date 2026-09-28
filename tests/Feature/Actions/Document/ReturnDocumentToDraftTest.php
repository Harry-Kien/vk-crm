<?php

use App\Actions\Document\ReturnDocumentToDraft;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
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
 * "Trả về bản nháp" — ruling vòng sửa 1 Task 16: `pending_approval` → `internal_draft`. Trước
 * Action này, một bản thảo trình duyệt nhầm không có đường quay lại ngoài tải một tài liệu MỚI.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function returnMatter(User $owner): Matter
{
    return Matter::factory()->create(['lead_lawyer_id' => $owner->id]);
}

function returnDocument(Matter $matter, DocumentGroup $group = DocumentGroup::Issued, DocumentStatus $status = DocumentStatus::PendingApproval): Document
{
    return Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => $group,
        'status' => $status,
        'client_can_view' => false,
        'client_can_download' => false,
    ]);
}

function returnToDraftAs(Document $document, User $actor): Document
{
    return app(ReturnDocumentToDraft::class)->handle(document: $document, actor: $actor);
}

it('luật sư trả được một bản thảo đang chờ duyệt về lại bản nháp, và Action ghi một dòng nhật ký', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = returnMatter($lawyer);
    $document = returnDocument($matter);

    $returned = returnToDraftAs($document, $lawyer);

    expect($returned->status)->toBe(DocumentStatus::InternalDraft);

    $activity = Activity::query()->where('event', 'document_returned_to_draft')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($lawyer))->toBeTrue()
        ->and($activity->subject?->is($document))->toBeTrue()
        ->and($activity->properties->get('from_status'))->toBe(DocumentStatus::PendingApproval->value)
        ->and($activity->properties->get('to_status'))->toBe(DocumentStatus::InternalDraft->value);
});

/**
 * Ruling: "Trả về bản nháp" đòi `document.publish`, KHÁC "Trình duyệt" (đòi `document.update`).
 * Trợ lý trình duyệt được (test riêng ở `SubmitDocumentForApprovalTest`) nhưng không kéo lùi
 * được một bản thảo đã trình duyệt — một quyết định nặng hơn.
 */
it('trợ lý không trả về bản nháp được, dù có document.update và đọc được tài liệu', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = returnMatter($lawyer);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    $document = returnDocument($matter);

    expect($assistant->can('view', $document))->toBeTrue()
        ->and($assistant->can('update', $document))->toBeTrue();

    expect(fn () => returnToDraftAs($document, $assistant))->toThrow(AuthorizationException::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::PendingApproval);
});

it('luật sư ngoài đội ngũ không trả về bản nháp được tài liệu của vụ việc đó', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = returnMatter($lawyer);
    $document = returnDocument($matter);

    expect(fn () => returnToDraftAs($document, $outsider))->toThrow(AuthorizationException::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::PendingApproval);
});

/** Mutation probe: xoá điều kiện `$fresh->group !== DocumentGroup::Issued` làm test này đỏ. */
it('chỉ nhóm B mới trả về bản nháp được', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = returnMatter($lawyer);
    $document = returnDocument($matter, group: DocumentGroup::Authority);

    expect(fn () => returnToDraftAs($document, $lawyer))->toThrow(DocumentLifecycleNotAllowed::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::PendingApproval);
});

/** Mutation probe: xoá điều kiện `$fresh->status !== DocumentStatus::PendingApproval` làm test này đỏ. */
it('một bản thảo còn internal_draft không trả về bản nháp được — nó đã ở đó rồi', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = returnMatter($lawyer);
    $document = returnDocument($matter, status: DocumentStatus::InternalDraft);

    expect(fn () => returnToDraftAs($document, $lawyer))->toThrow(DocumentLifecycleNotAllowed::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::InternalDraft);
});

it('một tài liệu đã signed_filed (hay đã published) không trả về bản nháp được', function (DocumentStatus $status) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = returnMatter($lawyer);
    $document = returnDocument($matter, status: $status);

    expect(fn () => returnToDraftAs($document, $lawyer))->toThrow(DocumentLifecycleNotAllowed::class);

    expect($document->fresh()->status)->toBe($status);
})->with([
    'signed_filed' => [DocumentStatus::SignedFiled],
    'published' => [DocumentStatus::Published],
]);

it('không trả về bản nháp được cho tài liệu của một vụ việc đã xoá mềm', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = returnMatter($admin);
    $document = returnDocument($matter);
    $matter->delete();

    expect(fn () => returnToDraftAs($document->fresh(), $admin))->toThrow(DocumentLifecycleNotAllowed::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::PendingApproval);
});

/**
 * Nhánh xoá mềm CHÍNH TÀI LIỆU (khác nhánh vụ việc xoá mềm ở trên) — vòng sửa 1, "Also fix" đòi
 * test + mutation probe cho nhánh này ở cả ba Action mới. Mutation probe: xoá điều kiện
 * `$fresh->trashed()` làm test này đỏ (RegroupDocument/PublishDocument dùng `withTrashed()->find()`
 * + kiểm `trashed()` tường minh cùng lý do — một dòng đã xoá mềm vẫn đọc ra được, và phải bị từ
 * chối bằng một câu RIÊNG, không phải "không tìm thấy").
 */
it('không trả về bản nháp được cho một tài liệu đã bị xoá mềm', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = returnMatter($lawyer);
    $document = returnDocument($matter);
    $document->delete();

    expect(fn () => returnToDraftAs($document, $lawyer))->toThrow(DocumentLifecycleNotAllowed::class);
});

it('bản ghi bị xoá cứng giữa chừng thì nhận một lời từ chối, không phải lỗi 500', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = returnMatter($lawyer);
    $document = returnDocument($matter);

    DB::table('documents')->where('id', $document->id)->delete();

    expect(fn () => returnToDraftAs($document, $lawyer))->toThrow(DocumentLifecycleNotAllowed::class);
});

it('trả về bản nháp được dưới guard khách, vì Action không đọc dữ liệu bằng con mắt của guard đó', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->create(['lead_lawyer_id' => $lawyer->id]);
    $document = returnDocument($matter);

    ClientPortalScope::actingAs($clientUser, function () use ($document): void {
        expect(Document::query()->whereKey($document->getKey())->exists())->toBeFalse();
    });

    $returned = ClientPortalScope::actingAs(
        $clientUser,
        fn (): Document => returnToDraftAs($document, $lawyer),
    );

    expect($returned->status)->toBe(DocumentStatus::InternalDraft);
});
