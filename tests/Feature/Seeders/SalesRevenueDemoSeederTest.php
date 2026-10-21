<?php

use App\Enums\IntakeStatus;
use App\Filament\Admin\Pages\RevenueDashboard;
use App\Filament\Admin\Widgets\Revenue\RevenueByPersonWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueKpiWidget;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Billing\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\SalesRevenueDemoSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
 * Dữ liệu mẫu "tiền do nhân viên kinh doanh đem về" (2026-10-21): trên bản demo, chọn nhân viên kinh doanh mẫu ở
 * trang Doanh thu thì thấy số thật. Hai lead của `kinhdoanh@` đi qua đúng các Action: ghi, gọi lại, chuyển cho luật
 * sư, báo phí, LUẬT SƯ chuyển thành hồ sơ, hợp đồng ký, kế toán ghi khoản thu. Hàm toàn cục mang tiền tố `srd…`.
 */
beforeEach(function () {
    Storage::fake('private');
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Ho_Chi_Minh'));
    $this->seed(DatabaseSeeder::class);
    $this->sales = User::query()->where('email', SalesRevenueDemoSeeder::SALES_EMAIL)->firstOrFail();
    $this->admin = User::query()->where('email', DemoAccountsSeeder::ADMIN_EMAIL)->firstOrFail();
});

it('turns two leads of the demo sales person into matters, each converted by the lawyer it was handed to', function () {
    $won = IntakeRequest::query()
        ->where('assigned_to', $this->sales->id)
        ->where('status', IntakeStatus::Won->value)
        ->whereNotNull('matter_id')
        ->orderBy('id')
        ->get();

    expect($won)->toHaveCount(2)
        ->and($won->pluck('created_by')->unique()->all())->toBe([$this->sales->id]);

    foreach ($won as $intake) {
        $converted = Activity::query()->where('event', 'intake_converted')->where('subject_id', $intake->id)->sole();

        expect($converted->causer_id)->toBe($intake->consulting_lawyer_id)
            ->and($intake->matter->lead_lawyer_id)->toBe($intake->consulting_lawyer_id)
            ->and($intake->matter->contract)->not->toBeNull();
    }
});

it('shows the demo sales person money on the revenue page this month, and lists them in the sales filter', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin, 'web');

    $cards = collect(Livewire::test(RevenueKpiWidget::class, ['pageFilters' => ['sales_id' => $this->sales->id]])->instance()->cards())->keyBy('key');

    // Hai khoản về HÔM NAY: phần còn lại của lead 1 (30 tr) và tạm ứng của lead 2 (20 tr); còn 20 tr chưa đến hạn.
    expect($cards['collected']['amount'])->toBe(50_000_000)
        ->and($cards['outstanding']['amount'])->toBe(20_000_000)
        ->and(RevenueDashboard::salesOptions())->toHaveKey($this->sales->id);

    $rows = Livewire::test(RevenueByPersonWidget::class, ['pageFilters' => []])->set('filter', 'sales')->instance()->numberTableRows();

    expect(collect($rows)->firstWhere('label', $this->sales->name)['value'] ?? null)->toBe(Money::format(50_000_000));
});

it('adds nothing when it runs again', function () {
    $before = IntakeRequest::withTrashed()->count();

    $this->seed(SalesRevenueDemoSeeder::class);

    expect(IntakeRequest::withTrashed()->count())->toBe($before);
});
