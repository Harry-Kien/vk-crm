<?php

use App\Enums\ContractStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Pages\ContractEntry;
use App\Filament\Admin\Pages\Receivables;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Filament\Admin\Widgets\ContractsToEnterWidget;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\Staff\ContractEnteredByColleague;
use App\Support\Billing\AccountantContractRow;
use App\Support\Billing\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn kt (2026-10-15) — trang "Hợp đồng" ({@see ContractEntry}): kế toán nhập hợp đồng giấy đã ký cho
 * hồ sơ có sẵn, không mở nội dung hồ sơ. Mọi hành vi qua màn hình (HTTP và Livewire), không gọi Action
 * trực tiếp.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Hà']);
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Phụ Trách Minh']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create(['name' => 'Lan Kế Toán']);
    $this->sales = User::factory()->withRole(Role::Sales)->create();

    $this->empty = Matter::factory()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'title' => 'TIEU DE MAT KT1 KHONG DUOC LO',
        'description_internal' => 'GHI CHU NOI BO KT2 KHONG DUOC LO',
        'summary_for_client' => 'TOM TAT KT3 KHONG DUOC LO',
    ]);
    $this->empty->addTeamMember($this->assistant, MatterRole::Assistant);
    MatterParty::factory()->for($this->empty)->create(['name' => 'BEN DOI LAP KT4 KHONG DUOC LO']);

    $this->drafted = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->draft = Contract::factory()->for($this->drafted)->create(['status' => ContractStatus::Draft, 'total_amount' => 30_000_000]);
    Instalment::factory()->for($this->draft)->create(['amount' => 30_000_000, 'trigger_type' => InstalmentTrigger::OnSigning, 'due_days_after_trigger' => 5]);

    $this->signed = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->active = Contract::factory()->for($this->signed)->active()->create(['total_amount' => 40_000_000]);
    $this->activeInstalment = Instalment::factory()->for($this->active)->create(['amount' => 40_000_000, 'due_date' => today()->addDays(10)->toDateString()]);
    Payment::factory()->for($this->activeInstalment)->create(['amount' => 15_000_000]);

    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $this->cancelled = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->cancelled->delete();
});

function contractPage()
{
    return test()->livewire(ContractEntry::class);
}

/** @return array<string, mixed> một hợp đồng một đợt khi ký, đúng bằng giá trị */
function oneInstalmentContract(string $total = '25.000.000', string $amount = '25.000.000', array $extra = []): array
{
    return [
        'total_amount' => $total,
        'vat_rate_percent' => '10',
        'instalments' => [
            ['name' => 'Trọn gói khi ký', 'amount' => $amount, 'trigger_type' => 'on_signing', 'due_days_after_trigger' => '7'],
        ],
        ...$extra,
    ];
}

// =================================================================================================
// Ai mở được trang — ma trận sáu vai
// =================================================================================================

it('opens the page for the admin, the manager, the lawyer and the accountant, and answers 404 to the assistant and the sales role', function () {
    $matrix = [
        'admin' => [$this->admin, 200],
        'manager' => [$this->manager, 200],
        'lawyer' => [$this->lawyer, 200],
        'assistant' => [$this->assistant, 404],
        'accountant' => [$this->accountant, 200],
        'sales' => [$this->sales, 404],
    ];

    foreach ($matrix as $name => [$user, $status]) {
        $this->actingAs($user, 'web')->get(ContractEntry::getUrl(panel: 'admin'))->assertStatus($status);

        expect(ContractEntry::canBeOpenedBy($user))->toBe($status === 200, "vai {$name}");
    }
});

it('asks billing.view and one of the two contract rights, through the Gate', function () {
    $enterer = User::factory()->create();
    $enterer->givePermissionTo([Permission::MatterViewAny->value, Permission::BillingView->value, Permission::ContractEnter->value]);
    $manager = User::factory()->create();
    $manager->givePermissionTo([Permission::MatterViewAny->value, Permission::BillingView->value, Permission::ContractManage->value]);
    $reader = User::factory()->create();
    $reader->givePermissionTo([Permission::MatterViewAny->value, Permission::BillingView->value]);
    $noMoney = User::factory()->create();
    $noMoney->givePermissionTo([Permission::MatterViewAny->value, Permission::ContractEnter->value, Permission::ContractManage->value]);

    expect(ContractEntry::canBeOpenedBy($enterer))->toBeTrue()
        ->and(ContractEntry::canBeOpenedBy($manager))->toBeTrue()
        ->and(ContractEntry::canBeOpenedBy($reader))->toBeFalse()
        ->and(ContractEntry::canBeOpenedBy($noMoney))->toBeFalse();
});

it('refuses a Livewire request from someone who cannot open the page with 404', function () {
    $this->actingAs($this->assistant, 'web');

    contractPage()->assertStatus(404);
});

it('lists the page in the navigation only for those who can open it', function () {
    $this->actingAs($this->accountant, 'web');
    expect(ContractEntry::shouldRegisterNavigation())->toBeTrue();

    $this->actingAs($this->assistant, 'web');
    expect(ContractEntry::shouldRegisterNavigation())->toBeFalse();
});

it('still answers 404 to the accountant on the matter page itself', function () {
    $this->actingAs($this->accountant, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $this->empty], panel: 'admin'))
        ->assertNotFound();
});

// =================================================================================================
// Tập dòng và ranh giới dữ liệu
// =================================================================================================

it('shows the matters still to enter by default — no contract or a draft — and never a restricted or cancelled matter', function () {
    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->assertCanSeeTableRecords([$this->empty, $this->drafted])
        ->assertCanNotSeeTableRecords([$this->signed, $this->restricted, $this->cancelled])
        ->filterTable('contract_status', [])
        ->assertCanSeeTableRecords([$this->empty, $this->drafted, $this->signed])
        ->assertCanNotSeeTableRecords([$this->restricted, $this->cancelled])
        ->filterTable('contract_status', [ContractStatus::Active->value])
        ->assertCanSeeTableRecords([$this->signed])
        ->assertCanNotSeeTableRecords([$this->empty, $this->drafted]);
});

it('treats a matter whose current contract was cancelled as cancelled, not as without contract', function () {
    $redo = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    Contract::factory()->for($redo)->create(['status' => ContractStatus::Cancelled, 'total_amount' => 5_000_000]);

    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->assertCanNotSeeTableRecords([$redo])
        ->filterTable('contract_status', [ContractStatus::Cancelled->value])
        ->assertCanSeeTableRecords([$redo])
        ->assertActionVisible(TestAction::make('enterContract')->table($redo));
});

/**
 * Vòng sửa 1 (I1): hợp đồng hiện hành đã huỷ sau khi đã thu tiền — modal "Nhập hợp đồng" phải nói
 * đúng luật mà modal "Soạn hợp đồng mới" của tab nói (`billing_corrections.redraft.collected_rule`)
 * kèm SỐ đã thu (chỉ khoản chưa huỷ) trên hợp đồng đã huỷ: kế toán nhập lại đủ giá trị giấy thì doanh
 * thu bị đếm hai lần, Công nợ và cổng khách báo khách nợ nhiều hơn thực tế.
 */
it('tells the accountant entering a contract after a cancelled one how much was already collected, so only what is still owed is entered', function () {
    $redo = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $old = Contract::factory()->for($redo)->active()->create(['total_amount' => 40_000_000]);
    $oldInstalment = Instalment::factory()->for($old)->create(['amount' => 40_000_000]);
    Payment::factory()->for($oldInstalment)->create(['amount' => 15_000_000]);
    Payment::factory()->for($oldInstalment)->voided()->create(['amount' => 5_000_000]);
    $old->forceFill(['status' => ContractStatus::Cancelled])->save();

    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->filterTable('contract_status', [ContractStatus::Cancelled->value])
        ->mountAction(TestAction::make('enterContract')->table($redo))
        ->assertMountedActionModalSee(__('contract_entry.actions.enter_description'))
        ->assertMountedActionModalSee(__('billing_corrections.redraft.collected_rule', [
            'collected' => Money::format(15_000_000),
        ]));
});

it('says nothing about a cancelled contract when entering the first contract of a matter', function () {
    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->mountAction(TestAction::make('enterContract')->table($this->empty))
        ->assertMountedActionModalSee(__('contract_entry.actions.enter_description'))
        ->assertMountedActionModalDontSee('không ghi lại');
});

it('shows the restricted matter to the admin, who sees its money', function () {
    $this->actingAs($this->admin, 'web');

    contractPage()->assertCanSeeTableRecords([$this->empty, $this->restricted]);
});

it('shows the lawyer only the matters of their own team', function () {
    $foreign = Matter::factory()->create();

    $this->actingAs($this->lawyer, 'web');

    contractPage()->assertCanSeeTableRecords([$this->empty])->assertCanNotSeeTableRecords([$foreign]);
});

it('never shows a matter title, internal note, summary or party, while showing the code, type, client and lead lawyer', function () {
    $response = $this->actingAs($this->accountant, 'web')->get(ContractEntry::getUrl(panel: 'admin'))->assertOk();

    $response->assertSee($this->empty->code)
        ->assertSee($this->empty->matterType->name)
        ->assertSee($this->empty->client->name)
        ->assertSee('Luật sư Phụ Trách Minh')
        ->assertSee(__('contract_entry.status.none'))
        ->assertDontSee('TIEU DE MAT KT1 KHONG DUOC LO')
        ->assertDontSee('GHI CHU NOI BO KT2 KHONG DUOC LO')
        ->assertDontSee('TOM TAT KT3 KHONG DUOC LO')
        ->assertDontSee('BEN DOI LAP KT4 KHONG DUOC LO')
        ->assertDontSee($this->restricted->code)
        ->assertDontSee($this->cancelled->code);
});

it('shows the contract code, status, total, collected and outstanding of a signed contract', function () {
    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->filterTable('contract_status', [ContractStatus::Active->value])
        ->assertTableColumnStateSet('contract_code', $this->active->code, $this->signed)
        ->assertTableColumnStateSet('contract_status', ContractStatus::Active->label(), $this->signed)
        ->assertTableColumnStateSet('total_amount', Money::format(40_000_000), $this->signed)
        ->assertTableColumnStateSet('collected', Money::format(15_000_000), $this->signed)
        ->assertTableColumnStateSet('outstanding', Money::format(25_000_000), $this->signed);
});

it('filters by matter type and lead lawyer, and searches by matter code or client name', function () {
    $otherLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $other = Matter::factory()->create(['lead_lawyer_id' => $otherLawyer->id]);

    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->filterTable('lead_lawyer_id', $otherLawyer->id)
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$this->empty, $this->drafted])
        ->resetTableFilters()
        ->filterTable('matter_type_id', $this->empty->matter_type_id)
        ->assertCanSeeTableRecords([$this->empty])
        ->assertCanNotSeeTableRecords([$other, $this->drafted]);

    contractPage()
        ->searchTable($this->drafted->code)
        ->assertCanSeeTableRecords([$this->drafted])
        ->assertCanNotSeeTableRecords([$this->empty]);

    contractPage()
        ->searchTable($this->empty->client->name)
        ->assertCanSeeTableRecords([$this->empty])
        ->assertCanNotSeeTableRecords([$this->drafted]);
});

it('offers the accountant only the lawyers and matter types of the matters it may list', function () {
    $this->actingAs($this->accountant, 'web');

    $page = contractPage()->instance();
    $lawyers = (fn () => $this->leadLawyerOptions())->call($page);
    $types = (fn () => $this->matterTypeOptions())->call($page);

    expect(array_keys($lawyers))->toContain($this->lawyer->id)
        ->and(array_keys($types))->toContain($this->empty->matter_type_id)
        ->and(array_keys($types))->not->toContain($this->restricted->matter_type_id)
        ->and(array_keys($types))->not->toContain($this->cancelled->matter_type_id);
});

// =================================================================================================
// Nhập hợp đồng
// =================================================================================================

it('lets the accountant enter a draft contract for an existing matter, and tells the lead lawyer', function () {
    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->callAction(TestAction::make('enterContract')->table($this->empty), data: oneInstalmentContract())
        ->assertHasNoActionErrors()
        ->assertNotified(__('contract_entry.actions.enter_success'));

    $contract = Contract::query()->where('matter_id', $this->empty->id)->sole();

    expect($contract->status)->toBe(ContractStatus::Draft)
        ->and($contract->total_amount)->toBe(25_000_000)
        ->and($contract->vat_rate_percent)->toBe(10)
        ->and($contract->created_by)->toBe($this->accountant->id)
        ->and($contract->instalments()->sole()->amount)->toBe(25_000_000)
        ->and(Activity::query()->where('event', 'contract_drafted')->sole()->causer_id)->toBe($this->accountant->id);

    $notification = $this->lawyer->notifications()->sole();

    expect($notification->type)->toBe(ContractEnteredByColleague::class)
        ->and($notification->data['body'])->toBe(__('contract_entry.notifications.drafted', [
            'who' => 'Kế toán Lan Kế Toán',
            'contract' => $contract->code,
            'matter' => $this->empty->code,
            'total' => Money::format(25_000_000),
            'count' => 1,
        ]))
        ->and($notification->data['body'])->not->toContain('TIEU DE MAT KT1')
        ->and($this->accountant->notifications()->count())->toBe(0);
});

it('enters and activates a signed contract in one go when the switch is on', function () {
    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->callAction(TestAction::make('enterContract')->table($this->empty), data: oneInstalmentContract(extra: [
            'activate_now' => true,
            'signed_at' => today()->subDays(2)->toDateString(),
        ]))
        ->assertHasNoActionErrors()
        ->assertNotified(__('contract_entry.actions.enter_activated_success'));

    $contract = Contract::query()->where('matter_id', $this->empty->id)->sole();

    expect($contract->status)->toBe(ContractStatus::Active)
        ->and($contract->signed_at->toDateString())->toBe(today()->subDays(2)->toDateString())
        ->and($contract->activated_by)->toBe($this->accountant->id)
        ->and($contract->instalments()->sole()->due_date->toDateString())->toBe(today()->addDays(5)->toDateString());

    // MỘT dòng chuông cho cả hai bước, không phải "đã nhập" rồi "đã kích hoạt".
    expect($this->lawyer->notifications()->count())->toBe(1)
        ->and($this->lawyer->notifications()->sole()->data['body'])->toBe(__('contract_entry.notifications.entered', [
            'who' => 'Kế toán Lan Kế Toán',
            'contract' => $contract->code,
            'matter' => $this->empty->code,
            'date' => today()->subDays(2)->format('d/m/Y'),
            'total' => Money::format(25_000_000),
            'count' => 1,
        ]));
});

it('saves nothing at all when activating in one go fails on the schedule total, and keeps the dialog open', function () {
    $sequenceBefore = DB::table('code_sequences')->where('key', 'contract:'.now()->year)->value('last_number');

    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->callAction(TestAction::make('enterContract')->table($this->empty), data: oneInstalmentContract('25.000.000', '20.000.000', [
            'activate_now' => true,
            'signed_at' => today()->toDateString(),
        ]))
        ->assertNotified(__('actions.failed_title'))
        ->assertActionMounted(TestAction::make('enterContract')->table($this->empty));

    expect(Contract::query()->where('matter_id', $this->empty->id)->exists())->toBeFalse()
        ->and(DB::table('code_sequences')->where('key', 'contract:'.now()->year)->value('last_number'))->toBe($sequenceBefore)
        ->and($this->lawyer->notifications()->count())->toBe(0);
});

it('asks for the signing date only when activating in one go', function () {
    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->callAction(TestAction::make('enterContract')->table($this->empty), data: oneInstalmentContract(extra: [
            'activate_now' => true,
            'signed_at' => null,
        ]))
        ->assertHasActionErrors(['signed_at' => 'required']);

    expect(Contract::query()->where('matter_id', $this->empty->id)->exists())->toBeFalse();
});

it('offers the stages of the matter type, but not its first stage, for a stage-triggered instalment', function () {
    $this->actingAs($this->accountant, 'web');

    $stages = $this->empty->matterType->stages()->orderBy('sort_order')->get();

    contractPage()
        ->callAction(TestAction::make('enterContract')->table($this->empty), data: [
            'total_amount' => '10.000.000',
            'instalments' => [
                ['name' => 'Khi sang bước hai', 'amount' => '10.000.000', 'trigger_type' => 'stage', 'trigger_stage_key' => $stages[1]->key, 'due_days_after_trigger' => '0'],
            ],
        ])
        ->assertHasNoActionErrors();

    expect(Contract::query()->where('matter_id', $this->empty->id)->sole()->instalments()->sole()->trigger_stage_key)->toBe($stages[1]->key);

    $other = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    contractPage()
        ->callAction(TestAction::make('enterContract')->table($other), data: [
            'total_amount' => '10.000.000',
            'instalments' => [
                ['name' => 'Khi tiếp nhận', 'amount' => '10.000.000', 'trigger_type' => 'stage', 'trigger_stage_key' => $stages[0]->key, 'due_days_after_trigger' => '0'],
            ],
        ])
        ->assertHasActionErrors(['instalments.0.trigger_stage_key']);
});

it('does not tell the lead lawyer about a contract they entered themselves', function () {
    $this->actingAs($this->lawyer, 'web');

    contractPage()
        ->callAction(TestAction::make('enterContract')->table($this->empty), data: oneInstalmentContract())
        ->assertHasNoActionErrors();

    expect(Contract::query()->where('matter_id', $this->empty->id)->exists())->toBeTrue()
        ->and($this->lawyer->notifications()->count())->toBe(0);
});

it('tells the lead lawyer when the manager enters the contract too, naming the manager', function () {
    $this->manager->forceFill(['name' => 'Trưởng Phòng Tuấn'])->save();

    $this->actingAs($this->manager, 'web');

    contractPage()
        ->callAction(TestAction::make('enterContract')->table($this->empty), data: oneInstalmentContract())
        ->assertHasNoActionErrors();

    expect($this->lawyer->notifications()->sole()->data['body'])->toStartWith('Trưởng phòng Trưởng Phòng Tuấn đã nhập hợp đồng');
});

it('falls back to a manager who sees the money when the lead lawyer was deactivated, never to the one who entered it', function () {
    $this->lawyer->forceFill(['is_active' => false])->save();

    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->callAction(TestAction::make('enterContract')->table($this->empty), data: oneInstalmentContract())
        ->assertHasNoActionErrors();

    expect($this->lawyer->notifications()->count())->toBe(0)
        ->and($this->manager->notifications()->count())->toBe(1)
        ->and($this->accountant->notifications()->count())->toBe(0);
});

// =================================================================================================
// Bản nháp: sửa, kích hoạt, xoá
// =================================================================================================

it('lets the accountant correct a draft, filling the form from the stored contract', function () {
    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->mountAction(TestAction::make('updateDraft')->table($this->drafted))
        ->assertSchemaStateSet(['total_amount' => '30.000.000'])
        ->setActionData(oneInstalmentContract('32.000.000', '32.000.000'))
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($this->draft->fresh()->total_amount)->toBe(32_000_000)
        ->and($this->draft->instalments()->sole()->amount)->toBe(32_000_000)
        ->and(Activity::query()->where('event', 'contract_draft_updated')->sole()->causer_id)->toBe($this->accountant->id);
});

it('lets the accountant activate a draft with the signing date, and tells the lead lawyer', function () {
    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->callAction(TestAction::make('activate')->table($this->drafted), data: ['signed_at' => today()->toDateString()])
        ->assertHasNoActionErrors();

    $draft = $this->draft->fresh();

    expect($draft->status)->toBe(ContractStatus::Active)
        ->and($draft->activated_by)->toBe($this->accountant->id)
        ->and($this->lawyer->notifications()->sole()->data['body'])->toBe(__('contract_entry.notifications.activated', [
            'who' => 'Kế toán Lan Kế Toán',
            'contract' => $draft->code,
            'matter' => $this->drafted->code,
            'date' => today()->format('d/m/Y'),
            'total' => Money::format(30_000_000),
            'count' => 1,
        ]));
});

it('reports a schedule that does not add up instead of activating', function () {
    Instalment::factory()->for($this->draft)->create(['sequence' => 2, 'amount' => 1_000_000]);

    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->callAction(TestAction::make('activate')->table($this->drafted), data: ['signed_at' => today()->toDateString()])
        ->assertNotified(__('actions.failed_title'));

    expect($this->draft->fresh()->status)->toBe(ContractStatus::Draft)
        ->and($this->lawyer->notifications()->count())->toBe(0);
});

it('lets the accountant delete a draft', function () {
    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->callAction(TestAction::make('deleteDraft')->table($this->drafted))
        ->assertHasNoActionErrors();

    expect(Contract::query()->whereKey($this->draft->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'contract_draft_deleted')->sole()->causer_id)->toBe($this->accountant->id);
});

it('shows each row only the buttons that fit its contract', function () {
    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->filterTable('contract_status', [])
        ->assertActionVisible(TestAction::make('enterContract')->table($this->empty))
        ->assertActionHidden(TestAction::make('updateDraft')->table($this->empty))
        ->assertActionHidden(TestAction::make('activate')->table($this->empty))
        ->assertActionHidden(TestAction::make('deleteDraft')->table($this->empty))
        ->assertActionHidden(TestAction::make('recordPayment')->table($this->empty))
        ->assertActionHidden(TestAction::make('enterContract')->table($this->drafted))
        ->assertActionVisible(TestAction::make('updateDraft')->table($this->drafted))
        ->assertActionVisible(TestAction::make('activate')->table($this->drafted))
        ->assertActionVisible(TestAction::make('deleteDraft')->table($this->drafted))
        ->assertActionHidden(TestAction::make('recordPayment')->table($this->drafted))
        ->assertActionHidden(TestAction::make('enterContract')->table($this->signed))
        ->assertActionHidden(TestAction::make('updateDraft')->table($this->signed))
        ->assertActionHidden(TestAction::make('activate')->table($this->signed))
        ->assertActionHidden(TestAction::make('deleteDraft')->table($this->signed))
        ->assertActionVisible(TestAction::make('recordPayment')->table($this->signed));
});

it('closes the whole page to the accountant once contract.enter is taken from the role', function () {
    // Máy chủ chưa chạy lại `RolesAndPermissionsSeeder` sau nâng cấp: vai kế toán chưa có quyền mới.
    $this->accountant->roles()->first()->revokePermissionTo(Permission::ContractEnter->value);

    $this->actingAs($this->accountant->fresh(), 'web')->get(ContractEntry::getUrl(panel: 'admin'))->assertNotFound();
});

it('links a signed contract to the receivables page filtered on its matter code', function () {
    $other = Matter::factory()->create();
    $otherContract = Contract::factory()->for($other)->active()->create(['total_amount' => 9_000_000]);
    $otherInstalment = Instalment::factory()->for($otherContract)->create(['amount' => 9_000_000, 'due_date' => today()->addDays(3)->toDateString()]);

    $this->actingAs($this->accountant, 'web');

    contractPage()
        ->filterTable('contract_status', [ContractStatus::Active->value])
        ->assertActionHasUrl(
            TestAction::make('recordPayment')->table($this->signed),
            Receivables::getUrl(['filters' => ['matter_code' => ['code' => $this->signed->code]]]),
        );

    // Không lọc thì cả hai đợt cùng có — bộ lọc mới là thứ loại đợt kia. (Trước `withQueryParams()`:
    // tham số đó ở lại cho mọi lần `test()` sau trong cùng một test.)
    $this->livewire(Receivables::class)->assertCanSeeTableRecords([$this->activeInstalment, $otherInstalment]);

    Livewire::withQueryParams(['filters' => ['matter_code' => ['code' => $this->signed->code]]])
        ->test(Receivables::class)
        ->assertCanSeeTableRecords([$this->activeInstalment])
        ->assertCanNotSeeTableRecords([$otherInstalment]);
});

it('does not offer the receivables link to a lawyer, who cannot open that page', function () {
    $this->actingAs($this->lawyer, 'web');

    contractPage()
        ->filterTable('contract_status', [ContractStatus::Active->value])
        ->assertActionHidden(TestAction::make('recordPayment')->table($this->signed));
});

// =================================================================================================
// Lối tắt: nút đầu trang Công nợ, ô trên trang chủ
// =================================================================================================

it('puts a shortcut to the contracts page at the top of the receivables page for those who can open it', function () {
    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->assertActionVisible('openContractEntry')
        ->assertActionHasUrl('openContractEntry', ContractEntry::getUrl());

    $reader = User::factory()->create();
    $reader->givePermissionTo([Permission::MatterViewAny->value, Permission::BillingView->value, Permission::RevenueViewAny->value]);
    $this->actingAs($reader, 'web');

    $this->livewire(Receivables::class)->assertActionHidden('openContractEntry');
});

it('counts the matters without a contract and the drafts on the accountant home page, linking to the filtered page', function () {
    $this->actingAs($this->accountant, 'web');

    $this->get(route('filament.admin.pages.dashboard'))->assertOk()->assertSeeLivewire(ContractsToEnterWidget::class);

    $stats = collect((fn () => $this->getStats())->call($this->livewire(ContractsToEnterWidget::class)->instance()));

    // Kế toán: vụ trống (empty) — không vụ hạn chế, không vụ đã huỷ; một bản nháp.
    expect($stats->map(fn ($stat) => $stat->getValue())->all())->toBe(['1', '1'])
        ->and($stats->map(fn ($stat) => $stat->getUrl())->all())->toBe([
            ContractEntry::getUrl(['filters' => ['contract_status' => ['values' => [ContractEntry::STATUS_NONE]]]]),
            ContractEntry::getUrl(['filters' => ['contract_status' => ['values' => [ContractStatus::Draft->value]]]]),
        ]);

    Livewire::withQueryParams(['filters' => ['contract_status' => ['values' => [ContractEntry::STATUS_NONE]]]])
        ->test(ContractEntry::class)
        ->assertCanSeeTableRecords([$this->empty])
        ->assertCanNotSeeTableRecords([$this->drafted, $this->signed]);
});

it('counts the restricted matter for the admin, who sees it', function () {
    $this->actingAs($this->admin, 'web');

    $stats = collect((fn () => $this->getStats())->call($this->livewire(ContractsToEnterWidget::class)->instance()));

    expect($stats->first()->getValue())->toBe('2');
});

it('keeps the home-page tiles off an account holding contract.enter that cannot open the page', function () {
    $blind = User::factory()->create();
    $blind->givePermissionTo([Permission::MatterViewAny->value, Permission::ContractEnter->value]);
    $sighted = User::factory()->create();
    $sighted->givePermissionTo([Permission::MatterViewAny->value, Permission::ContractEnter->value, Permission::BillingView->value]);

    $this->actingAs($blind, 'web');
    expect(ContractsToEnterWidget::canView())->toBeFalse();

    $this->actingAs($sighted, 'web');
    expect(ContractsToEnterWidget::canView())->toBeTrue();
});

it('asks the same split abilities on the matter tab: contract.enter edits and activates a draft there, but never amends, completes or cancels', function () {
    $member = User::factory()->create();
    $member->givePermissionTo([Permission::MatterView->value, Permission::BillingView->value, Permission::ContractEnter->value]);
    $this->drafted->addTeamMember($member, MatterRole::Associate);

    $this->actingAs($member, 'web');

    $this->livewire(BillingRelationManager::class, [
        'ownerRecord' => $this->drafted,
        'pageClass' => ViewMatter::class,
    ])
        ->assertActionVisible(TestAction::make('updateDraftContract')->table())
        ->assertActionVisible(TestAction::make('activateContract')->table())
        ->assertActionVisible(TestAction::make('deleteDraftContract')->table())
        ->assertActionHidden(TestAction::make('amendContract')->table())
        ->assertActionHidden(TestAction::make('completeContract')->table())
        ->assertActionHidden(TestAction::make('cancelContract')->table());
});

it('keeps the home-page tiles off the lawyer and manager home pages, and answers 404 when mounted', function () {
    foreach ([$this->lawyer, $this->manager, $this->assistant, $this->sales] as $user) {
        $this->actingAs($user, 'web');

        expect(ContractsToEnterWidget::canView())->toBeFalse();
        $this->livewire(ContractsToEnterWidget::class)->assertStatus(404);
    }

    $this->actingAs($this->admin, 'web');
    expect(ContractsToEnterWidget::canView())->toBeTrue();
});

it('carries each row through a final readonly DTO of exactly nine fields', function () {
    $class = new ReflectionClass(AccountantContractRow::class);

    expect($class->isFinal())->toBeTrue()
        ->and($class->isReadOnly())->toBeTrue()
        ->and(array_map(fn (ReflectionProperty $property): string => $property->getName(), $class->getProperties()))->toBe([
            'matterCode', 'matterTypeName', 'clientName', 'leadLawyerName',
            'contractCode', 'contractStatus', 'totalAmount', 'collected', 'outstanding',
        ]);

    $row = AccountantContractRow::fromMatter($this->signed->fresh(), 15_000_000, 25_000_000);

    expect($row->toArray())->toBe([
        'matter_code' => $this->signed->code,
        'matter_type_name' => $this->signed->matterType->name,
        'client_name' => $this->signed->client->name,
        'lead_lawyer_name' => 'Luật sư Phụ Trách Minh',
        'contract_code' => $this->active->code,
        'contract_status' => ContractStatus::Active->value,
        'total_amount' => 40_000_000,
        'collected' => 15_000_000,
        'outstanding' => 25_000_000,
    ]);
});
