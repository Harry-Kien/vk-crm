<?php

use App\Actions\Search\MatterSearchResults;
use App\Actions\Search\SearchMatters;
use App\Enums\DocumentGroup;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\SearchSource;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * M7 Task 9 — phần của `SearchMatters` mà màn hình không đo được: bề mặt M11 dùng lại
 * (`search_matters` chỉ tìm bốn nguồn của vụ, tự phân trang trên `matching()`), trần `limit`,
 * actor đã bị vô hiệu hoá, chuẩn hoá chuỗi tìm, gộp và cắt dòng khớp. Hành vi của MÀN HÌNH (sáu
 * nguồn, ai thấy gì) ở `tests/Feature/Filament/SearchPageTest.php`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->search = app(SearchMatters::class);
});

function srchActionMatter(User $lead, array $attributes = []): Matter
{
    return Matter::factory()->create(['lead_lawyer_id' => $lead->id, ...$attributes])->fresh();
}

/** @return list<string> */
function srchActionCodes(MatterSearchResults $results): array
{
    return array_map(fn ($result) => $result->code, $results->matters);
}

it('chỉ tìm trong những nguồn được yêu cầu — đúng bốn nguồn của vụ cho search_matters của M11', function () {
    $byParty = srchActionMatter($this->lead);
    MatterParty::factory()->create(['matter_id' => $byParty->id, 'name' => 'Bùi Gecarrox']);
    $byTitle = srchActionMatter($this->lead, ['title' => 'Vụ Gecarrox']);

    $matterSources = [SearchSource::Code, SearchSource::Title, SearchSource::ClientName, SearchSource::CaseNumber];

    expect(srchActionCodes($this->search->handle($this->admin, 'Gecarrox', $matterSources)))->toBe([$byTitle->code])
        ->and(srchActionCodes($this->search->handle($this->admin, 'Gecarrox')))->toBe([$byTitle->code, $byParty->code]);
});

it('không nới được nguồn: kế toán xin tìm theo tên các bên vẫn không nhận gì', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = srchActionMatter($this->lead);
    MatterParty::factory()->create(['matter_id' => $matter->id, 'name' => 'Bùi Gecarrox']);

    expect(SearchMatters::sourcesFor($accountant))->toBe([SearchSource::Code, SearchSource::ClientName])
        ->and($this->search->handle($accountant, 'Gecarrox', [SearchSource::PartyName])->matters)->toBe([])
        ->and(SearchMatters::sourcesFor($this->admin))->toBe(SearchSource::cases());
});

it('không cho nguồn nào, và không trả gì, cho nhân sự không có quyền xem vụ việc', function () {
    $nobody = User::factory()->create();
    $matter = srchActionMatter($this->lead, ['title' => 'Vụ Bilbyon']);

    expect(SearchMatters::sourcesFor($nobody))->toBe([])
        ->and($this->search->handle($nobody, $matter->code)->matters)->toBe([]);
});

/**
 * `Normalizer::name()` bỏ mọi ký tự không có dạng ASCII, nên một chuỗi toàn biểu tượng không còn gì
 * để so với `name_normalized`: nguồn tên các bên khi đó không khớp gì — KHÔNG phải khớp mọi bên.
 */
it('một chuỗi chuẩn hoá ra rỗng ở tên các bên không khớp mọi bên', function () {
    $matter = srchActionMatter($this->lead);
    MatterParty::factory()->create(['matter_id' => $matter->id, 'name' => 'Lý Văn Numbatix']);

    expect($this->search->handle($this->admin, '🙂🙂')->matters)->toBe([]);
});

it('không trả quá MAX_LIMIT vụ dù nơi gọi xin nhiều hơn', function () {
    $first = srchActionMatter($this->lead, ['title' => 'Vụ Quollimo']);

    Matter::factory()->count(SearchMatters::MAX_LIMIT)->create([
        'client_id' => $first->client_id, 'matter_type_id' => $first->matter_type_id,
        'lead_lawyer_id' => $this->lead->id, 'title' => 'Vụ Quollimo',
    ]);

    $results = $this->search->handle($this->admin, 'Quollimo', limit: 10_000);

    expect($results->matters)->toHaveCount(SearchMatters::MAX_LIMIT)
        ->and($results->truncated)->toBeTrue();
});

it('kẹp limit vào khoảng [1, MAX_LIMIT] và báo còn nữa khi cắt', function () {
    $older = srchActionMatter($this->lead, ['title' => 'Vụ Pangolux một']);
    $newer = srchActionMatter($this->lead, ['title' => 'Vụ Pangolux hai']);

    $one = $this->search->handle($this->admin, 'Pangolux', limit: 0);

    expect(srchActionCodes($one))->toBe([$newer->code])
        ->and($one->truncated)->toBeTrue();

    $all = $this->search->handle($this->admin, 'Pangolux', limit: 10_000);

    expect(srchActionCodes($all))->toBe([$newer->code, $older->code])
        ->and($all->truncated)->toBeFalse();
});

it('không trả gì cho một tài khoản đã bị vô hiệu hoá hoặc đã xoá mềm, kể cả admin', function (string $how) {
    $matter = srchActionMatter($this->lead, ['title' => 'Vụ Serowix']);

    expect(srchActionCodes($this->search->handle($this->admin, 'Serowix')))->toBe([$matter->code]);

    match ($how) {
        'inactive' => $this->admin->forceFill(['is_active' => false])->save(),
        'trashed' => $this->admin->delete(),
    };

    expect($this->search->handle($this->admin, 'Serowix')->matters)->toBe([])
        ->and($this->search->matching($this->admin, 'Serowix')->exists())->toBeFalse();
})->with(['inactive', 'trashed']);

it('chuẩn hoá chuỗi tìm: bỏ khoảng trắng thừa, NFC, cắt ở MAX_TERM_LENGTH, dưới hai ký tự thì không tìm', function () {
    expect(SearchMatters::normalizeTerm("  Hợp   đồng\tthuê "))->toBe('Hợp đồng thuê')
        ->and(SearchMatters::normalizeTerm(Normalizer::normalize('Hợp đồng', Normalizer::FORM_D)))->toBe('Hợp đồng')
        ->and(mb_strlen((string) SearchMatters::normalizeTerm(str_repeat('ă', 500))))->toBe(SearchMatters::MAX_TERM_LENGTH)
        ->and(SearchMatters::normalizeTerm(' ă '))->toBeNull()
        ->and(SearchMatters::normalizeTerm(''))->toBeNull();
});

/**
 * M11 lắp bộ lọc riêng (loại vụ, giai đoạn, "vụ tôi phụ trách", phân trang) lên `matching()`, nên
 * hàm này phải tự mang luật hiển thị — không trông vào nơi gọi nhớ `listableBy()`.
 */
it('matching() tự mang luật hiển thị: một luật sư ngoài đội ngũ không nhận được vụ qua builder', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = srchActionMatter($this->lead, ['title' => 'Vụ Tarsiero']);

    expect($this->search->matching($this->admin, 'Tarsiero')->pluck('code')->all())->toBe([$matter->code])
        ->and($this->search->matching($outsider, 'Tarsiero')->exists())->toBeFalse()
        ->and($this->search->matching($this->admin, 'Tarsiero')->where('stage', 'không-có')->exists())->toBeFalse();
});

it('gộp các dòng khớp trùng chữ (nhiều version cùng tiêu đề) và cắt ở HITS_PER_SOURCE mỗi nguồn', function () {
    $matter = srchActionMatter($this->lead);

    foreach (range(1, 2) as $version) {
        Document::factory()->group(DocumentGroup::ClientProvided)->create(['matter_id' => $matter->id, 'title' => 'CCCD Margayo', 'version' => $version]);
    }

    foreach (range(1, SearchMatters::HITS_PER_SOURCE + 2) as $i) {
        MatterParty::factory()->create(['matter_id' => $matter->id, 'name' => "Bên Margayo số {$i}"]);
    }

    $hits = $this->search->handle($this->admin, 'Margayo')->matters[0]->hits;
    $documentHits = array_values(array_filter($hits, fn ($hit) => $hit->source === SearchSource::DocumentTitle));
    $partyHits = array_values(array_filter($hits, fn ($hit) => $hit->source === SearchSource::PartyName));

    expect(array_map(fn ($hit) => $hit->text, $documentHits))->toBe(['CCCD Margayo'])
        ->and($partyHits)->toHaveCount(SearchMatters::HITS_PER_SOURCE)
        ->and($partyHits[0]->text)->toBe('Bên Margayo số 1');
});

it('dòng kết quả mang id và tiêu đề chỉ cho người mở được trang vụ việc', function () {
    $member = User::factory()->withRole(Role::Assistant)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = srchActionMatter($this->lead, ['title' => 'Vụ Caracalo']);
    $matter->addTeamMember($member, MatterRole::Assistant);

    $forMember = $this->search->handle($member, $matter->code)->matters[0];
    $forAccountant = $this->search->handle($accountant, $matter->code)->matters[0];

    expect($forMember->matterId)->toBe($matter->id)
        ->and($forMember->title)->toBe('Vụ Caracalo')
        ->and($forAccountant->matterId)->toBeNull()
        ->and($forAccountant->title)->toBeNull()
        ->and($forAccountant->code)->toBe($matter->code)
        ->and($forAccountant->matterTypeName)->toBe($matter->matterType->name);
});
