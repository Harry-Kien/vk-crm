<?php

use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Enums\Role;
use App\Filament\Admin\Pages\RevenueDashboard;
use App\Filament\Admin\Widgets\Revenue\ClosedWithBalanceWidget;
use App\Filament\Admin\Widgets\Revenue\LoadPerLawyerWidget;
use App\Filament\Admin\Widgets\Revenue\MatterMixByPracticeAreaWidget;
use App\Filament\Admin\Widgets\Revenue\ReceivablesDonutWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueByStageWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueOverTimeWidget;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

/**
 * Trang doanh thu (M9 Task 9). Ba tầng test: cổng của trang (`canAccess`), cổng của hai widget
 * toàn văn phòng (`canView`), và dữ liệu từng widget đọc qua Livewire — không gọi thẳng Action.
 *
 * `widgetData()`/`widgetRows()` đọc `getData()`/`numberTableRows()` của widget đã MOUNT thật qua
 * Livewire (đăng nhập, `pageFilters` truyền lúc mount — xem docblock hàm `widgetData()` bên dưới
 * về vì sao không phải `->set()`), không phải gọi thẳng một hàm tính toán tách rời — cùng tinh
 * thần "test màn hình đi qua Livewire" của M6.5, áp dụng cho widget.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
});

/** Hợp đồng ACTIVE, một đợt bằng đúng giá trị hợp đồng — cùng thành ngữ ReceivablesPageTest. */
function signedContract(Matter $matter, int $amount, ?string $signedAt = null, ?string $dueDate = null): Contract
{
    $contract = Contract::factory()->for($matter)->active()->create([
        'total_amount' => $amount,
        'signed_at' => $signedAt ?? today()->toDateString(),
    ]);

    Instalment::factory()->for($contract)->create([
        'amount' => $amount,
        'due_date' => $dueDate ?? today()->addDays(30)->toDateString(),
    ]);

    return $contract;
}

/**
 * Hợp đồng nhiều đợt (donut cần đủ paid/overdue/waived/pending cùng lúc để chứng minh phép cộng).
 * `Contract::factory()->active()` tạo thẳng qua `create()` nên KHÔNG bị canh bất biến tổng (hook
 * là `updating`, không phải `saving` — xem docblock `ScheduleTotal`), nhưng MỖI đợt tạo trên một
 * hợp đồng ĐÃ active lại bị canh NGAY LẬP TỨC lúc chính nó `saving` (hook không đợi đủ mặt các
 * đợt khác) — nên dựng nhiều đợt lẻ trên một hợp đồng active thất bại ở đợt ĐẦU TIÊN nếu nó không
 * bằng đúng total. Cách đúng: dựng lịch thu trong lúc hợp đồng còn `draft` (hook chỉ canh hợp đồng
 * `active`), rồi mới chuyển sang `active` sau khi lịch đã khớp tổng.
 *
 * @param  list<array<string, mixed>>  $instalments
 */
function contractWithSchedule(Matter $matter, int $total, array $instalments, string $signedAt): Contract
{
    $contract = Contract::factory()->for($matter)->create([
        'total_amount' => $total,
        'signed_at' => null,
    ]);

    foreach ($instalments as $index => $overrides) {
        Instalment::factory()->for($contract)->create(['sequence' => $index + 1, ...$overrides]);
    }

    $contract->update(['status' => ContractStatus::Active, 'signed_at' => $signedAt]);

    return $contract->fresh();
}

/**
 * `pageFilters` là một prop `#[Reactive]` (`InteractsWithPageFilters`) — Livewire chỉ chấp nhận
 * nó được gán LÚC MOUNT (như một trang cha truyền xuống), không chấp nhận `->set()` sau đó
 * (`CannotMutateReactivePropException`). Truyền qua tham số thứ hai của `Livewire::test()`, đúng
 * đường một trang cha thật sự truyền `pageFilters` xuống widget.
 *
 * @return array<string, mixed> `getData()` của widget, mount thật qua Livewire.
 */
function widgetData(string $class, array $pageFilters = []): array
{
    $instance = Livewire::test($class, ['pageFilters' => $pageFilters])->instance();
    $method = new ReflectionMethod($instance, 'getData');
    $method->setAccessible(true);

    return $method->invoke($instance);
}

/** @return list<array{label: string, value: string}> */
function widgetRows(string $class, array $pageFilters = []): array
{
    return Livewire::test($class, ['pageFilters' => $pageFilters])->instance()->numberTableRows();
}

function widgetDescription(string $class, array $pageFilters = []): string
{
    $description = Livewire::test($class, ['pageFilters' => $pageFilters])->instance()->getDescription();

    return (string) $description;
}

// =================================================================================================
// Cổng của trang — billing.view, KHÔNG revenue.viewAny (khác trang "Công nợ").
// =================================================================================================

it('lets a lawyer, an accountant, a manager and an admin open the revenue page', function () {
    foreach (['lawyer', 'accountant', 'manager', 'admin'] as $key) {
        $this->actingAs($this->{$key}, 'web')
            ->get(RevenueDashboard::getUrl(panel: 'admin'))
            ->assertOk();
    }
});

it('answers 404 to an assistant, who has neither billing.view nor revenue.viewAny', function () {
    $this->actingAs($this->assistant, 'web')
        ->get(RevenueDashboard::getUrl(panel: 'admin'))
        ->assertNotFound();
});

// =================================================================================================
// Cổng của hai widget toàn văn phòng — đòi thêm revenue.viewAny (test bắt buộc).
// =================================================================================================

it('never gives the lawyer the two office-wide widgets, but gives them to accountant, manager and admin', function () {
    $this->actingAs($this->lawyer, 'web');
    expect(MatterMixByPracticeAreaWidget::canView())->toBeFalse()
        ->and(LoadPerLawyerWidget::canView())->toBeFalse();

    foreach (['accountant', 'manager', 'admin'] as $key) {
        $this->actingAs($this->{$key}, 'web');
        expect(MatterMixByPracticeAreaWidget::canView())->toBeTrue()
            ->and(LoadPerLawyerWidget::canView())->toBeTrue();
    }
});

// =================================================================================================
// Luật sư chỉ thấy số liệu của vụ mình (test bắt buộc): hai luật sư, hai con số khác nhau.
// =================================================================================================

it('shows two different lawyers two different donut totals, each their own matters only', function () {
    $lawyerA = User::factory()->withRole(Role::Lawyer)->create();
    $lawyerB = User::factory()->withRole(Role::Lawyer)->create();

    $matterA = Matter::factory()->create(['lead_lawyer_id' => $lawyerA->id]);
    $matterB = Matter::factory()->create(['lead_lawyer_id' => $lawyerB->id]);

    signedContract($matterA, 10_000_000);
    signedContract($matterB, 25_000_000);

    $this->actingAs($lawyerA, 'web');
    $dataA = widgetData(ReceivablesDonutWidget::class);

    $this->actingAs($lawyerB, 'web');
    $dataB = widgetData(ReceivablesDonutWidget::class);

    expect(array_sum($dataA['datasets'][0]['data']))->toBe(10_000_000)
        ->and(array_sum($dataB['datasets'][0]['data']))->toBe(25_000_000);
});

// =================================================================================================
// Kế toán/quản lý thấy mọi vụ THƯỜNG, không thấy vụ restricted; admin thấy cả restricted.
// =================================================================================================

it('shows the accountant and the manager every ordinary matter but never a restricted one, while the admin sees both', function () {
    $ordinary = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);

    signedContract($ordinary, 10_000_000);
    signedContract($restricted, 40_000_000);

    foreach (['accountant', 'manager'] as $key) {
        $this->actingAs($this->{$key}, 'web');
        $data = widgetData(ReceivablesDonutWidget::class);
        expect(array_sum($data['datasets'][0]['data']))->toBe(10_000_000);
    }

    $this->actingAs($this->admin, 'web');
    $data = widgetData(ReceivablesDonutWidget::class);
    expect(array_sum($data['datasets'][0]['data']))->toBe(50_000_000);
});

// =================================================================================================
// Trang không in số vụ bị loại (SPEC §10.10) — chỉ câu chung, không con số.
// =================================================================================================

it('never prints the count of matters excluded from the accountants totals, only the neutral scope sentence', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);
    signedContract($restricted, 40_000_000);

    $response = $this->actingAs($this->accountant, 'web')->get(RevenueDashboard::getUrl(panel: 'admin'));

    $response->assertOk();
    $response->assertSee(__('billing.receivables.scope_note'));
    $response->assertDontSee('hạn chế');
    $response->assertDontSee('restricted');
});

// =================================================================================================
// Ba lát donut cộng lại đúng "tổng giá trị đã ký trong kỳ trừ phần đã miễn" (test bắt buộc).
// =================================================================================================

it('adds the three donut slices up to exactly the signed total for the period, minus what was waived', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $contract = contractWithSchedule($matter, 30_000_000, [
        ['amount' => 10_000_000, 'due_date' => today()->subDays(10)->toDateString(), 'status' => InstalmentStatus::Paid],
        ['amount' => 8_000_000, 'due_date' => today()->subDays(5)->toDateString()],
        ['amount' => 5_000_000, 'due_date' => today()->subDays(1)->toDateString(), 'status' => InstalmentStatus::Waived, 'waived_reason' => str_repeat('a', 20), 'waived_at' => now()],
        ['amount' => 7_000_000, 'due_date' => today()->addDays(20)->toDateString()],
    ], today()->toDateString());

    $paidInstalment = $contract->instalments()->where('amount', 10_000_000)->sole();
    Payment::factory()->for($paidInstalment)->create(['amount' => 10_000_000, 'paid_on' => today()->toDateString(), 'attributed_lawyer_id' => $this->lawyer->id]);

    $this->actingAs($this->lawyer, 'web');
    $data = widgetData(ReceivablesDonutWidget::class);
    [$collected, $notYetDue, $overdueAmount] = $data['datasets'][0]['data'];

    expect($collected)->toBe(10_000_000)
        ->and($overdueAmount)->toBe(8_000_000)
        ->and($notYetDue)->toBe(7_000_000)
        ->and($collected + $notYetDue + $overdueAmount)->toBe(25_000_000); // 30tr đã ký - 5tr đã miễn
});

// =================================================================================================
// Đổi bộ lọc thời gian đổi đúng widget nói rằng nó đổi (payments.paid_on), KHÔNG đổi widget nói
// rằng nó không phụ thuộc thời gian (LoadPerLawyerWidget).
// =================================================================================================

it('changes the over-time widget when the period changes, but leaves the time-independent load-per-lawyer widget alone', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $contract = signedContract($matter, 12_000_000, today()->subMonths(2)->toDateString());
    $instalment = $contract->instalments->sole();
    Payment::factory()->for($instalment)->create([
        'amount' => 12_000_000,
        'paid_on' => today()->toDateString(),
        'attributed_lawyer_id' => $this->lawyer->id,
    ]);

    $this->actingAs($this->lawyer, 'web');

    $inRange = widgetData(RevenueOverTimeWidget::class, ['period' => 'this_month']);
    $outOfRange = widgetData(RevenueOverTimeWidget::class, [
        'period' => 'custom',
        'date_from' => today()->subYears(2)->toDateString(),
        'date_to' => today()->subYears(2)->addDay()->toDateString(),
    ]);

    expect(array_sum($inRange['datasets'][0]['data']))->toBe(12_000_000)
        ->and(array_sum($outOfRange['datasets'][0]['data']))->toBe(0);

    $loadThisMonth = widgetData(LoadPerLawyerWidget::class, ['period' => 'this_month']);
    $loadTwoYearsAgo = widgetData(LoadPerLawyerWidget::class, [
        'period' => 'custom',
        'date_from' => today()->subYears(2)->toDateString(),
        'date_to' => today()->subYears(2)->addDay()->toDateString(),
    ]);

    expect($loadThisMonth)->toBe($loadTwoYearsAgo);
});

// =================================================================================================
// Bộ lọc luật sư: khoản thu trước bàn giao vẫn tính cho luật sư CŨ; còn phải thu tính cho luật sư
// MỚI (P2, test bắt buộc).
// =================================================================================================

it('keeps a collected payment with the old lawyer after handover, but moves the outstanding balance to the new one', function () {
    $oldLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $newLawyer = User::factory()->withRole(Role::Lawyer)->create();

    $matter = Matter::factory()->create(['lead_lawyer_id' => $oldLawyer->id]);

    $contract = contractWithSchedule($matter, 20_000_000, [
        ['amount' => 8_000_000, 'due_date' => today()->subDays(3)->toDateString(), 'status' => InstalmentStatus::Paid],
        ['amount' => 12_000_000, 'due_date' => today()->addDays(10)->toDateString()],
    ], today()->toDateString());

    $paidInstalment = $contract->instalments()->where('amount', 8_000_000)->sole();
    Payment::factory()->for($paidInstalment)->create(['amount' => 8_000_000, 'paid_on' => today()->toDateString(), 'attributed_lawyer_id' => $oldLawyer->id]);

    // Bàn giao: đổi luật sư phụ trách hiện tại của vụ (ReassignMatter thật sự sẽ làm việc này;
    // ở đây chỉ cần đúng cột đổi để đo widget, không kiểm Action bàn giao).
    $matter->update(['lead_lawyer_id' => $newLawyer->id]);

    $this->actingAs($this->admin, 'web');

    $overTimeOldLawyer = widgetData(RevenueOverTimeWidget::class, ['lawyer_id' => $oldLawyer->id]);
    $overTimeNewLawyer = widgetData(RevenueOverTimeWidget::class, ['lawyer_id' => $newLawyer->id]);

    expect(array_sum($overTimeOldLawyer['datasets'][0]['data']))->toBe(8_000_000)
        ->and(array_sum($overTimeNewLawyer['datasets'][0]['data']))->toBe(0);

    // Donut lọc luật sư theo MỘT nghĩa duy nhất cho cả ba lát — matters.lead_lawyer_id HIỆN TẠI
    // (xem docblock ReceivablesDonutWidget): lọc theo luật sư mới cho thấy TOÀN BỘ bức tranh của
    // vụ (đã thu 8tr + còn phải thu 12tr = đúng 20tr đã ký), không chỉ phần còn lại — nhưng đúng
    // lát "còn phải thu, chưa tới hạn" (chỉ số 1) là đúng con số 12tr mà test này cần chứng minh
    // ("còn phải thu tính cho luật sư MỚI"). Lọc theo luật sư CŨ không còn thấy vụ này (đã đổi lead
    // lawyer_id), nên cả ba lát về 0 — không mâu thuẫn với việc RevenueOverTimeWidget (ở trên) vẫn
    // cho luật sư CŨ thấy khoản đã thu qua `attributed_lawyer_id`, một trục lọc KHÁC.
    $donutNewLawyer = widgetData(ReceivablesDonutWidget::class, ['lawyer_id' => $newLawyer->id]);
    $donutOldLawyer = widgetData(ReceivablesDonutWidget::class, ['lawyer_id' => $oldLawyer->id]);

    expect($donutNewLawyer['datasets'][0]['data'][1])->toBe(12_000_000)
        ->and(array_sum($donutOldLawyer['datasets'][0]['data']))->toBe(0);
});

// =================================================================================================
// Vụ xoá mềm không xuất hiện; khoản thu đã huỷ không được cộng.
// =================================================================================================

it('excludes a soft-deleted matters contract and a voided payment from every total', function () {
    // Vụ còn dư nợ KHÔNG xoá mềm được (M9 Task 5, MatterHasOutstandingBalance) — nên để trashed
    // được, đợt của nó phải thu ĐỦ trước (outstanding = 0), rồi mới `delete()`. Bản thân test vẫn
    // đo đúng điều cần đo: một hợp đồng ĐÃ THU ĐỦ trên một vụ trashed không được cộng vào "đã thu"
    // của bất kỳ ai, kể cả luật sư phụ trách của nó.
    $trashedMatter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $trashedContract = Contract::factory()->for($trashedMatter)->active()->create(['total_amount' => 15_000_000, 'signed_at' => today()->toDateString()]);
    $trashedInstalment = Instalment::factory()->for($trashedContract)->create(['amount' => 15_000_000, 'due_date' => today()->subDays(2)->toDateString(), 'status' => InstalmentStatus::Paid]);
    Payment::factory()->for($trashedInstalment)->create(['amount' => 15_000_000, 'paid_on' => today()->toDateString(), 'attributed_lawyer_id' => $this->lawyer->id]);
    $trashedMatter->delete();

    $liveMatter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $contract = Contract::factory()->for($liveMatter)->active()->create(['total_amount' => 9_000_000, 'signed_at' => today()->toDateString()]);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 9_000_000, 'due_date' => today()->subDays(2)->toDateString()]);
    Payment::factory()->for($instalment)->voided()->create([
        'amount' => 9_000_000,
        'paid_on' => today()->toDateString(),
        'attributed_lawyer_id' => $this->lawyer->id,
    ]);

    $this->actingAs($this->lawyer, 'web');
    $data = widgetData(ReceivablesDonutWidget::class);

    // Vụ xoá mềm (15tr) không tính; khoản thu đã huỷ (9tr) không cộng vào "đã thu" — cả khoản đó
    // vẫn là "còn phải thu" (huỷ khoản thu không huỷ đợt).
    [$collected, $notYetDue, $overdueAmount] = $data['datasets'][0]['data'];
    expect($collected)->toBe(0)
        ->and($notYetDue + $overdueAmount)->toBe(9_000_000);
});

// =================================================================================================
// Đủ mọi lĩnh vực, kể cả lĩnh vực 0 vụ (test bắt buộc, dựng 12 loại vụ việc cho chính test này —
// Task 1 hoãn nên hôm nay chỉ có bấy nhiêu loại đã seed; đủ 12 kiểm ở đây bằng fixture riêng).
// =================================================================================================

it('lists every practice area even one with zero matters, all twelve of them', function () {
    MatterType::query()->delete();
    // Chỉ loại DÙNG bởi một vụ việc cần bộ giai đoạn (Matter::booted() đòi firstStage()); mười một
    // loại còn lại không vụ nào trỏ tới, nên không cần dựng giai đoạn cho chúng.
    $typeWithMatter = MatterType::factory()->withStages()->create();
    MatterType::factory()->count(11)->create();

    $matterWithContract = Matter::factory()->create([
        'matter_type_id' => $typeWithMatter->id,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);
    signedContract($matterWithContract, 5_000_000);

    $this->actingAs($this->admin, 'web');
    $rows = widgetRows(MatterMixByPracticeAreaWidget::class);

    expect($rows)->toHaveCount(12);
});

// =================================================================================================
// Mỗi widget in nghĩa của bộ lọc thời gian và bộ lọc luật sư lên chính nó (test bắt buộc).
// =================================================================================================

it('prints the meaning of the time filter and the lawyer filter on every widget that filters by them', function () {
    $this->actingAs($this->admin, 'web');

    expect(widgetDescription(ReceivablesDonutWidget::class))
        ->toContain('contracts.signed_at')
        ->toContain('lead_lawyer_id');

    expect(widgetDescription(RevenueOverTimeWidget::class))
        ->toContain('payments.paid_on')
        ->toContain('attributed_lawyer_id');

    expect(widgetDescription(RevenueByStageWidget::class))
        ->toContain('payments.paid_on')
        ->toContain('attributed_lawyer_id');

    expect(widgetDescription(MatterMixByPracticeAreaWidget::class))
        ->toContain('contracts.signed_at')
        ->toContain('lead_lawyer_id');

    expect(widgetDescription(LoadPerLawyerWidget::class))
        ->toContain('KHÔNG phụ thuộc bộ lọc thời gian');
});

// =================================================================================================
// Trang chủ §7.1 không có widget doanh thu nào (test bắt buộc) — DashboardWidgetOrderTest riêng
// giữ thứ tự bốn widget cũ; đây chỉ khẳng định KHÔNG widget doanh thu nào lẫn vào Filament::getWidgets().
// =================================================================================================

it('never lets a revenue widget leak onto the home dashboard', function () {
    $registered = Filament::getWidgets();

    expect($registered)->not->toContain(ReceivablesDonutWidget::class)
        ->and($registered)->not->toContain(RevenueOverTimeWidget::class)
        ->and($registered)->not->toContain(RevenueByStageWidget::class)
        ->and($registered)->not->toContain(MatterMixByPracticeAreaWidget::class)
        ->and($registered)->not->toContain(LoadPerLawyerWidget::class)
        ->and($registered)->not->toContain(ClosedWithBalanceWidget::class);
});

// =================================================================================================
// Đếm truy vấn — chi phí thật của việc không có cột paid_amount (M9 Task 9, điểm 5).
//
// **Widget Filament mặc định LƯỜI (`CanBeLazy::$isLazy = true`, vendor/filament/support).** Một
// GET thường vào trang chỉ vẽ SHELL — mỗi widget tự tải dữ liệu qua một request Livewire RIÊNG
// sau đó (`wire:init`). Đo query trên chính GET đó cho ra một con số phẳng giả tạo (đã thử: N=3N
// vì cả hai lần đều không chạm widget nào cả — dán bằng chứng vào báo cáo Task 9). Đo ĐÚNG: mount
// từng widget qua `Livewire::test()` (đường mỗi request lười thật sự đi), cộng dồn truy vấn của cả
// sáu widget — đúng tổng chi phí một lượt tải đầy đủ trang, chỉ tách thành sáu round-trip thay vì
// một, không đổi TỔNG số việc CSDL phải làm.
// =================================================================================================

function countQueriesToMountAllRevenueWidgets(): int
{
    $widgets = [
        ReceivablesDonutWidget::class,
        RevenueOverTimeWidget::class,
        RevenueByStageWidget::class,
        MatterMixByPracticeAreaWidget::class,
        LoadPerLawyerWidget::class,
        ClosedWithBalanceWidget::class,
    ];

    $total = 0;
    foreach ($widgets as $widget) {
        DB::flushQueryLog();
        Livewire::test($widget);
        $total += count(DB::getQueryLog());
    }

    return $total;
}

it('measures and reports the query cost of mounting all six widgets for N matters versus 3N', function () {
    $this->actingAs($this->admin, 'web');

    $makeRow = function (): void {
        $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
        signedContract($matter, 5_000_000);
    };

    $makeRow();
    $makeRow();
    $makeRow();

    // Hâm nóng cache quyền trước khi đo (cùng lý do ReceivablesPageTest).
    countQueriesToMountAllRevenueWidgets();

    DB::enableQueryLog();
    $queriesForN = countQueriesToMountAllRevenueWidgets();

    $makeRow();
    $makeRow();
    $makeRow();
    $makeRow();
    $makeRow();
    $makeRow();

    $queriesFor3N = countQueriesToMountAllRevenueWidgets();
    DB::disableQueryLog();

    fwrite(STDERR, "\n[Task 9 query-count report] six widgets mounted separately — N matters: {$queriesForN} queries; 3N matters: {$queriesFor3N} queries.\n");

    // Báo cáo trung thực (điểm 5 của brief), KHÔNG ép về một hằng số bằng cách nới ngưỡng cho vừa
    // con số đo được: `RevenueByStageWidget` chạy một truy vấn phụ lấy `$matterIds` (không phụ
    // thuộc N — nó SELECT id, không SELECT theo N dòng); mọi widget còn lại gộp bằng một câu
    // SUM()/GROUP BY. Ngưỡng dưới đây là biên an toàn, KHÔNG phải con số kỳ vọng — số thật đo được
    // nằm trong dòng log ở trên và trong báo cáo Task 9.
    expect($queriesFor3N - $queriesForN)->toBeLessThanOrEqual(6);
})->group('query-count-report');

// =================================================================================================
// RevenueByStageWidget — bó theo giai đoạn, cộng hai bó "khác" có nhãn rõ ràng cho on_signing/due_date.
// =================================================================================================

it('buckets collected money by trigger stage, with clearly labelled buckets for the other two trigger types', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $contract = Contract::factory()->for($matter)->create(['total_amount' => 30_000_000, 'signed_at' => null]);

    $onSigning = Instalment::factory()->for($contract)->onSigning()->create(['sequence' => 1, 'amount' => 10_000_000]);
    $onStage = Instalment::factory()->for($contract)->onStage('court_accepted')->create(['sequence' => 2, 'amount' => 12_000_000, 'due_date' => today()->toDateString()]);
    $onDueDate = Instalment::factory()->for($contract)->create(['sequence' => 3, 'amount' => 8_000_000, 'due_date' => today()->toDateString()]);

    $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->toDateString()]);

    foreach ([$onSigning, $onStage, $onDueDate] as $instalment) {
        Payment::factory()->for($instalment)->create([
            'amount' => $instalment->amount,
            'paid_on' => today()->toDateString(),
            'attributed_lawyer_id' => $this->lawyer->id,
        ]);
    }

    $this->actingAs($this->lawyer, 'web');
    $rows = widgetRows(RevenueByStageWidget::class);
    $byLabel = collect($rows)->pluck('value', 'label');

    expect($byLabel->keys()->contains(fn (string $label): bool => str_contains($label, 'Toà thụ lý')))->toBeTrue()
        ->and($byLabel->get(__('widgets.revenue_dashboard.by_stage.on_signing_bucket')))->toBe(Money::format(10_000_000))
        ->and($byLabel->get(__('widgets.revenue_dashboard.by_stage.due_date_bucket')))->toBe(Money::format(8_000_000));
});

// =================================================================================================
// MatterMixByPracticeAreaWidget — công tắc số vụ/số tiền đổi thứ tự xếp hạng (test bắt buộc,
// "đổi thứ được đo, không thêm trục thứ hai").
// =================================================================================================

it('flips the ranking order between amount and count when the by_count switch is toggled', function () {
    $bigOneOff = MatterType::factory()->withStages()->create(['name' => 'Lĩnh vực giá trị lớn']);
    $manySmall = MatterType::factory()->withStages()->create(['name' => 'Lĩnh vực nhiều vụ nhỏ']);

    $matterBig = Matter::factory()->create(['matter_type_id' => $bigOneOff->id, 'lead_lawyer_id' => $this->lawyer->id]);
    signedContract($matterBig, 100_000_000);

    foreach (range(1, 3) as $_) {
        $matterSmall = Matter::factory()->create(['matter_type_id' => $manySmall->id, 'lead_lawyer_id' => $this->lawyer->id]);
        signedContract($matterSmall, 10_000_000);
    }

    $this->actingAs($this->admin, 'web');

    $byAmount = widgetData(MatterMixByPracticeAreaWidget::class, []);
    $byCount = widgetData(MatterMixByPracticeAreaWidget::class, ['by_count' => true]);

    expect($byAmount['labels'][0])->toBe('Lĩnh vực giá trị lớn')
        ->and($byCount['labels'][0])->toBe('Lĩnh vực nhiều vụ nhỏ');
});

// =================================================================================================
// ClosedWithBalanceWidget — chỉ hồ sơ ĐÃ KẾT THÚC còn dư nợ; không phụ thuộc lĩnh vực/kỳ; kế toán
// không thấy vụ restricted, admin thấy.
// =================================================================================================

it('lists only closed matters that still carry a balance, hiding open ones, fully-paid ones and a restricted one from the accountant', function () {
    $closedWithBalance = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id, 'closed_at' => today()->subDay()->toDateString()]);
    signedContract($closedWithBalance, 5_000_000);

    $closedFullyPaid = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id, 'closed_at' => today()->subDay()->toDateString()]);
    $paidContract = Contract::factory()->for($closedFullyPaid)->active()->create(['total_amount' => 3_000_000, 'signed_at' => today()->toDateString()]);
    $paidInstalment = Instalment::factory()->for($paidContract)->create(['amount' => 3_000_000, 'due_date' => today()->subDays(2)->toDateString(), 'status' => InstalmentStatus::Paid]);
    Payment::factory()->for($paidInstalment)->create(['amount' => 3_000_000, 'paid_on' => today()->toDateString(), 'attributed_lawyer_id' => $this->lawyer->id]);

    $openWithBalance = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    signedContract($openWithBalance, 4_000_000);

    $restrictedClosed = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id, 'closed_at' => today()->subDay()->toDateString()]);
    signedContract($restrictedClosed, 7_000_000);

    $this->actingAs($this->accountant, 'web');
    Livewire::test(ClosedWithBalanceWidget::class)
        ->assertCanSeeTableRecords([$closedWithBalance])
        ->assertCanNotSeeTableRecords([$closedFullyPaid, $openWithBalance, $restrictedClosed]);

    $this->actingAs($this->admin, 'web');
    Livewire::test(ClosedWithBalanceWidget::class)
        ->assertCanSeeTableRecords([$closedWithBalance, $restrictedClosed]);
});
