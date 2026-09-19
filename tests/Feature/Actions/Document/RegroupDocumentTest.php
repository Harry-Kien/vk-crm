<?php

use App\Actions\Document\RegroupDocument;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\DocumentGroupNotChangeable;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function regroupMatter(User $owner): Matter
{
    return Matter::factory()->create(['lead_lawyer_id' => $owner->id]);
}

function regroupDocument(Matter $matter, DocumentGroup $group): Document
{
    return Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => $group,
        'status' => DocumentStatus::InternalDraft,
        'client_can_view' => false,
        'client_can_download' => false,
    ]);
}

function regroupAs(Document $document, User $actor, DocumentGroup $group): Document
{
    return app(RegroupDocument::class)->handle(document: $document, actor: $actor, group: $group);
}

// ---------------------------------------------------------------------------------------------
// Rời khỏi nhóm D là một quyết định về số phận tài liệu, nên nó đòi `document.publish` — và nó
// để lại dấu vết. Trước đó: một lệnh `update(['group' => 'C'])` đi qua mà KHÔNG sinh dòng nhật ký
// nào, và thứ duy nhất còn lại sau đó là một dòng `document_published` về một tài liệu nhóm C.
// ---------------------------------------------------------------------------------------------

it('luật sư có document.publish thì chuyển được tài liệu ra khỏi nhóm D', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = regroupMatter($lawyer);
    $document = regroupDocument($matter, DocumentGroup::Internal);

    $moved = regroupAs($document, $lawyer, DocumentGroup::Authority);

    expect($moved->group)->toBe(DocumentGroup::Authority);
});

it('trợ lý không có document.publish thì không chuyển được tài liệu ra khỏi nhóm D', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = regroupMatter($lawyer);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    $document = regroupDocument($matter, DocumentGroup::Internal);

    expect(fn () => regroupAs($document, $assistant, DocumentGroup::Authority))
        ->toThrow(AuthorizationException::class);

    expect($document->fresh()->group)->toBe(DocumentGroup::Internal);
});

/*
 * Test ở trên xanh cả khi cổng `document.publish` bị gỡ: một trợ lý không có
 * `document.viewInternal` nên `DocumentPolicy::view` đã chặn họ từ trước, và cái chặn họ là tầm
 * NHÌN chứ không phải quyền công bố. Đó đúng cái bẫy dự án này rơi vào nhiều lần (xem ghi chú
 * Task 2 về `matter.update`), nên cổng cần một người chứng minh được nó: ai đó ĐỌC được tài liệu
 * nhóm D mà không được quyết định số phận nó.
 *
 * Bảng vai trò SPEC §5 hôm nay không có ai như vậy — bốn vai có `document.viewInternal` đều có
 * `document.publish`. Nên người đó được dựng bằng cách cấp quyền thẳng cho tài khoản, không qua
 * vai trò: quyền là DỮ LIỆU, một lần cấp tay hay một vai trò mới sau này tạo ra đúng hình dạng
 * này, và khi đó cổng phải đã đứng sẵn ở đó.
 */
it('người đọc được tài liệu nhóm D nhưng không có document.publish vẫn không chuyển nhóm được', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = regroupMatter($lawyer);
    $document = regroupDocument($matter, DocumentGroup::Internal);

    $reader = User::factory()->create();
    $reader->givePermissionTo([
        Permission::MatterView->value,
        Permission::MatterUpdate->value,
        Permission::DocumentViewInternal->value,
    ]);
    $matter->addTeamMember($reader, MatterRole::Assistant);

    // Cặp dương ngay tại chỗ: người này ĐỌC và SỬA được chính tài liệu đó, nên lời từ chối bên
    // dưới không thể là "không thấy vụ việc" hay "không thấy tài liệu".
    expect($reader->can('view', $document))->toBeTrue()
        ->and($reader->can('update', $document))->toBeTrue();

    expect(fn () => regroupAs($document, $reader, DocumentGroup::Authority))
        ->toThrow(AuthorizationException::class);

    expect($document->fresh()->group)->toBe(DocumentGroup::Internal);
});

it('chuyển VÀO nhóm D thì trợ lý làm được — siết lại không phải là nới ra', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = regroupMatter($lawyer);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    $document = regroupDocument($matter, DocumentGroup::Authority);

    expect(regroupAs($document, $assistant, DocumentGroup::Internal)->group)
        ->toBe(DocumentGroup::Internal);
});

it('ghi một dòng nhật ký nêu rõ nhóm cũ và nhóm mới, với actor tường minh', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = regroupMatter($lawyer);
    $document = regroupDocument($matter, DocumentGroup::Internal);
    $this->actingAs($admin, 'web');

    regroupAs($document, $lawyer, DocumentGroup::Authority);

    $activity = Activity::query()->where('event', 'document_regrouped')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($lawyer))->toBeTrue()
        ->and($activity->subject?->is($document))->toBeTrue()
        ->and($activity->properties->get('from_group'))->toBe(DocumentGroup::Internal->value)
        ->and($activity->properties->get('to_group'))->toBe(DocumentGroup::Authority->value);
});

it('đổi sang đúng nhóm đang có thì không ghi gì và không đổi gì', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = regroupMatter($lawyer);
    $document = regroupDocument($matter, DocumentGroup::Internal);

    regroupAs($document, $lawyer, DocumentGroup::Internal);

    expect(Activity::query()->where('event', 'document_regrouped')->count())->toBe(0);
});

/*
 * Hai test dưới đây đi trên nhóm B → C, tức KHÔNG chạm cổng `document.publish`. Chúng có mặt để
 * cổng `update` không thành một dòng thừa: với nhóm D, `publish` đã gọi `update()` bên trong nên
 * mọi test nhóm D vẫn xanh khi gỡ nó đi (đã kiểm bằng mutation).
 */
it('luật sư ngoài đội ngũ không đổi được nhóm tài liệu của vụ việc đó', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = regroupMatter($lawyer);
    $document = regroupDocument($matter, DocumentGroup::Issued);

    expect(fn () => regroupAs($document, $outsider, DocumentGroup::Authority))
        ->toThrow(AuthorizationException::class);

    expect($document->fresh()->group)->toBe(DocumentGroup::Issued);
});

it('không chuyển nhóm được cho tài liệu của một vụ việc đã xoá mềm', function (DocumentGroup $from, DocumentGroup $to) {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = regroupMatter($admin);
    $document = regroupDocument($matter, $from);
    $matter->delete();

    expect(fn () => regroupAs($document->fresh(), $admin, $to))
        ->toThrow(AuthorizationException::class);

    expect($document->fresh()->group)->toBe($from);
})->with([
    'rời nhóm D' => [DocumentGroup::Internal, DocumentGroup::Authority],
    'giữa B và C' => [DocumentGroup::Issued, DocumentGroup::Authority],
]);

// ---------------------------------------------------------------------------------------------
// Hàng rào ở tầng model: không đường nào khác rời được nhóm D.
// ---------------------------------------------------------------------------------------------

it('một lệnh update thẳng trên model không đưa được tài liệu ra khỏi nhóm D', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = regroupMatter($lawyer);
    $document = regroupDocument($matter, DocumentGroup::Internal);

    expect(fn () => $document->update(['group' => DocumentGroup::Authority]))
        ->toThrow(DocumentGroupNotChangeable::class);

    expect($document->fresh()->group)->toBe(DocumentGroup::Internal);
});

it('một lệnh update thẳng vẫn chuyển được nhóm giữa A, B và C', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = regroupMatter($lawyer);
    $document = regroupDocument($matter, DocumentGroup::Issued);

    $document->update(['group' => DocumentGroup::Authority]);

    expect($document->fresh()->group)->toBe(DocumentGroup::Authority);
});

// ---------------------------------------------------------------------------------------------
// Dòng dữ liệu nói dối: nhóm D không giữ nổi `client_can_download = true`.
// ---------------------------------------------------------------------------------------------

it('một dòng nhóm D không giữ được client_can_download bật, dù ai ghi', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = regroupMatter($lawyer);
    $document = regroupDocument($matter, DocumentGroup::Internal);

    $document->update(['client_can_download' => true, 'client_can_view' => true]);

    expect((bool) DB::table('documents')->where('id', $document->id)->value('client_can_download'))
        ->toBeFalse();
});

it('tạo mới một dòng nhóm D với client_can_download bật cũng bị hạ xuống', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = regroupMatter($lawyer);

    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => DocumentGroup::Internal,
        'client_can_download' => true,
    ]);

    expect((bool) DB::table('documents')->where('id', $document->id)->value('client_can_download'))
        ->toBeFalse();
});

it('chuyển một tài liệu vào nhóm D hạ luôn quyền tải của khách', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = regroupMatter($lawyer);
    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => DocumentGroup::Authority,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    regroupAs($document, $lawyer, DocumentGroup::Internal);

    expect($document->fresh()->client_can_download)->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Xoá tài liệu để lại dấu vết (việc mang sang từ rà soát Task 2).
// ---------------------------------------------------------------------------------------------

it('xoá mềm một tài liệu để lại một dòng nhật ký đọc được cả nhóm của nó', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = regroupMatter($lawyer);
    $document = regroupDocument($matter, DocumentGroup::Internal);

    $document->delete();

    $activity = Activity::query()
        ->where('event', 'deleted')
        ->where('subject_type', $document->getMorphClass())
        ->where('subject_id', $document->id)
        ->latest('id')
        ->first();

    // Với một model có SoftDeletes, spatie ghi giá trị của dòng vừa biến mất dưới khoá `old`,
    // không phải `attributes` — pin lại đúng hình dạng đó, vì Task 7 sẽ đọc nhật ký này.
    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('old')['group'])->toBe(DocumentGroup::Internal->value)
        ->and($activity->properties->get('old')['title'])->toBe($document->title);
});
