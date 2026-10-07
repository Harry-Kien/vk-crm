<?php

use App\Actions\Document\PublishDocument;
use App\Actions\Document\RegroupDocument;
use App\Actions\Document\RetractDocument;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\InstalmentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Exceptions\DocumentGroupNotChangeable;
use App\Exceptions\DocumentNotPublishable;
use App\Exceptions\DocumentNotRetractable;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * M7 Task 7 — `RetractDocument` ở tầng Action: trạng thái, bằng chứng, cổng quyền, ngưỡng lý do,
 * và "một đường rút duy nhất" (công bố lại, chuyển vào nhóm D, quyền xoá). Màn hình có test
 * Livewire riêng (`tests/Feature/Filament/DocumentRetractionTest.php`), cổng khách ở
 * `tests/Feature/Portal/RetractedDocumentNoticeTest.php`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
});

/** Một tài liệu ĐANG ra tới khách (đã công bố, cờ xem bật), có tệp thật trên đĩa giả. */
function retractionReleasedDocument(Matter $matter, array $attributes = []): Document
{
    $document = Document::factory()->for($matter)->group(DocumentGroup::Authority)->create([
        'title' => 'Quyết định đưa vụ án ra xét xử',
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
        'published_at' => now()->subDay(),
        ...$attributes,
    ]);

    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung that'))
        ->usingName('Quyet dinh.pdf')
        ->usingFileName('01k5g7q8wz00000000000000r1.pdf')
        ->toMediaCollection('file');

    return $document->refresh();
}

function retractAs(Document $document, User $actor, string $reason): Document
{
    return app(RetractDocument::class)->handle(document: $document, actor: $actor, reason: $reason);
}

/** 20 ký tự theo `mb_strlen`, 39 byte — một ngưỡng đếm byte sẽ cho qua cả bản 19 ký tự. */
const RETRACTION_REASON_OK = 'Công bố nhầm vụ khác';

it('rút lại: đặt trạng thái retracted, tắt hai cờ khách, ghi lý do/người/lúc rút, giữ tệp và mọi lượt tải', function () {
    $this->travelTo(now()->startOfMinute());
    $document = retractionReleasedDocument($this->matter);

    DocumentDownload::factory()->count(2)->create([
        'document_id' => $document->id,
        'downloader_type' => $this->clientUser->getMorphClass(),
        'downloader_id' => $this->clientUser->id,
    ]);
    DocumentDownload::factory()->create(['document_id' => $document->id]);

    $retracted = retractAs($document, $this->lawyer, '  '.RETRACTION_REASON_OK."\u{00A0}\n");

    $fresh = $document->fresh();

    expect($retracted->getKey())->toBe($document->getKey())
        ->and($fresh->status)->toBe(DocumentStatus::Retracted)
        ->and($fresh->client_can_view)->toBeFalse()
        ->and($fresh->client_can_download)->toBeFalse()
        ->and($fresh->retraction_reason)->toBe(RETRACTION_REASON_OK)
        ->and($fresh->retracted_by)->toBe($this->lawyer->id)
        ->and($fresh->retracted_at?->equalTo(now()))->toBeTrue()
        // Bằng chứng: tệp và ba dòng tải còn nguyên.
        ->and($fresh->getMedia('file'))->toHaveCount(1)
        ->and(DocumentDownload::query()->where('document_id', $document->id)->count())->toBe(3)
        // `published_at` là một sự kiện đã xảy ra — rút lại không xoá nó.
        ->and($fresh->published_at)->not->toBeNull();

    $activity = Activity::query()->where('event', 'document_retracted')->sole();

    expect($activity->subject_id)->toBe($document->id)
        ->and($activity->causer_id)->toBe($this->lawyer->id)
        ->and($activity->properties['matter_id'] ?? null)->toBe($this->matter->id)
        ->and($activity->properties['client_id'] ?? null)->toBe($this->client->id)
        ->and($activity->properties['client_downloads'] ?? null)->toBe(2);
});

it('lý do 19 ký tự có dấu bị từ chối (đếm mb_strlen, không đếm byte), 20 ký tự thì được', function () {
    $document = retractionReleasedDocument($this->matter);
    $nineteen = mb_substr(RETRACTION_REASON_OK, 0, 19);

    expect(mb_strlen($nineteen))->toBe(19)
        ->and(strlen($nineteen))->toBeGreaterThan(20);

    try {
        retractAs($document, $this->lawyer, $nineteen);
        $this->fail('Lý do 19 ký tự phải bị từ chối.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['retraction_reason']);
    }

    expect($document->fresh()->status)->toBe(DocumentStatus::Published);

    retractAs($document, $this->lawyer, RETRACTION_REASON_OK);

    expect($document->fresh()->status)->toBe(DocumentStatus::Retracted);
});

it('khoảng trắng Unicode ở hai đầu không được tính vào 20 ký tự', function () {
    $document = retractionReleasedDocument($this->matter);
    $padded = "\u{00A0}\u{3000}".mb_substr(RETRACTION_REASON_OK, 0, 19)."\u{200B} ";

    expect(mb_strlen($padded))->toBeGreaterThan(20);
    expect(fn () => retractAs($document, $this->lawyer, $padded))->toThrow(ValidationException::class);
    expect($document->fresh()->status)->toBe(DocumentStatus::Published);
});

it('từ chối lý do dài hơn trần của cột', function () {
    $document = retractionReleasedDocument($this->matter);

    expect(fn () => retractAs($document, $this->lawyer, str_repeat('ư', RetractDocument::REASON_MAX + 1)))
        ->toThrow(ValidationException::class);
    expect($document->fresh()->status)->toBe(DocumentStatus::Published);

    retractAs($document, $this->lawyer, str_repeat('ư', RetractDocument::REASON_MAX));
    expect($document->fresh()->status)->toBe(DocumentStatus::Retracted);
});

it('chỉ rút tài liệu ĐANG ra tới khách', function (array $attributes) {
    $document = retractionReleasedDocument($this->matter, $attributes);

    expect(fn () => retractAs($document, $this->lawyer, RETRACTION_REASON_OK))
        ->toThrow(DocumentNotRetractable::class, __('retraction.exceptions.not_released'));

    expect($document->fresh()->status)->toBe(DocumentStatus::from($attributes['status'] ?? 'published'))
        ->and(Activity::query()->where('event', 'document_retracted')->exists())->toBeFalse();
})->with([
    'bản nháp nhóm B' => [['group' => DocumentGroup::Issued, 'status' => 'internal_draft', 'client_can_view' => false, 'client_can_download' => false, 'published_at' => null]],
    'nhóm B đã ký, chưa công bố' => [['group' => DocumentGroup::Issued, 'status' => 'signed_filed', 'client_can_view' => false, 'client_can_download' => false, 'published_at' => null]],
    'đã công bố nhưng cờ xem đã tắt' => [['status' => 'published', 'client_can_view' => false, 'client_can_download' => false]],
]);

it('không rút lần hai: quyết định đầu giữ nguyên', function () {
    $document = retractionReleasedDocument($this->matter);
    retractAs($document, $this->lawyer, RETRACTION_REASON_OK);

    $other = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($other, MatterRole::Associate);

    expect(fn () => retractAs($document, $other, 'Một lý do khác, đủ dài để qua ngưỡng'))
        ->toThrow(DocumentNotRetractable::class, __('retraction.exceptions.already_retracted'));

    expect($document->fresh()->retracted_by)->toBe($this->lawyer->id)
        ->and($document->fresh()->retraction_reason)->toBe(RETRACTION_REASON_OK)
        ->and(Activity::query()->where('event', 'document_retracted')->count())->toBe(1);
});

it('từ chối tài liệu đã xoá mềm và tài liệu của vụ việc đã xoá mềm', function () {
    $trashed = retractionReleasedDocument($this->matter);
    $trashed->delete();

    expect(fn () => retractAs($trashed, $this->lawyer, RETRACTION_REASON_OK))
        ->toThrow(DocumentNotRetractable::class, __('retraction.exceptions.trashed'));

    $document = retractionReleasedDocument($this->matter);
    $this->matter->delete();

    expect(fn () => retractAs($document, $this->lawyer, RETRACTION_REASON_OK))
        ->toThrow(DocumentNotRetractable::class, __('retraction.exceptions.matter_unavailable'));

    expect(Document::withTrashed()->find($document->id)->status)->toBe(DocumentStatus::Published);
});

it('trợ lý trong đội ngũ (không có document.publish) không rút được', function () {
    $document = retractionReleasedDocument($this->matter);
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    // Trợ lý ĐỌC được tài liệu này — cái chặn họ là quyền công bố, không phải tầm nhìn.
    expect(Gate::forUser($assistant)->allows('view', $document))->toBeTrue();

    expect(fn () => retractAs($document, $assistant, RETRACTION_REASON_OK))
        ->toThrow(AuthorizationException::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::Published);
});

it('đọc lại người thực hiện: tài khoản vừa bị vô hiệu hoá không rút được bằng đối tượng cũ trong tay', function () {
    $document = retractionReleasedDocument($this->matter);
    $stale = User::query()->find($this->lawyer->id);
    $this->lawyer->forceFill(['is_active' => false])->save();

    expect(fn () => retractAs($document, $stale, RETRACTION_REASON_OK))
        ->toThrow(AuthorizationException::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::Published);
});

it('đọc lại tài liệu dưới khoá: thuộc tính sửa trong bộ nhớ không quyết định gì', function () {
    $document = retractionReleasedDocument($this->matter, [
        'status' => 'published', 'client_can_view' => false, 'client_can_download' => false,
    ]);

    // Caller nói dối rằng tài liệu đang ra tới khách.
    $document->client_can_view = true;

    expect(fn () => retractAs($document, $this->lawyer, RETRACTION_REASON_OK))
        ->toThrow(DocumentNotRetractable::class);
});

it('tài liệu đã rút nằm ngoài tầm truy vấn của khách', function () {
    $document = retractionReleasedDocument($this->matter);

    $visible = fn (): bool => ClientPortalScope::actingAs(
        $this->clientUser,
        fn (): bool => Document::query()->whereKey($document->id)->exists(),
    );

    expect($visible())->toBeTrue();

    retractAs($document, $this->lawyer, RETRACTION_REASON_OK);

    expect($visible())->toBeFalse()
        ->and(Gate::forUser($this->clientUser)->allows('view', $document->fresh()))->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Một đường rút duy nhất.
// ---------------------------------------------------------------------------------------------

it('tài liệu đã rút không công bố lại được, kể cả với luật sư phụ trách', function () {
    $document = retractionReleasedDocument($this->matter);
    retractAs($document, $this->lawyer, RETRACTION_REASON_OK);

    expect(fn () => app(PublishDocument::class)->handle(
        document: $document,
        actor: $this->lawyer,
        clientCanView: true,
        clientCanDownload: true,
        expectedClientCanView: false,
        expectedClientCanDownload: false,
        expectedIsReleased: false,
    ))->toThrow(DocumentNotPublishable::class, __('retraction.blocked.republish'));

    expect($document->fresh()->status)->toBe(DocumentStatus::Retracted)
        ->and($document->fresh()->client_can_view)->toBeFalse();
});

it('chuyển một tài liệu ĐANG ra tới khách vào nhóm D bị từ chối, chỉ tới nút Rút lại', function () {
    $document = retractionReleasedDocument($this->matter);

    expect(fn () => app(RegroupDocument::class)->handle(document: $document, actor: $this->lawyer, group: DocumentGroup::Internal))
        ->toThrow(DocumentGroupNotChangeable::class, __('retraction.blocked.regroup_to_internal'));

    expect($document->fresh()->group)->toBe(DocumentGroup::Authority)
        ->and($document->fresh()->isReleasedToPortal())->toBeTrue();
});

it('tài liệu CHƯA từng ra tới khách vẫn vào nhóm D tự do, kể cả với trợ lý', function () {
    $document = retractionReleasedDocument($this->matter, [
        'status' => 'published', 'client_can_view' => false, 'client_can_download' => false,
    ]);
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    app(RegroupDocument::class)->handle(document: $document, actor: $assistant, group: DocumentGroup::Internal);

    expect($document->fresh()->group)->toBe(DocumentGroup::Internal);
});

it('quyền xoá: không với tài liệu đang ra tới khách hay đã rút, có với bản nháp', function () {
    $released = retractionReleasedDocument($this->matter);
    $toRetract = retractionReleasedDocument($this->matter);
    retractAs($toRetract, $this->lawyer, RETRACTION_REASON_OK);
    $draft = retractionReleasedDocument($this->matter, [
        'status' => 'internal_draft', 'client_can_view' => false, 'client_can_download' => false,
    ]);

    expect(Gate::forUser($this->lawyer)->allows('delete', $released->fresh()))->toBeFalse()
        ->and(Gate::forUser($this->lawyer)->allows('delete', $toRetract->fresh()))->toBeFalse()
        ->and(Gate::forUser($this->lawyer)->allows('delete', $draft->fresh()))->toBeTrue();
});

it('tài liệu không còn tồn tại khi Action đọc lại dưới khoá: câu "không còn tồn tại"', function () {
    $document = retractionReleasedDocument($this->matter);
    // Xoá cứng được vì tài liệu chưa có lượt tải nào (khoá ngoại restrict chỉ giữ bằng chứng tải).
    $document->getMedia('file')->each->delete();
    DB::table('documents')->where('id', $document->id)->delete();

    expect(fn () => retractAs($document, $this->lawyer, RETRACTION_REASON_OK))
        ->toThrow(DocumentNotRetractable::class, __('retraction.exceptions.missing'));
});

/**
 * Cùng lý lẽ với quyền xoá ở trên: dòng "Văn phòng đã rút lại tài liệu này" trên cổng đọc từ CHÍNH
 * bản ghi đã rút, và đường đọc hẹp đó không bao giờ trả nhóm D. Đưa tài liệu đã rút vào D là xoá
 * lời giải thích khỏi tay khách — đúng khoảng trống không lời mà Task 7 tồn tại để tránh. Đổi giữa
 * A/B/C thì dòng rút vẫn còn, nên không chặn.
 */
it('tài liệu đã rút không vào nhóm D được — dòng giải thích của khách sẽ biến mất', function () {
    $document = retractionReleasedDocument($this->matter);
    retractAs($document, $this->lawyer, RETRACTION_REASON_OK);

    expect(fn () => app(RegroupDocument::class)->handle(document: $document->fresh(), actor: $this->lawyer, group: DocumentGroup::Internal))
        ->toThrow(DocumentGroupNotChangeable::class, __('retraction.blocked.regroup_retracted_to_internal'));

    expect($document->fresh()->group)->toBe(DocumentGroup::Authority)
        ->and($document->fresh()->status)->toBe(DocumentStatus::Retracted);
});

/**
 * Gộp M7 vào `main` — chỗ gắn cho M9 ở bước 6 (docblock `RetractDocument`; báo cáo gộp M9, xung đột
 * 5, "việc mang sang cho M7"): tài liệu đang là biên lai của một khoản thu
 * (`payments.receipt_document_id`) hay bản scan phụ lục hợp đồng (`contract_amendments.document_id`)
 * không rút được — hỏi CÙNG định nghĩa `Document::isReferencedByBillingRecord()` mà
 * `DocumentPolicy::delete` và hook `Document::deleting` hỏi. Đường tới được trên sản phẩm: bản scan
 * nhóm D được gắn vào khoản thu/phụ lục, sau đó chuyển ra nhóm C (`RegroupDocument`) và công bố cho
 * khách. Từ chối bằng câu trạng thái (`DocumentNotRetractable`), không đổi gì, không ghi audit. Cặp
 * dương là mọi test rút ở trên (tài liệu không bản ghi tiền nào trỏ tới).
 */
it('không rút tài liệu đang là biên lai của một khoản thu hay bản scan phụ lục hợp đồng', function (string $kind) {
    $document = retractionReleasedDocument($this->matter);
    $contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);

    if ($kind === 'payment') {
        $instalment = Instalment::factory()->for($contract)->create([
            'amount' => 10_000_000,
            'status' => InstalmentStatus::Pending,
        ]);
        Payment::factory()->for($instalment)->create([
            'amount' => 4_000_000,
            'receipt_document_id' => $document->id,
            'attributed_lawyer_id' => $this->lawyer->id,
        ]);
    } else {
        ContractAmendment::factory()->for($contract)->create(['document_id' => $document->id]);
    }

    expect($document->fresh()->isReferencedByBillingRecord())->toBeTrue();

    expect(fn () => retractAs($document, $this->lawyer, RETRACTION_REASON_OK))
        ->toThrow(DocumentNotRetractable::class, __('retraction.exceptions.billing_reference'));

    $fresh = $document->fresh();

    expect($fresh->status)->toBe(DocumentStatus::Published)
        ->and($fresh->client_can_view)->toBeTrue()
        ->and($fresh->retracted_at)->toBeNull()
        ->and($fresh->retraction_reason)->toBeNull()
        ->and(Activity::query()->where('event', 'document_retracted')->exists())->toBeFalse();
})->with([
    'biên lai của khoản thu' => ['payment'],
    'bản scan phụ lục hợp đồng' => ['amendment'],
]);
