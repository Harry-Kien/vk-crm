<?php

use App\Actions\Billing\CancelContract;
use App\Enums\ContractStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\Role;
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
use Filament\Notifications\Notification;

/*
 * Rà soát toàn hệ thống 2026-10-22, làn harden — việc 3 (suy luận của làn billing).
 *
 * Tab "Hợp đồng và thanh toán" chỉ liệt kê đợt của hợp đồng HIỆN HÀNH (bản mới nhất). Trong lúc một hộp
 * thoại trên dòng đợt thu đang mở, người khác huỷ hợp đồng rồi soạn bản mới: dòng rời bảng, Filament
 * không tìm lại được bản ghi và bấm "Gửi" không làm gì — người bấm tưởng đã ghi. Cùng cơ chế lỗi đã sửa ở
 * trang Công nợ (`Receivables::resolveTableRecord()`, `ReceivablesVoidRaceTest`).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    // Ghi/huỷ khoản thu trên vụ thường: kế toán (SPEC §5) — quản lý và luật sư không thấy hai nút này.
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);

    $this->contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);
    $this->instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'trigger_type' => InstalmentTrigger::DueDate,
        'due_date' => today()->subDays(5)->toDateString(),
    ]);
});

function bcrReplaceContract(Matter $matter, Contract $contract, User $by): void
{
    app(CancelContract::class)->handle($by, $contract, 'Khách đổi phạm vi công việc, ký lại hợp đồng mới.');
    Contract::factory()->for($matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 12_000_000, 'signed_at' => null]);

    expect($matter->fresh()->contract->getKey())->not->toBe($contract->getKey());
}

function bcrTab(Matter $matter)
{
    return test()->livewire(BillingRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
}

it('tells the user why "record payment" was refused when the contract was replaced while the dialog was open', function () {
    $this->actingAs($this->accountant, 'web');

    $tab = bcrTab($this->matter)
        ->mountAction(TestAction::make('recordPayment')->table($this->instalment))
        ->setActionData(['amount' => '4.000.000', 'paid_on' => today()->toDateString(), 'method' => 'bank_transfer']);

    bcrReplaceContract($this->matter, $this->contract, $this->manager);

    $tab->callMountedAction();

    // Câu của chính `RecordPayment`: hợp đồng của đợt này không còn hiệu lực.
    Notification::assertNotified(
        Notification::make()
            ->title(__('actions.failed_title'))
            ->body(__('billing.errors.instalment_contract_not_active', [
                'name' => $this->instalment->name,
                'code' => $this->contract->code,
                'status' => ContractStatus::Cancelled->label(),
            ]))
            ->danger()
            ->persistent(),
    );
    expect(Payment::query()->where('instalment_id', $this->instalment->id)->exists())->toBeFalse();
});

it('still voids the payment when the contract was replaced while the void dialog was open', function () {
    $payment = Payment::factory()->for($this->instalment)->create(['amount' => 3_000_000]);

    $this->actingAs($this->accountant, 'web');

    $tab = bcrTab($this->matter)
        ->mountAction(TestAction::make('voidPayment')->table($this->instalment))
        ->setActionData(['payment_id' => $payment->id, 'reason' => 'Khách chuyển nhầm tài khoản, đã hoàn lại.']);

    bcrReplaceContract($this->matter, $this->contract, $this->manager);

    $tab->callMountedAction();

    // Huỷ một khoản thu ghi nhầm trên hợp đồng đã huỷ vẫn hợp lệ (`ContractStatus::allowsPaymentVoid()`):
    // lần bấm phải XẢY RA, không lặng im.
    expect($payment->fresh()->voided_at)->not->toBeNull()
        ->and($payment->fresh()->void_reason)->toBe('Khách chuyển nhầm tài khoản, đã hoàn lại.');
});

it('never resolves an instalment of another matter through the dialog fallback', function () {
    $other = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $otherContract = Contract::factory()->for($other)->active()->create(['total_amount' => 5_000_000]);
    $foreign = Instalment::factory()->for($otherContract)->create(['amount' => 5_000_000, 'trigger_type' => InstalmentTrigger::DueDate, 'due_date' => today()->toDateString()]);

    $this->actingAs($this->accountant, 'web');

    $tab = bcrTab($this->matter)->mountAction(TestAction::make('recordPayment')->table($this->instalment));

    expect($tab->instance()->getTableRecord((string) $foreign->getKey()))->toBeNull();
});
