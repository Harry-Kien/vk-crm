<?php

use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Enums\Role;
use App\Exceptions\MatterHasRecordedPayments;
use App\Filament\Admin\Pages\Performance;
use App\Filament\Admin\Pages\RevenueDashboard;
use App\Filament\Admin\Resources\Matters\Pages\EditMatter;
use App\Filament\Admin\Widgets\Billing\RecentPaymentsWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueByStageWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueKpiWidget;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\MatterOpenWork;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fa3, mục 1 (kiểm tra luồng nội bộ 2026-10-16, phán quyết controller): tiền đã về là sự thật kế
 * toán. "Huỷ hồ sơ mở nhầm" TỪ CHỐI khi vụ còn khoản thu chưa huỷ trên bất kỳ hợp đồng nào, hộp thoại
 * nói điều đó trước khi bấm; và tiền đã về của một vụ đã huỷ từ TRƯỚC luật này vẫn được tính ở Doanh thu,
 * Hiệu suất và "Khoản thu gần đây" (kèm nhãn hồ sơ đã huỷ), không lộ vụ hạn chế. Mọi hành vi qua màn hình.
 * Hàm toàn cục mang tiền tố `fa3Cm`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Fa Ba']);
});

const FA3_CANCEL_REASON = 'Mở nhầm khách hàng, mở lại hồ sơ đúng.';

/** Vụ có hợp đồng `$status` một đợt 10 triệu, và một khoản thu `$paid` (0 = không có khoản thu). */
function fa3CmMatter(ContractStatus $status, int $paid, array $matter = [], bool $voided = false, string $paidOn = '2026-10-05'): Matter
{
    $record = Matter::factory()->create($matter);
    $contract = Contract::factory()->for($record)->active()->create(['total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($contract)->create([
        'amount' => 10_000_000,
        'due_date' => '2026-10-01',
        'status' => $paid >= 10_000_000 && ! $voided ? InstalmentStatus::Paid : InstalmentStatus::Pending,
    ]);

    if ($paid > 0) {
        $payment = Payment::factory()->for($instalment);

        ($voided ? $payment->voided() : $payment)->create([
            'amount' => $paid,
            'paid_on' => $paidOn,
            'attributed_lawyer_id' => $record->lead_lawyer_id,
        ]);
    }

    if ($status !== ContractStatus::Active) {
        $contract->forceFill(['status' => $status, 'ended_at' => '2026-10-06', 'ended_reason' => str_repeat('a', 20)])->saveQuietly();
    }

    return $record;
}

/** Vụ đã huỷ TRƯỚC luật này (dữ liệu cũ): xoá mềm thẳng, không qua `CancelMatter`. */
function fa3CmLegacyCancel(Matter $matter): Matter
{
    Matter::query()->whereKey($matter->getKey())->update(['deleted_at' => now()->subDay()]);

    return $matter;
}

/** @return list<string> */
function fa3CmDialogTexts($component): array
{
    $formName = $component->instance()->getMountedActionSchemaName();
    /** @var Schema $schema */
    $schema = $component->instance()->{$formName};

    return collect($schema->getFlatComponents())
        ->filter(fn ($c) => $c instanceof Text && $c->isVisible())
        ->map(fn ($c) => (string) $c->getContent())
        ->values()
        ->all();
}

function fa3CmRefusal(Matter $matter, int $count, int $amount): string
{
    return __('actions.cancel_matter.payments_recorded', [
        'code' => $matter->code,
        'count' => $count,
        'amount' => Money::format($amount),
    ]);
}

// =================================================================================================
// Huỷ hồ sơ còn khoản thu: từ chối, nói cách làm
// =================================================================================================

it('refuses to cancel a matter whose contract is fully paid, on the reason field, naming the payments and the way out', function () {
    $matter = fa3CmMatter(ContractStatus::Active, 10_000_000);

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->callAction('cancelMatter', data: ['reason' => FA3_CANCEL_REASON])
        ->assertHasActionErrors(['reason']);

    expect(Matter::withTrashed()->find($matter->id)->trashed())->toBeFalse()
        ->and(Activity::query()->where('event', 'matter_cancelled')->exists())->toBeFalse();
});

it('says exactly how many payments and how much, and that the accountant voids them and records them on the right matter first', function () {
    $matter = fa3CmMatter(ContractStatus::Active, 10_000_000);

    $this->actingAs($this->admin, 'web');

    $component = $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->callAction('cancelMatter', data: ['reason' => FA3_CANCEL_REASON]);

    $errors = collect($component->errors()->toArray())->flatten()->all();

    expect($errors)->toBe([fa3CmRefusal($matter, 1, 10_000_000)])
        ->and($errors[0])->toContain('1 khoản thu')
        ->toContain(Money::format(10_000_000))
        ->toContain('huỷ các khoản thu này')
        ->toContain('ghi lại vào đúng hồ sơ');
});

it('refuses a matter whose payment sits on a cancelled contract — the money is still money', function () {
    $matter = fa3CmMatter(ContractStatus::Cancelled, 4_000_000);

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->callAction('cancelMatter', data: ['reason' => FA3_CANCEL_REASON])
        ->assertHasActionErrors(['reason']);

    expect(Matter::withTrashed()->find($matter->id)->trashed())->toBeFalse();
});

it('cancels once every payment of the matter has been voided and the contract cancelled', function () {
    $matter = fa3CmMatter(ContractStatus::Cancelled, 4_000_000, voided: true);

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->callAction('cancelMatter', data: ['reason' => FA3_CANCEL_REASON])
        ->assertHasNoActionErrors();

    expect(Matter::withTrashed()->find($matter->id)->trashed())->toBeTrue();
});

it('warns on the cancel dialog, before the click, that the payments block the cancellation', function () {
    $paid = fa3CmMatter(ContractStatus::Active, 10_000_000);
    $clean = Matter::factory()->create();

    $this->actingAs($this->admin, 'web');

    $texts = fa3CmDialogTexts($this->livewire(EditMatter::class, ['record' => $paid->getKey()])->mountAction('cancelMatter'));
    $cleanTexts = fa3CmDialogTexts($this->livewire(EditMatter::class, ['record' => $clean->getKey()])->mountAction('cancelMatter'));

    expect($texts)->toContain(fa3CmRefusal($paid, 1, 10_000_000))
        ->and(implode("\n", $cleanTexts))->not->toContain(Money::format(10_000_000))
        ->and(implode("\n", $cleanTexts))->not->toContain('khoản thu');
});

it('no longer tells the admin to collect the rest or waive it before cancelling a mistaken matter — only to cancel the contract', function () {
    $text = __('actions.cancel_matter.outstanding_balance', ['code' => 'VK-1', 'amount' => '1 ₫', 'count' => 1]);

    expect($text)->not->toContain('thu nốt')
        ->and($text)->not->toContain('miễn')
        ->and($text)->toContain('huỷ hợp đồng');
});

// -------------------------------------------------------------------------------------------------
// Fix round 1 (I1, m7, m9)
// -------------------------------------------------------------------------------------------------

function fa3CmCompletedRefusal(Matter $matter, int $count, int $amount): string
{
    return __('actions.cancel_matter.payments_on_completed', [
        'code' => $matter->code,
        'contract' => $matter->contracts()->sole()->code,
        'count' => $count,
        'amount' => Money::format($amount),
    ]);
}

it('I1: says honestly that a matter whose payments sit on a COMPLETED contract cannot be cancelled — not "void the payments", which the system refuses there', function () {
    $matter = fa3CmMatter(ContractStatus::Completed, 10_000_000);

    $this->actingAs($this->admin, 'web');

    $component = $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->callAction('cancelMatter', data: ['reason' => FA3_CANCEL_REASON])
        ->assertHasActionErrors(['reason']);

    $errors = collect($component->errors()->toArray())->flatten()->all();

    expect($errors)->toBe([fa3CmCompletedRefusal($matter, 1, 10_000_000)])
        ->and($errors[0])->toContain($matter->contracts()->sole()->code)
        ->toContain('đã hoàn tất')
        ->toContain('đóng hồ sơ')
        // Fix round 2 (I-A): đóng hồ sơ thôi chưa đủ — hồ sơ đã công bố vẫn hiện (cùng hợp đồng và khoản thu)
        // trên cổng của khách đang gắn nhầm, và chuyển sang giai đoạn kết thúc có công bố thì gửi thư cho khách đó.
        ->toContain('"'.__('matters.actions.unpublish_from_portal').'"')
        ->toContain('mà không bật "'.__('matters.transition_form.publish').'"')
        ->not->toContain('huỷ các khoản thu này')
        ->and(Matter::withTrashed()->find($matter->id)->trashed())->toBeFalse();

    // Và đúng là hệ thống không cho huỷ khoản thu của hợp đồng đã hoàn tất (lời từ chối không bịa ra điều đó).
    expect(ContractStatus::Completed->allowsPaymentVoid())->toBeFalse();
});

it('I1: the cancel dialog shows the same completed-contract line before the click', function () {
    $matter = fa3CmMatter(ContractStatus::Completed, 10_000_000);

    $this->actingAs($this->admin, 'web');

    $texts = fa3CmDialogTexts($this->livewire(EditMatter::class, ['record' => $matter->getKey()])->mountAction('cancelMatter'));

    expect($texts)->toContain(fa3CmCompletedRefusal($matter, 1, 10_000_000))
        ->and($texts)->not->toContain(fa3CmRefusal($matter, 1, 10_000_000))
        // Fix round 2 (I-A): hộp thoại nói luôn bước tắt công bố portal và không công bố giai đoạn kết thúc.
        ->and(implode(' ', $texts))->toContain('"'.__('matters.actions.unpublish_from_portal').'"')
        ->toContain('mà không bật "'.__('matters.transition_form.publish').'"');
});

it('I1: a completed contract whose payments were all voided does not block, and active-contract payments keep the void-first line', function () {
    $voided = fa3CmMatter(ContractStatus::Completed, 10_000_000, voided: true);
    $active = fa3CmMatter(ContractStatus::Active, 10_000_000);

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditMatter::class, ['record' => $voided->getKey()])
        ->callAction('cancelMatter', data: ['reason' => FA3_CANCEL_REASON])
        ->assertHasNoActionErrors();

    $errors = collect($this->livewire(EditMatter::class, ['record' => $active->getKey()])
        ->callAction('cancelMatter', data: ['reason' => FA3_CANCEL_REASON])
        ->errors()->toArray())->flatten()->all();

    expect(Matter::withTrashed()->find($voided->id)->trashed())->toBeTrue()
        ->and($errors)->toBe([fa3CmRefusal($active, 1, 10_000_000)]);
});

it('I1: when payments sit on both a completed and a cancelled contract, the completed-contract line wins (there is no way out)', function () {
    // Hợp đồng đầu bị huỷ (còn 2 triệu đã thu), hợp đồng sau đã hoàn tất (10 triệu đã thu).
    $matter = fa3CmMatter(ContractStatus::Cancelled, 2_000_000);
    $completed = Contract::factory()->for($matter)->active()->create(['total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($completed)->create(['amount' => 10_000_000, 'due_date' => '2026-10-01', 'status' => InstalmentStatus::Paid]);
    Payment::factory()->for($instalment)->create(['amount' => 10_000_000, 'paid_on' => '2026-10-02']);
    $completed->forceFill(['status' => ContractStatus::Completed, 'ended_at' => '2026-10-06'])->saveQuietly();

    $this->actingAs($this->admin, 'web');

    $errors = collect($this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->callAction('cancelMatter', data: ['reason' => FA3_CANCEL_REASON])
        ->errors()->toArray())->flatten()->all();

    expect($errors)->toBe([__('actions.cancel_matter.payments_on_completed', [
        'code' => $matter->code,
        'contract' => $completed->code,
        'count' => 1,
        'amount' => Money::format(10_000_000),
    ])]);
});

it('m7: the payments line also says to cancel the contract if it is still in force, so the next refusal is no surprise', function () {
    expect(__('actions.cancel_matter.payments_recorded', ['code' => 'VK-1', 'count' => 1, 'amount' => '1 ₫']))
        ->toContain('huỷ hợp đồng nếu còn hiệu lực');
});

it('m9: the Matter deleting hook also refuses a soft delete while unvoided payments remain, even with no balance outstanding', function () {
    $paid = fa3CmMatter(ContractStatus::Cancelled, 4_000_000);
    $voided = fa3CmMatter(ContractStatus::Cancelled, 4_000_000, voided: true);

    expect(fn () => $paid->delete())->toThrow(MatterHasRecordedPayments::class, __('exceptions.matter_has_recorded_payments', [
        'code' => $paid->code,
        'count' => 1,
        'amount' => Money::format(4_000_000),
    ]));

    expect(Matter::withTrashed()->find($paid->id)->trashed())->toBeFalse()
        ->and($voided->delete())->toBeTrue()
        ->and(Matter::withTrashed()->find($voided->id)->trashed())->toBeTrue();
});

// =================================================================================================
// Vụ đã huỷ từ trước luật này: tiền đã về vẫn được tính
// =================================================================================================

it('still counts money collected on a matter cancelled before this rule in the revenue "collected" card', function () {
    $live = fa3CmMatter(ContractStatus::Active, 3_000_000, ['lead_lawyer_id' => $this->lawyer->id]);
    fa3CmLegacyCancel(fa3CmMatter(ContractStatus::Cancelled, 15_000_000, ['lead_lawyer_id' => $this->lawyer->id]));

    $this->actingAs($this->admin, 'web');

    $cards = collect(Livewire::test(RevenueKpiWidget::class, ['pageFilters' => []])->instance()->cards())->keyBy('key');

    expect($cards['collected']['value'])->toBe(Money::format(18_000_000));
});

it('still counts it in the performance revenue column of the lead lawyer at the time', function () {
    fa3CmLegacyCancel(fa3CmMatter(ContractStatus::Cancelled, 15_000_000, ['lead_lawyer_id' => $this->lawyer->id], paidOn: '2026-09-10'));

    $this->actingAs($this->admin, 'web');

    $rows = Livewire::test(Performance::class)->instance()->getTableRecords()->all();

    expect($rows[$this->lawyer->id]['revenueCollected'])->toBe(15_000_000);
});

it('lists the payment of a cancelled matter among the recent payments, labelled as a cancelled matter, without a void button', function () {
    $legacy = fa3CmLegacyCancel(fa3CmMatter(ContractStatus::Cancelled, 15_000_000));
    $payment = Payment::query()->sole();

    $this->actingAs($this->accountant, 'web');

    $component = $this->livewire(RecentPaymentsWidget::class)
        ->assertCanSeeTableRecords([$payment])
        ->assertActionHidden(TestAction::make('voidPayment')->table($payment))
        ->assertSee($legacy->code)
        ->assertSee(__('billing.receivables.recent_payments.cancelled_matter'));

    // Bộ lọc mã hồ sơ cũng tìm ra nó (khoản cũ ngoài cửa sổ 90 ngày tìm bằng mã).
    $component->filterTable('matter_code', ['code' => $legacy->code])
        ->assertCanSeeTableRecords([$payment]);
});

it('labels only the cancelled matter, not a live one', function () {
    fa3CmMatter(ContractStatus::Active, 3_000_000);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(RecentPaymentsWidget::class)
        ->assertDontSee(__('billing.receivables.recent_payments.cancelled_matter'));
});

it('never shows the money of a cancelled restricted matter to the accountant or the manager, only to the admin', function () {
    fa3CmLegacyCancel(fa3CmMatter(ContractStatus::Cancelled, 15_000_000, ['lead_lawyer_id' => $this->lawyer->id, 'confidentiality' => 'restricted']));
    $payment = Payment::query()->sole();

    foreach ([$this->accountant, $this->manager] as $viewer) {
        $this->actingAs($viewer, 'web');

        $this->livewire(RecentPaymentsWidget::class)->assertCanNotSeeTableRecords([$payment]);

        $cards = collect(Livewire::test(RevenueKpiWidget::class, ['pageFilters' => []])->instance()->cards())->keyBy('key');
        expect($cards['collected']['value'])->toBe(Money::format(0));
    }

    $this->actingAs($this->admin, 'web');
    $this->livewire(RecentPaymentsWidget::class)->assertCanSeeTableRecords([$payment]);
});

it('finds the payment of a cancelled matter through the client filter too, and offers that client', function () {
    $legacy = fa3CmLegacyCancel(fa3CmMatter(ContractStatus::Cancelled, 15_000_000, paidOn: '2026-10-01'));
    $live = fa3CmMatter(ContractStatus::Active, 3_000_000);
    [$onLegacy, $onLive] = [Payment::query()->orderBy('id')->first(), Payment::query()->orderByDesc('id')->first()];

    $this->actingAs($this->accountant, 'web');

    $component = $this->livewire(RecentPaymentsWidget::class);

    expect($component->instance()->getTable()->getFilter('client_id')->getOptions())->toHaveKey($legacy->client_id);

    $component->filterTable('client_id', $legacy->client_id)
        ->assertCanSeeTableRecords([$onLegacy])
        ->assertCanNotSeeTableRecords([$onLive]);
});

it('warns about the payments on the cancel dialog only to a viewer who sees money', function () {
    $paid = fa3CmMatter(ContractStatus::Active, 10_000_000);
    $assistant = User::factory()->withRole(Role::Assistant)->create();

    expect(MatterOpenWork::cancellingLines($paid, $this->admin))->toContain(fa3CmRefusal($paid, 1, 10_000_000))
        ->and(MatterOpenWork::cancellingLines($paid, $assistant))->not->toContain(fa3CmRefusal($paid, 1, 10_000_000))
        ->and(MatterOpenWork::cancellingLines($paid))->not->toContain(fa3CmRefusal($paid, 1, 10_000_000));
});

it('keeps that money when the revenue page is filtered on the practice area of the cancelled matter', function () {
    $legacy = fa3CmLegacyCancel(fa3CmMatter(ContractStatus::Cancelled, 15_000_000));
    fa3CmMatter(ContractStatus::Active, 3_000_000);

    $this->actingAs($this->admin, 'web');

    $cards = collect(Livewire::test(RevenueKpiWidget::class, ['pageFilters' => ['practice_area_id' => $legacy->matter_type_id]])->instance()->cards())->keyBy('key');

    expect($cards['collected']['value'])->toBe(Money::format(15_000_000));
});

it('still counts it in the revenue by stage chart, so its buckets add up to the "collected" card', function () {
    fa3CmMatter(ContractStatus::Active, 3_000_000);
    fa3CmLegacyCancel(fa3CmMatter(ContractStatus::Cancelled, 15_000_000));

    $this->actingAs($this->admin, 'web');

    $instance = Livewire::test(RevenueByStageWidget::class, ['pageFilters' => []])->instance();
    $data = (new ReflectionMethod($instance, 'getData'))->invoke($instance);

    expect(array_sum($data['datasets'][0]['data']))->toBe(18_000_000);
});

it('still offers on the revenue page the lawyer credited with money on a cancelled matter only', function () {
    $former = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư vụ đã huỷ Fabarev']);
    $hidden = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư vụ hạn chế đã huỷ Fabahid']);
    fa3CmLegacyCancel(fa3CmMatter(ContractStatus::Cancelled, 15_000_000, ['lead_lawyer_id' => $former->id]));
    fa3CmLegacyCancel(fa3CmMatter(ContractStatus::Cancelled, 5_000_000, ['lead_lawyer_id' => $hidden->id, 'confidentiality' => 'restricted']));

    $html = $this->actingAs($this->accountant, 'web')->get(RevenueDashboard::getUrl(panel: 'admin'))->assertOk()->getContent();

    expect($html)->toContain('Luật sư vụ đã huỷ Fabarev')
        ->not->toContain('Luật sư vụ hạn chế đã huỷ Fabahid');
});
