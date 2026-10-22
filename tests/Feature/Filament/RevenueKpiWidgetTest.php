<?php

use App\Enums\ContractStatus;
use App\Enums\Role;
use App\Filament\Admin\Pages\Receivables;
use App\Filament\Admin\Pages\RevenueDashboard;
use App\Filament\Admin\Widgets\Revenue\MatterMixByPracticeAreaWidget;
use App\Filament\Admin\Widgets\Revenue\ReceivablesDonutWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueKpiWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueOverTimeWidget;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Billing\RevenueColours;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Hàng ô số đầu trang Doanh thu (làn rv, 2026-10-15): Đã thu, Còn phải thu, Quá hạn, Hợp đồng ký mới.
 * Mỗi con số đọc qua widget đã MOUNT thật qua Livewire (`cards()`), và so với con số của biểu đồ / trang
 * đang có cùng định nghĩa — không có công thức thứ hai.
 *
 * Ngày cố định 15/10/2026: "Tháng này" là 01/10 – 31/10, "cùng khoảng kỳ trước" là 01/09 – 15/09.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
});

/** Hợp đồng ACTIVE một đợt bằng đúng giá trị — cùng thành ngữ RevenueDashboardTest. */
function rvContract(Matter $matter, int $amount, string $signedAt, string $dueDate): Contract
{
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => $amount, 'signed_at' => $signedAt]);
    Instalment::factory()->for($contract)->create(['amount' => $amount, 'due_date' => $dueDate]);

    return $contract;
}

function rvPay(Contract $contract, int $amount, string $paidOn, User $lawyer): Payment
{
    return Payment::factory()->for($contract->instalments()->sole())->create([
        'amount' => $amount, 'paid_on' => $paidOn, 'attributed_lawyer_id' => $lawyer->id,
    ]);
}

/** @return array<string, array<string, mixed>> các ô theo khoá, đọc qua widget đã mount */
function rvCards(array $pageFilters = []): array
{
    return collect(Livewire::test(RevenueKpiWidget::class, ['pageFilters' => $pageFilters])->instance()->cards())
        ->keyBy('key')
        ->all();
}

it('shows four cards in a fixed order: collected, outstanding, overdue, newly signed contracts', function () {
    $this->actingAs($this->admin, 'web');

    expect(array_keys(rvCards()))->toBe(['collected', 'outstanding', 'overdue', 'signed'])
        ->and(rvCards()['collected']['label'])->toBe(__('widgets.revenue_dashboard.kpi.collected.label'))
        ->and(rvCards()['signed']['label'])->toBe(__('widgets.revenue_dashboard.kpi.signed.label'));
});

it('counts collected money exactly like the revenue-over-time chart, and compares it with the same span of the previous period', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $old = rvContract($matter, 100_000_000, '2026-08-01', '2026-12-31');

    rvPay($old, 12_000_000, '2026-10-03', $this->lawyer);
    rvPay($old, 6_000_000, '2026-10-15', $this->lawyer);
    rvPay($old, 15_000_000, '2026-09-10', $this->lawyer); // trong 01/09 – 15/09: tính vào kỳ trước
    rvPay($old, 40_000_000, '2026-09-20', $this->lawyer); // sau 15/09: KHÔNG so với nửa tháng này

    $this->actingAs($this->admin, 'web');

    // 2026-10-21: biểu đồ là xu hướng từ đầu năm; ô "Đã thu" (kỳ "Tháng này") bằng đúng cột của tháng này — cột cuối.
    $chartTotal = Money::parse(trim(str_replace('₫', '', collect(Livewire::test(RevenueOverTimeWidget::class, ['pageFilters' => []])->instance()->numberTableRows())->last()['value'])));

    $card = rvCards()['collected'];

    expect($card['amount'])->toBe(18_000_000)
        ->and($card['amount'])->toBe($chartTotal)
        ->and($card['value'])->toBe('18.000.000 ₫')
        ->and($card['delta'])->toBe(['direction' => 'up', 'text' => '▲ 20 %'])
        ->and($card['comparison'])->toContain('01/09/2026 – 15/09/2026');
});

it('shows a falling figure in red with a down arrow, and a dash when the previous period had nothing', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $contract = rvContract($matter, 100_000_000, '2026-08-01', '2026-12-31');
    rvPay($contract, 5_000_000, '2026-10-02', $this->lawyer);
    rvPay($contract, 20_000_000, '2026-09-05', $this->lawyer);

    $this->actingAs($this->admin, 'web');

    expect(rvCards()['collected']['delta'])->toBe(['direction' => 'down', 'text' => '▼ 75 %']);

    // Năm nay so với 01/01 – 15/10/2025: năm ngoái không có đồng nào.
    expect(rvCards(['period' => 'this_year'])['collected']['delta'])->toBe(['direction' => null, 'text' => '—']);
});
it('rounds a tiny change to "0 %" without an arrow, rather than an arrow next to zero', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $contract = rvContract($matter, 100_000_000, '2026-08-01', '2026-12-31');
    rvPay($contract, 1_001_000, '2026-10-02', $this->lawyer);
    rvPay($contract, 1_000_000, '2026-09-05', $this->lawyer);

    $this->actingAs($this->admin, 'web');

    expect(rvCards()['collected']['delta'])->toBe(['direction' => 'flat', 'text' => '0 %']);

    // Và theo chiều giảm.
    rvPay($contract, 2_000, '2026-09-06', $this->lawyer);

    expect(rvCards()['collected']['delta'])->toBe(['direction' => 'flat', 'text' => '0 %']);
});

it('compares a custom range with the equally long range right before it', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $contract = rvContract($matter, 100_000_000, '2026-05-01', '2026-12-31');
    rvPay($contract, 10_000_000, '2026-06-05', $this->lawyer);
    rvPay($contract, 10_000_000, '2026-05-25', $this->lawyer);
    rvPay($contract, 99_000_000, '2026-05-20', $this->lawyer); // trước 21/05: ngoài khoảng so sánh

    $this->actingAs($this->admin, 'web');

    $card = rvCards(['period' => 'custom', 'date_from' => '2026-06-01', 'date_to' => '2026-06-10'])['collected'];

    expect($card['amount'])->toBe(10_000_000)
        ->and($card['delta'])->toBe(['direction' => 'flat', 'text' => '0 %'])
        ->and($card['comparison'])->toContain('22/05/2026 – 31/05/2026');
});

it('reads outstanding and overdue as of today across every active contract, like the receivables page, whatever the period', function () {
    // Mỗi vụ một hợp đồng (`contracts.matter_id` unique).
    $matter = fn () => Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    // Ký từ tháng 3 (ngoài "Tháng này"), một đợt quá hạn từ tháng 9 đã thu một phần.
    $oldOverdue = rvContract($matter(), 30_000_000, '2026-03-01', '2026-09-30');
    rvPay($oldOverdue, 10_000_000, '2026-09-01', $this->lawyer);

    // Ký tháng này, chưa tới hạn.
    rvContract($matter(), 8_000_000, '2026-10-05', '2026-11-30');

    $this->actingAs($this->accountant, 'web');

    $cards = rvCards();

    expect($cards['outstanding']['amount'])->toBe(28_000_000)
        ->and($cards['overdue']['amount'])->toBe(20_000_000)
        ->and($cards['overdue']['value'])->toBe('20.000.000 ₫')
        ->and($cards['overdue']['tone'])->toBe('danger')
        ->and($cards['outstanding']['detail'])->toBe(__('widgets.revenue_dashboard.kpi.outstanding.detail', ['count' => 2]))
        ->and($cards['overdue']['detail'])->toBe(__('widgets.revenue_dashboard.kpi.overdue.detail', ['count' => 1]));

    // Cùng con số dù đổi kỳ: đây là ảnh chụp hôm nay, không phải một số phát sinh trong kỳ.
    expect(rvCards(['period' => 'custom', 'date_from' => '2020-01-01', 'date_to' => '2020-01-31'])['overdue']['amount'])->toBe(20_000_000);

    // Và bằng đúng trang Công nợ (cùng BillingSummary::pendingInstalmentsQuery()).
    Livewire::test(Receivables::class)->assertCountTableRecords(2);
});

it('keeps the donut on contracts signed in the period while the cards read every open contract', function () {
    $old = rvContract(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]), 30_000_000, '2026-03-01', '2026-09-30');
    $new = rvContract(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]), 8_000_000, '2026-10-05', '2026-11-30');

    $this->actingAs($this->admin, 'web');

    $donut = Livewire::test(ReceivablesDonutWidget::class, ['pageFilters' => []])->instance();
    [$collected, $notYetDue, $overdue] = (new ReflectionMethod($donut, 'getData'))->invoke($donut)['datasets'][0]['data'];

    // Vành khuyên: chỉ hợp đồng ký trong tháng 10 (đợt 8 triệu chưa tới hạn); hợp đồng ký tháng 3 không có mặt.
    expect([$collected, $notYetDue, $overdue])->toBe([0, 8_000_000, 0])
        // Ô số: mọi hợp đồng đang hiệu lực, tại hôm nay.
        ->and(rvCards()['outstanding']['amount'])->toBe(38_000_000)
        ->and(rvCards()['overdue']['amount'])->toBe(30_000_000);
});

/*
 * Rà soát cuối làn rv, I1: hai ô "Còn phải thu"/"Quá hạn" là ảnh chụp HÔM NAY, không theo Kỳ — trong khi
 * lát "Quá hạn" của bánh tròn cùng trang (cùng màu đỏ) chỉ tính hợp đồng ký trong kỳ. Dòng phụ IN TRÊN ô
 * (không chỉ trong ⓘ) phải nói "tại hôm nay", ở mọi kỳ đang lọc, để người chọn "Năm nay" thấy vì sao ô
 * không đổi còn bánh tròn đổi.
 */
it('says on the outstanding and overdue cards themselves that they are as of today, whatever the period', function () {
    $matter = fn () => Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    rvContract($matter(), 30_000_000, '2026-03-01', '2026-09-30');
    rvContract($matter(), 8_000_000, '2026-10-05', '2026-11-30');

    $this->actingAs($this->accountant, 'web');

    foreach ([[], ['period' => 'this_year']] as $filters) {
        $html = Livewire::test(RevenueKpiWidget::class, ['pageFilters' => $filters])->html();

        expect($html)->toContain('2 đợt chưa thu xong, tại hôm nay')
            ->and($html)->toContain('1 đợt quá hạn, tại hôm nay');
    }
});

/*
 * Rà soát cuối làn rv, I2: phần đã thu của một đợt quá hạn KHÔNG chắc nằm ở ô "Đã thu" — ô đó chỉ đếm
 * tiền về TRONG kỳ. Đợt quá hạn thu một phần ngày 01/09, kỳ "Tháng này" (tháng 10): 10 triệu đó không ở
 * ô nào. Lời giải thích của "Quá hạn" không được hứa điều ngược lại.
 */
it('does not promise in the overdue explanation that the part already paid shows under collected', function () {
    $contract = rvContract(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]), 30_000_000, '2026-03-01', '2026-09-30');
    rvPay($contract, 10_000_000, '2026-09-01', $this->lawyer);

    $this->actingAs($this->accountant, 'web');

    $cards = rvCards();

    expect($cards['collected']['amount'])->toBe(0)
        ->and($cards['overdue']['amount'])->toBe(20_000_000)
        ->and($cards['overdue']['explanation'])->not->toContain('Đã thu')
        ->and($cards['overdue']['explanation'])->toContain('phần đã thu không tính ở ô này');
});

it('paints the overdue card red only when something is overdue', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    rvContract($matter, 8_000_000, '2026-10-05', '2026-11-30');

    $this->actingAs($this->admin, 'web');

    expect(rvCards()['overdue']['amount'])->toBe(0)
        ->and(rvCards()['overdue']['tone'])->toBeNull();
});

it('counts newly signed contracts and their value exactly like the practice-area mix, compared with the previous span', function () {
    $type = MatterType::factory()->withStages()->create();
    $matter = fn () => Matter::factory()->create(['matter_type_id' => $type->id, 'lead_lawyer_id' => $this->lawyer->id]);

    rvContract($matter(), 10_000_000, '2026-10-02', '2026-12-31');
    rvContract($matter(), 15_000_000, '2026-10-14', '2026-12-31');
    rvContract($matter(), 50_000_000, '2026-09-08', '2026-12-31');
    $cancelled = rvContract($matter(), 70_000_000, '2026-10-03', '2026-12-31');
    $cancelled->update(['status' => ContractStatus::Cancelled, 'ended_at' => '2026-10-04', 'ended_reason' => str_repeat('a', 20)]);

    $this->actingAs($this->manager, 'web');

    $mixAmount = collect(Livewire::test(MatterMixByPracticeAreaWidget::class, ['pageFilters' => []])->instance()->numberTableRows())
        ->sum(fn (array $row): int => Money::parse(trim(str_replace('₫', '', $row['value']))));
    $mixCount = collect(Livewire::test(MatterMixByPracticeAreaWidget::class, ['pageFilters' => ['by_count' => true]])->instance()->numberTableRows())
        ->sum(fn (array $row): int => (int) $row['value']);

    $card = rvCards()['signed'];

    expect($card['amount'])->toBe(2)
        ->and($card['amount'])->toBe($mixCount)
        ->and($card['value'])->toBe(__('widgets.revenue_dashboard.kpi.signed.value', ['count' => 2]))
        ->and($card['detail'])->toBe(__('widgets.revenue_dashboard.kpi.signed.detail', ['amount' => Money::format(25_000_000)]))
        ->and($mixAmount)->toBe(25_000_000)
        ->and($card['delta'])->toBe(['direction' => 'up', 'text' => '▲ 100 %']);
});

it('reads the lawyer filter as the lawyer when paid for collected money, and as the current lead for what is still owed', function () {
    $before = User::factory()->withRole(Role::Lawyer)->create();
    $now = User::factory()->withRole(Role::Lawyer)->create();

    $matter = Matter::factory()->create(['lead_lawyer_id' => $now->id]);
    $contract = rvContract($matter, 50_000_000, '2026-03-01', '2026-12-31');
    rvPay($contract, 10_000_000, '2026-10-01', $before);

    $this->actingAs($this->admin, 'web');

    $byBefore = rvCards(['lawyer_id' => $before->id]);
    $byNow = rvCards(['lawyer_id' => $now->id]);

    expect($byBefore['collected']['amount'])->toBe(10_000_000)
        ->and($byBefore['outstanding']['amount'])->toBe(0)
        ->and($byNow['collected']['amount'])->toBe(0)
        ->and($byNow['outstanding']['amount'])->toBe(40_000_000);
});

it('narrows every card to the chosen practice area', function () {
    $civil = MatterType::factory()->withStages()->create();
    $criminal = MatterType::factory()->withStages()->create();

    $a = rvContract(Matter::factory()->create(['matter_type_id' => $civil->id, 'lead_lawyer_id' => $this->lawyer->id]), 10_000_000, '2026-10-02', '2026-10-10');
    rvPay($a, 3_000_000, '2026-10-03', $this->lawyer);
    $b = rvContract(Matter::factory()->create(['matter_type_id' => $criminal->id, 'lead_lawyer_id' => $this->lawyer->id]), 20_000_000, '2026-10-02', '2026-10-10');
    rvPay($b, 5_000_000, '2026-10-03', $this->lawyer);

    $this->actingAs($this->admin, 'web');

    $cards = rvCards(['practice_area_id' => $civil->id]);

    expect($cards['collected']['amount'])->toBe(3_000_000)
        ->and($cards['outstanding']['amount'])->toBe(7_000_000)
        ->and($cards['overdue']['amount'])->toBe(7_000_000)
        ->and($cards['signed']['amount'])->toBe(1);
});

it('never lets a restricted matter into the figures of an accountant or a manager, while the admin sees it', function () {
    $ordinary = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $a = rvContract($ordinary, 10_000_000, '2026-10-02', '2026-10-10');
    rvPay($a, 1_000_000, '2026-10-03', $this->lawyer);
    $b = rvContract($restricted, 40_000_000, '2026-10-02', '2026-10-10');
    rvPay($b, 4_000_000, '2026-10-03', $this->lawyer);

    foreach (['accountant', 'manager'] as $key) {
        $this->actingAs($this->{$key}, 'web');
        $cards = rvCards();

        expect($cards['collected']['amount'])->toBe(1_000_000)
            ->and($cards['outstanding']['amount'])->toBe(9_000_000)
            ->and($cards['overdue']['amount'])->toBe(9_000_000)
            ->and($cards['signed']['amount'])->toBe(1);
    }

    $this->actingAs($this->admin, 'web');
    $cards = rvCards();

    expect($cards['collected']['amount'])->toBe(5_000_000)
        ->and($cards['outstanding']['amount'])->toBe(45_000_000)
        ->and($cards['signed']['amount'])->toBe(2);
});

it('shows a lawyer only the money of their own matters', function () {
    $other = User::factory()->withRole(Role::Lawyer)->create();

    $mine = rvContract(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]), 10_000_000, '2026-10-02', '2026-12-31');
    rvPay($mine, 2_000_000, '2026-10-03', $this->lawyer);
    $theirs = rvContract(Matter::factory()->create(['lead_lawyer_id' => $other->id]), 30_000_000, '2026-10-02', '2026-12-31');
    rvPay($theirs, 9_000_000, '2026-10-03', $other);

    $this->actingAs($this->lawyer, 'web');
    $cards = rvCards();

    expect($cards['collected']['amount'])->toBe(2_000_000)
        ->and($cards['outstanding']['amount'])->toBe(8_000_000)
        ->and($cards['signed']['amount'])->toBe(1);
});

it('links outstanding and overdue to the receivables page, overdue already filtered, only for who can open that page', function () {
    $this->actingAs($this->accountant, 'web');
    $cards = rvCards();

    expect($cards['outstanding']['url'])->toBe(Receivables::getUrl(panel: 'admin'))
        ->and($cards['overdue']['url'])->toBe(Receivables::getUrl(['filters' => ['overdue' => ['isActive' => '1']]], panel: 'admin'))
        ->and($cards['collected']['url'])->toBeNull()
        ->and($cards['signed']['url'])->toBeNull();

    // Luật sư không mở được trang Công nợ: ô không thành liên kết.
    $this->actingAs($this->lawyer, 'web');
    $cards = rvCards();

    expect($cards['outstanding']['url'])->toBeNull()
        ->and($cards['overdue']['url'])->toBeNull();

    Livewire::test(RevenueKpiWidget::class, ['pageFilters' => []])
        ->assertDontSeeHtml('href="'.e(Receivables::getUrl(panel: 'admin')));
});

it('drops the receivables link while the page is narrowed to a lawyer or a practice area, since that page cannot narrow the same way', function () {
    $type = MatterType::factory()->create();
    $this->actingAs($this->accountant, 'web');

    foreach ([['lawyer_id' => $this->lawyer->id], ['practice_area_id' => $type->id]] as $narrowing) {
        $cards = rvCards($narrowing);

        expect($cards['outstanding']['url'])->toBeNull()
            ->and($cards['overdue']['url'])->toBeNull();
    }

    expect(rvCards()['overdue']['url'])->not->toBeNull();
});

it('opens the receivables page already filtered to overdue instalments from the link of the overdue card', function () {
    $overdue = rvContract(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]), 30_000_000, '2026-03-01', '2026-09-30');
    rvContract(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]), 8_000_000, '2026-10-05', '2026-11-30');

    $this->actingAs($this->accountant, 'web');

    $this->get(rvCards()['overdue']['url'])->assertOk();

    Livewire::withQueryParams(['filters' => ['overdue' => ['isActive' => '1']]])
        ->test(Receivables::class)
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$overdue->instalments()->sole()]);
});

it('draws each card with its colour — green collected, blue outstanding, red overdue — the same colours as the donut', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    rvContract($matter, 30_000_000, '2026-03-01', '2026-09-30');

    $this->actingAs($this->admin, 'web');

    expect(RevenueColours::COLLECTED)->toBe('#0ca30c')
        ->and(RevenueColours::OUTSTANDING)->toBe('#4a73bd')
        ->and(RevenueColours::OVERDUE)->toBe('#d03b3b');

    $html = Livewire::test(RevenueKpiWidget::class, ['pageFilters' => []])->html();

    expect($html)->toContain('background: '.RevenueColours::COLLECTED)
        ->toContain('background: '.RevenueColours::OUTSTANDING)
        ->toContain('background: '.RevenueColours::OVERDUE)
        ->toContain('color: var(--danger-600)')
        ->toContain('30.000.000 ₫');

    // Không class Tailwind viết tay (không có bước build CSS): quét MÃ NGUỒN của các view làn rv viết, không
    // HTML đã vẽ — class của chính Filament (`fi-grid-col`…) thì dùng được bình thường.
    foreach ([
        'views/filament/admin/widgets/revenue/kpi-cards.blade.php',
        'views/components/info-tip.blade.php',
        'views/filament/admin/widgets/revenue/heading-with-tip.blade.php',
    ] as $view) {
        expect(file_get_contents(resource_path($view)))
            ->not->toMatch('/\bclass="[^"]*\b(?:flex|grid|gap-\d|p[xytblr]?-\d|m[xytblr]?-\d|text-(?:xs|sm|base|lg|xl)|bg-[a-z]+-\d|rounded-(?:sm|md|lg|xl|full)|w-\d|h-\d)\b/');
    }
});

it('keeps the definition of every card one tap away behind an info button, not as text on the page', function () {
    $this->actingAs($this->admin, 'web');

    $html = Livewire::test(RevenueKpiWidget::class, ['pageFilters' => []])->html();

    foreach (['collected', 'outstanding', 'overdue', 'signed'] as $key) {
        $explanation = rvCards()[$key]['explanation'];

        expect($explanation)->not->toBe('')
            ->and($html)->toContain(e($explanation));
    }

    expect(substr_count($html, 'data-info-tip="'))->toBe(4);
});

it('puts the cards first on the revenue page, full width, for every role that opens the page, and still 404s an assistant', function () {
    $this->actingAs($this->accountant, 'web');

    $widgets = Livewire::test(RevenueDashboard::class)->instance()->getWidgets();

    expect($widgets[0])->toBe(RevenueKpiWidget::class)
        ->and((new RevenueKpiWidget)->getColumnSpan())->toBe('full');

    foreach (['lawyer', 'accountant', 'manager', 'admin'] as $key) {
        $this->actingAs($this->{$key}, 'web');
        expect(RevenueKpiWidget::canView())->toBeTrue();
    }

    $this->actingAs($this->assistant, 'web');
    expect(RevenueKpiWidget::canView())->toBeFalse();
    $this->get(RevenueDashboard::getUrl(panel: 'admin'))->assertNotFound();
});

it('answers 404 when someone without billing access mounts the cards directly, not just hides them', function () {
    $this->actingAs($this->assistant, 'web');
    Livewire::test(RevenueKpiWidget::class, ['pageFilters' => []])->assertStatus(404);

    $this->actingAs(User::factory()->withRole(Role::Sales)->create(), 'web');
    Livewire::test(RevenueKpiWidget::class, ['pageFilters' => []])->assertStatus(404);
});
