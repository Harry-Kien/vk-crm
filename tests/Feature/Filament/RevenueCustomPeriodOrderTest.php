<?php

use App\Enums\Role;
use App\Filament\Admin\Widgets\Revenue\RevenueKpiWidget;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Billing\RevenueFilters;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn billing. Trang Doanh thu, kỳ "Tuỳ chọn": người dùng chọn "Đến
 * ngày" trước "Từ ngày" (chọn ngày cuối trước, hay bấm nhầm ô) — trước bản sửa mọi ô số ra 0 ₫ mà không
 * một dòng nào nói vì sao, dù trong khoảng đó có tiền về. Khoảng ngày là một khoảng: hai đầu đảo thì đọc
 * theo thứ tự đúng, và dòng "khoảng đang xem" in đúng thứ tự đó.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-22 10:00:00'));

    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $contract = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]))
        ->active()->create(['total_amount' => 30_000_000, 'signed_at' => '2026-09-01']);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 30_000_000, 'due_date' => '2026-12-31']);
    Payment::factory()->for($instalment)->create(['amount' => 12_500_000, 'paid_on' => '2026-10-05', 'attributed_lawyer_id' => $lawyer->id]);
});

it('reads a custom period whose two ends were picked in reverse order as the same span', function () {
    $this->actingAs($this->accountant, 'web');

    $reversed = ['period' => 'custom', 'date_from' => '2026-10-20', 'date_to' => '2026-10-01'];

    $cards = collect(Livewire::test(RevenueKpiWidget::class, ['pageFilters' => $reversed])->instance()->cards())->keyBy('key');

    expect($cards['collected']['amount'])->toBe(12_500_000)
        ->and($cards['collected']['value'])->toBe(Money::format(12_500_000))
        ->and(RevenueFilters::fromPageFilters($reversed)->rangeLabel())->toBe('01/10/2026 – 20/10/2026');
});
