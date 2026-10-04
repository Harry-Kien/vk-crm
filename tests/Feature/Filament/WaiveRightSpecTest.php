<?php

use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

/*
|--------------------------------------------------------------------------
| Ai miễn được một đợt thanh toán — SPEC §5 và tab "Hợp đồng và thanh toán" nói cùng một câu
|--------------------------------------------------------------------------
|
| Rà soát cuối làn m9f, I1 (minor m3 của rà soát Task 13). Đính chính 2026-10-03 của SPEC §5 ghi
| người miễn được một đợt là "luật sư phụ trách, quản lý, admin", nhưng `InstalmentPolicy::waive`
| = `contract.manage` + thấy tiền của vụ (`billing.view` + `Matter::listableBy`), tức MỌI luật sư
| trong đội của một vụ thường — kể cả luật sư phối hợp — miễn được; đúng dấu "✓ (vụ của mình)" ở
| bảng bốn quyền tiền ngay trên. SPEC là nguồn sự thật, nên nó đang mô tả một quyền xoá nợ HẸP
| hơn quyền mã thật cấp. Mã giữ nguyên (khớp bảng); câu SPEC sửa cho khớp.
|
| Ba test đầu đo quyền thật qua màn hình (Livewire, nút `waiveInstalment` của tab vụ việc); test
| cuối đọc CHÍNH câu SPEC và đòi nó nói đúng điều ba test kia vừa đo.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->coCounsel = User::factory()->withRole(Role::Lawyer)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->coCounsel, MatterRole::Associate);

    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->restricted->addTeamMember($this->coCounsel, MatterRole::Associate);
});

function wrsTab(Matter $matter)
{
    return test()->livewire(BillingRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

/** Hợp đồng đang hiệu lực với đúng một đợt `pending` bằng giá trị hợp đồng (bất biến tổng giữ sạch). */
function wrsPendingInstalment(Matter $matter): Instalment
{
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 50_000_000]);

    return Instalment::factory()->for($contract)->create([
        'amount' => 50_000_000,
        'trigger_type' => InstalmentTrigger::DueDate,
        'due_date' => today()->addDays(10)->toDateString(),
    ]);
}

/** Đoạn đính chính "miễn một đợt" của SPEC §5 — đúng một dòng trích dẫn của tệp. */
function wrsSpecWaiveErratum(): string
{
    $lines = array_values(array_filter(
        explode("\n", (string) file_get_contents(base_path('docs/SPEC.md'))),
        fn (string $line): bool => str_contains($line, 'miễn một đợt cũng thuộc `contract.manage`'),
    ));

    expect($lines)->toHaveCount(1);

    return $lines[0];
}

it('lets a co-counsel lawyer on the team of a normal matter waive an instalment from the matter tab', function () {
    $instalment = wrsPendingInstalment($this->matter);

    $this->actingAs($this->coCounsel, 'web');

    wrsTab($this->matter)
        ->assertActionVisible(TestAction::make('waiveInstalment')->table($instalment))
        ->callAction(TestAction::make('waiveInstalment')->table($instalment), data: [
            'reason' => 'Khách là đối tác lâu năm, miễn đợt này theo thoả thuận.',
        ])
        ->assertHasNoActionErrors();

    expect($instalment->fresh())
        ->status->toBe(InstalmentStatus::Waived)
        ->waived_by->toBe($this->coCounsel->id);
});

it('keeps the waive button of a restricted matter to its lead lawyer and the admin, not the co-counsel', function () {
    $instalment = wrsPendingInstalment($this->restricted);

    $this->actingAs($this->coCounsel, 'web');
    expect(BillingRelationManager::canViewForRecord($this->restricted, ViewMatter::class))->toBeFalse();

    $this->actingAs($this->admin, 'web');
    wrsTab($this->restricted)->assertActionVisible(TestAction::make('waiveInstalment')->table($instalment));

    $this->actingAs($this->lead, 'web');
    wrsTab($this->restricted)
        ->assertActionVisible(TestAction::make('waiveInstalment')->table($instalment))
        ->callAction(TestAction::make('waiveInstalment')->table($instalment), data: [
            'reason' => 'Vụ hạn chế, luật sư phụ trách miễn đợt theo thoả thuận.',
        ])
        ->assertHasNoActionErrors();

    expect($instalment->fresh()->status)->toBe(InstalmentStatus::Waived);
});

it('shows the waive button to the manager and hides it from the accountant on a normal matter', function () {
    $instalment = wrsPendingInstalment($this->matter);

    $this->actingAs($this->manager, 'web');
    wrsTab($this->matter)->assertActionVisible(TestAction::make('waiveInstalment')->table($instalment));

    $this->actingAs($this->accountant, 'web');
    expect(BillingRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeTrue();
    wrsTab($this->matter)->assertActionHidden(TestAction::make('waiveInstalment')->table($instalment));
});

it('SPEC §5 names who can waive exactly as the matter tab does: every lawyer on the team, manager, admin; restricted: lead and admin; never the accountant', function () {
    $erratum = wrsSpecWaiveErratum();

    expect($erratum)
        ->toContain('mọi luật sư trong đội của vụ (kể cả luật sư phối hợp, không riêng luật sư phụ trách), quản lý và admin')
        ->toContain('trên vụ `restricted` chỉ luật sư phụ trách và admin')
        ->toContain('**kế toán không miễn được**')
        ->not->toContain('— luật sư phụ trách, quản lý, admin;');
});
