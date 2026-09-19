<?php

use App\Actions\Document\PublishDocument;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Events\DocumentPublished;
use App\Exceptions\DocumentNotPublishable;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function publishableMatter(User $owner): Matter
{
    return Matter::factory()->create(['lead_lawyer_id' => $owner->id]);
}

function documentOn(Matter $matter, DocumentGroup $group, DocumentStatus $status): Document
{
    return Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => $group,
        'status' => $status,
        'client_can_view' => false,
        'client_can_download' => false,
    ]);
}

function publishDocumentAs(Document $document, User $actor, bool $view = true, bool $download = true): Document
{
    return app(PublishDocument::class)->handle(
        document: $document,
        actor: $actor,
        clientCanView: $view,
        clientCanDownload: $download,
    );
}

// ---------------------------------------------------------------------------------------------
// SPEC §11 "Tài liệu nội bộ" — ba dòng bắt buộc, mỗi dòng có một trường hợp DƯƠNG đi kèm để
// khẳng định phủ định không xanh chỉ vì mọi thứ đều bị từ chối.
// ---------------------------------------------------------------------------------------------

it('nhóm D không công bố được và không đổi một cột nào', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Internal, DocumentStatus::InternalDraft);

    expect(fn () => publishDocumentAs($document, $lawyer))->toThrow(DocumentNotPublishable::class);

    $fresh = $document->fresh();

    expect($fresh->status)->toBe(DocumentStatus::InternalDraft)
        ->and($fresh->client_can_view)->toBeFalse()
        ->and($fresh->client_can_download)->toBeFalse()
        ->and($fresh->published_at)->toBeNull()
        ->and($fresh->published_by)->toBeNull();
});

it('nhóm D ở signed_filed cũng không công bố được — trạng thái không cứu được nhóm', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Internal, DocumentStatus::SignedFiled);

    expect(fn () => publishDocumentAs($document, $lawyer))->toThrow(DocumentNotPublishable::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::SignedFiled);
});

it('nhóm B ở internal_draft không công bố được', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Issued, DocumentStatus::InternalDraft);

    expect(fn () => publishDocumentAs($document, $lawyer))->toThrow(DocumentNotPublishable::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::InternalDraft)
        ->and($document->fresh()->client_can_view)->toBeFalse();
});

it('nhóm B ở pending_approval không công bố được', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Issued, DocumentStatus::PendingApproval);

    expect(fn () => publishDocumentAs($document, $lawyer))->toThrow(DocumentNotPublishable::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::PendingApproval);
});

it('nhóm B ở signed_filed thì công bố được — cặp dương của hai test trên', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Issued, DocumentStatus::SignedFiled);

    $published = publishDocumentAs($document, $lawyer);

    expect($published->status)->toBe(DocumentStatus::Published)
        ->and($published->client_can_view)->toBeTrue()
        ->and($published->client_can_download)->toBeTrue()
        ->and($published->published_at)->not->toBeNull()
        ->and($published->published_by)->toBe($lawyer->id);
});

it('nhóm C ở internal_draft công bố thẳng được — luật signed_filed chỉ của nhóm B', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    expect(publishDocumentAs($document, $lawyer)->status)->toBe(DocumentStatus::Published);
});

// ---------------------------------------------------------------------------------------------
// Nhóm D không có đường vòng nào.
// ---------------------------------------------------------------------------------------------

it('nhóm D trong cơ sở dữ liệu thắng nhóm mà caller cầm trong tay', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Internal, DocumentStatus::SignedFiled);

    // Đối tượng trong tay caller bị sửa nhóm nhưng KHÔNG lưu: đây là hình dạng chính xác của
    // "caller truyền nhóm vào". Action phải đọc lại nhóm từ dòng dữ liệu, không tin thuộc tính.
    $document->group = DocumentGroup::Issued;

    expect(fn () => publishDocumentAs($document, $lawyer))->toThrow(DocumentNotPublishable::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::SignedFiled)
        ->and($document->fresh()->group)->toBe(DocumentGroup::Internal);
});

it('một tài liệu bị sửa thành nhóm D sau đó không công bố lại được', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    publishDocumentAs($document, $lawyer);

    // Ai đó đổi nhóm sang D qua màn hình sửa tài liệu. Lần công bố sau phải bị chặn.
    $document->fresh()->update(['group' => DocumentGroup::Internal]);

    expect(fn () => publishDocumentAs($document->fresh(), $lawyer))->toThrow(DocumentNotPublishable::class);
});

it('nhóm D đã bị ghi thẳng status=published vẫn không đi qua được Action', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Internal, DocumentStatus::InternalDraft);

    // Bỏ qua Action, ghi thẳng vào bảng — hình dạng của một lần sửa dữ liệu tay hoặc một màn
    // hình tương lai quên đi qua Action. Action vẫn phải từ chối bật hai cờ cho khách.
    DB::table('documents')->where('id', $document->id)->update(['status' => DocumentStatus::Published->value]);

    expect(fn () => publishDocumentAs($document->fresh(), $lawyer))->toThrow(DocumentNotPublishable::class);

    expect((bool) DB::table('documents')->where('id', $document->id)->value('client_can_view'))->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Hai cờ độc lập, và giới hạn của chúng.
// ---------------------------------------------------------------------------------------------

it('cho khách biết đã có tài liệu mà chưa cho tải là một lựa chọn hợp lệ', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    $published = publishDocumentAs($document, $lawyer, view: true, download: false);

    expect($published->status)->toBe(DocumentStatus::Published)
        ->and($published->client_can_view)->toBeTrue()
        ->and($published->client_can_download)->toBeFalse();
});

it('công bố mà không cho khách xem thì bị từ chối — không có đường thu hồi trá hình', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    expect(fn () => publishDocumentAs($document, $lawyer, view: false, download: false))
        ->toThrow(DocumentNotPublishable::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::InternalDraft);
});

it('không thu hồi được một tài liệu đã công bố bằng cách gọi lại Action với client_can_view false', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    publishDocumentAs($document, $lawyer);

    expect(fn () => publishDocumentAs($document->fresh(), $lawyer, view: false, download: false))
        ->toThrow(DocumentNotPublishable::class);

    $fresh = $document->fresh();

    expect($fresh->status)->toBe(DocumentStatus::Published)
        ->and($fresh->client_can_view)->toBeTrue();
});

it('gọi lại Action rút được quyền tải mà vẫn giữ quyền xem', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    publishDocumentAs($document, $lawyer, view: true, download: true);
    $again = publishDocumentAs($document->fresh(), $lawyer, view: true, download: false);

    expect($again->client_can_view)->toBeTrue()
        ->and($again->client_can_download)->toBeFalse();
});

it('công bố lại không tua lại thời điểm tài liệu lần đầu tới tay khách', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    $this->travelTo(now()->subDays(3));
    $first = publishDocumentAs($document, $lawyer);
    $firstPublishedAt = $first->published_at;
    $this->travelBack();

    $again = publishDocumentAs($document->fresh(), $lawyer, view: true, download: false);

    expect($again->published_at->equalTo($firstPublishedAt))->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Quyền, vụ việc và bản ghi phải còn sống.
// ---------------------------------------------------------------------------------------------

it('trợ lý không có document.publish thì không công bố được', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = publishableMatter($lawyer);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    expect(fn () => publishDocumentAs($document, $assistant))->toThrow(AuthorizationException::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::InternalDraft);
});

it('luật sư ngoài đội ngũ không công bố được tài liệu của vụ việc đó', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    expect(fn () => publishDocumentAs($document, $outsider))->toThrow(AuthorizationException::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::InternalDraft);
});

it('không công bố được tài liệu của một vụ việc đã xoá mềm', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = publishableMatter($admin);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);
    $matter->delete();

    expect(fn () => publishDocumentAs($document->fresh(), $admin))->toThrow(DocumentNotPublishable::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::InternalDraft);
});

it('không công bố được một tài liệu đã xoá mềm', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = publishableMatter($admin);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);
    $document->delete();

    expect(fn () => publishDocumentAs($document, $admin))->toThrow(DocumentNotPublishable::class);

    expect(Document::withTrashed()->find($document->id)->status)->toBe(DocumentStatus::InternalDraft);
});

// ---------------------------------------------------------------------------------------------
// Nhật ký và thông báo.
// ---------------------------------------------------------------------------------------------

it('ghi nhật ký kiểm toán công bố tài liệu với đúng actor tường minh', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);
    $this->actingAs($admin, 'web');

    publishDocumentAs($document, $lawyer, view: true, download: false);

    $activity = Activity::query()->where('event', 'document_published')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($lawyer))->toBeTrue()
        ->and($activity->subject?->is($document))->toBeTrue()
        ->and($activity->properties->get('group'))->toBe(DocumentGroup::Authority->value)
        ->and($activity->properties->get('client_can_download'))->toBeFalse();
});

it('một lần công bố bị từ chối không ghi dòng nhật ký công bố nào', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Internal, DocumentStatus::InternalDraft);

    expect(fn () => publishDocumentAs($document, $lawyer))->toThrow(DocumentNotPublishable::class);

    expect(Activity::query()->where('event', 'document_published')->count())->toBe(0);
});

it('nhóm B và C dispatch thông báo cho khách', function (DocumentGroup $group, DocumentStatus $status) {
    Event::fake([DocumentPublished::class]);

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, $group, $status);

    publishDocumentAs($document, $lawyer);

    Event::assertDispatched(DocumentPublished::class);
})->with([
    'nhóm B' => [DocumentGroup::Issued, DocumentStatus::SignedFiled],
    'nhóm C' => [DocumentGroup::Authority, DocumentStatus::InternalDraft],
]);

it('nhóm A không dispatch thông báo — không phải tài liệu quan trọng theo SPEC §6.5', function () {
    Event::fake([DocumentPublished::class]);

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::ClientProvided, DocumentStatus::InternalDraft);

    publishDocumentAs($document, $lawyer);

    Event::assertNotDispatched(DocumentPublished::class);
});

it('transaction ngoài rollback thì khách không nhận thông báo về một lần công bố không xảy ra', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    $heard = 0;
    Event::listen(DocumentPublished::class, function () use (&$heard): void {
        $heard++;
    });

    try {
        DB::transaction(function () use ($document, $lawyer): void {
            publishDocumentAs($document, $lawyer);

            throw new RuntimeException('một bước sau đó hỏng');
        });
    } catch (RuntimeException) {
        // Mong đợi.
    }

    expect($heard)->toBe(0)
        ->and($document->fresh()->status)->toBe(DocumentStatus::InternalDraft);
});

it('transaction ngoài commit thì thông báo vẫn tới', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    $heard = 0;
    Event::listen(DocumentPublished::class, function () use (&$heard): void {
        $heard++;
    });

    DB::transaction(function () use ($document, $lawyer): void {
        publishDocumentAs($document, $lawyer);
    });

    expect($heard)->toBe(1)
        ->and($document->fresh()->status)->toBe(DocumentStatus::Published);
});

it('công bố lại không gửi thông báo lần hai cho khách', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    $heard = 0;
    Event::listen(DocumentPublished::class, function () use (&$heard): void {
        $heard++;
    });

    publishDocumentAs($document, $lawyer, view: true, download: true);
    expect($heard)->toBe(1);

    publishDocumentAs($document->fresh(), $lawyer, view: true, download: false);

    expect($heard)->toBe(1);
});
