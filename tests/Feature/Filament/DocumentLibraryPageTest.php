<?php

use App\Enums\ContractStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\DocumentLibrary;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Mcp\AdminUrls;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Làn tl — trang "Tài liệu" chung cho nhân sự (mọi tài liệu người xem được phép thấy, xuyên các vụ)
|--------------------------------------------------------------------------
|
| Yêu cầu của chủ văn phòng 2026-10-15: tài liệu phải dễ tìm. Trang này là danh sách đọc — tải lên,
| sửa, công bố vẫn ở tab "Tài liệu" của từng vụ. Trọng tâm là QUYỀN, ở mức truy vấn: một tài liệu
| không được thấy không có mặt trong dòng, số đếm, kết quả tìm, danh sách chọn vụ — và trong HTML.
| Mọi hành vi đo qua Livewire/HTTP.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
});

/** Tài liệu có tệp thật trên đĩa giả (nút "Tải về" chỉ hiện khi có tệp). */
function tlDocument(Matter $matter, DocumentGroup $group, string $title, array $attributes = []): Document
{
    $document = Document::factory()->for($matter)->group($group)->create(['title' => $title, ...$attributes]);

    $document->addMedia(UploadedFile::fake()->createWithContent('nguon.pdf', str_repeat('%PDF-1.4 x', 150)))
        ->usingFileName(Str::lower((string) Str::ulid()).'.pdf')
        ->toMediaCollection('file');

    return $document->refresh();
}

/**
 * Sáu vai, sáu vụ, chín tài liệu:
 *  - `team` (thường): luật sư phụ trách, trợ lý trong đội — một tài liệu nhóm B, một nhóm D;
 *  - `other` (thường): không ai trong sáu vai có tên — một nhóm A, một nhóm D;
 *  - `restrictedOwn` (hạn chế): luật sư phụ trách — một nhóm B;
 *  - `restrictedOther` (hạn chế): trưởng phòng và trợ lý có tên trong đội nhưng không phụ trách;
 *  - `deletedMatter` (đã xoá mềm), `destroyedMatter` (đã ghi quyết định tiêu huỷ);
 *  - một tài liệu đã xoá mềm của `team`.
 */
function tlWorld(): object
{
    $w = new stdClass;

    foreach (['admin' => Role::Admin, 'manager' => Role::Manager, 'lawyer' => Role::Lawyer, 'assistant' => Role::Assistant, 'accountant' => Role::Accountant, 'sales' => Role::Sales] as $key => $role) {
        $w->{$key} = User::factory()->withRole($role)->create(['name' => 'Người '.$key]);
    }

    $w->client = Client::factory()->create(['name' => 'Khách TLKHACHTEAM']);
    $w->type = MatterType::factory()->withStages()->create(['name' => 'Loại TLLOAI']);
    $w->team = Matter::factory()->create([
        'client_id' => $w->client->id, 'matter_type_id' => $w->type->id,
        'lead_lawyer_id' => $w->lawyer->id, 'code' => 'TL-TEAM-0001', 'title' => 'Vụ TLVUTEAM',
    ]);
    $w->team->addTeamMember($w->assistant, MatterRole::Assistant);

    $w->other = Matter::factory()->create(['code' => 'TL-OTHER-0001', 'client_id' => Client::factory()->create(['name' => 'Khách TLKHACHOTHER'])->id]);
    $w->restrictedOwn = Matter::factory()->restricted()->create(['lead_lawyer_id' => $w->lawyer->id, 'code' => 'TL-RSOWN-0001']);
    $w->restrictedOther = Matter::factory()->restricted()->create([
        'code' => 'TL-RSOTH-0001', 'title' => 'Vụ TLVURSOTH',
        'client_id' => Client::factory()->create(['name' => 'Khách TLKHACHRSOTH'])->id,
    ]);
    $w->restrictedOther->addTeamMember($w->manager, MatterRole::Associate);
    $w->restrictedOther->addTeamMember($w->assistant, MatterRole::Assistant);
    $w->deletedMatter = Matter::factory()->create(['code' => 'TL-DELETED-0001']);
    $w->destroyedMatter = Matter::factory()->create(['code' => 'TL-DESTROY-0001', 'closed_at' => now()->subYears(11)]);

    $w->teamB = tlDocument($w->team, DocumentGroup::Issued, 'TLDOC-TEAM-B');
    $w->teamD = tlDocument($w->team, DocumentGroup::Internal, 'TLDOC-TEAM-D');
    $w->otherA = tlDocument($w->other, DocumentGroup::ClientProvided, 'TLDOC-OTHER-A');
    $w->otherD = tlDocument($w->other, DocumentGroup::Internal, 'TLDOC-OTHER-D');
    $w->rsOwn = tlDocument($w->restrictedOwn, DocumentGroup::Issued, 'TLDOC-RSOWN-B');
    $w->rsOther = tlDocument($w->restrictedOther, DocumentGroup::Issued, 'TLDOC-RSOTH-B');
    $w->onDeleted = tlDocument($w->deletedMatter, DocumentGroup::Issued, 'TLDOC-ONDELETED');
    $w->onDestroyed = tlDocument($w->destroyedMatter, DocumentGroup::Issued, 'TLDOC-ONDESTROYED');
    $w->softDeleted = tlDocument($w->team, DocumentGroup::Issued, 'TLDOC-SOFTDELETED');

    $w->softDeleted->delete();
    $w->deletedMatter->delete();
    MatterArchive::factory()->create(['matter_id' => $w->destroyedMatter->id, 'destroyed_at' => now()->subDay()]);

    return $w;
}

/** @return list<string> */
function tlAllTitles(object $w): array
{
    return ['TLDOC-TEAM-B', 'TLDOC-TEAM-D', 'TLDOC-OTHER-A', 'TLDOC-OTHER-D', 'TLDOC-RSOWN-B', 'TLDOC-RSOTH-B', 'TLDOC-ONDELETED', 'TLDOC-ONDESTROYED', 'TLDOC-SOFTDELETED'];
}

/** Snapshot Livewire của trang trong HTML đã vẽ — để gửi một request cập nhật THẬT. */
function tlPageSnapshot(string $html): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === DocumentLibrary::class) {
            return $snapshot;
        }
    }

    throw new RuntimeException('Không có snapshot Livewire của trang Tài liệu trong HTML.');
}

function tlPostUpdate(string $snapshot, array $updates = []): TestResponse
{
    return test()->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => []]],
    ]);
}

// ---------------------------------------------------------------------------------------------
// Cổng: người có `matter.view`; kế toán và kinh doanh 404, kể cả request cập nhật Livewire
// ---------------------------------------------------------------------------------------------

it('mở được cho admin, trưởng phòng, luật sư, trợ lý và hiện trên thanh điều hướng của họ', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user, 'web')
        ->get(DocumentLibrary::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('document_library.title'));

    expect(DocumentLibrary::shouldRegisterNavigation())->toBeTrue();
})->with([Role::Admin, Role::Manager, Role::Lawyer, Role::Assistant]);

it('trả 404 cho kế toán và nhân viên kinh doanh, không hiện trên thanh điều hướng của họ', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user, 'web')
        ->get(DocumentLibrary::getUrl(panel: 'admin'))
        ->assertNotFound();

    expect(DocumentLibrary::shouldRegisterNavigation())->toBeFalse();
})->with([Role::Accountant, Role::Sales]);

it('trả 404 cho một request cập nhật Livewire từ người vừa mất matter.view, không trả dòng nào', function () {
    $w = tlWorld();

    $html = $this->actingAs($w->lawyer, 'web')->get(DocumentLibrary::getUrl(panel: 'admin'))->assertOk()->getContent();
    expect($html)->toContain('TLDOC-TEAM-B');

    $w->lawyer->syncRoles([Role::Accountant->value]);
    $this->actingAs($w->lawyer->fresh(), 'web');

    $response = tlPostUpdate(tlPageSnapshot($html), ['tableSearch' => 'TLDOC'])->assertNotFound();

    expect($response->getContent())->not->toContain('TLDOC-TEAM-B');
});

// ---------------------------------------------------------------------------------------------
// Ma trận sáu vai: tập dòng ở MỨC TRUY VẤN
// ---------------------------------------------------------------------------------------------

it('mỗi vai thấy đúng tập tài liệu của mình — vụ hạn chế, nhóm D, đã xoá, đã tiêu huỷ', function (string $viewer, array $visible) {
    $w = tlWorld();

    $this->actingAs($w->{$viewer}, 'web');

    $html = $this->get(DocumentLibrary::getUrl(panel: 'admin'))->assertOk()->getContent();

    foreach (tlAllTitles($w) as $title) {
        in_array($title, $visible, true)
            ? expect($html)->toContain($title)
            : expect($html)->not->toContain($title);
    }

    $byTitle = Document::withTrashed()->get()->keyBy('title');

    Livewire::test(DocumentLibrary::class)
        ->assertCanSeeTableRecords(collect($visible)->map(fn (string $title) => $byTitle[$title]))
        ->assertCanNotSeeTableRecords(collect(tlAllTitles($w))->diff($visible)->map(fn (string $title) => $byTitle[$title]))
        ->assertCountTableRecords(count($visible));
})->with([
    'admin: mọi vụ kể cả hạn chế, cả nhóm D' => ['admin', ['TLDOC-TEAM-B', 'TLDOC-TEAM-D', 'TLDOC-OTHER-A', 'TLDOC-OTHER-D', 'TLDOC-RSOWN-B', 'TLDOC-RSOTH-B']],
    'trưởng phòng: mọi vụ thường, cả nhóm D, không vụ hạn chế dù có tên trong đội' => ['manager', ['TLDOC-TEAM-B', 'TLDOC-TEAM-D', 'TLDOC-OTHER-A', 'TLDOC-OTHER-D']],
    'luật sư: vụ mình trong đội và vụ hạn chế mình phụ trách, cả nhóm D' => ['lawyer', ['TLDOC-TEAM-B', 'TLDOC-TEAM-D', 'TLDOC-RSOWN-B']],
    'trợ lý: vụ mình trong đội, không nhóm D, không vụ hạn chế dù có tên trong đội' => ['assistant', ['TLDOC-TEAM-B']],
]);

it('tìm theo tên tài liệu không bao giờ ra tài liệu của vụ hạn chế, kể cả khi gõ đúng tên', function () {
    $w = tlWorld();
    $this->actingAs($w->manager, 'web');

    $page = Livewire::test(DocumentLibrary::class)
        ->searchTable('TLDOC-RSOTH')
        ->assertCountTableRecords(0);

    expect($page->html())->not->toContain('TLDOC-RSOTH-B')
        ->and($page->html())->not->toContain('TL-RSOTH-0001')
        ->and($page->html())->not->toContain('TLKHACHRSOTH');

    Livewire::test(DocumentLibrary::class)
        ->searchTable('TLKHACHRSOTH')
        ->assertCountTableRecords(0);

    Livewire::test(DocumentLibrary::class)
        ->searchTable('TL-RSOTH')
        ->assertCountTableRecords(0);
});

it('tìm theo tên tài liệu, mã hồ sơ và tên khách trong phạm vi được xem', function () {
    $w = tlWorld();
    $this->actingAs($w->lawyer, 'web');

    Livewire::test(DocumentLibrary::class)
        ->searchTable('TLDOC-TEAM-B')
        ->assertCanSeeTableRecords([$w->teamB])
        ->assertCountTableRecords(1);

    Livewire::test(DocumentLibrary::class)
        ->searchTable('TL-TEAM-0001')
        ->assertCanSeeTableRecords([$w->teamB, $w->teamD])
        ->assertCountTableRecords(2);

    Livewire::test(DocumentLibrary::class)
        ->searchTable('TLKHACHTEAM')
        ->assertCanSeeTableRecords([$w->teamB, $w->teamD])
        ->assertCountTableRecords(2);
});

it('danh sách chọn vụ của bộ lọc chỉ gồm vụ người xem được thấy, không mã của vụ hạn chế', function () {
    $w = tlWorld();
    $this->actingAs($w->manager, 'web');

    Livewire::test(DocumentLibrary::class)
        ->assertTableFilterExists('matter', fn (SelectFilter $filter): bool => array_key_exists($w->team->id, $filter->getOptions())
            && array_key_exists($w->other->id, $filter->getOptions())
            && ! array_key_exists($w->restrictedOther->id, $filter->getOptions())
            && ! array_key_exists($w->restrictedOwn->id, $filter->getOptions())
            && ! in_array('TL-RSOTH-0001', $filter->getOptions(), true));
});

it('đặt thẳng id của vụ hạn chế vào bộ lọc vụ: không dòng nào, không mã, không tên tài liệu', function () {
    $w = tlWorld();
    $this->actingAs($w->manager, 'web');

    $page = Livewire::test(DocumentLibrary::class)
        ->set('tableFilters.matter.value', $w->restrictedOther->id)
        ->assertCountTableRecords(0);

    expect($page->html())->not->toContain('TL-RSOTH-0001')
        ->and($page->html())->not->toContain('TLDOC-RSOTH-B');
});

it('bộ lọc nhóm không có nhóm D với người không đọc được nhóm D', function () {
    $w = tlWorld();

    $this->actingAs($w->assistant, 'web');
    Livewire::test(DocumentLibrary::class)
        ->assertTableFilterExists('group', fn (SelectFilter $filter): bool => ! array_key_exists(DocumentGroup::Internal->value, $filter->getOptions())
            && array_key_exists(DocumentGroup::Issued->value, $filter->getOptions()));

    $this->actingAs($w->lawyer, 'web');
    Livewire::test(DocumentLibrary::class)
        ->assertTableFilterExists('group', fn (SelectFilter $filter): bool => array_key_exists(DocumentGroup::Internal->value, $filter->getOptions()));
});

it('trợ lý lọc nhóm D bằng tay vẫn không ra dòng nào', function () {
    $w = tlWorld();
    $this->actingAs($w->assistant, 'web');

    $page = Livewire::test(DocumentLibrary::class)
        ->set('tableFilters.group.value', DocumentGroup::Internal->value)
        ->assertCountTableRecords(0);

    expect($page->html())->not->toContain('TLDOC-TEAM-D');
});

// ---------------------------------------------------------------------------------------------
// Bộ lọc, sắp xếp, cột
// ---------------------------------------------------------------------------------------------

it('lọc theo vụ, loại vụ, nhóm, trạng thái và người tải lên', function () {
    $w = tlWorld();
    $w->otherA->forceFill(['status' => DocumentStatus::Published])->save();
    $uploaded = tlDocument($w->team, DocumentGroup::Authority, 'TLDOC-TEAM-C', [
        'uploader_type' => $w->assistant->getMorphClass(), 'uploader_id' => $w->assistant->id,
    ]);

    $this->actingAs($w->admin, 'web');

    Livewire::test(DocumentLibrary::class)
        ->filterTable('matter', $w->team->id)
        ->assertCanSeeTableRecords([$w->teamB, $w->teamD, $uploaded])
        ->assertCountTableRecords(3);

    Livewire::test(DocumentLibrary::class)
        ->filterTable('matter_type', $w->type->id)
        ->assertCanSeeTableRecords([$w->teamB, $w->teamD, $uploaded])
        ->assertCountTableRecords(3);

    Livewire::test(DocumentLibrary::class)
        ->filterTable('group', DocumentGroup::Internal->value)
        ->assertCanSeeTableRecords([$w->teamD, $w->otherD])
        ->assertCountTableRecords(2);

    Livewire::test(DocumentLibrary::class)
        ->filterTable('status', DocumentStatus::Published->value)
        ->assertCanSeeTableRecords([$w->otherA])
        ->assertCountTableRecords(1);

    Livewire::test(DocumentLibrary::class)
        ->filterTable('uploader', 'user:'.$w->assistant->id)
        ->assertCanSeeTableRecords([$uploaded])
        ->assertCountTableRecords(1);
});

it('lọc "khách hàng nộp qua cổng" chỉ ra tài liệu do tài khoản khách tải lên', function () {
    $w = tlWorld();
    $clientUser = ClientUser::factory()->for($w->client)->create(['name' => 'Tài khoản TLKHACHNOP']);
    $fromClient = tlDocument($w->team, DocumentGroup::ClientProvided, 'TLDOC-FROM-CLIENT', [
        'uploader_type' => $clientUser->getMorphClass(), 'uploader_id' => $clientUser->id,
    ]);

    $this->actingAs($w->lawyer, 'web');

    Livewire::test(DocumentLibrary::class)
        ->filterTable('uploader', 'client')
        ->assertCanSeeTableRecords([$fromClient])
        ->assertCountTableRecords(1)
        ->assertSee('Tài khoản TLKHACHNOP');
});

it('lọc "vụ tôi phụ trách" và "vụ tôi trong đội"', function () {
    $w = tlWorld();

    $this->actingAs($w->lawyer, 'web');
    Livewire::test(DocumentLibrary::class)
        ->filterTable('involvement', 'lead')
        ->assertCanSeeTableRecords([$w->teamB, $w->teamD, $w->rsOwn])
        ->assertCountTableRecords(3);

    // Admin thấy mọi vụ; "trong đội" thu về đúng vụ có tên admin trong `matter_user`.
    $w->other->addTeamMember($w->admin, MatterRole::Observer);
    $this->actingAs($w->admin, 'web');
    Livewire::test(DocumentLibrary::class)
        ->filterTable('involvement', 'team')
        ->assertCanSeeTableRecords([$w->otherA, $w->otherD])
        ->assertCountTableRecords(2);

    Livewire::test(DocumentLibrary::class)
        ->filterTable('involvement', 'lead')
        ->assertCountTableRecords(0);
});

it('lọc theo khoảng ngày tải lên (ngày theo lịch, cả hai đầu)', function () {
    $w = tlWorld();
    $w->teamB->forceFill(['created_at' => CarbonImmutable::parse('2026-09-01 08:00')])->save();
    $w->teamD->forceFill(['created_at' => CarbonImmutable::parse('2026-09-15 23:30')])->save();

    $this->actingAs($w->lawyer, 'web');

    Livewire::test(DocumentLibrary::class)
        ->filterTable('uploaded_between', ['from' => '2026-09-01', 'until' => '2026-09-15'])
        ->assertCanSeeTableRecords([$w->teamB, $w->teamD])
        ->assertCanNotSeeTableRecords([$w->rsOwn])
        ->assertCountTableRecords(2);

    Livewire::test(DocumentLibrary::class)
        ->filterTable('uploaded_between', ['from' => '2026-09-02'])
        ->assertCanNotSeeTableRecords([$w->teamB])
        ->assertCanSeeTableRecords([$w->teamD]);
});

it('mặc định sắp mới nhất trước', function () {
    $w = tlWorld();
    $w->teamB->forceFill(['created_at' => now()->subDays(3)])->save();
    $w->teamD->forceFill(['created_at' => now()->subDay()])->save();
    $w->rsOwn->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->actingAs($w->lawyer, 'web');

    Livewire::test(DocumentLibrary::class)
        ->assertCanSeeTableRecords([$w->teamD, $w->rsOwn, $w->teamB], inOrder: true);
});

it('hiện mã hồ sơ, khách, nhóm, trạng thái, phiên bản, người tải lên, ngày tải lên và dung lượng', function () {
    $w = tlWorld();
    $w->teamB->forceFill([
        'created_at' => CarbonImmutable::parse('2026-10-01 09:05', config('app.timezone')),
        'version' => 3,
        'uploader_type' => $w->assistant->getMorphClass(), 'uploader_id' => $w->assistant->id,
    ])->save();

    $this->actingAs($w->lawyer, 'web');

    Livewire::test(DocumentLibrary::class)
        ->searchTable('TLDOC-TEAM-B')
        ->assertTableColumnStateSet('matter.code', 'TL-TEAM-0001', $w->teamB)
        ->assertTableColumnStateSet('matter.client.name', 'Khách TLKHACHTEAM', $w->teamB)
        ->assertTableColumnFormattedStateSet('group', DocumentGroup::Issued->label(), $w->teamB)
        ->assertTableColumnFormattedStateSet('status', DocumentStatus::InternalDraft->label(), $w->teamB)
        ->assertTableColumnStateSet('version', 3, $w->teamB)
        ->assertTableColumnStateSet('uploader_name', 'Người assistant', $w->teamB)
        ->assertTableColumnFormattedStateSet('created_at', '09:05 01/10/2026', $w->teamB)
        ->assertTableColumnStateSet('size', '1,5 KB', $w->teamB);
});

it('người tải lên đã nghỉ (xoá mềm) vẫn hiện tên', function () {
    $w = tlWorld();
    $departed = User::factory()->withRole(Role::Assistant)->create(['name' => 'Người TLDANGHI']);
    $w->teamB->forceFill(['uploader_type' => $departed->getMorphClass(), 'uploader_id' => $departed->id])->save();
    $departed->delete();

    $this->actingAs($w->lawyer, 'web');

    Livewire::test(DocumentLibrary::class)
        ->assertTableColumnStateSet('uploader_name', 'Người TLDANGHI', $w->teamB);
});

it('khách hàng đã xoá mềm vẫn hiện tên ở cột khách', function () {
    $w = tlWorld();
    $w->client->delete();

    $this->actingAs($w->lawyer, 'web');

    Livewire::test(DocumentLibrary::class)
        ->assertTableColumnStateSet('matter.client.name', 'Khách TLKHACHTEAM', $w->teamB);
});

// ---------------------------------------------------------------------------------------------
// Hành động: "Mở vụ" (tab Tài liệu) và "Tải về" (route ký + nhật ký tải hiện có)
// ---------------------------------------------------------------------------------------------

it('"Mở vụ" và mã hồ sơ dẫn tới tab Tài liệu của vụ', function () {
    $w = tlWorld();
    $this->actingAs($w->lawyer, 'web');

    $page = Livewire::test(DocumentLibrary::class)
        ->assertActionHasUrl(TestAction::make('open_matter')->table($w->teamB), AdminUrls::document($w->teamB));

    $codeColumn = $page->instance()->getTable()->getColumn('matter.code')->record($w->teamB);

    expect($codeColumn->getUrl())->toBe(AdminUrls::document($w->teamB))
        ->and(AdminUrls::document($w->teamB))->toContain('relation=');
});

it('"Tải về" chuyển tới đúng route có chữ ký cho người bấm, và lượt tải ghi vào nhật ký tải', function () {
    $w = tlWorld();
    $this->actingAs($w->assistant, 'web');

    $page = Livewire::test(DocumentLibrary::class)
        ->callAction(TestAction::make('download')->table($w->teamB))
        ->assertRedirectContains('/admin/documents/'.$w->teamB->id.'/download');

    $url = $page->effects['redirect'];

    expect($url)->toContain('signature=')
        ->and($url)->toContain('recipient='.urlencode(Document::recipientToken($w->assistant)));

    $this->get($url)->assertOk();

    expect(DocumentDownload::query()->where('document_id', $w->teamB->id)->sole()->downloader_id)->toBe($w->assistant->id);
});

it('"Tải về" hỏi lại quyền tải lúc bấm: gói bàn giao mang tiền thì trợ lý không nhận đường dẫn', function () {
    $w = tlWorld();
    Contract::factory()->for($w->team)->create(['status' => ContractStatus::Active, 'signed_at' => now()->subMonth()->toDateString()]);
    MatterArchive::factory()->create(['matter_id' => $w->team->id, 'handover_document_id' => $w->teamB->id]);

    $this->actingAs($w->assistant, 'web');

    Livewire::test(DocumentLibrary::class)
        ->callAction(TestAction::make('download')->table($w->teamB))
        ->assertNoRedirect();

    Notification::assertNotified(__('document_library.download_denied'));

    // Luật sư phụ trách (thấy tiền của vụ) vẫn tải được.
    $this->actingAs($w->lawyer, 'web');

    Livewire::test(DocumentLibrary::class)
        ->callAction(TestAction::make('download')->table($w->teamB))
        ->assertRedirectContains('/admin/documents/'.$w->teamB->id.'/download');
});

it('không có nút "Tải về" trên tài liệu không có tệp', function () {
    $w = tlWorld();
    $withoutFile = Document::factory()->for($w->team)->group(DocumentGroup::Issued)->create(['title' => 'TLDOC-NOFILE']);

    $this->actingAs($w->lawyer, 'web');

    Livewire::test(DocumentLibrary::class)
        ->assertActionHidden(TestAction::make('download')->table($withoutFile))
        ->assertActionVisible(TestAction::make('download')->table($w->teamB));
});

it('trang không có đường tải lên, sửa hay công bố riêng', function () {
    $w = tlWorld();
    $this->actingAs($w->admin, 'web');

    Livewire::test(DocumentLibrary::class)
        ->assertActionDoesNotExist(TestAction::make('upload')->table())
        ->assertActionDoesNotExist(TestAction::make('publish')->table($w->teamB))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($w->teamB));
});

// ---------------------------------------------------------------------------------------------
// Hiệu năng: không N+1
// ---------------------------------------------------------------------------------------------

it('số truy vấn không đổi khi trang có N dòng hay 3N dòng, kể cả một dòng của vụ hạn chế', function (string $viewer) {
    $w = tlWorld();
    $user = $w->{$viewer};
    $this->actingAs($user, 'web');

    $makeRow = function () use ($w, $user): void {
        $matter = Matter::factory()->create(['lead_lawyer_id' => $user->id]);
        $matter->addTeamMember($w->assistant, MatterRole::Assistant);
        $clientUser = ClientUser::factory()->for($matter->client)->create();
        tlDocument($matter, DocumentGroup::ClientProvided, 'TLDOC-N-'.Str::random(6), [
            'uploader_type' => $clientUser->getMorphClass(), 'uploader_id' => $clientUser->id,
        ]);
    };

    $makeRow();

    Livewire::test(DocumentLibrary::class);

    DB::enableQueryLog();
    DB::flushQueryLog();
    Livewire::test(DocumentLibrary::class);
    $queriesForN = count(DB::getQueryLog());

    foreach (range(1, 6) as $i) {
        $makeRow();
    }

    DB::flushQueryLog();
    Livewire::test(DocumentLibrary::class);
    $queriesFor3N = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queriesFor3N)->toBe($queriesForN);
})->with(['admin', 'lawyer']);
