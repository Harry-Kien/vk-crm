<?php

use App\Actions\Document\PublishDocument;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Events\DocumentPublished;
use App\Exceptions\DocumentNotPublishable;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Tệp của test không được rơi vào `storage/app/private` thật của máy dev: mỗi lần chạy bộ
    // test sẽ để lại một đống thư mục không ai dọn.
    Storage::fake('private');
});

function publishableMatter(User $owner): Matter
{
    return Matter::factory()->create(['lead_lawyer_id' => $owner->id]);
}

/**
 * Tài liệu dùng cho test công bố LUÔN có tệp đính kèm, vì một tài liệu không tệp không công bố
 * được (xem `DocumentNotPublishable::withoutFile()`). Hai test cố ý không gọi hàm này là hai
 * test về chính luật đó.
 */
function documentOn(Matter $matter, DocumentGroup $group, DocumentStatus $status): Document
{
    $document = documentWithoutFileOn($matter, $group, $status);

    $document->addMedia(UploadedFile::fake()->createWithContent('van-ban.pdf', '%PDF-1.4 test'))
        ->toMediaCollection('file');

    return $document->refresh();
}

function documentWithoutFileOn(Matter $matter, DocumentGroup $group, DocumentStatus $status): Document
{
    return Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => $group,
        'status' => $status,
        'client_can_view' => false,
        'client_can_download' => false,
    ]);
}

/**
 * Hai nhóm ra được tới khách và có vòng đời KHÁC nhau: nhóm B phải đi hết
 * `internal_draft → pending_approval → signed_filed`, nhóm C công bố thẳng từ bản nháp.
 * Mọi test về hai cờ khách hàng và về công bố lại chạy trên CẢ HAI — bản đầu của Action chỉ
 * được test trên nhóm C, và đó là lý do một tài liệu nhóm B đã công bố không bao giờ công bố
 * lại được mà bộ test vẫn xanh.
 */
dataset('nhóm ra được tới khách', [
    'nhóm B đã ký và nộp' => [DocumentGroup::Issued, DocumentStatus::SignedFiled],
    'nhóm C bản nháp nội bộ' => [DocumentGroup::Authority, DocumentStatus::InternalDraft],
]);

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

it('cho khách biết đã có tài liệu mà chưa cho tải là một lựa chọn hợp lệ', function (DocumentGroup $group, DocumentStatus $status) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, $group, $status);

    $published = publishDocumentAs($document, $lawyer, view: true, download: false);

    expect($published->status)->toBe(DocumentStatus::Published)
        ->and($published->client_can_view)->toBeTrue()
        ->and($published->client_can_download)->toBeFalse();
})->with('nhóm ra được tới khách');

it('công bố mà không cho khách xem thì bị từ chối — không có đường thu hồi trá hình', function (DocumentGroup $group, DocumentStatus $status) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, $group, $status);

    expect(fn () => publishDocumentAs($document, $lawyer, view: false, download: false))
        ->toThrow(DocumentNotPublishable::class);

    expect($document->fresh()->status)->toBe($status);
})->with('nhóm ra được tới khách');

it('không thu hồi được một tài liệu đã công bố bằng cách gọi lại Action với client_can_view false', function (DocumentGroup $group, DocumentStatus $status) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, $group, $status);

    publishDocumentAs($document, $lawyer);

    expect(fn () => publishDocumentAs($document->fresh(), $lawyer, view: false, download: false))
        ->toThrow(DocumentNotPublishable::class);

    $fresh = $document->fresh();

    expect($fresh->status)->toBe(DocumentStatus::Published)
        ->and($fresh->client_can_view)->toBeTrue();
})->with('nhóm ra được tới khách');

it('gọi lại Action rút được quyền tải mà vẫn giữ quyền xem', function (DocumentGroup $group, DocumentStatus $status) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, $group, $status);

    publishDocumentAs($document, $lawyer, view: true, download: true);
    $again = publishDocumentAs($document->fresh(), $lawyer, view: true, download: false);

    expect($again->client_can_view)->toBeTrue()
        ->and($again->client_can_download)->toBeFalse();
})->with('nhóm ra được tới khách');

it('công bố lại không tua lại thời điểm tài liệu lần đầu tới tay khách', function (DocumentGroup $group, DocumentStatus $status) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, $group, $status);

    $this->travelTo(now()->subDays(3));
    $first = publishDocumentAs($document, $lawyer);
    $firstPublishedAt = $first->published_at;
    $this->travelBack();

    $again = publishDocumentAs($document->fresh(), $lawyer, view: true, download: false);

    expect($again->published_at->equalTo($firstPublishedAt))->toBeTrue();
})->with('nhóm ra được tới khách');

/*
 * C1: cổng vòng đời nhóm B so `status !== signed_filed`, mà một lần công bố THÀNH CÔNG đặt
 * `status = published` — nên lần gọi thứ hai luôn bị từ chối, bằng một câu nói rằng tài liệu
 * chưa được ký và nộp trong khi chính nó đã tới tay khách. SPEC §6.5 bước 3 (rút quyền tải, giữ
 * quyền xem) vì thế không với tới được nhóm B, đúng nhóm SPEC coi là nhạy cảm nhất.
 */
it('tài liệu nhóm B đã công bố vẫn rút được quyền tải — cổng vòng đời không quay lại cắn chính nó', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Issued, DocumentStatus::SignedFiled);

    publishDocumentAs($document, $lawyer, view: true, download: true);
    $again = publishDocumentAs($document->fresh(), $lawyer, view: true, download: false);

    expect($again->status)->toBe(DocumentStatus::Published)
        ->and($again->client_can_view)->toBeTrue()
        ->and($again->client_can_download)->toBeFalse();
});

it('nhóm B bị ghi thẳng status=published mà khách chưa hề thấy vẫn phải đi hết vòng đời', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Issued, DocumentStatus::InternalDraft);

    // Một lần sửa tay hoặc một màn hình quên đi qua Action: cột `status` nhảy thẳng sang
    // `published` mà `client_can_view` vẫn false, tức tài liệu CHƯA ra tới khách. Nới cổng vòng
    // đời theo mỗi `status = published` sẽ biến lần ghi này thành một lối tắt hợp lệ.
    DB::table('documents')->where('id', $document->id)->update(['status' => DocumentStatus::Published->value]);

    expect(fn () => publishDocumentAs($document->fresh(), $lawyer))->toThrow(DocumentNotPublishable::class);

    expect((bool) DB::table('documents')->where('id', $document->id)->value('client_can_view'))->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Tệp: một tài liệu không có tệp thì không có gì để công bố.
// ---------------------------------------------------------------------------------------------

it('không công bố được một tài liệu chưa có tệp nào', function (DocumentGroup $group, DocumentStatus $status) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentWithoutFileOn($matter, $group, $status);

    expect(fn () => publishDocumentAs($document, $lawyer))->toThrow(DocumentNotPublishable::class);

    expect($document->fresh()->status)->toBe($status)
        ->and($document->fresh()->client_can_view)->toBeFalse();
})->with('nhóm ra được tới khách');

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
})->with('nhóm ra được tới khách');

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

it('công bố lại không gửi thông báo lần hai cho khách', function (DocumentGroup $group, DocumentStatus $status) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, $group, $status);

    $heard = 0;
    Event::listen(DocumentPublished::class, function () use (&$heard): void {
        $heard++;
    });

    publishDocumentAs($document, $lawyer, view: true, download: true);
    expect($heard)->toBe(1);

    publishDocumentAs($document->fresh(), $lawyer, view: true, download: false);

    expect($heard)->toBe(1);
})->with('nhóm ra được tới khách');

it('công bố lại ghi một dòng nhật ký nữa, đánh dấu là lần công bố lại', function (DocumentGroup $group, DocumentStatus $status) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, $group, $status);

    publishDocumentAs($document, $lawyer, view: true, download: true);
    publishDocumentAs($document->fresh(), $lawyer, view: true, download: false);

    $rows = Activity::query()->where('event', 'document_published')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->properties->get('republished'))->toBeFalse()
        ->and($rows[1]->properties->get('republished'))->toBeTrue()
        ->and($rows[1]->properties->get('client_id'))->toBe($matter->client_id)
        ->and($rows[1]->properties->get('version'))->toBe($document->version);
})->with('nhóm ra được tới khách');

it('bản ghi bị xoá cứng giữa chừng thì nhận một lời từ chối, không phải một lỗi 500', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    // Xoá CỨNG bằng một câu lệnh thẳng: model có SoftDeletes nên `delete()` chỉ đánh dấu, mà
    // nhánh `missing()` nói về một dòng không còn tồn tại thật.
    DB::table('documents')->where('id', $document->id)->delete();

    expect(fn () => publishDocumentAs($document, $lawyer))->toThrow(DocumentNotPublishable::class);
});

// ---------------------------------------------------------------------------------------------
// Action không để guard đang mở quyết định nó đọc thấy gì (rà soát cuối M4).
// ---------------------------------------------------------------------------------------------

/**
 * Cả lần đọc lại tài liệu lẫn lần đọc vụ việc đều phải ĐỘC LẬP với guard đang mở.
 *
 * Trước bản sửa này chúng là `Document::query()` và `$fresh->matter()` trần, nên dưới guard
 * `client` `ClientPortalScope` cắt chúng xuống những gì khách đọc được — và một tài liệu nhóm C
 * còn `internal_draft`, tức đúng loại tài liệu Action này tồn tại để công bố, đọc ra `null`.
 * Action khi đó trả lời `DocumentNotPublishable::missing()` về một bản ghi đang nằm đó: một câu
 * từ chối sai, trên đường đi mà M5 sẽ mở (portal chạy guard `client` thường trực, và một nhân sự
 * đăng nhập cả hai panel có cả hai guard cùng xác thực).
 *
 * Quyền không bị nới ra: `Gate::forUser($actor)` vẫn hỏi trên `$actor`, và policy vẫn tự chạy
 * scope thật qua `ClientPortalScope::actingAs()`.
 */
it('công bố được dưới guard khách, vì Action không đọc dữ liệu bằng con mắt của guard đó', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->create(['lead_lawyer_id' => $lawyer->id]);

    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    // Tiền đề: dưới guard khách, chính tài liệu này là vô hình.
    ClientPortalScope::actingAs($clientUser, function () use ($document): void {
        expect(Document::query()->whereKey($document->getKey())->exists())->toBeFalse();
    });

    $published = ClientPortalScope::actingAs(
        $clientUser,
        fn (): Document => publishDocumentAs($document, $lawyer),
    );

    expect($published->status)->toBe(DocumentStatus::Published)
        ->and($published->client_can_view)->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Kiểm tra optimistic (vòng sửa 1): "hai tab" — một tài liệu ĐÃ công bố, ai đó đổi cờ SAU khi
// một hộp thoại khác đã mở, TRƯỚC khi hộp thoại đó kịp xác nhận.
// ---------------------------------------------------------------------------------------------

it('công bố lại bị từ chối nếu ảnh chụp lúc mở hộp thoại lệch với dữ liệu hiện tại — hai tab', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    // "Tab 1" mở hộp thoại khi tài liệu đang view=true/download=true.
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::Published);
    $document->update(['client_can_view' => true, 'client_can_download' => true]);

    // "Tab 2" công bố lại TRƯỚC, tắt quyền tải.
    publishDocumentAs($document->fresh(), $lawyer, view: true, download: false);

    expect($document->fresh()->client_can_download)->toBeFalse();

    // "Tab 1" xác nhận với ảnh chụp CŨ (view=true, download=true) — dữ liệu nó gửi lên TRÙNG với
    // ảnh chụp, vì người dùng không sửa gì trên form, nhưng CSDL đã đổi ở dưới chân họ.
    expect(fn () => app(PublishDocument::class)->handle(
        document: $document->fresh(),
        actor: $lawyer,
        clientCanView: true,
        clientCanDownload: true,
        expectedClientCanView: true,
        expectedClientCanDownload: true,
    ))->toThrow(DocumentNotPublishable::class);

    // "download stays off" — lần xác nhận cũ (stale) không được phép ghi đè CSDL trở lại.
    expect($document->fresh()->client_can_download)->toBeFalse();
});

/**
 * Cặp dương: ảnh chụp KHỚP với CSDL hiện tại (không ai đổi gì ở giữa) — công bố lại vẫn thành
 * công như bình thường.
 */
it('công bố lại vẫn thành công khi ảnh chụp lúc mở hộp thoại khớp với dữ liệu hiện tại', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::Published);
    $document->update(['client_can_view' => true, 'client_can_download' => true]);

    $republished = app(PublishDocument::class)->handle(
        document: $document->fresh(),
        actor: $lawyer,
        clientCanView: true,
        clientCanDownload: false,
        expectedClientCanView: true,
        expectedClientCanDownload: true,
    );

    expect($republished->client_can_download)->toBeFalse();
});

/**
 * Mutation probe (nói bằng lời, xem báo cáo cho RED thật): xoá điều kiện `$wasAlreadyReleased`
 * khỏi cổng optimistic làm test này đỏ — một lần công bố ĐẦU TIÊN (không có ảnh chụp thật, màn
 * hình gửi `expectedClientCanView = null`) không được phép bị cổng này chặn.
 */
it('lần công bố ĐẦU TIÊN không bị cổng optimistic chặn, dù không truyền ảnh chụp nào', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = publishableMatter($lawyer);
    $document = documentOn($matter, DocumentGroup::Authority, DocumentStatus::InternalDraft);

    $published = app(PublishDocument::class)->handle(
        document: $document,
        actor: $lawyer,
        clientCanView: true,
        clientCanDownload: true,
        expectedClientCanView: null,
        expectedClientCanDownload: null,
    );

    expect($published->status)->toBe(DocumentStatus::Published);
});
