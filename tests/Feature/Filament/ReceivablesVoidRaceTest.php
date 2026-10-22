<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\Receivables;
use App\Filament\Admin\Widgets\Billing\RecentPaymentsWidget;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn billing — hai kế toán cùng sửa một khoản thu ghi nhầm trên trang
 * "Công nợ". Người thứ nhất mở hộp thoại "Huỷ khoản thu", chọn khoản và gõ lý do; trong lúc đó người thứ
 * hai đã huỷ đúng khoản ấy. Người thứ nhất bấm "Gửi": trước bản sửa hộp thoại đóng lại hoặc báo "giá trị
 * đã chọn không hợp lệ" mà không ai biết vì sao — khoản thu đã được huỷ bởi người khác. Tab "Hợp đồng và
 * thanh toán" của hồ sơ đã sửa đúng tình huống này ở làn fb (BillingRelationManagerTest, "voided by
 * someone else while the modal is open"); trang của kế toán phải nói cùng một câu.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->colleague = User::factory()->withRole(Role::Accountant)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $contract = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]))
        ->active()->create(['total_amount' => 10_000_000]);
    $this->instalment = Instalment::factory()->for($contract)->create(['amount' => 10_000_000, 'due_date' => today()->subDays(3)->toDateString()]);
    $this->payment = Payment::factory()->for($this->instalment)->create(['amount' => 3_000_000, 'paid_on' => today()->subDay()->toDateString()]);
});

function voidedMeanwhile(Payment $payment, User $by): void
{
    $payment->fill(['voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => 'Ghi trùng với khoản đã ghi ở tab hồ sơ.'])->save();
}

it('tells the accountant on the receivables table that the payment was already voided by someone else', function () {
    $this->actingAs($this->accountant, 'web');

    $page = test()->livewire(Receivables::class)
        ->mountAction(TestAction::make('voidPayment')->table($this->instalment))
        ->setActionData(['payment_id' => $this->payment->id, 'reason' => 'Khách chuyển nhầm tài khoản, đã hoàn lại.']);

    voidedMeanwhile($this->payment, $this->colleague);

    $page->callMountedAction();

    Notification::assertNotified(__('actions.failed_title'));
    expect($this->payment->fresh()->void_reason)->toBe('Ghi trùng với khoản đã ghi ở tab hồ sơ.');
});

it('tells the accountant in the recent payments section that the payment was already voided by someone else', function () {
    $this->actingAs($this->accountant, 'web');

    $widget = test()->livewire(RecentPaymentsWidget::class)
        ->mountAction(TestAction::make('voidPayment')->table($this->payment))
        ->setActionData(['reason' => 'Khách chuyển nhầm tài khoản, đã hoàn lại.']);

    voidedMeanwhile($this->payment, $this->colleague);

    $widget->callMountedAction();

    Notification::assertNotified(__('actions.failed_title'));
    expect($this->payment->fresh()->void_reason)->toBe('Ghi trùng với khoản đã ghi ở tab hồ sơ.');
});
