<?php

use App\Actions\Document\MarkDocumentSignedFiled;
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
 * "Đã ký, đã nộp" — bước THỨ HAI và cuối của vòng đời văn bản nhóm B trước khi nó công bố được
 * (SPEC §4.11, §6.5 bước 2, phán quyết R9). Trước Task 16 (`docs/docs-1`, critical) không có
 * Action nào ghi được `signed_filed`, nên `PublishDocument` từ chối MỌI tài liệu nhóm B mãi mãi.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function markMatter(User $owner): Matter
{
    return Matter::factory()->create(['lead_lawyer_id' => $owner->id]);
}

function markDocument(Matter $matter, DocumentGroup $group = DocumentGroup::Issued, DocumentStatus $status = DocumentStatus::PendingApproval): Document
{
    return Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => $group,
        'status' => $status,
        'client_can_view' => false,
        'client_can_download' => false,
    ]);
}

function markSignedFiledAs(Document $document, User $actor): Document
{
    return app(MarkDocumentSignedFiled::class)->handle(document: $document, actor: $actor);
}

it('luật sư đánh dấu được một bản thảo đang chờ duyệt là đã ký, đã nộp, và Action ghi một dòng nhật ký', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = markMatter($lawyer);
    $document = markDocument($matter);

    $marked = markSignedFiledAs($document, $lawyer);

    expect($marked->status)->toBe(DocumentStatus::SignedFiled);

    $activity = Activity::query()->where('event', 'document_signed_filed')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($lawyer))->toBeTrue()
        ->and($activity->subject?->is($document))->toBeTrue()
        ->and($activity->properties->get('from_status'))->toBe(DocumentStatus::PendingApproval->value)
        ->and($activity->properties->get('to_status'))->toBe(DocumentStatus::SignedFiled->value);
});

/**
 * R9, cốt lõi của "Trợ lý không bấm được 'Đã ký, đã nộp'" (brief Task 16): khác "Trình duyệt",
 * bước này đòi `document.publish`. Trợ lý có `document.update` (đủ để trình duyệt) nhưng không
 * đủ để đánh dấu bước này.
 */
it('trợ lý không đánh dấu được đã ký, đã nộp, dù có document.update và đọc được tài liệu', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = markMatter($lawyer);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    $document = markDocument($matter);

    // Cặp dương ngay tại chỗ: trợ lý ĐỌC và SỬA được chính tài liệu này (qua đúng cổng
    // `document.update`), nên lời từ chối bên dưới không thể là "không thấy tài liệu".
    expect($assistant->can('view', $document))->toBeTrue()
        ->and($assistant->can('update', $document))->toBeTrue();

    expect(fn () => markSignedFiledAs($document, $assistant))->toThrow(AuthorizationException::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::PendingApproval);
});

it('luật sư ngoài đội ngũ không đánh dấu được tài liệu của vụ việc đó', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = markMatter($lawyer);
    $document = markDocument($matter);

    expect(fn () => markSignedFiledAs($document, $outsider))->toThrow(AuthorizationException::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::PendingApproval);
});

/** Mutation probe: xoá điều kiện `$fresh->group !== DocumentGroup::Issued` làm test này đỏ. */
it('chỉ nhóm B mới đánh dấu đã ký, đã nộp được', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = markMatter($lawyer);
    $document = markDocument($matter, group: DocumentGroup::Authority);

    expect(fn () => markSignedFiledAs($document, $lawyer))->toThrow(DocumentLifecycleNotAllowed::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::PendingApproval);
});

/** Mutation probe: xoá điều kiện `$fresh->status !== DocumentStatus::PendingApproval` làm test này đỏ. */
it('một bản thảo chưa trình duyệt không đánh dấu đã ký, đã nộp được', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = markMatter($lawyer);
    $document = markDocument($matter, status: DocumentStatus::InternalDraft);

    expect(fn () => markSignedFiledAs($document, $lawyer))->toThrow(DocumentLifecycleNotAllowed::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::InternalDraft);
});

it('một tài liệu đã signed_filed (hay đã published) không đánh dấu lại được', function (DocumentStatus $status) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = markMatter($lawyer);
    $document = markDocument($matter, status: $status);

    expect(fn () => markSignedFiledAs($document, $lawyer))->toThrow(DocumentLifecycleNotAllowed::class);

    expect($document->fresh()->status)->toBe($status);
})->with([
    'signed_filed' => [DocumentStatus::SignedFiled],
    'published' => [DocumentStatus::Published],
]);

/**
 * Cùng lý lẽ với `SubmitDocumentForApprovalTest`: cổng "vụ việc đã xoá mềm" đứng TRƯỚC `Gate`
 * nên ném `DocumentLifecycleNotAllowed`, không phải `AuthorizationException`.
 */
it('không đánh dấu được cho tài liệu của một vụ việc đã xoá mềm', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = markMatter($admin);
    $document = markDocument($matter);
    $matter->delete();

    expect(fn () => markSignedFiledAs($document->fresh(), $admin))->toThrow(DocumentLifecycleNotAllowed::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::PendingApproval);
});

it('bản ghi bị xoá cứng giữa chừng thì nhận một lời từ chối, không phải lỗi 500', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = markMatter($lawyer);
    $document = markDocument($matter);

    DB::table('documents')->where('id', $document->id)->delete();

    expect(fn () => markSignedFiledAs($document, $lawyer))->toThrow(DocumentLifecycleNotAllowed::class);
});

/**
 * Action không để guard đang mở quyết định nó đọc thấy gì — cùng bài học `PublishDocument`/
 * `RegroupDocument`/`SubmitDocumentForApproval`.
 */
it('đánh dấu được dưới guard khách, vì Action không đọc dữ liệu bằng con mắt của guard đó', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->create(['lead_lawyer_id' => $lawyer->id]);
    $document = markDocument($matter);

    ClientPortalScope::actingAs($clientUser, function () use ($document): void {
        expect(Document::query()->whereKey($document->getKey())->exists())->toBeFalse();
    });

    $marked = ClientPortalScope::actingAs(
        $clientUser,
        fn (): Document => markSignedFiledAs($document, $lawyer),
    );

    expect($marked->status)->toBe(DocumentStatus::SignedFiled);
});
