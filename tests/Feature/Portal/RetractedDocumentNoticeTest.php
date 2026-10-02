<?php

use App\Actions\Document\RetractDocument;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Filament\Portal\Pages\MatterProgress;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * M7 Task 7 — dòng "Văn phòng đã rút lại tài liệu này. Lý do: …" trên cổng khách, và cách ly của
 * nó theo nghi thức ba tầng:
 *
 *  1. **Truy vấn** — `Document::retractionNoticesFor($matter, $viewer)`, đo ở đây KHÔNG qua policy;
 *  2. **Quyền** — `DocumentPolicy::viewRetractionNotice`, đo ở đây KHÔNG qua truy vấn (bản ghi
 *     dựng thẳng, policy đọc thuộc tính);
 *  3. **Serialize** — hình chiếu hẹp `MatterProgress::retractionNotices()` (tiêu đề, lý do, ngày;
 *     không id, không đường tải), đo qua HTML thật của trang.
 *
 * Mỗi khẳng định âm đi kèm vế dương trong chính test đó: tài liệu đã rút của CHÍNH khách trên vụ
 * CHÍNH khách đang xem thì hiện.
 */
const RDN_REASON = 'Văn bản này thuộc hồ sơ của khách khác, công bố nhầm';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('portal');
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
    $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);

    $this->otherClient = Client::factory()->create();
    $this->otherUser = ClientUser::factory()->activated()->create(['client_id' => $this->otherClient->id]);
    $this->otherMatter = Matter::factory()->for($this->otherClient)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);
});

function rdnDocument(Matter $matter, string $title): Document
{
    $document = Document::factory()->for($matter)->group(DocumentGroup::Issued)->create([
        'title' => $title,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
        'published_at' => now()->subDays(3),
    ]);

    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', '%PDF-1.4 noi dung that'))
        ->usingName('Van ban.pdf')
        ->usingFileName(Str::lower((string) Str::ulid()).'.pdf')
        ->toMediaCollection('file');

    return $document->refresh();
}

function rdnRetract(Document $document, User $lawyer): Document
{
    return app(RetractDocument::class)->handle(document: $document, actor: $lawyer, reason: RDN_REASON);
}

function rdnPage(Matter $matter)
{
    return test()->get(MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal'));
}

/** @return list<int> id các tài liệu tầng truy vấn trả về cho khách này trên vụ này. */
function rdnQueryIds(Matter $matter, ClientUser $viewer): array
{
    return Document::retractionNoticesFor($matter, $viewer)->pluck('id')->map(fn ($id): int => (int) $id)->all();
}

// ---------------------------------------------------------------------------------------------
// Tầng 3 — trang thật, HTML thật.
// ---------------------------------------------------------------------------------------------

it('khách thấy một dòng rút lại ở chỗ tài liệu từng hiện: tiêu đề, lý do, ngày rút — không đường tải', function () {
    $document = rdnDocument($this->matter, 'Quyết định đình chỉ bản nhầm');
    $kept = rdnDocument($this->matter, 'Bản án sơ thẩm còn hiệu lực');

    $this->actingAs($this->clientUser, 'client');
    $signed = $document->downloadUrlFor($this->clientUser);
    $this->get($signed)->assertOk();

    rdnRetract($document, $this->lawyer);

    $html = rdnPage($this->matter)->assertOk()
        ->assertSee('Quyết định đình chỉ bản nhầm')
        ->assertSee(__('retraction.portal.notice', ['reason' => RDN_REASON]))
        ->assertSee(__('retraction.portal.retracted_on', ['date' => '20/10/2026']))
        // Vế dương: tài liệu còn hiệu lực vẫn ở đó, kèm đường tải của nó.
        ->assertSee('Bản án sơ thẩm còn hiệu lực')
        ->getContent();

    // Không một đường tải nào trỏ tới tài liệu đã rút; đường của tài liệu còn lại thì có.
    expect($html)->not->toContain('documents/'.$document->id.'/download')
        ->and($html)->toContain('documents/'.$kept->id.'/download');

    // URL ký TRƯỚC lúc rút, còn hạn chữ ký, nay trả 404.
    $this->get($signed)->assertNotFound();
});

it('khách khác không thấy dòng rút lại của vụ không phải của mình', function () {
    $document = rdnDocument($this->matter, 'Tài liệu rút của khách thứ nhất');
    rdnRetract($document, $this->lawyer);
    $theirs = rdnDocument($this->otherMatter, 'Tài liệu rút của khách thứ hai');
    rdnRetract($theirs, $this->lawyer);

    $this->actingAs($this->otherUser, 'client');

    rdnPage($this->matter)->assertNotFound();
    rdnPage($this->otherMatter)->assertOk()
        ->assertSee('Tài liệu rút của khách thứ hai')
        ->assertDontSee('Tài liệu rút của khách thứ nhất');

    expect(rdnQueryIds($this->matter, $this->otherUser))->toBe([])
        ->and(rdnQueryIds($this->otherMatter, $this->otherUser))->toBe([$theirs->id])
        ->and(Gate::forUser($this->otherUser)->allows('viewRetractionNotice', $document->fresh()))->toBeFalse()
        ->and(Gate::forUser($this->otherUser)->allows('viewRetractionNotice', $theirs->fresh()))->toBeTrue();
});

it('vụ chưa công bố lên portal: không trang, không dòng, ở cả tầng truy vấn lẫn tầng quyền', function () {
    $document = rdnDocument($this->matter, 'Tài liệu rút trên vụ bị ẩn');
    rdnRetract($document, $this->lawyer);

    $this->actingAs($this->clientUser, 'client');
    expect(rdnQueryIds($this->matter, $this->clientUser))->toBe([$document->id])
        ->and(Gate::forUser($this->clientUser)->allows('viewRetractionNotice', $document->fresh()))->toBeTrue();

    $this->matter->forceFill(['is_published_to_portal' => false])->save();

    rdnPage($this->matter)->assertNotFound();
    expect(rdnQueryIds($this->matter->fresh(), $this->clientUser))->toBe([])
        ->and(Gate::forUser($this->clientUser)->allows('viewRetractionNotice', $document->fresh()))->toBeFalse();
});

it('vụ đã hết hạn tra cứu (Task 5): không trang, không dòng, ở cả hai tầng', function () {
    $document = rdnDocument($this->matter, 'Tài liệu rút trên vụ đã kết thúc');
    rdnRetract($document, $this->lawyer);
    $this->matter->forceFill(['closed_at' => '2026-07-22'])->save();
    MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'client_access_until' => '2026-10-20',
    ]);

    $this->actingAs($this->clientUser, 'client');

    // Ngày cuối còn tra cứu: còn thấy.
    rdnPage($this->matter)->assertOk()->assertSee('Tài liệu rút trên vụ đã kết thúc');

    $this->travelTo(Carbon::parse('2026-10-21 00:00:00'));

    rdnPage($this->matter)->assertNotFound();
    expect(rdnQueryIds($this->matter->fresh(), $this->clientUser))->toBe([])
        ->and(Gate::forUser($this->clientUser)->allows('viewRetractionNotice', $document->fresh()))->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Tầng 1 và 2, từng điều kiện riêng — mỗi điều kiện có một bản ghi ghim nó ở MỖI tầng.
// ---------------------------------------------------------------------------------------------

it('không bao giờ nhóm D, không bao giờ bản đã xoá mềm, không bao giờ một trạng thái khác', function (array $attributes, ?Closure $after) {
    $document = rdnDocument($this->matter, 'Bản ghi bị loại');
    rdnRetract($document, $this->lawyer);
    $positive = rdnDocument($this->matter, 'Bản ghi vế dương');
    rdnRetract($positive, $this->lawyer);

    // Ghi thẳng bằng query builder: không đi qua hook `saving` hay Action nào — đúng hình dạng
    // "một lần ghi tay vào CSDL" mà hai tầng phải tự chống được.
    if ($attributes !== []) {
        DB::table('documents')->where('id', $document->id)->update($attributes);
    }

    if ($after !== null) {
        $after($document);
    }

    // Đọc bản ghi bị loại TRƯỚC khi mở guard khách: dưới guard đó `ClientPortalScope` giấu nó, `find()`
    // trả `null`, và `Gate::allows(…, null)` sai bất kể policy nói gì — khẳng định âm ở tầng quyền
    // sẽ không đo được gì (mutation probe P23–P25 đã sống sót đúng vì vậy).
    $excluded = Document::withTrashed()->find($document->id);
    expect($excluded)->not->toBeNull();

    $this->actingAs($this->clientUser, 'client');

    expect(rdnQueryIds($this->matter, $this->clientUser))->toBe([$positive->id])
        // `whereNull(deleted_at)` của tầng truy vấn là của riêng nó, không mượn `SoftDeletingScope`:
        // một màn hình gọi thêm `withTrashed()` vẫn không nhận bản đã xoá mềm.
        ->and(Document::retractionNoticesFor($this->matter, $this->clientUser)->withTrashed()->pluck('id')->map(fn ($id): int => (int) $id)->all())->toBe([$positive->id])
        ->and(Gate::forUser($this->clientUser)->allows('viewRetractionNotice', $excluded))->toBeFalse()
        ->and(Gate::forUser($this->clientUser)->allows('viewRetractionNotice', $positive->fresh()))->toBeTrue();

    rdnPage($this->matter)->assertOk()
        ->assertSee('Bản ghi vế dương')
        ->assertDontSee('Bản ghi bị loại');
})->with([
    'nhóm D' => [['group' => 'D'], null],
    'đã xoá mềm' => [[], fn (Document $document) => $document->delete()],
    'đã công bố (không phải đã rút)' => [['status' => 'published', 'client_can_view' => false], null],
]);

it('nhân sự không dùng ability của dòng rút (nó chỉ dành cho khách)', function () {
    $document = rdnDocument($this->matter, 'Tài liệu rút');
    rdnRetract($document, $this->lawyer);

    expect(Gate::forUser($this->lawyer)->allows('viewRetractionNotice', $document->fresh()))->toBeFalse()
        // Nhân sự vẫn xem và tải được tài liệu đã rút: tệp là bằng chứng.
        ->and(Gate::forUser($this->lawyer)->allows('download', $document->fresh()))->toBeTrue();
});

it('vụ chỉ còn tài liệu đã rút: không nói "chưa có tài liệu", vẫn nói khi vụ không có gì', function () {
    $this->actingAs($this->clientUser, 'client');

    rdnPage($this->matter)->assertOk()->assertSee(__('portal_progress.blocks.documents.empty'));

    $document = rdnDocument($this->matter, 'Tài liệu duy nhất, đã rút');
    rdnRetract($document, $this->lawyer);

    rdnPage($this->matter)->assertOk()
        ->assertSee('Tài liệu duy nhất, đã rút')
        ->assertDontSee(__('portal_progress.blocks.documents.empty'));
});

it('tầng truy vấn chỉ trả tài liệu của ĐÚNG vụ đang xem, kể cả khi cùng khách có vụ khác cũng có tài liệu đã rút', function () {
    $sibling = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);
    $here = rdnDocument($this->matter, 'Tài liệu rút của vụ này');
    rdnRetract($here, $this->lawyer);
    $there = rdnDocument($sibling, 'Tài liệu rút của vụ kia');
    rdnRetract($there, $this->lawyer);

    $this->actingAs($this->clientUser, 'client');

    expect(rdnQueryIds($this->matter, $this->clientUser))->toBe([$here->id])
        ->and(rdnQueryIds($sibling, $this->clientUser))->toBe([$there->id]);

    rdnPage($this->matter)->assertOk()
        ->assertSee('Tài liệu rút của vụ này')
        ->assertDontSee('Tài liệu rút của vụ kia');
});
