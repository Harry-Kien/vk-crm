<?php

use App\Actions\Search\MatterSearchResult;
use App\Actions\Search\MatterSearchResults;
use App\Actions\Search\SearchMatters;
use App\Enums\DocumentGroup;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\SearchSource;
use App\Filament\Admin\Pages\Search;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Models\Client;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * M7 Task 9 — trang "Tìm kiếm" (SPEC §6.13, R7). Mọi hành vi đo qua Livewire hoặc HTTP, không gọi
 * thẳng Action. Mỗi nguồn trong sáu nguồn có một cặp test: người có quyền thấy, người không có
 * quyền KHÔNG thấy cả sự tồn tại (cùng câu "không tìm thấy" như một chuỗi không khớp gì, không mã,
 * không tiêu đề, không tên khách) — và mỗi test "không thấy" mang một vế đối chứng (admin thấy đúng
 * vụ đó bằng đúng chuỗi đó), để nó không xanh nhờ một chuỗi không khớp gì cả.
 *
 * Chuỗi tìm là những từ bịa ("Kiwizon", "Quokkavy"…) không thể có trong dữ liệu faker.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->member = User::factory()->withRole(Role::Lawyer)->create();
    $this->outsider = User::factory()->withRole(Role::Lawyer)->create();
});

/** Một vụ việc do `$lead` phụ trách, đội ngũ thêm `$members` (vai associate). */
function srchMatter(User $lead, array $attributes = [], array $members = []): Matter
{
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id, ...$attributes]);

    foreach ($members as $member) {
        $matter->addTeamMember($member, MatterRole::Associate);
    }

    return $matter->fresh();
}

/** Mở trang dưới tài khoản `$user` và gõ `$term`, đúng như người dùng gõ vào ô tìm. */
function srchAs(User $user, string $term): Testable
{
    test()->actingAs($user, 'web');

    return Livewire::test(Search::class)->set('term', $term);
}

function srchResults(Testable $page): ?MatterSearchResults
{
    return $page->viewData('results');
}

/** @return list<string> mã hồ sơ của mọi dòng kết quả trên màn hình */
function srchCodes(Testable $page): array
{
    return array_map(fn (MatterSearchResult $result): string => $result->code, srchResults($page)?->matters ?? []);
}

function srchResultFor(Testable $page, Matter $matter): ?MatterSearchResult
{
    foreach (srchResults($page)?->matters ?? [] as $result) {
        if ($result->code === $matter->code) {
            return $result;
        }
    }

    return null;
}

/** @return list<SearchSource> nguồn đã khớp của một dòng kết quả */
function srchSources(?MatterSearchResult $result): array
{
    return array_map(fn ($hit) => $hit->source, $result?->hits ?? []);
}

/** Đúng khối kết quả của trang (`<section id="vk-search-results">`), không gồm ô tìm và snapshot. */
function srchSection(Testable $page): string
{
    preg_match('/<section id="vk-search-results".*?<\/section>/s', $page->html(), $matches);

    return $matches[0] ?? throw new RuntimeException('Trang không có khối kết quả tìm kiếm.');
}

/**
 * Khẳng định "không thấy cả sự tồn tại": không dòng nào, câu "không tìm thấy" giống hệt câu của một
 * chuỗi không khớp gì, và không mảnh nào của vụ việc (mã, tiêu đề, tên khách) trong khối kết quả.
 */
function srchAssertInvisible(Testable $page, Matter $matter): void
{
    $section = srchSection($page);

    expect(srchCodes($page))->toBe([])
        ->and($section)->toContain(e(__('search.no_results')))
        ->and($section)->not->toContain(e($matter->code))
        ->and($section)->not->toContain(e($matter->title))
        ->and($section)->not->toContain(e($matter->client->name));
}

function srchPageSnapshot(string $html): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === Search::class) {
            return $snapshot;
        }
    }

    throw new RuntimeException('Không có snapshot Livewire của trang Tìm kiếm trong HTML.');
}

/** Một request cập nhật Livewire THẬT (đường `/livewire-…/update`), không qua `Livewire::test()`. */
function srchPostUpdate(string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    return test()->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls]],
    ]);
}

// ---------------------------------------------------------------------------------------------
// Cổng: nhân sự đang hoạt động có matter.viewAny hoặc matter.view; còn lại 404, kể cả Livewire update.
// ---------------------------------------------------------------------------------------------

it('mở được cho cả năm vai trò nội bộ và hiện trên thanh điều hướng của họ', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user, 'web')
        ->get(Search::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('search.page_title'));

    expect(Search::shouldRegisterNavigation())->toBeTrue();
})->with([Role::Admin, Role::Manager, Role::Lawyer, Role::Assistant, Role::Accountant]);

it('trả 404 cho một nhân sự không có quyền xem vụ việc nào, và không hiện trên thanh điều hướng', function () {
    $nobody = User::factory()->create();

    $this->actingAs($nobody, 'web')
        ->get(Search::getUrl(panel: 'admin'))
        ->assertNotFound();

    expect(Search::shouldRegisterNavigation())->toBeFalse();
});

/**
 * Đường Livewire update THẬT: luật sư mở trang, rồi mất vai trò (hoặc bị vô hiệu hoá), rồi gõ tiếp từ
 * trang đang mở. Mất vai trò: `hydrateCanAuthorizeAccess()` của Filament trả 403 bên trong vòng đời
 * component, nên chính `boot()` của trang biến lần này thành 404 (mutation probe: xoá `boot()` làm
 * vế `role` đỏ). Bị vô hiệu hoá: `Authenticate` của panel — middleware bền của Livewire — từ chối
 * trước khi component chạy, và middleware 404 của panel đổi nó thành 404. Cả hai: không kết quả nào.
 */
it('trả 404 cho một request cập nhật Livewire từ người đã mất quyền hoặc đã bị vô hiệu hoá', function (string $how) {
    $matter = srchMatter($this->lead, ['title' => 'Tranh chấp Kiwizon']);

    $snapshot = srchPageSnapshot(
        $this->actingAs($this->lead, 'web')->get(Search::getUrl(panel: 'admin'))->assertOk()->getContent()
    );

    match ($how) {
        'role' => $this->lead->syncRoles([]),
        'inactive' => $this->lead->forceFill(['is_active' => false])->save(),
    };

    $this->actingAs($this->lead->fresh(), 'web');

    $response = srchPostUpdate($snapshot, ['term' => 'Kiwizon']);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain($matter->code);

    srchPostUpdate($snapshot, [], [['path' => '', 'method' => 'search', 'params' => []]])->assertNotFound();
})->with(['role', 'inactive']);

/** Hành động thật tự hỏi lại cổng, độc lập với `boot()` (một thể hiện dựng thẳng). */
it('search() tự hỏi lại cổng, kể cả khi vòng đời Livewire bị bỏ qua', function () {
    $this->actingAs(User::factory()->create(), 'web');

    expect(fn () => (new Search)->search())->toThrow(NotFoundHttpException::class);
});

// ---------------------------------------------------------------------------------------------
// Sáu nguồn × (thấy / không thấy)
// ---------------------------------------------------------------------------------------------

it('nguồn 1, mã hồ sơ — thành viên đội ngũ tìm thấy vụ bằng một mảnh của mã', function () {
    $matter = srchMatter($this->lead, [], [$this->member]);
    $serial = substr($matter->code, -7); // "XX-0001": mảnh giữa của mã, không phải tiền tố

    $page = srchAs($this->member, $serial);

    expect(srchCodes($page))->toContain($matter->code)
        ->and(srchSources(srchResultFor($page, $matter)))->toContain(SearchSource::Code);

    $page->assertSee(ViewMatter::getUrl(['record' => $matter], panel: 'admin'), escape: false)
        ->assertSee($matter->title);
});

it('nguồn 1, mã hồ sơ — luật sư ngoài đội ngũ không thấy vụ, admin thấy', function () {
    $matter = srchMatter($this->lead, [], [$this->member]);

    srchAssertInvisible(srchAs($this->outsider, $matter->code), $matter);

    expect(srchCodes(srchAs($this->admin, $matter->code)))->toBe([$matter->code]);
});

it('nguồn 2, tiêu đề vụ việc — thành viên đội ngũ tìm thấy vụ bằng một từ giữa tiêu đề', function () {
    $matter = srchMatter($this->lead, ['title' => 'Tranh chấp hợp đồng Kiwizon chi nhánh'], [$this->member]);

    $page = srchAs($this->member, 'kiwizon');

    expect(srchCodes($page))->toBe([$matter->code])
        ->and(srchSources(srchResultFor($page, $matter)))->toBe([SearchSource::Title]);
});

it('nguồn 2, tiêu đề vụ việc — luật sư ngoài đội ngũ không thấy vụ, admin thấy', function () {
    $matter = srchMatter($this->lead, ['title' => 'Tranh chấp hợp đồng Kiwizon chi nhánh'], [$this->member]);

    srchAssertInvisible(srchAs($this->outsider, 'Kiwizon'), $matter);

    expect(srchCodes(srchAs($this->admin, 'Kiwizon')))->toBe([$matter->code]);
});

it('nguồn 3, tên khách hàng — thành viên đội ngũ tìm thấy vụ bằng tên khách', function () {
    $client = Client::factory()->create(['name' => 'Công ty TNHH Quokkavy']);
    $matter = srchMatter($this->lead, ['client_id' => $client->id], [$this->member]);

    $page = srchAs($this->member, 'Quokkavy');

    expect(srchCodes($page))->toBe([$matter->code])
        ->and(srchSources(srchResultFor($page, $matter)))->toBe([SearchSource::ClientName]);

    $page->assertSee('Công ty TNHH Quokkavy');
});

it('nguồn 3, tên khách hàng — luật sư ngoài đội ngũ không thấy vụ, admin thấy', function () {
    $client = Client::factory()->create(['name' => 'Công ty TNHH Quokkavy']);
    $matter = srchMatter($this->lead, ['client_id' => $client->id], [$this->member]);

    srchAssertInvisible(srchAs($this->outsider, 'Quokkavy'), $matter);

    expect(srchCodes(srchAs($this->admin, 'Quokkavy')))->toBe([$matter->code]);
});

/**
 * Số thụ lý tìm theo TIỀN TỐ (`LIKE 'x%'`): người ta nhớ số và năm thụ lý ở đầu ("4711/2026"); phần
 * ký hiệu ở cuối ("/TLST-DS") chung cho cả loạt vụ, tìm kiểu "chứa" theo nó ra mọi vụ cùng loại.
 */
it('nguồn 4, số thụ lý — thành viên đội ngũ tìm thấy vụ bằng phần đầu của số thụ lý', function () {
    $matter = srchMatter($this->lead, ['case_number' => '4711/2026/TLST-DS'], [$this->member]);

    $page = srchAs($this->member, '4711/2026');

    expect(srchCodes($page))->toBe([$matter->code])
        ->and(srchSources(srchResultFor($page, $matter)))->toBe([SearchSource::CaseNumber]);

    $page->assertSee('4711/2026/TLST-DS');

    // Tiền tố, không phải "chứa": một mảnh ở giữa không khớp (hành vi đã chọn, ghi trong docblock).
    expect(srchCodes(srchAs($this->member, '2026/TLST-DS')))->toBe([]);

    // Vụ ra nhờ một nguồn khác thì dòng "Khớp" cũng không nhận số thụ lý chỉ vì nó CHỨA chuỗi đó.
    $matter->update(['title' => 'Kháng cáo bản án 2026/TLST-DS']);
    $byTitle = srchAs($this->member, '2026/TLST-DS');

    expect(srchCodes($byTitle))->toBe([$matter->code])
        ->and(srchSources(srchResultFor($byTitle, $matter)))->toBe([SearchSource::Title]);
});

it('nguồn 4, số thụ lý — luật sư ngoài đội ngũ không thấy vụ, admin thấy', function () {
    $matter = srchMatter($this->lead, ['case_number' => '4711/2026/TLST-DS'], [$this->member]);

    $page = srchAs($this->outsider, '4711/2026');

    srchAssertInvisible($page, $matter);
    expect(srchSection($page))->not->toContain('TLST-DS');

    expect(srchCodes(srchAs($this->admin, '4711/2026')))->toBe([$matter->code]);
});

/**
 * Tên các bên so trên `name_normalized` (chữ thường, không dấu, `đ` → `d` — `Normalizer::name()`),
 * với chuỗi tìm chuẩn hoá cùng một hàm: gõ có dấu hay không dấu đều ra, trên cả SQLite lẫn MariaDB.
 */
it('nguồn 5, tên các bên — thành viên đội ngũ tìm thấy vụ bằng tên một bên, có dấu hay không dấu', function () {
    $matter = srchMatter($this->lead, [], [$this->member]);
    MatterParty::factory()->defendant()->create(['matter_id' => $matter->id, 'name' => 'Đặng Thị Wombatyá']);

    foreach (['wombatya', 'Đặng Thị Wombatyá', 'dang thi WOMBATYA'] as $term) {
        $page = srchAs($this->member, $term);

        expect(srchCodes($page))->toBe([$matter->code])
            ->and(srchSources(srchResultFor($page, $matter)))->toBe([SearchSource::PartyName]);

        $page->assertSee('Đặng Thị Wombatyá');
    }
});

it('nguồn 5, tên các bên — luật sư ngoài đội ngũ không thấy vụ, admin thấy', function () {
    $matter = srchMatter($this->lead, [], [$this->member]);
    MatterParty::factory()->defendant()->create(['matter_id' => $matter->id, 'name' => 'Đặng Thị Wombatyá']);

    $page = srchAs($this->outsider, 'Wombatya');

    srchAssertInvisible($page, $matter);
    expect(srchSection($page))->not->toContain('Đặng Thị Wombatyá');

    expect(srchCodes(srchAs($this->admin, 'Wombatya')))->toBe([$matter->code]);
});

it('nguồn 6, tiêu đề tài liệu — thành viên đội ngũ tìm thấy vụ bằng tiêu đề một tài liệu', function () {
    $matter = srchMatter($this->lead, [], [$this->member]);
    Document::factory()->group(DocumentGroup::Issued)->create(['matter_id' => $matter->id, 'title' => 'Đơn khởi kiện Narwhalo']);

    $page = srchAs($this->member, 'narwhalo');

    expect(srchCodes($page))->toBe([$matter->code])
        ->and(srchSources(srchResultFor($page, $matter)))->toBe([SearchSource::DocumentTitle]);

    $page->assertSee('Đơn khởi kiện Narwhalo');
});

it('nguồn 6, tiêu đề tài liệu — luật sư ngoài đội ngũ không thấy vụ, admin thấy', function () {
    $matter = srchMatter($this->lead, [], [$this->member]);
    Document::factory()->group(DocumentGroup::Issued)->create(['matter_id' => $matter->id, 'title' => 'Đơn khởi kiện Narwhalo']);

    $page = srchAs($this->outsider, 'Narwhalo');

    srchAssertInvisible($page, $matter);
    expect(srchSection($page))->not->toContain('Đơn khởi kiện Narwhalo');

    expect(srchCodes(srchAs($this->admin, 'Narwhalo')))->toBe([$matter->code]);
});

// ---------------------------------------------------------------------------------------------
// Vụ restricted: chỉ luật sư phụ trách và admin, qua CẢ sáu nguồn
// ---------------------------------------------------------------------------------------------

/**
 * Một vụ `restricted` với dấu vết "Okapizu" ở đúng MỘT nguồn. Trưởng phòng (có matter.viewAny) và
 * một thành viên đội ngũ không phải lead đều không thấy nó qua nguồn đó; lead và admin thấy.
 */
it('vụ restricted không lộ với trưởng phòng hay thành viên đội ngũ không phải lead, qua nguồn', function (string $source) {
    $client = Client::factory()->create(['name' => $source === 'client' ? 'Hộ kinh doanh Okapizu' : 'Khách thường']);
    $matter = srchMatter($this->lead, [
        'client_id' => $client->id,
        'title' => $source === 'title' ? 'Vụ Okapizu' : 'Vụ hạn chế',
        'case_number' => $source === 'case' ? 'Okapizu/2026' : null,
    ], [$this->member]);
    $matter->update(['confidentiality' => 'restricted']);

    match ($source) {
        'party' => MatterParty::factory()->create(['matter_id' => $matter->id, 'name' => 'Lê Okapizu']),
        'document' => Document::factory()->group(DocumentGroup::Issued)->create(['matter_id' => $matter->id, 'title' => 'Hợp đồng Okapizu']),
        default => null,
    };

    $term = $source === 'code' ? $matter->code : 'Okapizu';
    $manager = User::factory()->withRole(Role::Manager)->create();

    srchAssertInvisible(srchAs($manager, $term), $matter);
    srchAssertInvisible(srchAs($this->member, $term), $matter);

    expect(srchCodes(srchAs($this->lead, $term)))->toBe([$matter->code])
        ->and(srchCodes(srchAs($this->admin, $term)))->toBe([$matter->code]);
})->with(['code', 'title', 'client', 'case', 'party', 'document']);

// ---------------------------------------------------------------------------------------------
// Số lượng kết quả không rò rỉ
// ---------------------------------------------------------------------------------------------

/**
 * Cùng một chuỗi: trưởng phòng thấy đúng ba vụ thường, admin thấy cả năm. Khối kết quả của trưởng
 * phòng GIỐNG HỆT TỪNG BYTE dù hai vụ restricted có tồn tại hay không (xoá mềm chúng rồi tìm lại) —
 * không đếm, không "có kết quả bị ẩn", không gợi ý gì khác.
 */
it('khối kết quả của người không có quyền không đổi một byte nào dù vụ họ không được xem có tồn tại hay không', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $normal = collect(range(1, 3))->map(fn (int $i) => srchMatter($this->lead, ['title' => "Platypo thường {$i}"]));
    $restricted = collect(range(1, 2))->map(fn (int $i) => srchMatter($this->lead, ['title' => "Platypo mật {$i}", 'confidentiality' => 'restricted']));

    $withRestricted = srchAs($manager, 'Platypo');

    expect(srchCodes($withRestricted))->toEqualCanonicalizing($normal->pluck('code')->all())
        ->and(srchCodes(srchAs($this->admin, 'Platypo')))->toHaveCount(5);

    $restricted->each->delete();

    expect(srchSection(srchAs($manager, 'Platypo')))->toBe(srchSection($withRestricted));
});

/**
 * Câu "chỉ hiện N hồ sơ đầu" chỉ dựa trên tập người đó thấy: giới hạn áp SAU luật hiển thị, trong
 * cùng một câu SQL. Trưởng phòng thấy đúng `DEFAULT_LIMIT` vụ thường (thêm hai vụ restricted cùng
 * khớp) — không có câu "còn nữa"; admin (thấy cả restricted) có câu đó.
 */
it('câu "còn kết quả khác" chỉ dựa trên những vụ người đó thấy', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $client = Client::factory()->create();
    $type = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id])->matter_type_id;

    Matter::factory()->count(SearchMatters::DEFAULT_LIMIT)->create([
        'client_id' => $client->id, 'matter_type_id' => $type, 'lead_lawyer_id' => $this->lead->id, 'title' => 'Echidnor thường',
    ]);
    Matter::factory()->count(2)->restricted()->create([
        'client_id' => $client->id, 'matter_type_id' => $type, 'lead_lawyer_id' => $this->lead->id, 'title' => 'Echidnor mật',
    ]);

    $managerPage = srchAs($manager, 'Echidnor');

    expect(srchCodes($managerPage))->toHaveCount(SearchMatters::DEFAULT_LIMIT)
        ->and(srchResults($managerPage)->truncated)->toBeFalse()
        ->and(srchSection($managerPage))->not->toContain(e(__('search.truncated', ['limit' => SearchMatters::DEFAULT_LIMIT])))
        ->and(srchSection($managerPage))->not->toContain('Echidnor mật');

    $adminPage = srchAs($this->admin, 'Echidnor');

    expect(srchCodes($adminPage))->toHaveCount(SearchMatters::DEFAULT_LIMIT)
        ->and(srchSection($adminPage))->toContain(e(__('search.truncated', ['limit' => SearchMatters::DEFAULT_LIMIT])));
});

// ---------------------------------------------------------------------------------------------
// Nhóm D, kế toán
// ---------------------------------------------------------------------------------------------

it('tiêu đề tài liệu nhóm D không trả cho trợ lý trong đội ngũ, trả cho luật sư trong đội ngũ', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = srchMatter($this->lead, [], [$this->member, $assistant]);
    Document::factory()->group(DocumentGroup::Internal)->create(['matter_id' => $matter->id, 'title' => 'Ghi nhớ chiến lược Okapiro']);

    $assistantPage = srchAs($assistant, 'Okapiro');

    srchAssertInvisible($assistantPage, $matter);
    expect(srchSection($assistantPage))->not->toContain('Ghi nhớ chiến lược Okapiro');

    $lawyerPage = srchAs($this->member, 'Okapiro');

    expect(srchCodes($lawyerPage))->toBe([$matter->code]);
    $lawyerPage->assertSee('Ghi nhớ chiến lược Okapiro');
});

/** Vụ khớp qua một nguồn KHÁC vẫn ra cho trợ lý, nhưng dòng khớp không mang tiêu đề nhóm D. */
it('vụ khớp qua tiêu đề vẫn ra cho trợ lý, nhưng không kèm tiêu đề tài liệu nhóm D cùng khớp', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = srchMatter($this->lead, ['title' => 'Tư vấn Okapiro'], [$assistant]);
    Document::factory()->group(DocumentGroup::Internal)->create(['matter_id' => $matter->id, 'title' => 'Ghi nhớ chiến lược Okapiro']);
    Document::factory()->group(DocumentGroup::Issued)->create(['matter_id' => $matter->id, 'title' => 'Công văn Okapiro']);

    $page = srchAs($assistant, 'Okapiro');

    expect(srchCodes($page))->toBe([$matter->code])
        ->and(srchSources(srchResultFor($page, $matter)))->toBe([SearchSource::Title, SearchSource::DocumentTitle]);

    $page->assertSee('Công văn Okapiro')->assertDontSee('Ghi nhớ chiến lược Okapiro');
});

it('kế toán không nhận kết quả từ tên các bên, tiêu đề tài liệu (kể cả nhóm D), tiêu đề vụ hay số thụ lý', function (string $source) {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = srchMatter($this->lead, [
        'title' => $source === 'title' ? 'Vụ Dingoxa' : 'Vụ thường',
        'case_number' => $source === 'case' ? 'Dingoxa/2026' : null,
    ]);

    match ($source) {
        'party' => MatterParty::factory()->create(['matter_id' => $matter->id, 'name' => 'Phạm Dingoxa']),
        'document' => Document::factory()->group(DocumentGroup::Issued)->create(['matter_id' => $matter->id, 'title' => 'Biên bản Dingoxa']),
        'internal' => Document::factory()->group(DocumentGroup::Internal)->create(['matter_id' => $matter->id, 'title' => 'Nháp Dingoxa']),
        default => null,
    };

    // Kế toán LIỆT KÊ được vụ thường này (matter.viewAny) — nên "không thấy" ở đây là do nguồn.
    expect(Matter::query()->listableBy($accountant)->whereKey($matter->id)->exists())->toBeTrue();

    $page = srchAs($accountant, 'Dingoxa');

    expect(srchCodes($page))->toBe([])
        ->and(srchSection($page))->not->toContain('Dingoxa');

    expect(srchCodes(srchAs($this->admin, 'Dingoxa')))->toBe([$matter->code]);
})->with(['party', 'document', 'internal', 'title', 'case']);

/**
 * Kế toán tìm được bằng mã hồ sơ và tên khách hàng — đúng hai cột họ thấy trên danh sách vụ việc —
 * và dòng kết quả chỉ mang mã, tên khách, loại vụ: không tiêu đề, không liên kết vào trang vụ việc
 * (kế toán không mở được trang đó — `MatterPolicy::view` đòi `matter.view`).
 */
it('kế toán tìm được bằng mã và tên khách, dòng kết quả không tiêu đề và không liên kết', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $client = Client::factory()->create(['name' => 'Bà Nguyễn Thị Tapirelle']);
    $matter = srchMatter($this->lead, ['client_id' => $client->id, 'title' => 'Ly hôn có tranh chấp tài sản']);
    // Cùng chữ ở một bên và một tài liệu: vụ ra nhờ tên khách, nhưng dòng "Khớp" không được mang
    // tên bên hay tiêu đề tài liệu cho kế toán.
    MatterParty::factory()->create(['matter_id' => $matter->id, 'name' => 'Ông Tapirelle Phụ']);
    Document::factory()->create(['matter_id' => $matter->id, 'title' => 'Hợp đồng Tapirelle']);

    foreach (['Tapirelle', $matter->code] as $term) {
        $page = srchAs($accountant, $term);
        $result = srchResultFor($page, $matter);

        expect(srchCodes($page))->toBe([$matter->code])
            ->and($result->matterId)->toBeNull()
            ->and($result->title)->toBeNull()
            ->and($result->clientName)->toBe('Bà Nguyễn Thị Tapirelle')
            ->and(srchSources($result))->toBe([$term === 'Tapirelle' ? SearchSource::ClientName : SearchSource::Code]);

        expect(srchSection($page))->not->toContain('Ly hôn có tranh chấp tài sản')
            ->and(srchSection($page))->not->toContain('<a ')
            ->and(srchSection($page))->not->toContain('Ông Tapirelle Phụ')
            ->and(srchSection($page))->not->toContain('Hợp đồng Tapirelle')
            ->and(srchSection($page))->toContain('Bà Nguyễn Thị Tapirelle');
    }

    // Đối chứng: admin thấy cả ba dòng khớp của cùng vụ đó.
    expect(srchSources(srchResultFor(srchAs($this->admin, 'Tapirelle'), $matter)))
        ->toBe([SearchSource::ClientName, SearchSource::PartyName, SearchSource::DocumentTitle]);
});

// ---------------------------------------------------------------------------------------------
// Bản ghi đã xoá mềm, ký tự đại diện, độ dài
// ---------------------------------------------------------------------------------------------

it('không tìm theo một bên đã gỡ, một tài liệu đã xoá, hay một vụ đã xoá mềm', function () {
    $matter = srchMatter($this->lead);
    $party = MatterParty::factory()->create(['matter_id' => $matter->id, 'name' => 'Võ Quetzalo']);
    $document = Document::factory()->create(['matter_id' => $matter->id, 'title' => 'Tờ trình Quetzalo']);

    expect(srchSources(srchResultFor(srchAs($this->admin, 'Quetzalo'), $matter)))
        ->toBe([SearchSource::PartyName, SearchSource::DocumentTitle]);

    $party->delete();
    $document->delete();

    expect(srchCodes(srchAs($this->admin, 'Quetzalo')))->toBe([]);

    $other = srchMatter($this->lead, ['title' => 'Vụ Quetzalo đã huỷ']);
    $other->delete();

    expect(srchCodes(srchAs($this->admin, 'Quetzalo')))->toBe([]);
});

it('coi % và _ trong chuỗi gõ vào là chữ thường, không phải ký tự đại diện của LIKE', function () {
    $percent = srchMatter($this->lead, ['title' => 'Giảm 50% phí Lemurio']);
    $zero = srchMatter($this->lead, ['title' => 'Giảm 500 phí Lemurio']);
    $underscore = srchMatter($this->lead, ['title' => 'Mã A_B Lemurio']);
    $letter = srchMatter($this->lead, ['title' => 'Mã AXB Lemurio']);

    expect(srchCodes(srchAs($this->admin, '50%')))->toBe([$percent->code])
        ->and(srchCodes(srchAs($this->admin, 'A_B')))->toBe([$underscore->code])
        ->and(srchCodes(srchAs($this->admin, 'Lemurio')))->toEqualCanonicalizing([$percent->code, $zero->code, $underscore->code, $letter->code]);
});

it('chưa tìm khi chuỗi ngắn hơn hai ký tự, và nói điều đó', function () {
    srchMatter($this->lead, ['title' => 'A']);

    $page = srchAs($this->admin, ' a ');

    expect(srchResults($page))->toBeNull();
    $page->assertSee(__('search.too_short', ['min' => 2]));
});

it('giới hạn ô tìm ở trần độ dài của Action', function () {
    $this->actingAs($this->admin, 'web')
        ->get(Search::getUrl(panel: 'admin'))
        ->assertSee('maxlength="'.SearchMatters::MAX_TERM_LENGTH.'"', escape: false);
});

it('nói cho người dùng biết đang tìm trong những nguồn nào: đủ sáu với luật sư, hai với kế toán', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $lawyerSection = srchAs($this->member, 'xy')->html();
    $accountantSection = srchAs($accountant, 'xy')->html();

    foreach (SearchSource::cases() as $source) {
        expect($lawyerSection)->toContain(e($source->label()));
    }

    expect($accountantSection)->toContain(e(SearchSource::Code->label()))
        ->and($accountantSection)->toContain(e(SearchSource::ClientName->label()))
        ->and($accountantSection)->not->toContain(e(SearchSource::PartyName->label()))
        ->and($accountantSection)->not->toContain(e(SearchSource::DocumentTitle->label()));
});

// ---------------------------------------------------------------------------------------------
// Tiếng Việt: hành vi THẬT của từng CSDL (chạy cả `test` lẫn `test:mariadb`)
// ---------------------------------------------------------------------------------------------

/**
 * `utf8mb4_unicode_ci` của MariaDB bỏ qua dấu và hoa/thường, nhưng coi "đ" là một chữ KHÁC "d".
 * SQLite so `LIKE` theo byte, chỉ gộp hoa/thường của chữ ASCII. Tên các bên đi qua
 * `name_normalized` nên giống nhau trên cả hai. Test ghi lại đúng hành vi của CSDL đang chạy.
 */
it('tìm tiếng Việt: tên các bên như nhau trên mọi CSDL, tiêu đề và tên khách theo collation', function () {
    $mariadb = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $client = Client::factory()->create(['name' => 'Ông Đỗ Văn Ánh']);
    $matter = srchMatter($this->lead, ['client_id' => $client->id, 'title' => 'Hợp đồng thuê nhà Đường Lâm']);
    MatterParty::factory()->create(['matter_id' => $matter->id, 'name' => 'Đinh Thị Ngọc Ánh']);

    $finds = fn (string $term): bool => srchCodes(srchAs($this->admin, $term)) === [$matter->code];

    // Tên các bên — chuẩn hoá cả hai vế: bỏ dấu, đ → d, hoa/thường. Mọi CSDL.
    expect($finds('dinh thi ngoc anh'))->toBeTrue()
        ->and($finds('ĐINH THỊ NGỌC ÁNH'))->toBeTrue();

    // Tiêu đề và tên khách — đúng chữ, đúng dấu: mọi CSDL.
    expect($finds('thuê nhà'))->toBeTrue()
        ->and($finds('Đỗ Văn'))->toBeTrue();

    // Không dấu: MariaDB bỏ qua dấu, SQLite không.
    expect($finds('thue nha'))->toBe($mariadb);

    // Hoa/thường ngoài bảng ASCII ("Ỗ" và "ỗ"): MariaDB gộp, SQLite không.
    expect($finds('ĐỖ VĂN'))->toBe($mariadb);

    // "đ" khác "d" trên MariaDB (và trên SQLite): "Duong Lam" không ra "Đường Lâm".
    expect($finds('duong lam'))->toBeFalse()
        ->and($finds('đường lâm'))->toBe($mariadb);
});

/** Chuỗi gõ ở dạng NFD (chữ gốc + dấu tổ hợp) được đưa về NFC trước khi so — mọi CSDL. */
it('tìm được khi chuỗi gõ vào ở dạng Unicode tổ hợp (NFD)', function () {
    $matter = srchMatter($this->lead, ['title' => 'Bồi thường thiệt hại Kakapoé']);
    $nfd = Normalizer::normalize('Bồi thường', Normalizer::FORM_D);

    expect($nfd)->not->toBe('Bồi thường')
        ->and(srchCodes(srchAs($this->admin, $nfd)))->toBe([$matter->code]);
});

// ---------------------------------------------------------------------------------------------
// Giao diện: không lớp CSS, chỉ biến màu đã đăng ký
// ---------------------------------------------------------------------------------------------

it('khối kết quả không phát lớp CSS nào và chỉ tô bằng biến màu đã đăng ký', function () {
    $matter = srchMatter($this->lead, ['title' => 'Tranh chấp Axolotan']);
    MatterParty::factory()->create(['matter_id' => $matter->id, 'name' => 'Hồ Axolotan']);

    foreach (['Axolotan', 'khongcogihet', 'a'] as $term) {
        $section = srchSection(srchAs($this->admin, $term));

        expect($section)->not->toContain('class="')
            ->and(colourVariablesIn($section))->not->toBeEmpty()
            ->and(unregisteredColourVariables($section))->toBe([]);
    }
});
