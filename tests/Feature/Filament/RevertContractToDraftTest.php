<?php

use App\Actions\Billing\RevertContractToDraft;
use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Exceptions\ContractNotRevertible;
use App\Filament\Admin\Pages\ContractEntry;
use App\Filament\Admin\Resources\Matters\Pages\EditMatter;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Filament\Portal\Pages\MatterProgress;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fa3, mục 2 + 5 (kiểm tra luồng nội bộ 2026-10-16, phán quyết controller): "Đưa về bản nháp" cho một
 * hợp đồng kích hoạt nhầm (ngày ký, thuế suất, tổng) — chỉ khi chưa có khoản thu nào chưa huỷ, chưa có phụ
 * lục, chưa có đợt nào từng được miễn hay dời hạn; ai kích hoạt được thì đưa về được (`contract.manage` hoặc
 * `contract.enter`); lý do bắt buộc; nhật ký `contract_reverted_to_draft`; luật sư phụ trách được báo (không
 * báo chính người làm); cổng khách thôi hiện lộ trình của hợp đồng nháp. Có ở tab "Hợp đồng và thanh toán" và
 * trang "Hợp đồng". Mục 5: kế toán sửa/xoá bản nháp thì luật sư phụ trách được báo. Mọi hành vi qua màn hình.
 * Hàm toàn cục mang tiền tố `fa3Rv`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Phụ Trách Fa']);
    $this->accountant = User::factory()->withRole(Role::Accountant)->create(['name' => 'Kế Toán Fa']);
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id, 'is_published_to_portal' => true]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);

    // Kế toán gõ nhầm ngày ký 10/01 thay vì 01/10, rồi bật "kích hoạt luôn".
    $this->contract = Contract::factory()->for($this->matter)->create(['total_amount' => 50_000_000, 'vat_rate_percent' => 10]);
    $this->onSigning = Instalment::factory()->for($this->contract)->create([
        'sequence' => 1, 'name' => 'Tạm ứng khi ký', 'amount' => 20_000_000,
        'trigger_type' => InstalmentTrigger::OnSigning, 'due_days_after_trigger' => 5,
        'due_date' => '2026-01-15', 'triggered_at' => '2026-10-15 09:00:00',
    ]);
    $this->fixed = Instalment::factory()->for($this->contract)->create([
        'sequence' => 2, 'name' => 'Đợt cuối', 'amount' => 30_000_000,
        'trigger_type' => InstalmentTrigger::DueDate, 'due_date' => '2026-12-31',
    ]);
    $this->contract->forceFill(['status' => ContractStatus::Active, 'signed_at' => '2026-01-10', 'activated_by' => $this->accountant->id])->save();
});

const FA3_REVERT_REASON = 'Gõ nhầm ngày ký 10/01 thay vì 01/10, đưa về nháp để sửa.';

function fa3RvPage()
{
    return test()->livewire(ContractEntry::class);
}

function fa3RvTab(Matter $matter)
{
    return test()->livewire(BillingRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
}

function fa3RvRevertOnPage(Matter $matter, string $reason = FA3_REVERT_REASON)
{
    return fa3RvPage()
        ->filterTable('contract_status', [])
        ->callAction(TestAction::make('revertToDraft')->table($matter), data: ['reason' => $reason]);
}

// =================================================================================================
// Đưa về bản nháp: đường chính
// =================================================================================================

it('lets the accountant put a wrongly activated contract back to draft from the contracts page, with a reason', function () {
    $this->actingAs($this->accountant, 'web');

    fa3RvRevertOnPage($this->matter)->assertHasNoActionErrors();

    $contract = $this->contract->fresh();

    expect($contract->status)->toBe(ContractStatus::Draft)
        ->and($contract->signed_at)->toBeNull()
        ->and($contract->activated_by)->toBeNull()
        ->and($contract->total_amount)->toBe(50_000_000)
        ->and($contract->vat_rate_percent)->toEqual(10)
        // Đợt "khi ký" mất hạn đã tính từ ngày ký sai; đợt có ngày cố định giữ ngày của nó.
        ->and($this->onSigning->fresh()->due_date)->toBeNull()
        ->and($this->onSigning->fresh()->triggered_at)->toBeNull()
        ->and($this->fixed->fresh()->due_date->toDateString())->toBe('2026-12-31');

    $activity = Activity::query()->where('event', 'contract_reverted_to_draft')->sole();

    expect($activity->causer_id)->toBe($this->accountant->id)
        ->and($activity->subject_id)->toBe($this->contract->id)
        ->and($activity->properties->get('reason'))->toBe(FA3_REVERT_REASON)
        ->and($activity->properties->get('previous_signed_at'))->toBe('2026-01-10')
        ->and($activity->properties->get('previous_activated_by'))->toBe($this->accountant->id);
});

it('then lets the accountant correct the draft and activate it again with the right signing date', function () {
    $this->actingAs($this->accountant, 'web');

    fa3RvRevertOnPage($this->matter)->assertHasNoActionErrors();

    fa3RvPage()
        ->assertActionVisible(TestAction::make('updateDraft')->table($this->matter))
        ->assertActionHidden(TestAction::make('revertToDraft')->table($this->matter))
        ->callAction(TestAction::make('activate')->table($this->matter), data: ['signed_at' => '2026-10-01'])
        ->assertHasNoActionErrors();

    expect($this->contract->fresh()->status)->toBe(ContractStatus::Active)
        ->and($this->contract->fresh()->signed_at->toDateString())->toBe('2026-10-01')
        ->and($this->onSigning->fresh()->due_date->toDateString())->toBe('2026-10-06');
});

it('tells the lead lawyer, naming who did it, and nobody when the lead lawyer does it on their own matter', function () {
    $this->actingAs($this->accountant, 'web');
    fa3RvRevertOnPage($this->matter)->assertHasNoActionErrors();

    $bell = $this->lawyer->notifications()->sole();

    expect($bell->data['title'])->toBe(__('contract_entry.notifications.titles.reverted', ['matter' => $this->matter->code]))
        ->and($bell->data['body'])->toBe(__('contract_entry.notifications.reverted', [
            'who' => 'Kế toán Kế Toán Fa',
            'contract' => $this->contract->code,
            'matter' => $this->matter->code,
            'total' => Money::format(50_000_000),
            'count' => 2,
            'date' => '—',
        ]))
        ->and($bell->data['body'])->not->toContain(FA3_REVERT_REASON)
        ->and($this->accountant->notifications()->count())->toBe(0);

    // Luật sư phụ trách tự kích hoạt lại rồi tự đưa về nháp trên tab của vụ: không ai nhận chuông.
    $this->contract->fresh()->forceFill(['status' => ContractStatus::Active, 'signed_at' => '2026-10-01'])->saveQuietly();
    $this->lawyer->notifications()->delete();

    $this->actingAs($this->lawyer, 'web');
    fa3RvTab($this->matter)
        ->callAction(TestAction::make('revertContractToDraft')->table(), data: ['reason' => FA3_REVERT_REASON])
        ->assertHasNoActionErrors();

    expect($this->contract->fresh()->status)->toBe(ContractStatus::Draft)
        ->and($this->lawyer->notifications()->count())->toBe(0)
        ->and($this->manager->notifications()->count())->toBe(0);
});

it('offers the action on the matter tab only on a signed contract, and only to those who may activate one', function () {
    $this->actingAs($this->lawyer, 'web');
    fa3RvTab($this->matter)->assertActionVisible(TestAction::make('revertContractToDraft')->table());

    $this->actingAs($this->assistant, 'web');
    fa3RvTab($this->matter)->assertActionHidden(TestAction::make('revertContractToDraft')->table());

    $draftMatter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    Contract::factory()->for($draftMatter)->create(['status' => ContractStatus::Draft]);

    $this->actingAs($this->lawyer, 'web');
    fa3RvTab($draftMatter)->assertActionHidden(TestAction::make('revertContractToDraft')->table());
});

it('shows the button on the contracts page only on a signed contract row', function () {
    $draftMatter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    Contract::factory()->for($draftMatter)->create(['status' => ContractStatus::Draft]);
    $empty = Matter::factory()->create();

    $this->actingAs($this->accountant, 'web');

    fa3RvPage()
        ->filterTable('contract_status', [])
        ->assertActionVisible(TestAction::make('revertToDraft')->table($this->matter))
        ->assertActionHidden(TestAction::make('revertToDraft')->table($draftMatter))
        ->assertActionHidden(TestAction::make('revertToDraft')->table($empty));
});

it('requires a real reason, and changes nothing without one', function () {
    $this->actingAs($this->accountant, 'web');

    fa3RvRevertOnPage($this->matter, 'nhầm')->assertHasActionErrors(['reason']);

    expect($this->contract->fresh()->status)->toBe(ContractStatus::Active)
        ->and(Activity::query()->where('event', 'contract_reverted_to_draft')->exists())->toBeFalse()
        ->and($this->lawyer->notifications()->count())->toBe(0);
});

it('stops showing the contract and its schedule on the client portal once it is a draft again', function () {
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->matter->client_id]);
    $url = MatterProgress::getUrl(['record' => $this->matter->getKey()], panel: 'portal');

    $this->actingAs($clientUser, 'client')->get($url)->assertOk()->assertSee($this->contract->code)->assertSee('Tạm ứng khi ký');

    Filament::setCurrentPanel('admin');
    $this->actingAs($this->accountant, 'web');
    fa3RvRevertOnPage($this->matter)->assertHasNoActionErrors();

    $this->actingAs($clientUser, 'client')->get($url)->assertOk()->assertDontSee($this->contract->code)->assertDontSee('Tạm ứng khi ký');
});

// =================================================================================================
// Khi nào KHÔNG đưa về nháp được — Action từ chối, hộp thoại nói vì sao
// =================================================================================================

it('refuses while a payment that is not voided sits on the contract, and says to void it first', function () {
    Payment::factory()->for($this->onSigning)->create(['amount' => 15_000_000, 'paid_on' => '2026-10-05']);

    $this->actingAs($this->accountant, 'web');

    fa3RvRevertOnPage($this->matter);

    FilamentNotification::assertNotified(FilamentNotification::make()->title(__('actions.failed_title'))
        ->body(__('billing_corrections.revert.has_payments', ['code' => $this->contract->code, 'amount' => Money::format(15_000_000)]))
        ->danger()->persistent());

    expect($this->contract->fresh()->status)->toBe(ContractStatus::Active)
        ->and($this->lawyer->notifications()->count())->toBe(0);
});

it('allows it once the only payment has been voided, keeps the voided payment, and lets the draft be corrected', function () {
    $voided = Payment::factory()->for($this->onSigning)->voided()->create(['amount' => 15_000_000, 'paid_on' => '2026-10-05']);

    $this->actingAs($this->accountant, 'web');

    fa3RvRevertOnPage($this->matter)->assertHasNoActionErrors();

    expect($this->contract->fresh()->status)->toBe(ContractStatus::Draft);

    // Sửa bản nháp (đổi thuế suất, giữ hai đợt): đợt mang lịch sử khoản thu đã huỷ được giữ, không xoá.
    fa3RvPage()
        ->callAction(TestAction::make('updateDraft')->table($this->matter), data: [
            'total_amount' => '50.000.000',
            'vat_rate_percent' => '8',
            'instalments' => [
                ['name' => 'Tạm ứng khi ký', 'amount' => '20.000.000', 'trigger_type' => 'on_signing', 'due_days_after_trigger' => '5'],
                ['name' => 'Đợt cuối', 'amount' => '30.000.000', 'trigger_type' => 'due_date', 'due_date' => '2026-12-31'],
            ],
        ])
        ->assertHasNoActionErrors();

    expect($this->contract->fresh()->vat_rate_percent)->toEqual(8)
        ->and($voided->fresh()->instalment_id)->toBe($this->onSigning->id)
        ->and($this->onSigning->fresh()->name)->toBe('Tạm ứng khi ký')
        ->and(Instalment::query()->where('contract_id', $this->contract->id)->count())->toBe(2);
});

it('refuses to drop an instalment that still carries voided payment history when correcting the draft', function () {
    Payment::factory()->for($this->fixed)->voided()->create(['amount' => 15_000_000]);

    $this->actingAs($this->accountant, 'web');
    fa3RvRevertOnPage($this->matter)->assertHasNoActionErrors();

    // Còn một đợt: đợt thứ hai (mang lịch sử khoản thu đã huỷ) bị bỏ — từ chối, không xoá gì.
    $component = fa3RvPage()
        ->callAction(TestAction::make('updateDraft')->table($this->matter), data: [
            'total_amount' => '50.000.000',
            'vat_rate_percent' => '10',
            'instalments' => [
                ['name' => 'Trọn gói', 'amount' => '50.000.000', 'trigger_type' => 'on_signing', 'due_days_after_trigger' => '5'],
            ],
        ]);

    $component->assertNotified(__('actions.failed_title'));

    expect(Instalment::query()->where('contract_id', $this->contract->id)->count())->toBe(2);
});

it('says why, instead of dropping the click, when the contract was already put back to draft in the matter tab meanwhile', function () {
    $this->actingAs($this->accountant, 'web');

    $page = fa3RvPage()
        ->filterTable('contract_status', [])
        ->mountAction(TestAction::make('revertToDraft')->table($this->matter));

    // Luật sư phụ trách đưa về nháp ở tab của vụ trong lúc hộp thoại của kế toán còn mở.
    $this->actingAs($this->lawyer, 'web');
    fa3RvTab($this->matter)
        ->callAction(TestAction::make('revertContractToDraft')->table(), data: ['reason' => FA3_REVERT_REASON])
        ->assertHasNoActionErrors();

    $this->actingAs($this->accountant, 'web');
    $page->setActionData(['reason' => FA3_REVERT_REASON])->callMountedAction();

    FilamentNotification::assertNotified(FilamentNotification::make()->title(__('actions.failed_title'))
        ->body(__('billing_corrections.revert.not_active', ['code' => $this->contract->code, 'status' => ContractStatus::Draft->label()]))
        ->danger()->persistent());

    expect(Activity::query()->where('event', 'contract_reverted_to_draft')->count())->toBe(1);
});

it('refuses once an amendment has been signed', function () {
    ContractAmendment::factory()->create(['contract_id' => $this->contract->id, 'previous_total_amount' => 40_000_000, 'new_total_amount' => 50_000_000]);

    $this->actingAs($this->accountant, 'web');

    fa3RvRevertOnPage($this->matter)->assertNotified(__('actions.failed_title'));

    expect($this->contract->fresh()->status)->toBe(ContractStatus::Active);
});

it('refuses once an instalment has been waived — now or ever — or rescheduled', function (string $history) {
    match ($history) {
        'waived' => $this->fixed->forceFill(['status' => InstalmentStatus::Waived, 'waived_reason' => str_repeat('a', 20), 'waived_at' => now()])->saveQuietly(),
        'unwaived' => Audit::record('instalment_waived', $this->fixed, ['reason' => str_repeat('a', 20)], $this->lawyer),
        'rescheduled' => Audit::record('instalment_rescheduled', $this->fixed, ['from' => '2026-11-30', 'to' => '2026-12-31'], $this->lawyer),
    };

    $this->actingAs($this->accountant, 'web');

    fa3RvRevertOnPage($this->matter)->assertNotified(__('actions.failed_title'));

    expect($this->contract->fresh()->status)->toBe(ContractStatus::Active);
})->with(['waived', 'unwaived', 'rescheduled']);

it('does not count the history of another contract against this one', function () {
    $other = Contract::factory()->for(Matter::factory()->create())->active()->create(['total_amount' => 5_000_000]);
    $otherInstalment = Instalment::factory()->for($other)->create(['amount' => 5_000_000]);
    Audit::record('instalment_rescheduled', $otherInstalment, ['from' => '2026-11-30', 'to' => '2026-12-31'], $this->lawyer);
    Payment::factory()->for($otherInstalment)->create(['amount' => 5_000_000]);

    $this->actingAs($this->accountant, 'web');

    fa3RvRevertOnPage($this->matter)->assertHasNoActionErrors();

    expect($this->contract->fresh()->status)->toBe(ContractStatus::Draft);
});

it('never offers the action on a restricted matter to the accountant, who does not see it', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);
    Contract::factory()->for($restricted)->active()->create();

    $this->actingAs($this->accountant, 'web');

    fa3RvPage()->filterTable('contract_status', [])->assertCanNotSeeTableRecords([$restricted]);
});

// =================================================================================================
// Mục 5: kế toán sửa / xoá bản nháp của luật sư → luật sư phụ trách được báo
// =================================================================================================

it('tells the lead lawyer when the accountant corrects their draft, and nobody when the lead lawyer corrects it', function () {
    $this->contract->forceFill(['status' => ContractStatus::Draft, 'signed_at' => null])->saveQuietly();

    $this->actingAs($this->accountant, 'web');
    fa3RvPage()
        ->callAction(TestAction::make('updateDraft')->table($this->matter), data: [
            'total_amount' => '50.000.000',
            'vat_rate_percent' => '8',
            'instalments' => [
                ['name' => 'Trọn gói', 'amount' => '50.000.000', 'trigger_type' => 'on_signing', 'due_days_after_trigger' => '5'],
            ],
        ])
        ->assertHasNoActionErrors();

    $bell = $this->lawyer->notifications()->sole();

    expect($bell->data['title'])->toBe(__('contract_entry.notifications.titles.draft_updated', ['matter' => $this->matter->code]))
        ->and($bell->data['body'])->toBe(__('contract_entry.notifications.draft_updated', [
            'who' => 'Kế toán Kế Toán Fa',
            'contract' => $this->contract->code,
            'matter' => $this->matter->code,
            'total' => Money::format(50_000_000),
            'count' => 1,
            'date' => '—',
        ]));

    $this->lawyer->notifications()->delete();

    $this->actingAs($this->lawyer, 'web');
    fa3RvTab($this->matter)
        ->callAction(TestAction::make('updateDraftContract')->table(), data: [
            'total_amount' => '50.000.000',
            'vat_rate_percent' => '10',
            'instalments' => [
                ['name' => 'Trọn gói', 'amount' => '50.000.000', 'trigger_type' => 'on_signing', 'due_days_after_trigger' => '5'],
            ],
        ])
        ->assertHasNoActionErrors();

    expect($this->lawyer->notifications()->count())->toBe(0);
});

it('tells the lead lawyer when the accountant deletes their draft, with the code and the schedule that was deleted', function () {
    $this->contract->forceFill(['status' => ContractStatus::Draft, 'signed_at' => null])->saveQuietly();

    $this->actingAs($this->accountant, 'web');
    fa3RvPage()->callAction(TestAction::make('deleteDraft')->table($this->matter))->assertHasNoActionErrors();

    expect(Contract::query()->whereKey($this->contract->id)->exists())->toBeFalse();

    $bell = $this->lawyer->notifications()->sole();

    expect($bell->data['title'])->toBe(__('contract_entry.notifications.titles.draft_deleted', ['matter' => $this->matter->code]))
        ->and($bell->data['body'])->toBe(__('contract_entry.notifications.draft_deleted', [
            'who' => 'Kế toán Kế Toán Fa',
            'contract' => $this->contract->code,
            'matter' => $this->matter->code,
            'total' => Money::format(50_000_000),
            'count' => 2,
            'date' => '—',
        ]));
});

it('tells nobody when the lead lawyer deletes their own draft', function () {
    $this->contract->forceFill(['status' => ContractStatus::Draft, 'signed_at' => null])->saveQuietly();

    $this->actingAs($this->lawyer, 'web');
    fa3RvTab($this->matter)->callAction(TestAction::make('deleteDraftContract')->table())->assertHasNoActionErrors();

    expect(Contract::query()->whereKey($this->contract->id)->exists())->toBeFalse()
        ->and($this->lawyer->notifications()->count())->toBe(0)
        ->and($this->manager->notifications()->count())->toBe(0);
});

// =================================================================================================
// Fix round 1 (m5, m6, m8)
// =================================================================================================

it('m5: refuses on a matter that has ended, with a clear message, and changes nothing', function () {
    // Fix round 2 (mB): nút không còn hiện trên hồ sơ đã kết thúc — lời từ chối của Action là cho hộp thoại đã mở
    // trước khi hồ sơ được đóng ở nơi khác.
    $this->actingAs($this->accountant, 'web');

    $page = fa3RvPage()
        ->filterTable('contract_status', [])
        ->mountAction(TestAction::make('revertToDraft')->table($this->matter));

    $this->matter->forceFill(['closed_at' => '2026-10-10'])->saveQuietly();

    $page->setActionData(['reason' => FA3_REVERT_REASON])->callMountedAction();

    FilamentNotification::assertNotified(FilamentNotification::make()->title(__('actions.failed_title'))
        ->body(__('billing_corrections.revert.matter_closed', ['code' => $this->contract->code, 'matter' => $this->matter->code]))
        ->danger()->persistent());

    expect($this->contract->fresh()->status)->toBe(ContractStatus::Active)
        ->and($this->contract->fresh()->signed_at->toDateString())->toBe('2026-01-10')
        ->and(Activity::query()->where('event', 'contract_reverted_to_draft')->exists())->toBeFalse()
        ->and($this->lawyer->notifications()->count())->toBe(0);
});

it('m5: the dialog warns that reminders and mails already sent to the client cannot be recalled', function () {
    $this->actingAs($this->accountant, 'web');

    $description = (string) fa3RvPage()
        ->filterTable('contract_status', [])
        ->mountAction(TestAction::make('revertToDraft')->table($this->matter))
        ->instance()->getMountedAction()->getModalDescription();

    expect($description)->toBe(__('billing_corrections.revert.description', ['code' => $this->contract->code]))
        ->and($description)->toContain('không thu hồi được');

    $this->actingAs($this->lawyer, 'web');

    $tabDescription = (string) fa3RvTab($this->matter)
        ->mountAction(TestAction::make('revertContractToDraft')->table())
        ->instance()->getMountedAction()->getModalDescription();

    expect($tabDescription)->toBe($description);
});

it('m6: hides "delete draft" on a draft that carries voided payment history, on the contracts page and on the matter tab', function () {
    Payment::factory()->for($this->onSigning)->voided()->create(['amount' => 15_000_000, 'paid_on' => '2026-10-05']);

    $this->actingAs($this->accountant, 'web');
    fa3RvRevertOnPage($this->matter)->assertHasNoActionErrors();

    fa3RvPage()
        ->filterTable('contract_status', [])
        ->assertActionVisible(TestAction::make('updateDraft')->table($this->matter))
        ->assertActionHidden(TestAction::make('deleteDraft')->table($this->matter));

    $this->actingAs($this->lawyer, 'web');
    fa3RvTab($this->matter)
        ->assertActionVisible(TestAction::make('updateDraftContract')->table())
        ->assertActionHidden(TestAction::make('deleteDraftContract')->table());

    // Đối chứng: bản nháp không mang lịch sử nào vẫn có nút xoá ở cả hai nơi.
    $clean = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    Contract::factory()->for($clean)->create(['status' => ContractStatus::Draft]);

    fa3RvTab($clean)->assertActionVisible(TestAction::make('deleteDraftContract')->table());

    $this->actingAs($this->accountant, 'web');
    fa3RvPage()->filterTable('contract_status', [])->assertActionVisible(TestAction::make('deleteDraft')->table($clean));
});

/** Đúng lịch thu của `beforeEach` dưới dạng dữ liệu của form "Sửa bản nháp". */
function fa3RvSameDraftData(): array
{
    return [
        'total_amount' => '50.000.000',
        'vat_rate_percent' => '10',
        'instalments' => [
            ['name' => 'Tạm ứng khi ký', 'amount' => '20.000.000', 'trigger_type' => 'on_signing', 'due_days_after_trigger' => '5'],
            ['name' => 'Đợt cuối', 'amount' => '30.000.000', 'trigger_type' => 'due_date', 'due_date' => '2026-12-31'],
        ],
    ];
}

it('m8: no bell when the draft is saved again without a material change (same amounts, dates, tax and schedule)', function () {
    $this->contract->forceFill(['status' => ContractStatus::Draft, 'signed_at' => null])->saveQuietly();

    $this->actingAs($this->accountant, 'web');

    // Lưu lại y nguyên, rồi chỉ đổi ghi chú của một đợt và tên một đợt: không phải thay đổi về tiền — không chuông.
    fa3RvPage()->callAction(TestAction::make('updateDraft')->table($this->matter), data: fa3RvSameDraftData())->assertHasNoActionErrors();

    $renamed = fa3RvSameDraftData();
    $renamed['instalments'][1]['note'] = 'Ghi chú đợt mới';
    $renamed['instalments'][0]['name'] = 'Tạm ứng (đổi tên)';

    fa3RvPage()->callAction(TestAction::make('updateDraft')->table($this->matter), data: $renamed)->assertHasNoActionErrors();

    expect($this->lawyer->notifications()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'contract_draft_updated')->count())->toBe(2)
        ->and(Instalment::query()->where('contract_id', $this->contract->id)->where('sequence', 2)->value('note'))->toBe('Ghi chú đợt mới');
});

it('m8: a bell for each material change — total, tax, an instalment amount, a due date, the trigger, the number of instalments', function (Closure $change) {
    $this->contract->forceFill(['status' => ContractStatus::Draft, 'signed_at' => null])->saveQuietly();

    $this->actingAs($this->accountant, 'web');
    fa3RvPage()->callAction(TestAction::make('updateDraft')->table($this->matter), data: $change(fa3RvSameDraftData()))->assertHasNoActionErrors();

    expect($this->lawyer->notifications()->count())->toBe(1);
})->with([
    'total' => [fn (array $d): array => [...$d, 'total_amount' => '55.000.000']],
    'tax' => [fn (array $d): array => [...$d, 'vat_rate_percent' => '8']],
    'instalment amount' => [function (array $d): array {
        $d['instalments'][0]['amount'] = '25.000.000';

        return $d;
    }],
    'due date' => [function (array $d): array {
        $d['instalments'][1]['due_date'] = '2026-12-15';

        return $d;
    }],
    'days after trigger' => [function (array $d): array {
        $d['instalments'][0]['due_days_after_trigger'] = '7';

        return $d;
    }],
    'trigger' => [function (array $d): array {
        $d['instalments'][0] = ['name' => 'Tạm ứng khi ký', 'amount' => '20.000.000', 'trigger_type' => 'due_date', 'due_date' => '2026-11-01'];

        return $d;
    }],
    'number of instalments' => [function (array $d): array {
        $d['instalments'] = [['name' => 'Trọn gói', 'amount' => '50.000.000', 'trigger_type' => 'on_signing', 'due_days_after_trigger' => '5']];

        return $d;
    }],
]);

// =================================================================================================
// Fix round 2 (I-B, mB, mD, mE)
// =================================================================================================

/** Hợp đồng đang hiệu lực, chưa có gì xảy ra, của một vụ mới — kích hoạt bởi `$activator`. */
function fa3RvActiveContract(User $lead, ?User $activator): Contract
{
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    $contract = Contract::factory()->for($matter)->create(['total_amount' => 10_000_000]);
    Instalment::factory()->for($contract)->create([
        'sequence' => 1, 'name' => 'Trọn gói', 'amount' => 10_000_000,
        'trigger_type' => InstalmentTrigger::DueDate, 'due_date' => '2026-12-31',
    ]);
    $contract->forceFill(['status' => ContractStatus::Active, 'signed_at' => '2026-10-01', 'activated_by' => $activator?->id])->save();

    return $contract;
}

function fa3RvNotOwnRefusal(Contract $contract): string
{
    return __('billing_corrections.revert.not_own_activation', [
        'code' => $contract->code,
        'who' => $contract->activator?->name ?? __('billing_corrections.revert.unknown_activator'),
    ]);
}

it('I-B: the accountant (contract.enter only) puts back to draft only a contract they activated — the button is hidden on one the lawyer activated', function () {
    $byLawyer = fa3RvActiveContract($this->lawyer, $this->lawyer);
    $byNobody = fa3RvActiveContract($this->lawyer, null);

    $this->actingAs($this->accountant, 'web');

    fa3RvPage()
        ->filterTable('contract_status', [])
        ->assertActionVisible(TestAction::make('revertToDraft')->table($this->matter))
        ->assertActionHidden(TestAction::make('revertToDraft')->table($byLawyer->matter))
        ->assertActionHidden(TestAction::make('revertToDraft')->table($byNobody->matter));

    expect($this->accountant->can('revertToDraft', $this->contract))->toBeTrue()
        ->and($this->accountant->can('revertToDraft', $byLawyer))->toBeFalse()
        ->and($this->accountant->can('revertToDraft', $byNobody))->toBeFalse()
        // Kích hoạt (nhập tờ giấy đã ký) thì vẫn được như trước — giới hạn chỉ ở việc đưa về.
        ->and($this->accountant->can('activate', $byLawyer))->toBeTrue();
});

it('I-B: the Action refuses the accountant on a contract someone else activated, naming who, and changes nothing', function () {
    $byLawyer = fa3RvActiveContract($this->lawyer, $this->lawyer);

    expect(fn () => app(RevertContractToDraft::class)->handle($this->accountant, $byLawyer, FA3_REVERT_REASON))
        ->toThrow(ContractNotRevertible::class, fa3RvNotOwnRefusal($byLawyer));

    expect($byLawyer->fresh()->status)->toBe(ContractStatus::Active)
        ->and($byLawyer->fresh()->activated_by)->toBe($this->lawyer->id)
        ->and(Activity::query()->where('event', 'contract_reverted_to_draft')->exists())->toBeFalse()
        ->and($this->lawyer->notifications()->count())->toBe(0);
});

it('I-B: re-checks the activator on the locked row — a dialog opened on the accountant own activation is refused once the lawyer re-activated it meanwhile', function () {
    $this->actingAs($this->accountant, 'web');

    $page = fa3RvPage()
        ->filterTable('contract_status', [])
        ->mountAction(TestAction::make('revertToDraft')->table($this->matter));

    // Luật sư phụ trách đưa về nháp rồi kích hoạt lại ở tab của vụ trong lúc hộp thoại của kế toán còn mở.
    $this->actingAs($this->lawyer, 'web');
    fa3RvTab($this->matter)
        ->callAction(TestAction::make('revertContractToDraft')->table(), data: ['reason' => FA3_REVERT_REASON])
        ->assertHasNoActionErrors();
    fa3RvTab($this->matter)
        ->callAction(TestAction::make('activateContract')->table(), data: ['signed_at' => '2026-10-01'])
        ->assertHasNoActionErrors();

    expect($this->contract->fresh()->activated_by)->toBe($this->lawyer->id);

    $this->actingAs($this->accountant, 'web');
    $page->setActionData(['reason' => FA3_REVERT_REASON])->callMountedAction();

    FilamentNotification::assertNotified(FilamentNotification::make()->title(__('actions.failed_title'))
        ->body(fa3RvNotOwnRefusal($this->contract->fresh()))
        ->danger()->persistent());

    expect($this->contract->fresh()->status)->toBe(ContractStatus::Active)
        ->and(Activity::query()->where('event', 'contract_reverted_to_draft')->count())->toBe(1);
});

it('I-B: contract.manage reverts any eligible contract, whoever activated it — the manager on the contracts page and on the matter tab', function () {
    $byAccountantOnPage = fa3RvActiveContract($this->lawyer, $this->accountant);
    $byLawyerOnTab = fa3RvActiveContract($this->lawyer, $this->lawyer);

    $this->actingAs($this->manager, 'web');

    fa3RvRevertOnPage($byAccountantOnPage->matter)->assertHasNoActionErrors();

    fa3RvTab($byLawyerOnTab->matter)
        ->assertActionVisible(TestAction::make('revertContractToDraft')->table())
        ->callAction(TestAction::make('revertContractToDraft')->table(), data: ['reason' => FA3_REVERT_REASON])
        ->assertHasNoActionErrors();

    expect($byAccountantOnPage->fresh()->status)->toBe(ContractStatus::Draft)
        ->and($byLawyerOnTab->fresh()->status)->toBe(ContractStatus::Draft)
        // Luật sư (contract.manage) đưa về được hợp đồng kế toán kích hoạt (đường của beforeEach).
        ->and($this->lawyer->can('revertToDraft', $this->contract))->toBeTrue();
});

it('I-B: on the matter tab too, someone with contract.enter but not contract.manage sees the button only on their own activation', function () {
    $enterer = User::factory()->withRole(Role::Assistant)->create();
    $enterer->givePermissionTo(['billing.view', 'contract.enter']);

    $own = fa3RvActiveContract($this->lawyer, $enterer);
    $other = fa3RvActiveContract($this->lawyer, $this->lawyer);
    $own->matter->addTeamMember($enterer, MatterRole::Assistant);
    $other->matter->addTeamMember($enterer, MatterRole::Assistant);

    $this->actingAs($enterer, 'web');

    fa3RvTab($own->matter)->assertActionVisible(TestAction::make('revertContractToDraft')->table());
    fa3RvTab($other->matter)->assertActionHidden(TestAction::make('revertContractToDraft')->table());
});

it('mB: hides "put back to draft" on a matter that has ended, on the contracts page and on the matter tab', function () {
    $this->matter->forceFill(['closed_at' => '2026-10-10'])->saveQuietly();

    $this->actingAs($this->accountant, 'web');
    fa3RvPage()
        ->filterTable('contract_status', [])
        ->assertActionHidden(TestAction::make('revertToDraft')->table($this->matter));

    $this->actingAs($this->lawyer, 'web');
    fa3RvTab($this->matter)->assertActionHidden(TestAction::make('revertContractToDraft')->table());

    // Đối chứng: hồ sơ còn mở thì vẫn hiện ở cả hai nơi.
    $open = fa3RvActiveContract($this->lawyer, $this->accountant);
    fa3RvTab($open->matter)->assertActionVisible(TestAction::make('revertContractToDraft')->table());

    $this->actingAs($this->accountant, 'web');
    fa3RvPage()->filterTable('contract_status', [])->assertActionVisible(TestAction::make('revertToDraft')->table($open->matter));
});

it('mD: a reverted draft with voided payment history is undone by activating then cancelling it, after which the mistaken matter can be cancelled', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    Payment::factory()->for($this->onSigning)->voided()->create(['amount' => 15_000_000, 'paid_on' => '2026-10-05']);

    $this->actingAs($this->accountant, 'web');
    fa3RvRevertOnPage($this->matter)->assertHasNoActionErrors();

    expect(fn () => $this->contract->fresh()->delete())->toThrow(DomainException::class);

    $this->actingAs($this->lawyer, 'web');
    fa3RvTab($this->matter)
        ->callAction(TestAction::make('activateContract')->table(), data: ['signed_at' => '2026-10-01'])
        ->assertHasNoActionErrors();
    fa3RvTab($this->matter)
        ->callAction(TestAction::make('cancelContract')->table(), data: ['reason' => 'Hợp đồng nhập nhầm hồ sơ, huỷ để nhập lại đúng hồ sơ.'])
        ->assertHasNoActionErrors();

    expect($this->contract->fresh()->status)->toBe(ContractStatus::Cancelled);

    $this->actingAs($admin, 'web');
    $this->livewire(EditMatter::class, ['record' => $this->matter->getKey()])
        ->callAction('cancelMatter', data: ['reason' => 'Mở nhầm khách hàng, mở lại hồ sơ đúng.'])
        ->assertHasNoActionErrors();

    expect(Matter::withTrashed()->find($this->matter->id)->trashed())->toBeTrue();
});

it('mE: the contracts page does not ask for payment history once per draft row', function () {
    $drafts = function (int $count): void {
        foreach (range(1, $count) as $ignored) {
            Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]))->create(['status' => ContractStatus::Draft]);
        }
    };

    $this->actingAs($this->accountant, 'web');

    $paymentQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        fa3RvPage()->assertOk();
        $count = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'payments'))
            ->count();
        DB::disableQueryLog();

        return $count;
    };

    $drafts(1);
    $withOne = $paymentQueries();
    $drafts(3);

    expect($paymentQueries())->toBe($withOne);
});

it('I-B: the Action still refuses someone who sees the money but holds neither contract.manage nor contract.enter', function () {
    $this->assistant->givePermissionTo('billing.view');

    expect(fn () => app(RevertContractToDraft::class)->handle($this->assistant, $this->contract, FA3_REVERT_REASON))
        ->toThrow(AuthorizationException::class);

    expect($this->contract->fresh()->status)->toBe(ContractStatus::Active);
});
