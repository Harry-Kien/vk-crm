<?php

use App\Actions\Intake\UpdateIntakeIdentity;
use App\Enums\IntakeStatus;
use App\Enums\Role;
use App\Filament\Admin\Pages\RevenueDashboard;
use App\Filament\Admin\Widgets\Revenue\ClosedWithBalanceWidget;
use App\Filament\Admin\Widgets\Revenue\LoadPerLawyerWidget;
use App\Filament\Admin\Widgets\Revenue\MatterMixByPracticeAreaWidget;
use App\Filament\Admin\Widgets\Revenue\ReceivablesDonutWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueByPersonWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueByStageWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueKpiWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueOverTimeWidget;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\CollectedRevenue;
use App\Support\Billing\Money;
use App\Support\Billing\RevenueColours;
use App\Support\Billing\RevenueFilters;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Trang Doanh thu — xem tiền theo TỪNG NGƯỜI (chủ văn phòng, 2026-10-21): chọn một luật sư phụ trách hoặc
 * một nhân viên kinh doanh thì mọi ô số và biểu đồ theo người đó — "người đó đem về bao nhiêu tiền".
 *
 * Luật (phán quyết controller 2026-10-21, chờ chủ văn phòng xác nhận):
 *  - luật sư: như từ M9 — đã thu theo `payments.attributed_lawyer_id` (lúc thu), còn phải thu / quá hạn /
 *    hợp đồng theo luật sư phụ trách hiện tại;
 *  - nhân viên kinh doanh: MỌI số của các hồ sơ người đó đem về — hồ sơ mở từ một bản ghi tiếp nhận
 *    (`intake_requests.matter_id`) mà người đó là người được giao (`assigned_to`). Mốc này đứng yên sau khi
 *    chuyển đổi: bản ghi đã chuyển thành hồ sơ không sửa được nữa (`IntakeRequest::isClosedToChanges()`).
 *
 * Ngày cố định 21/10/2026: "Tháng này" là 01/10 – 31/10. Hàm toàn cục mang tiền tố `rbp…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo('2026-10-21 10:00:00');

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Admin']);
    $this->accountant = User::factory()->withRole(Role::Accountant)->create(['name' => 'Kế toán Thu']);
    $this->lawyerA = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Đỗ Quốc Bảo']);
    $this->lawyerB = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Trần Minh Châu']);
    $this->salesA = User::factory()->withRole(Role::Sales)->create(['name' => 'Đinh Thị Kinh Doanh']);
    $this->salesB = User::factory()->withRole(Role::Sales)->create(['name' => 'Lý Văn Bán']);
});

/** Hồ sơ của `$lead`; có `$sales` thì hồ sơ mở từ một bản ghi tiếp nhận người đó được giao (đã chuyển đổi). */
function rbpMatter(User $lead, ?User $sales = null, ?MatterType $type = null, bool $restricted = false, array $overrides = []): Matter
{
    $factory = $restricted ? Matter::factory()->restricted() : Matter::factory();
    $matter = $factory->create(['lead_lawyer_id' => $lead->id, ...($type ? ['matter_type_id' => $type->id] : []), ...$overrides]);

    if ($sales !== null) {
        IntakeRequest::factory()->status(IntakeStatus::Won)->create([
            'assigned_to' => $sales->id,
            'matter_id' => $matter->id,
            'client_id' => $matter->client_id,
        ]);
    }

    return $matter;
}

/** Hợp đồng ACTIVE một đợt bằng đúng giá trị. */
function rbpContract(Matter $matter, int $amount, string $signedAt = '2026-10-05', string $dueDate = '2026-12-31'): Contract
{
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => $amount, 'signed_at' => $signedAt]);
    Instalment::factory()->for($contract)->create(['amount' => $amount, 'due_date' => $dueDate]);

    return $contract;
}

function rbpPay(Contract $contract, int $amount, User $lawyer, string $paidOn = '2026-10-10'): Payment
{
    return Payment::factory()->for($contract->instalments()->sole())->create([
        'amount' => $amount, 'paid_on' => $paidOn, 'attributed_lawyer_id' => $lawyer->id,
    ]);
}

/** @return array<string, array<string, mixed>> */
function rbpCards(array $pageFilters): array
{
    return collect(Livewire::test(RevenueKpiWidget::class, ['pageFilters' => $pageFilters])->instance()->cards())->keyBy('key')->all();
}

function rbpData(string $class, array $pageFilters = [], ?string $filter = null): array
{
    $component = Livewire::test($class, ['pageFilters' => $pageFilters]);

    if ($filter !== null) {
        $component->set('filter', $filter);
    }

    $instance = $component->instance();

    return (new ReflectionMethod($instance, 'getData'))->invoke($instance);
}

/** @return list<array{label: string, value: string}> */
function rbpRows(string $class, array $pageFilters = [], ?string $filter = null): array
{
    $component = Livewire::test($class, ['pageFilters' => $pageFilters]);

    if ($filter !== null) {
        $component->set('filter', $filter);
    }

    return $component->instance()->numberTableRows();
}

function rbpMoney(string $formatted): int
{
    return Money::parse(trim(str_replace('₫', '', $formatted)));
}

/**
 * Văn phòng mẫu của tệp: hai nhân viên kinh doanh, hai luật sư, một hồ sơ không qua tiếp nhận.
 *
 *  - m1: salesA đem về, luật sư A, ký 100tr, thu 30tr (ghi cho A);
 *  - m2: salesA đem về, luật sư B, ký 50tr, thu 20tr (ghi cho B);
 *  - m3: salesB đem về, luật sư A, ký 40tr, thu 10tr (ghi cho A);
 *  - m4: không qua tiếp nhận, luật sư B, ký 70tr, thu 5tr (ghi cho B).
 *
 * @return array<string, Matter>
 */
function rbpOffice(object $t): array
{
    $m1 = rbpMatter($t->lawyerA, $t->salesA);
    $m2 = rbpMatter($t->lawyerB, $t->salesA);
    $m3 = rbpMatter($t->lawyerA, $t->salesB);
    $m4 = rbpMatter($t->lawyerB);

    rbpPay(rbpContract($m1, 100_000_000), 30_000_000, $t->lawyerA);
    rbpPay(rbpContract($m2, 50_000_000), 20_000_000, $t->lawyerB);
    rbpPay(rbpContract($m3, 40_000_000), 10_000_000, $t->lawyerA);
    rbpPay(rbpContract($m4, 70_000_000), 5_000_000, $t->lawyerB);

    return compact('m1', 'm2', 'm3', 'm4');
}

// =================================================================================================
// Tiền của một nhân viên kinh doanh = mọi số của các hồ sơ người đó đem về từ tiếp nhận.
// =================================================================================================

it('shows a sales person every number of the matters they brought in from intake, and nothing else, on every card and chart', function () {
    rbpOffice($this);
    $this->actingAs($this->admin, 'web');

    $filters = ['sales_id' => $this->salesA->id];
    $cards = rbpCards($filters);

    expect($cards['collected']['amount'])->toBe(50_000_000)
        ->and($cards['outstanding']['amount'])->toBe(100_000_000)
        ->and($cards['signed']['amount'])->toBe(2);

    $trend = rbpData(RevenueOverTimeWidget::class, $filters)['datasets'][0]['data'];
    $byStage = collect(rbpRows(RevenueByStageWidget::class, $filters))->sum(fn (array $row): int => rbpMoney($row['value']));
    $donut = rbpData(ReceivablesDonutWidget::class, $filters)['datasets'][0]['data'];
    $mix = collect(rbpRows(MatterMixByPracticeAreaWidget::class, $filters))->sum(fn (array $row): int => rbpMoney($row['value']));

    expect(end($trend))->toBe(50_000_000)
        ->and($byStage)->toBe(50_000_000)
        ->and($donut)->toBe([50_000_000, 100_000_000, 0])
        ->and($mix)->toBe(150_000_000);

    // Người kia: chỉ hồ sơ của mình.
    expect(rbpCards(['sales_id' => $this->salesB->id])['collected']['amount'])->toBe(10_000_000);

    // Trang "Công nợ" không lọc theo nhân viên kinh doanh: ô "Còn phải thu" thôi dẫn sang đó, như khi lọc luật sư.
    expect(rbpCards([])['outstanding']['url'])->not->toBeNull()
        ->and($cards['outstanding']['url'])->toBeNull()
        ->and($cards['overdue']['url'])->toBeNull();
});

it('adds the meaning of the sales filter to the info button of every card and money chart while a sales person is chosen', function () {
    rbpOffice($this);
    $this->actingAs($this->admin, 'web');

    $meaning = __('widgets.revenue_dashboard.sales_filter_meaning');
    $filters = ['sales_id' => $this->salesA->id];

    foreach (rbpCards($filters) as $key => $card) {
        expect($card['explanation'])->toEndWith($meaning, "ô {$key}");
    }

    foreach (rbpCards([]) as $card) {
        expect($card['explanation'])->not->toContain($meaning);
    }

    foreach ([ReceivablesDonutWidget::class, RevenueOverTimeWidget::class, RevenueByStageWidget::class, MatterMixByPracticeAreaWidget::class] as $widget) {
        expect(Livewire::test($widget, ['pageFilters' => $filters])->instance()->getExplanation())->toEndWith($meaning, $widget)
            ->and(Livewire::test($widget, ['pageFilters' => []])->instance()->getExplanation())->not->toContain($meaning);
    }
});

it('gives the intersection when a lawyer and a sales person are both chosen', function () {
    rbpOffice($this);
    $this->actingAs($this->admin, 'web');

    $both = ['sales_id' => $this->salesA->id, 'lawyer_id' => $this->lawyerA->id];

    expect(rbpCards($both)['collected']['amount'])->toBe(30_000_000)
        ->and(rbpCards($both)['outstanding']['amount'])->toBe(70_000_000)
        ->and(rbpCards($both)['signed']['amount'])->toBe(1);
});

it('lists a closed matter still owing money only under the sales person who brought it in', function () {
    $mine = rbpMatter($this->lawyerA, $this->salesA, overrides: ['closed_at' => '2026-10-01']);
    $theirs = rbpMatter($this->lawyerA, $this->salesB, overrides: ['closed_at' => '2026-10-01']);
    rbpContract($mine, 9_000_000);
    rbpContract($theirs, 8_000_000);

    $this->actingAs($this->admin, 'web');

    Livewire::test(ClosedWithBalanceWidget::class, ['pageFilters' => ['sales_id' => $this->salesA->id]])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('keeps the sales attribution fixed after conversion: the converted intake can no longer be handed to someone else', function () {
    $matter = rbpMatter($this->lawyerA, $this->salesA);
    $intake = IntakeRequest::query()->where('matter_id', $matter->id)->sole();

    $refusal = null;

    try {
        app(UpdateIntakeIdentity::class)->handle($this->admin, $intake, [
            'contact_name' => $intake->contact_name,
            'contact_phone' => $intake->contact_phone,
            'contact_role' => $intake->contact_role,
            'source' => $intake->source,
            'assigned_to' => $this->salesB->id,
        ], []);
    } catch (ValidationException $e) {
        $refusal = $e->errors();
    }

    expect($refusal)->toBe(['intake' => [__('intake.errors.record_final')]])
        ->and($intake->fresh()->assigned_to)->toBe($this->salesA->id);
});

// =================================================================================================
// Một nguồn số liệu: ô "Đã thu" = cột của kỳ trong xu hướng = "Doanh thu theo người" = CollectedRevenue.
// =================================================================================================

it('keeps one source of truth for collected money, for the whole office, a lawyer and a sales person', function () {
    rbpOffice($this);
    $this->actingAs($this->admin, 'web');

    $source = fn (array $pageFilters): int => (int) CollectedRevenue::filtered(
        $this->admin,
        RevenueFilters::fromPageFilters($pageFilters),
        RevenueFilters::fromPageFilters($pageFilters)->bounds(),
    )->sum('amount');

    $lastColumn = function (array $pageFilters): int {
        $data = rbpData(RevenueOverTimeWidget::class, $pageFilters)['datasets'][0]['data'];

        return end($data);
    };

    $byPersonTotal = fn (string $mode): int => (int) collect(rbpRows(RevenueByPersonWidget::class, [], $mode))
        ->sum(fn (array $row): int => rbpMoney($row['value']));

    $byPersonRow = fn (string $mode, User $person): int => rbpMoney(collect(rbpRows(RevenueByPersonWidget::class, [], $mode))
        ->first(fn (array $row): bool => str_starts_with($row['label'], $person->name))['value']);

    // Cả văn phòng.
    expect(rbpCards([])['collected']['amount'])->toBe(65_000_000)
        ->and($lastColumn([]))->toBe(65_000_000)
        ->and($byPersonTotal('lawyer'))->toBe(65_000_000)
        ->and($byPersonTotal('sales'))->toBe(65_000_000)
        ->and($source([]))->toBe(65_000_000);

    // Một luật sư.
    $lawyer = ['lawyer_id' => $this->lawyerA->id];
    expect(rbpCards($lawyer)['collected']['amount'])->toBe(40_000_000)
        ->and($lastColumn($lawyer))->toBe(40_000_000)
        ->and($byPersonRow('lawyer', $this->lawyerA))->toBe(40_000_000)
        ->and($source($lawyer))->toBe(40_000_000);

    // Một nhân viên kinh doanh.
    $sales = ['sales_id' => $this->salesA->id];
    expect(rbpCards($sales)['collected']['amount'])->toBe(50_000_000)
        ->and($lastColumn($sales))->toBe(50_000_000)
        ->and($byPersonRow('sales', $this->salesA))->toBe(50_000_000)
        ->and($source($sales))->toBe(50_000_000);
});

// =================================================================================================
// Widget "Doanh thu theo người".
// =================================================================================================

it('ranks the money collected in the period per lawyer, highest first', function () {
    rbpOffice($this);
    $this->actingAs($this->admin, 'web');

    $rows = rbpRows(RevenueByPersonWidget::class, [], 'lawyer');

    expect($rows)->toBe([
        ['label' => 'Đỗ Quốc Bảo', 'value' => Money::format(40_000_000)],
        ['label' => 'Trần Minh Châu', 'value' => Money::format(25_000_000)],
    ]);
});

it('ranks the money collected in the period per sales person, with matters not brought in by anyone kept apart at the end', function () {
    rbpOffice($this);
    $this->actingAs($this->admin, 'web');

    $rows = rbpRows(RevenueByPersonWidget::class, [], 'sales');

    expect($rows)->toBe([
        ['label' => 'Đinh Thị Kinh Doanh', 'value' => Money::format(50_000_000)],
        ['label' => 'Lý Văn Bán', 'value' => Money::format(10_000_000)],
        ['label' => __('widgets.revenue_dashboard.by_person.no_sales'), 'value' => Money::format(5_000_000)],
    ]);

    // Cột "không qua nhân viên kinh doanh" xám, cả khi đang chọn một người.
    expect(rbpData(RevenueByPersonWidget::class, ['sales_id' => $this->salesB->id], 'sales')['datasets'][0]['backgroundColor'])
        ->toBe([RevenueColours::PERSON_OTHER, RevenueColours::PERSON_SELECTED, RevenueColours::PERSON_NONE]);
});

it('opens on the lawyers and offers the two ways of splitting, and draws one horizontal bar per person', function () {
    rbpOffice($this);
    $this->actingAs($this->admin, 'web');

    $component = Livewire::test(RevenueByPersonWidget::class, ['pageFilters' => []]);
    $html = $component->html();

    expect($component->get('filter'))->toBe('lawyer')
        ->and($html)->toContain(__('widgets.revenue_dashboard.by_person.filter_lawyer'))
        ->toContain(__('widgets.revenue_dashboard.by_person.filter_sales'))
        ->and($component->instance()->chartOptionsForKind()['indexAxis'])->toBe('y');
});

it('opens on the sales people when only a sales person is chosen on the page', function () {
    $this->actingAs($this->admin, 'web');

    expect(Livewire::test(RevenueByPersonWidget::class, ['pageFilters' => ['sales_id' => $this->salesA->id]])->get('filter'))->toBe('sales');
});

it('keeps showing everyone while a person is chosen, and paints the chosen one apart from the others', function () {
    rbpOffice($this);
    $this->actingAs($this->admin, 'web');

    $data = rbpData(RevenueByPersonWidget::class, ['lawyer_id' => $this->lawyerB->id], 'lawyer');

    expect($data['datasets'][0]['data'])->toBe([40_000_000, 25_000_000])
        ->and($data['datasets'][0]['backgroundColor'])->toBe([RevenueColours::PERSON_OTHER, RevenueColours::PERSON_SELECTED]);

    $plain = rbpData(RevenueByPersonWidget::class, [], 'lawyer');

    expect($plain['datasets'][0]['backgroundColor'])->toBe([RevenueColours::PERSON_SELECTED, RevenueColours::PERSON_SELECTED]);

    // Bảng số nói bằng chữ ai đang được xem (không chỉ bằng màu).
    expect(rbpRows(RevenueByPersonWidget::class, ['lawyer_id' => $this->lawyerB->id], 'lawyer')[1]['label'])
        ->toBe(__('widgets.revenue_dashboard.by_person.selected', ['name' => 'Trần Minh Châu']));
});

it('still shows the chosen person at zero when they collected nothing in the period', function () {
    rbpOffice($this);
    $idle = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư chưa thu Giáp']);
    rbpMatter($idle);

    $this->actingAs($this->admin, 'web');

    $rows = rbpRows(RevenueByPersonWidget::class, ['lawyer_id' => $idle->id], 'lawyer');

    expect(end($rows))->toBe(['label' => __('widgets.revenue_dashboard.by_person.selected', ['name' => 'Luật sư chưa thu Giáp']), 'value' => Money::format(0)]);
});

it('follows the period and the practice area of the page', function () {
    $land = MatterType::factory()->withStages()->create(['name' => 'Đất đai và bất động sản']);
    $family = MatterType::factory()->withStages()->create(['name' => 'Hôn nhân gia đình']);

    rbpPay(rbpContract(rbpMatter($this->lawyerA, $this->salesA, $land), 50_000_000), 7_000_000, $this->lawyerA);
    rbpPay(rbpContract(rbpMatter($this->lawyerA, $this->salesA, $family), 50_000_000), 3_000_000, $this->lawyerA);
    rbpPay(rbpContract(rbpMatter($this->lawyerA, $this->salesA, $family), 50_000_000), 11_000_000, $this->lawyerA, '2026-08-10');

    $this->actingAs($this->admin, 'web');

    expect(rbpRows(RevenueByPersonWidget::class, ['practice_area_id' => $land->id], 'sales'))
        ->toBe([['label' => 'Đinh Thị Kinh Doanh', 'value' => Money::format(7_000_000)]])
        ->and(rbpRows(RevenueByPersonWidget::class, [], 'sales'))
        ->toBe([['label' => 'Đinh Thị Kinh Doanh', 'value' => Money::format(10_000_000)]])
        ->and(rbpRows(RevenueByPersonWidget::class, ['period' => 'this_year'], 'sales'))
        ->toBe([['label' => 'Đinh Thị Kinh Doanh', 'value' => Money::format(21_000_000)]]);
});

it('is only for people who may see the whole office revenue: a lawyer neither gets it on the page nor can mount it', function () {
    $this->actingAs($this->lawyerA, 'web');

    expect(RevenueByPersonWidget::canView())->toBeFalse();
    Livewire::test(RevenueByPersonWidget::class, ['pageFilters' => []])->assertStatus(404);

    foreach (['accountant', 'admin'] as $who) {
        $this->actingAs($this->{$who}, 'web');
        expect(RevenueByPersonWidget::canView())->toBeTrue();
    }

    $this->actingAs($this->admin, 'web');
    expect(Livewire::test(RevenueDashboard::class)->instance()->getWidgets())->toContain(RevenueByPersonWidget::class);
});

it('never shows the accountant the money or the name tied only to a restricted matter, while the admin sees both', function () {
    // Đã nghỉ: một nhân viên kinh doanh ĐANG LÀM luôn có trong ô chọn (danh sách nhân sự, không phải tiền).
    $hiddenSales = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh doanh vụ kín Nhâm', 'is_active' => false]);
    $hiddenLawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư vụ kín Quý']);

    rbpPay(rbpContract(rbpMatter($this->lawyerA, $this->salesA), 10_000_000), 4_000_000, $this->lawyerA);
    rbpPay(rbpContract(rbpMatter($hiddenLawyer, $hiddenSales, restricted: true), 90_000_000), 60_000_000, $hiddenLawyer);

    $this->actingAs($this->accountant, 'web');

    foreach (['lawyer', 'sales'] as $mode) {
        $component = Livewire::test(RevenueByPersonWidget::class, ['pageFilters' => []])->set('filter', $mode);
        $rows = $component->instance()->numberTableRows();

        expect(collect($rows)->sum(fn (array $row): int => rbpMoney($row['value'])))->toBe(4_000_000)
            ->and($component->html())->not->toContain('Nhâm')->not->toContain('Quý');
    }

    // Chọn thẳng id của người đó (gõ tay) cũng không đưa tên ra.
    $typed = Livewire::test(RevenueByPersonWidget::class, ['pageFilters' => ['sales_id' => $hiddenSales->id]])->set('filter', 'sales');
    expect($typed->html())->not->toContain('Nhâm');

    $this->actingAs($this->admin, 'web');

    expect(collect(rbpRows(RevenueByPersonWidget::class, [], 'sales'))->pluck('label')->all())->toContain('Kinh doanh vụ kín Nhâm');
});

// =================================================================================================
// Bộ lọc của trang.
// =================================================================================================

it('offers active sales people and former handlers of a converted matter with money, never one tied only to a restricted matter', function () {
    $former = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh doanh đã nghỉ Ất', 'is_active' => false]);
    rbpContract(rbpMatter($this->lawyerA, $former), 5_000_000);

    $formerNoMoney = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh doanh đã nghỉ chưa có tiền Bính', 'is_active' => false]);
    rbpMatter($this->lawyerA, $formerNoMoney);
    // Hợp đồng còn nháp chưa phải tiền.
    Contract::factory()->for(rbpMatter($this->lawyerA, $formerNoMoney))->create(['total_amount' => 9_000_000, 'signed_at' => null]);

    $hidden = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh doanh vụ kín Đinh Mậu', 'is_active' => false]);
    rbpContract(rbpMatter($this->lawyerA, $hidden, restricted: true), 5_000_000);

    // Tài khoản đã xoá (mềm), chưa đem về hồ sơ nào: không còn là nhân sự đang làm.
    $deleted = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh doanh đã xoá Tân']);
    $deleted->delete();

    // Tài khoản đã xoá (mềm) nhưng đã đem về một hồ sơ có tiền: vẫn chọn được — tiền đó vẫn là của người đó.
    $deletedWithMoney = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh doanh đã xoá có tiền Nhâm']);
    rbpContract(rbpMatter($this->lawyerA, $deletedWithMoney), 5_000_000);
    $deletedWithMoney->delete();

    $this->actingAs($this->accountant, 'web');

    $options = RevenueDashboard::salesOptions();

    expect($options)->toContain('Đinh Thị Kinh Doanh', 'Lý Văn Bán', 'Kinh doanh đã nghỉ Ất', 'Kinh doanh đã xoá có tiền Nhâm')
        ->not->toContain('Kinh doanh đã nghỉ chưa có tiền Bính')
        ->not->toContain('Kinh doanh vụ kín Đinh Mậu')
        ->not->toContain('Kinh doanh đã xoá Tân')
        ->not->toContain('Đỗ Quốc Bảo');

    $page = Livewire::test(RevenueDashboard::class);
    $select = collect($page->instance()->getFiltersForm()->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof Select && $component->getName() === 'sales_id');

    expect($select)->not->toBeNull()
        ->and($select->getLabel())->toBe(__('widgets.revenue_dashboard.filters.sales'))
        ->and($select->hasDynamicSearchResults())->toBeTrue();

    $results = null;
    $page->call('callSchemaComponentMethod', $select->getKey(), 'getSearchResultsForJs', ['search' => 'dinh kinh'])
        ->assertReturned(function (mixed $returned) use (&$results): bool {
            $results = $returned;

            return is_array($returned);
        });

    expect(collect($results)->pluck('label')->all())->toBe(['Đinh Thị Kinh Doanh']);
});

it('says "all" in the empty person boxes and "all practice areas" in the empty practice area box', function () {
    $this->actingAs($this->admin, 'web');

    $selects = collect(Livewire::test(RevenueDashboard::class)->instance()->getFiltersForm()->getFlatComponents(withHidden: true))
        ->filter(fn ($component): bool => $component instanceof Select)
        ->keyBy(fn (Select $select): string => $select->getName());

    expect($selects['practice_area_id']->getPlaceholder())->toBe('Tất cả lĩnh vực')
        ->and($selects['lawyer_id']->getPlaceholder())->toBe('Tất cả')
        ->and($selects['sales_id']->getPlaceholder())->toBe('Tất cả');
});

it('goes back to every practice area when the chosen person changes', function () {
    $type = MatterType::factory()->withStages()->create();
    $this->actingAs($this->admin, 'web');

    Livewire::test(RevenueDashboard::class)
        ->set('filters.practice_area_id', $type->id)
        ->set('filters.lawyer_id', $this->lawyerA->id)
        ->assertSet('filters.practice_area_id', null)
        ->set('filters.practice_area_id', $type->id)
        ->set('filters.sales_id', $this->salesA->id)
        ->assertSet('filters.practice_area_id', null)
        // Đổi lĩnh vực thì người đang chọn giữ nguyên.
        ->set('filters.practice_area_id', $type->id)
        ->assertSet('filters.sales_id', $this->salesA->id)
        ->assertSet('filters.lawyer_id', $this->lawyerA->id);
});

it('says under the title whose money is on screen and by which rule', function () {
    rbpOffice($this);
    $this->actingAs($this->admin, 'web');

    $page = Livewire::test(RevenueDashboard::class);

    expect((string) $page->instance()->getSubheading())->toBe(__('billing.receivables.scope_note'));

    $page->set('filters.lawyer_id', $this->lawyerA->id);
    expect((string) $page->instance()->getSubheading())
        ->toContain(__('widgets.revenue_dashboard.viewing.lawyer', ['name' => 'Đỗ Quốc Bảo']))
        ->toContain(__('billing.receivables.scope_note'));

    $page->set('filters.lawyer_id', null)->set('filters.sales_id', $this->salesA->id);
    expect((string) $page->instance()->getSubheading())
        ->toContain(__('widgets.revenue_dashboard.viewing.sales', ['name' => 'Đinh Thị Kinh Doanh']))
        ->not->toContain('Đỗ Quốc Bảo');
});

it('never prints under the title the name of a person the viewer could not have picked', function () {
    $hidden = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh doanh vụ kín Canh', 'is_active' => false]);
    rbpContract(rbpMatter($this->lawyerA, $hidden, restricted: true), 5_000_000);

    $this->actingAs($this->accountant, 'web');

    $page = Livewire::test(RevenueDashboard::class)->set('filters.sales_id', $hidden->id);

    expect((string) $page->instance()->getSubheading())->not->toContain('Canh');
});

// =================================================================================================
// "Tải theo luật sư" vẫn hiện mọi người khi đang chọn một luật sư — tô người đó.
// =================================================================================================

it('keeps every lawyer on the load chart when one lawyer is chosen, and paints the chosen one apart', function () {
    rbpMatter($this->lawyerA);
    rbpMatter($this->lawyerA);
    rbpMatter($this->lawyerB);

    $this->actingAs($this->admin, 'web');

    $data = rbpData(LoadPerLawyerWidget::class, ['lawyer_id' => $this->lawyerB->id]);

    expect($data['labels'])->toBe(['Đỗ Quốc Bảo', 'Trần Minh Châu'])
        ->and($data['datasets'][0]['data'])->toBe([2, 1])
        ->and($data['datasets'][0]['backgroundColor'])->toBe([RevenueColours::PERSON_OTHER, RevenueColours::PERSON_SELECTED]);
});
