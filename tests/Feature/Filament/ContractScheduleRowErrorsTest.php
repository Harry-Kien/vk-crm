<?php

use App\Enums\ContractStatus;
use App\Enums\Role;
use App\Filament\Admin\Pages\ContractEntry;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn billing. Trình duyệt gửi các dòng của ô "Lịch thu" (Repeater) với
 * khoá là UUID do Filament sinh (`instalments.3f2a…`), không phải 0, 1, 2 — test cũ điền dữ liệu bằng mảng
 * số nên không thấy. Action báo lỗi một dòng bằng VỊ TRÍ (`instalments.1.amount`); lỗi đó phải nằm dưới
 * đúng ô của dòng thứ hai trong hộp thoại, không trôi thành một thông báo chung không nói dòng nào.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
});

it('puts a row error of the schedule under the field of that row when the browser keys rows by uuid', function () {
    $this->actingAs($this->accountant, 'web');

    test()->livewire(ContractEntry::class)
        ->mountAction(TestAction::make('enterContract')->table($this->matter))
        ->set('mountedActions.0.data.total_amount', '20.000.000')
        ->set('mountedActions.0.data.instalments', [
            'a3c1f0de-0000-4000-8000-000000000001' => ['name' => 'Tạm ứng', 'amount' => '10.000.000', 'trigger_type' => 'on_signing', 'due_days_after_trigger' => '0'],
            'a3c1f0de-0000-4000-8000-000000000002' => ['name' => 'Đợt hai', 'amount' => '10,5 triệu', 'trigger_type' => 'on_signing', 'due_days_after_trigger' => '0'],
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['instalments.a3c1f0de-0000-4000-8000-000000000002.amount'])
        ->assertNotNotified(__('actions.failed_title'));

    expect(Contract::query()->where('matter_id', $this->matter->id)->exists())->toBeFalse();
});

it('maps a row error the Action raises (a negative number of days) to the uuid key of that row', function () {
    $this->actingAs($this->accountant, 'web');

    test()->livewire(ContractEntry::class)
        ->mountAction(TestAction::make('enterContract')->table($this->matter))
        ->set('mountedActions.0.data.total_amount', '20.000.000')
        ->set('mountedActions.0.data.instalments', [
            'b0000000-0000-4000-8000-00000000000a' => ['name' => 'Tạm ứng', 'amount' => '10.000.000', 'trigger_type' => 'on_signing', 'due_days_after_trigger' => '0'],
            'b0000000-0000-4000-8000-00000000000b' => ['name' => 'Đợt hai', 'amount' => '10.000.000', 'trigger_type' => 'on_signing', 'due_days_after_trigger' => '-3'],
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['instalments.b0000000-0000-4000-8000-00000000000b.due_days_after_trigger']);
});

it('maps a row error of an amendment (a new amount below what was collected) to the uuid key of that change', function () {
    $contract = Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 30_000_000]);
    $first = Instalment::factory()->for($contract)->create(['amount' => 10_000_000, 'sequence' => 1]);
    $second = Instalment::factory()->for($contract)->create(['amount' => 20_000_000, 'sequence' => 2]);
    $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->subMonth()->toDateString()]);
    Payment::factory()->for($second)->create(['amount' => 8_000_000]);

    $this->actingAs($this->lawyer, 'web');

    test()->livewire(BillingRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->mountAction(TestAction::make('amendContract')->table())
        ->set('mountedActions.0.data.new_total_amount', '15.000.000')
        ->set('mountedActions.0.data.reason', 'Khách thương lượng giảm phí sau khi rút một yêu cầu.')
        ->set('mountedActions.0.data.instalment_changes', [
            'c0000000-0000-4000-8000-000000000001' => ['action' => 'update', 'instalment_id' => (string) $first->id, 'amount' => '10.000.000'],
            'c0000000-0000-4000-8000-000000000002' => ['action' => 'update', 'instalment_id' => (string) $second->id, 'amount' => '5.000.000'],
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['instalment_changes.c0000000-0000-4000-8000-000000000002.amount']);

    expect($contract->fresh()->total_amount)->toBe(30_000_000);
});
