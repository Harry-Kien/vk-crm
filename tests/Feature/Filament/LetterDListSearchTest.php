<?php

use App\Actions\Intake\RecordIntake;
use App\Enums\IntakeSource;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Pages\RevenueDashboard;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\ClientUsers\Pages\ListClientUsers;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ListIntakeRequests;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\PartiesRelationManager;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Search\LetterD;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Làn fa3, fix round 1 (m3 của rà soát Task 1): nhân sự gõ không dấu cả ngày, nên "Dang" phải ra "Đặng"
 * ở các ô tìm của danh sách chính — không chỉ ở trang Tìm kiếm, Tài liệu và Hợp đồng. Cột của bảng đi qua
 * `LetterD::searchable()` (SQL, "đ" như "d" ở hai vế; dấu khác và hoa/thường theo collation: MariaDB bỏ
 * qua, SQLite không); ô chọn tìm trên máy chủ qua `LetterD::searchOptions()` (PHP, `Normalizer::name()`
 * ở hai vế — bỏ mọi dấu trên mọi CSDL) trên ĐÚNG danh sách tuỳ chọn cũ, nên tầm nhìn không đổi. Mọi hành
 * vi qua màn hình (Livewire). Hàm toàn cục mang tiền tố `ldl`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->otherLawyer = User::factory()->withRole(Role::Lawyer)->create();
});

function ldlMariadb(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
}

/**
 * Kết quả ô chọn `$name` của schema `$schema` cho chuỗi `$term` — đúng lời gọi ô chọn gửi khi người dùng gõ
 * (`callSchemaComponentMethod` → `getSearchResultsForJs`).
 *
 * @return array<int, string> id => nhãn
 */
function ldlSelectSearch(Testable $page, string $schema, string $name, string $term): array
{
    $select = collect($page->instance()->getSchema($schema)->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof Select && str_ends_with((string) $component->getKey(), '.'.$name));

    expect($select)->not->toBeNull()
        ->and($select->hasDynamicSearchResults())->toBeTrue();

    $results = null;

    $page->call('callSchemaComponentMethod', $select->getKey(), 'getSearchResultsForJs', ['search' => $term])
        ->assertReturned(function (mixed $returned) use (&$results): bool {
            $results = $returned;

            return is_array($returned);
        });

    return collect($results)->mapWithKeys(fn (array $option): array => [(int) $option['value'] => $option['label']])->all();
}

// =================================================================================================
// Cột của bảng
// =================================================================================================

it('Clients list: name and code treat "đ" as "d"; "Dang" finds "Đặng" on MariaDB', function () {
    $dinh = Client::factory()->create(['name' => 'Bà Đinh Fabacl']);
    $dang = Client::factory()->create(['name' => 'Ông Đặng Fabacl']);
    $other = Client::factory()->create(['name' => 'Ông Dũng Fabaother']);

    $this->actingAs($this->admin, 'web');

    $this->livewire(ListClients::class)->searchTable('dinh fabacl')
        ->assertCanSeeTableRecords([$dinh])->assertCanNotSeeTableRecords([$dang, $other]);

    $this->livewire(ListClients::class)->searchTable('ĐINH FABACL')->assertCanSeeTableRecords([$dinh]);

    $page = $this->livewire(ListClients::class)->searchTable('Dang Fabacl');
    ldlMariadb() ? $page->assertCanSeeTableRecords([$dang]) : $page->assertCanNotSeeTableRecords([$dang]);

    $this->livewire(ListClients::class)->searchTable($dinh->code)->assertCanSeeTableRecords([$dinh]);
});

it('Matters list: code, client, matter type, title and lead lawyer treat "đ" as "d"', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Đinh Fabalead']);
    $type = MatterType::factory()->withStages()->create(['name' => 'Đinh Fabatype']);
    $byClient = Matter::factory()->create(['client_id' => Client::factory()->create(['name' => 'Đinh Fabamcl'])->id]);
    $byTitle = Matter::factory()->create(['title' => 'Tranh chấp Đinh Fabamtitle']);
    $byLead = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    $byType = Matter::factory()->for($type)->create();
    $plain = Matter::factory()->create(['title' => 'Không liên quan']);

    $this->actingAs($this->admin, 'web');

    $search = fn (string $term) => $this->livewire(ListMatters::class)->searchTable($term);

    $search('dinh fabamcl')->assertCanSeeTableRecords([$byClient])->assertCanNotSeeTableRecords([$byTitle, $plain]);
    $search('dinh fabamtitle')->assertCanSeeTableRecords([$byTitle])->assertCanNotSeeTableRecords([$byClient, $plain]);
    $search('dinh fabalead')->assertCanSeeTableRecords([$byLead])->assertCanNotSeeTableRecords([$plain]);
    $search('dinh fabatype')->assertCanSeeTableRecords([$byType])->assertCanNotSeeTableRecords([$plain]);
    $search($byClient->code)->assertCanSeeTableRecords([$byClient])->assertCanNotSeeTableRecords([$byTitle]);
});

it('Matters list: the "đ" search does not widen what a lawyer sees — a restricted matter stays hidden', function () {
    $restricted = Matter::factory()->create([
        'lead_lawyer_id' => $this->otherLawyer->id,
        'confidentiality' => 'restricted',
        'title' => 'Vụ Đinh Fabarestricted',
        'client_id' => Client::factory()->create(['name' => 'Đinh Fabarestrictedcl'])->id,
    ]);
    $mine = Matter::factory()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'title' => 'Vụ Đinh Fabamine',
    ]);

    $this->actingAs($this->lawyer, 'web');

    $this->livewire(ListMatters::class)->searchTable('dinh fabarestricted')->assertCanNotSeeTableRecords([$restricted]);
    $this->livewire(ListMatters::class)->searchTable('dinh fabarestrictedcl')->assertCanNotSeeTableRecords([$restricted]);
    $this->livewire(ListMatters::class)->searchTable('dinh fabamine')->assertCanSeeTableRecords([$mine]);

    // Đối chứng: admin thấy vụ hạn chế đó bằng đúng chuỗi tìm.
    $this->actingAs($this->admin, 'web');
    $this->livewire(ListMatters::class)->searchTable('dinh fabarestricted')->assertCanSeeTableRecords([$restricted]);
});

it('Matters list: the accountant, who has no title column, does not find a matter by its title', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->create(['title' => 'Vụ Đinh Fabaacctitle']);

    $this->actingAs($accountant, 'web');

    $this->livewire(ListMatters::class)->searchTable('dinh fabaacctitle')->assertCanNotSeeTableRecords([$matter]);
    $this->livewire(ListMatters::class)->searchTable($matter->code)->assertCanSeeTableRecords([$matter]);
});

it('Intake list: contact name treats "đ" as "d", still only within what the viewer may see', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $record = fn (User $actor, string $name): IntakeRequest => app(RecordIntake::class)->handle($actor, [
        'contact_name' => $name, 'contact_phone' => '0912 345 678',
        'contact_role' => PartyRole::Plaintiff, 'source' => IntakeSource::Phone,
    ])->intake;

    $mine = $record($assistant, 'Đinh Fabaintake');
    $hidden = $record(User::factory()->withRole(Role::Assistant)->create(), 'Đinh Fabaintake Khác');

    $this->actingAs($assistant, 'web');

    $this->livewire(ListIntakeRequests::class)->searchTable('dinh fabaintake')
        ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$hidden]);

    $this->livewire(ListIntakeRequests::class)->searchTable($mine->code)->assertCanSeeTableRecords([$mine]);
});

it('Users list: name and bar number treat "đ" as "d"', function () {
    $user = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Đinh Fabastaff', 'bar_number' => 'ĐLS-77-FABA']);
    $other = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Người Khác Fabastaff', 'email' => 'nguoikhac.fabastaff@example.test']);

    $this->actingAs($this->admin, 'web');

    $this->livewire(ListUsers::class)->searchTable('dinh fabastaff')
        ->assertCanSeeTableRecords([$user])->assertCanNotSeeTableRecords([$other]);
    $this->livewire(ListUsers::class)->searchTable('dls-77-faba')->assertCanSeeTableRecords([$user]);
});

it('Client portal accounts list: account name and client name treat "đ" as "d"', function () {
    $byName = ClientUser::factory()->create(['name' => 'Đinh Fabapn']);
    $byClient = ClientUser::factory()->create(['client_id' => Client::factory()->create(['name' => 'Công ty Đinh Fabapc'])->id]);
    $other = ClientUser::factory()->create(['name' => 'Người Khác']);

    $this->actingAs($this->admin, 'web');

    $this->livewire(ListClientUsers::class)->searchTable('dinh fabapn')
        ->assertCanSeeTableRecords([$byName])->assertCanNotSeeTableRecords([$byClient, $other]);
    $this->livewire(ListClientUsers::class)->searchTable('dinh fabapc')
        ->assertCanSeeTableRecords([$byClient])->assertCanNotSeeTableRecords([$byName, $other]);
});

it('Parties tab: the party name treats "đ" as "d"', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $dinh = MatterParty::factory()->create(['matter_id' => $matter->id, 'name' => 'Đinh Fabaparty']);
    $other = MatterParty::factory()->create(['matter_id' => $matter->id, 'name' => 'Lê Fabaparty']);

    $this->actingAs($this->lawyer, 'web');

    Livewire::test(PartiesRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->searchTable('dinh fabaparty')
        ->assertCanSeeTableRecords([$dinh])
        ->assertCanNotSeeTableRecords([$other]);
});

it('a typed "%" or "_" is a letter, not a wildcard, in the list search boxes', function () {
    $pct = Client::factory()->create(['name' => 'Công ty 50% Fabapct']);
    $plain = Client::factory()->create(['name' => 'Công ty 5a0 Fabapct']);

    $this->actingAs($this->admin, 'web');

    $this->livewire(ListClients::class)->searchTable('50%')->assertCanSeeTableRecords([$pct])->assertCanNotSeeTableRecords([$plain]);
    $this->livewire(ListClients::class)->searchTable('5_0')->assertCanNotSeeTableRecords([$pct, $plain]);
});

// =================================================================================================
// Ô chọn (tìm trên máy chủ, trên đúng danh sách tuỳ chọn cũ)
// =================================================================================================

it('new matter form: the client select finds "Đặng" by "dang" on every database, only among the clients the lawyer may see', function () {
    $visible = Client::factory()->create(['name' => 'Ông Đặng Fabaselect']);
    $hidden = Client::factory()->create(['name' => 'Ông Đặng Fabaselect Ẩn']);
    $other = Client::factory()->create(['name' => 'Ông Lê Fabaselect']);

    // Luật sư (không có client.manage) chỉ thấy khách của vụ mình liệt kê được: vụ của $visible (đội ngũ),
    // vụ của $other (đội ngũ); khách $hidden chỉ có một vụ hạn chế của người khác.
    Matter::factory()->create(['client_id' => $visible->id, 'lead_lawyer_id' => $this->lawyer->id]);
    Matter::factory()->create(['client_id' => $other->id, 'lead_lawyer_id' => $this->lawyer->id]);
    Matter::factory()->create(['client_id' => $hidden->id, 'lead_lawyer_id' => $this->otherLawyer->id, 'confidentiality' => 'restricted']);

    $this->actingAs($this->lawyer, 'web');

    $page = Livewire::test(CreateMatter::class);

    expect(ldlSelectSearch($page, 'form', 'client_id', 'dang fabaselect'))->toBe([$visible->id => 'Ông Đặng Fabaselect'])
        ->and(ldlSelectSearch($page, 'form', 'client_id', 'ĐẶNG'))->toHaveKey($visible->id)
        ->and(ldlSelectSearch($page, 'form', 'client_id', 'fabaselect'))->toHaveKeys([$visible->id, $other->id])
        ->and(ldlSelectSearch($page, 'form', 'client_id', 'fabaselect'))->not->toHaveKey($hidden->id);

    // Admin (client.manage) thấy cả khách kia — cùng danh sách tuỳ chọn như trước.
    $this->actingAs($this->admin, 'web');

    expect(ldlSelectSearch(Livewire::test(CreateMatter::class), 'form', 'client_id', 'dang fabaselect'))
        ->toHaveKeys([$visible->id, $hidden->id]);
});

it('new matter form: the lead lawyer select finds "Đoàn" by "doan", still only active people who may lead a matter', function () {
    $active = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Đoàn Fabaleadsel']);
    $inactive = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Đoàn Fabaleadsel Nghỉ', 'is_active' => false]);
    $accountant = User::factory()->withRole(Role::Accountant)->create(['name' => 'Đoàn Fabaleadsel Kế Toán']);

    $this->actingAs($this->admin, 'web');

    expect(ldlSelectSearch(Livewire::test(CreateMatter::class), 'form', 'lead_lawyer_id', 'doan fabaleadsel'))
        ->toBe([$active->id => 'Đoàn Fabaleadsel'])
        ->and(array_keys(ldlSelectSearch(Livewire::test(CreateMatter::class), 'form', 'lead_lawyer_id', 'fabaleadsel')))
        ->not->toContain($inactive->id, $accountant->id);
});

it('revenue dashboard: the lawyer filter finds "Đinh" by "dinh", and never offers a lawyer whose only matter the viewer cannot see', function () {
    $seen = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Đinh Fabarev']);
    $unseen = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Đinh Fabarev Hạn Chế']);
    Matter::factory()->create(['lead_lawyer_id' => $seen->id]);
    Matter::factory()->create(['lead_lawyer_id' => $unseen->id, 'confidentiality' => 'restricted']);
    $manager = User::factory()->withRole(Role::Manager)->create();

    $this->actingAs($manager, 'web');

    expect(ldlSelectSearch(Livewire::test(RevenueDashboard::class), 'filtersForm', 'lawyer_id', 'dinh fabarev'))
        ->toBe([$seen->id => 'Đinh Fabarev']);
});

it('activity log: the "who" filter finds "Đinh" by "dinh"', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Đinh Fabalog']);

    $this->actingAs($this->admin, 'web');

    expect(ldlSelectSearch(Livewire::test(ActivityLogPage::class), 'tableFiltersForm', 'causer.value', 'dinh fabalog'))
        ->toBe([$staff->id => 'Đinh Fabalog']);
});

it('LetterD::searchOptions matches every typed word, in any order, ignoring marks and case, and returns nothing on no match', function () {
    $search = LetterD::searchOptions(fn (): array => [1 => 'Nguyễn Văn Đức', 2 => 'Trần Thị Đào', 3 => 'Lê Dũng']);

    expect($search('duc nguyen'))->toBe([1 => 'Nguyễn Văn Đức'])
        ->and($search('DAO'))->toBe([2 => 'Trần Thị Đào'])
        ->and($search('d'))->toBe([1 => 'Nguyễn Văn Đức', 2 => 'Trần Thị Đào', 3 => 'Lê Dũng'])
        ->and($search('khong co'))->toBe([])
        // Mọi từ phải có mặt: "duc" chỉ ở dòng 1, "tran" chỉ ở dòng 2 — không dòng nào có cả hai.
        ->and($search('duc tran'))->toBe([])
        ->and($search(''))->toBe([1 => 'Nguyễn Văn Đức', 2 => 'Trần Thị Đào', 3 => 'Lê Dũng']);
});
