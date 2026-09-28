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
use App\Models\MatterTypeStage;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\BillingSummary;
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

/**
 * `numberTableRows()` trả giá trị đã qua `Money::format()` (có "₫" và dấu chấm nhóm nghìn) — KHÔNG
 * đọc lại được bằng `Money::parse()` (nó cố tình từ chối mọi ký tự ngoài chữ số và dấu chấm nhóm
 * ba, để không đoán nghĩa một chuỗi gõ sai). Test ở đây chỉ cần đọc LẠI một chuỗi CHÍNH NÓ đã tự
 * sinh ra bằng `Money::format()`, nên bóc "₫" trước khi gọi `Money::parse()` là an toàn — không
 * viết một bộ định dạng/đọc tiền thứ hai, chỉ là bước ngược lại của bước hiển thị.
 */
function parseMoneyRow(string $formatted): int
{
    return Money::parse(trim(str_replace('₫', '', $formatted)));
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

    // Fix round 1, I2: donut lọc luật sư theo ĐÚNG P2 — mỗi lát mang nghĩa riêng. "Đã thu" (chỉ
    // số 0) lọc theo payments.attributed_lawyer_id: khoản 8tr vẫn tính cho luật sư CŨ, dù matter
    // giờ đã đổi lead_lawyer_id. "Còn phải thu"/"quá hạn" (chỉ số 1, 2) lọc theo
    // matters.lead_lawyer_id HIỆN TẠI: 12tr còn lại giờ thuộc về luật sư MỚI, không phải cũ.
    $donutNewLawyer = widgetData(ReceivablesDonutWidget::class, ['lawyer_id' => $newLawyer->id]);
    $donutOldLawyer = widgetData(ReceivablesDonutWidget::class, ['lawyer_id' => $oldLawyer->id]);

    // Luật sư MỚI: không thu đồng nào (khoản 8tr đã ghi cho người cũ), nhưng đang gánh 12tr còn
    // phải thu (vụ giờ do người mới phụ trách).
    expect($donutNewLawyer['datasets'][0]['data'])->toBe([0, 12_000_000, 0]);

    // Luật sư CŨ: vẫn đứng tên đã thu 8tr (lịch sử không đổi), nhưng không còn gánh khoản còn phải
    // thu nào (vụ không còn do người cũ phụ trách).
    expect($donutOldLawyer['datasets'][0]['data'])->toBe([8_000_000, 0, 0]);
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

    // Fix round 1, I6: LoadPerLawyerWidget và ClosedWithBalanceWidget áp bộ lọc luật sư (hiện tại)
    // nhưng bản trước không nói ra — giờ cả hai phải nêu rõ cột `lead_lawyer_id`.
    expect(widgetDescription(LoadPerLawyerWidget::class))
        ->toContain('KHÔNG phụ thuộc bộ lọc thời gian')
        ->toContain('lead_lawyer_id');

    $closedWithBalanceDescription = (string) Livewire::test(ClosedWithBalanceWidget::class, ['pageFilters' => []])
        ->instance()
        ->getTable()
        ->getDescription();

    expect($closedWithBalanceDescription)
        ->toContain('KHÔNG phụ thuộc bộ lọc thời gian')
        ->toContain('lead_lawyer_id');
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

// =================================================================================================
// Fix round 1, C1 (Critical) — "còn phải thu"/"quá hạn" chỉ từ BillingSummary/scopeOverdue(), không
// công thức trừ tay nào.
// =================================================================================================

/** (a) Một hợp đồng đã HUỶ không góp phần dư vào "chưa tới hạn". */
it('does not count a cancelled contracts pending instalment as receivable', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $contract = contractWithSchedule($matter, 100_000_000, [
        ['amount' => 20_000_000, 'due_date' => today()->subDays(5)->toDateString(), 'status' => InstalmentStatus::Paid],
        ['amount' => 80_000_000, 'due_date' => today()->addDays(20)->toDateString()],
    ], today()->toDateString());

    Payment::factory()->for($contract->instalments()->where('amount', 20_000_000)->sole())->create([
        'amount' => 20_000_000, 'paid_on' => today()->toDateString(), 'attributed_lawyer_id' => $this->lawyer->id,
    ]);

    $contract->update(['status' => ContractStatus::Cancelled, 'ended_at' => today()->toDateString(), 'ended_reason' => str_repeat('a', 20)]);

    $this->actingAs($this->lawyer, 'web');
    $data = widgetData(ReceivablesDonutWidget::class);
    [, $notYetDue, $overdue] = $data['datasets'][0]['data'];

    // BillingSummary đồng ý: hợp đồng cancelled không có gì "còn phải thu".
    expect(BillingSummary::outstandingForMatter($matter->id)['amount'])->toBe(0)
        ->and($notYetDue)->toBe(0)
        ->and($overdue)->toBe(0);

    $rows = widgetRows(ReceivablesDonutWidget::class);
    $cancelledRow = collect($rows)->firstWhere('label', __('widgets.revenue_dashboard.donut.table.cancelled_total'));
    expect($cancelledRow['value'])->toBe(Money::format(100_000_000));
});

/** (b) Một đợt đã thu MỘT PHẦN rồi mới được miễn không bị trừ hai lần. */
it('does not double-subtract a partly-paid instalment that is later waived', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $contract = contractWithSchedule($matter, 30_000_000, [
        ['amount' => 10_000_000, 'due_date' => today()->addDays(5)->toDateString()],
        ['amount' => 20_000_000, 'due_date' => today()->addDays(10)->toDateString()],
    ], today()->toDateString());

    $partlyPaid = $contract->instalments()->where('amount', 10_000_000)->sole();
    Payment::factory()->for($partlyPaid)->create(['amount' => 4_000_000, 'paid_on' => today()->toDateString(), 'attributed_lawyer_id' => $this->lawyer->id]);
    $partlyPaid->update(['status' => InstalmentStatus::Waived, 'waived_reason' => str_repeat('a', 20), 'waived_at' => now()]);

    $this->actingAs($this->lawyer, 'web');
    $data = widgetData(ReceivablesDonutWidget::class);
    [$collected, $notYetDue, $overdue] = $data['datasets'][0]['data'];

    // 4tr đã thu vẫn đứng; 20tr đợt kia còn nguyên — KHÔNG phải 16tr (30 - 10(mặt) - 4 - 0).
    expect($collected)->toBe(4_000_000)
        ->and($notYetDue + $overdue)->toBe(20_000_000);

    $rows = widgetRows(ReceivablesDonutWidget::class);
    $writtenOffRow = collect($rows)->firstWhere('label', __('widgets.revenue_dashboard.donut.table.written_off'));
    // Phần THẬT SỰ bị xoá là 10tr - 4tr đã thu = 6tr, không phải nguyên 10tr mặt giá trị.
    expect($writtenOffRow['value'])->toBe(Money::format(6_000_000));
});

/**
 * Lượt rà soát cuối M9, I1: MỘT định nghĩa "quá hạn" (đợt `pending`, hợp đồng `active`, đã quá
 * ngày, còn phải thu > 0). Một đợt đã thu một phần mà quá hạn nằm trong lát "quá hạn" bằng đúng
 * PHẦN CÒN LẠI của nó — không phải nguyên giá trị mặt (phần đã thu đã nằm ở lát "đã thu"), và
 * không rơi sang lát "chưa tới hạn" như trước.
 */
it('puts the remainder of a partly-paid past-due instalment into the overdue slice, not the not-yet-due one', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $contract = contractWithSchedule($matter, 30_000_000, [
        ['amount' => 10_000_000, 'due_date' => today()->subDays(5)->toDateString()],
        ['amount' => 20_000_000, 'due_date' => today()->addDays(10)->toDateString()],
    ], today()->toDateString());

    $partlyPaid = $contract->instalments()->where('amount', 10_000_000)->sole();
    Payment::factory()->for($partlyPaid)->create(['amount' => 4_000_000, 'paid_on' => today()->toDateString(), 'attributed_lawyer_id' => $this->lawyer->id]);

    $this->actingAs($this->lawyer, 'web');
    [$collected, $notYetDue, $overdue] = widgetData(ReceivablesDonutWidget::class)['datasets'][0]['data'];

    expect($collected)->toBe(4_000_000)
        ->and($overdue)->toBe(6_000_000)
        ->and($notYetDue)->toBe(20_000_000)
        ->and($collected + $notYetDue + $overdue)->toBe(30_000_000);
});

/** Đối chiếu chéo: "còn phải thu" + "quá hạn" của donut khớp ĐÚNG tổng BillingSummary cho cùng tập vụ việc. */
it('matches BillingSummary exactly: donut not_yet_due plus overdue equals the BillingSummary sum for the same matters', function () {
    $matterA = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $matterB = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $contractA = contractWithSchedule($matterA, 15_000_000, [
        ['amount' => 5_000_000, 'due_date' => today()->subDays(3)->toDateString()], // overdue
        ['amount' => 10_000_000, 'due_date' => today()->addDays(15)->toDateString()], // not yet due
    ], today()->toDateString());

    contractWithSchedule($matterB, 9_000_000, [
        ['amount' => 9_000_000, 'due_date' => today()->subDay()->toDateString()], // overdue
    ], today()->toDateString());

    // Một hợp đồng cancelled xen vào — BillingSummary và donut phải CÙNG bỏ qua nó (probe C1).
    $matterC = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $contractC = contractWithSchedule($matterC, 50_000_000, [
        ['amount' => 50_000_000, 'due_date' => today()->subDays(2)->toDateString()],
    ], today()->toDateString());
    $contractC->update(['status' => ContractStatus::Cancelled, 'ended_at' => today()->toDateString(), 'ended_reason' => str_repeat('a', 20)]);

    $this->actingAs($this->lawyer, 'web');

    $billingSummaryTotal = BillingSummary::outstandingForMatter($matterA->id)['amount']
        + BillingSummary::outstandingForMatter($matterB->id)['amount']
        + BillingSummary::outstandingForMatter($matterC->id)['amount'];

    $data = widgetData(ReceivablesDonutWidget::class);
    [, $notYetDue, $overdue] = $data['datasets'][0]['data'];

    expect($billingSummaryTotal)->toBe(24_000_000)
        ->and($notYetDue + $overdue)->toBe($billingSummaryTotal);
});

// =================================================================================================
// Fix round 1, I3 — gộp theo ID (không theo nhãn); JOIN cả giai đoạn đã xoá mềm.
// =================================================================================================

it('still counts revenue attributed to a stage that was later soft-deleted', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $stage = $matter->matterType->stages->firstWhere('key', 'court_accepted');

    $contract = Contract::factory()->for($matter)->create(['total_amount' => 12_000_000, 'signed_at' => null]);
    $instalment = Instalment::factory()->for($contract)->onStage('court_accepted')->create(['sequence' => 1, 'amount' => 12_000_000]);
    $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->toDateString()]);

    Payment::factory()->for($instalment)->create(['amount' => 12_000_000, 'paid_on' => today()->toDateString(), 'attributed_lawyer_id' => $this->lawyer->id]);

    // Xoá mềm giai đoạn SAU KHI tiền đã về.
    $stage->delete();
    expect($stage->fresh()->trashed())->toBeTrue();

    $this->actingAs($this->lawyer, 'web');
    $rows = widgetRows(RevenueByStageWidget::class);
    $total = collect($rows)->sum(fn (array $row): int => parseMoneyRow($row['value']));

    expect($total)->toBe(12_000_000);
});

it('keeps two stages that happen to share a display label as two separate buckets, neither overwriting the other', function () {
    $matterX = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]); // preset "civil" -> stage "court_accepted" / "Toà thụ lý"
    $matterY = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]); // another independently-seeded civil-preset type, same label

    $contractX = Contract::factory()->for($matterX)->create(['total_amount' => 3_000_000, 'signed_at' => null]);
    $instalmentX = Instalment::factory()->for($contractX)->onStage('court_accepted')->create(['sequence' => 1, 'amount' => 3_000_000]);
    $contractX->update(['status' => ContractStatus::Active, 'signed_at' => today()->toDateString()]);
    Payment::factory()->for($instalmentX)->create(['amount' => 3_000_000, 'paid_on' => today()->toDateString(), 'attributed_lawyer_id' => $this->lawyer->id]);

    $contractY = Contract::factory()->for($matterY)->create(['total_amount' => 7_000_000, 'signed_at' => null]);
    $instalmentY = Instalment::factory()->for($contractY)->onStage('court_accepted')->create(['sequence' => 1, 'amount' => 7_000_000]);
    $contractY->update(['status' => ContractStatus::Active, 'signed_at' => today()->toDateString()]);
    Payment::factory()->for($instalmentY)->create(['amount' => 7_000_000, 'paid_on' => today()->toDateString(), 'attributed_lawyer_id' => $this->lawyer->id]);

    $this->actingAs($this->lawyer, 'web');
    $rows = widgetRows(RevenueByStageWidget::class);
    $matchingRows = collect($rows)->filter(fn (array $row): bool => str_contains($row['label'], 'Toà thụ lý'));

    // Hai bó, KHÔNG một — nhãn trùng không được đè lên nhau (Fix round 1, I3).
    expect($matchingRows)->toHaveCount(2);
    expect((int) $matchingRows->sum(fn (array $row): int => parseMoneyRow($row['value'])))->toBe(10_000_000);
});

it('keeps two lawyers that share the same display name as two separate columns in load-per-lawyer', function () {
    $twinA = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Nguyễn Văn A']);
    $twinB = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Nguyễn Văn A']);

    Matter::factory()->count(2)->create(['lead_lawyer_id' => $twinA->id]);
    Matter::factory()->count(3)->create(['lead_lawyer_id' => $twinB->id]);

    $this->actingAs($this->admin, 'web');
    $rows = widgetRows(LoadPerLawyerWidget::class);
    $matchingRows = collect($rows)->filter(fn (array $row): bool => $row['label'] === 'Nguyễn Văn A');

    expect($matchingRows)->toHaveCount(2);
    expect((int) $matchingRows->sum(fn (array $row): int => (int) $row['value']))->toBe(5);
});

it('sums the by-stage buckets to exactly the revenue-over-time total for the same filters', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $contract = Contract::factory()->for($matter)->create(['total_amount' => 45_000_000, 'signed_at' => null]);

    $onSigning = Instalment::factory()->for($contract)->onSigning()->create(['sequence' => 1, 'amount' => 10_000_000]);
    $onStage = Instalment::factory()->for($contract)->onStage('court_accepted')->create(['sequence' => 2, 'amount' => 15_000_000]);
    $onDueDate = Instalment::factory()->for($contract)->create(['sequence' => 3, 'amount' => 20_000_000, 'due_date' => today()->toDateString()]);

    $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->toDateString()]);

    foreach ([$onSigning, $onStage, $onDueDate] as $instalment) {
        Payment::factory()->for($instalment)->create([
            'amount' => $instalment->amount,
            'paid_on' => today()->toDateString(),
            'attributed_lawyer_id' => $this->lawyer->id,
        ]);
    }

    $this->actingAs($this->lawyer, 'web');

    $byStageRows = widgetRows(RevenueByStageWidget::class);
    $byStageTotal = (int) collect($byStageRows)->sum(fn (array $row): int => parseMoneyRow($row['value']));

    $overTimeData = widgetData(RevenueOverTimeWidget::class);
    $overTimeTotal = array_sum($overTimeData['datasets'][0]['data']);

    expect($byStageTotal)->toBe(45_000_000)
        ->and($byStageTotal)->toBe($overTimeTotal);
});

// =================================================================================================
// Fix round 1, I4 — listableBy và voided_at trên bốn/hai widget còn thiếu test riêng, cộng nhánh
// practiceAreaId.
// =================================================================================================

it('never lets an accountant see a restricted matters money in over-time, by-stage, mix or load-per-lawyer', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id, 'closed_at' => null]);
    $contract = contractWithSchedule($restricted, 60_000_000, [
        ['amount' => 60_000_000, 'due_date' => today()->subDay()->toDateString()],
    ], today()->toDateString());
    Payment::factory()->for($contract->instalments()->sole())->create(['amount' => 60_000_000, 'paid_on' => today()->toDateString(), 'attributed_lawyer_id' => $this->lawyer->id]);

    $this->actingAs($this->accountant, 'web');

    $overTime = widgetData(RevenueOverTimeWidget::class);
    expect(array_sum($overTime['datasets'][0]['data']))->toBe(0);

    $byStageRows = widgetRows(RevenueByStageWidget::class);
    expect(collect($byStageRows)->sum(fn (array $row): int => parseMoneyRow($row['value'])))->toBe(0);

    $this->actingAs($this->admin, 'web');
    $mixRows = widgetRows(MatterMixByPracticeAreaWidget::class);
    $adminAmount = (int) collect($mixRows)->sum(fn (array $row): int => parseMoneyRow($row['value']));

    $this->actingAs($this->accountant, 'web');
    $accountantMixRows = widgetRows(MatterMixByPracticeAreaWidget::class);
    $accountantAmount = (int) collect($accountantMixRows)->sum(fn (array $row): int => parseMoneyRow($row['value']));

    expect($accountantAmount)->toBeLessThan($adminAmount);

    $loadRows = widgetRows(LoadPerLawyerWidget::class);
    $lawyerRow = collect($loadRows)->firstWhere('label', $this->lawyer->name);
    // Kế toán không thấy vụ restricted của luật sư này còn mở — nếu đó là vụ đang mở DUY NHẤT của
    // luật sư, tên anh ta/cô ta không xuất hiện với kế toán, dù có xuất hiện với admin.
    $adminLoadRows = collect(widgetRowsAs($this->admin, LoadPerLawyerWidget::class));
    expect($adminLoadRows->firstWhere('label', $this->lawyer->name))->not->toBeNull()
        ->and($lawyerRow)->toBeNull();
});

it('never counts a voided payment in revenue-over-time or revenue-by-stage', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $contract = Contract::factory()->for($matter)->create(['total_amount' => 8_000_000, 'signed_at' => null]);
    $instalment = Instalment::factory()->for($contract)->onSigning()->create(['sequence' => 1, 'amount' => 8_000_000]);
    $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->toDateString()]);

    Payment::factory()->for($instalment)->voided()->create([
        'amount' => 8_000_000, 'paid_on' => today()->toDateString(), 'attributed_lawyer_id' => $this->lawyer->id,
    ]);

    $this->actingAs($this->lawyer, 'web');

    $overTime = widgetData(RevenueOverTimeWidget::class);
    expect(array_sum($overTime['datasets'][0]['data']))->toBe(0);

    $byStageRows = widgetRows(RevenueByStageWidget::class);
    expect(collect($byStageRows)->sum(fn (array $row): int => parseMoneyRow($row['value'])))->toBe(0);
});

it('narrows every widget down to a single practice area when the practice-area filter is set', function () {
    $typeA = MatterType::factory()->withStages()->create();
    $typeB = MatterType::factory()->withStages()->create();

    $matterA = Matter::factory()->create(['matter_type_id' => $typeA->id, 'lead_lawyer_id' => $this->lawyer->id]);
    $matterB = Matter::factory()->create(['matter_type_id' => $typeB->id, 'lead_lawyer_id' => $this->lawyer->id]);

    signedContract($matterA, 11_000_000);
    signedContract($matterB, 22_000_000);

    $this->actingAs($this->lawyer, 'web');

    $filteredToA = widgetData(ReceivablesDonutWidget::class, ['practice_area_id' => $typeA->id]);
    $unfiltered = widgetData(ReceivablesDonutWidget::class);

    expect(array_sum($filteredToA['datasets'][0]['data']))->toBe(11_000_000)
        ->and(array_sum($unfiltered['datasets'][0]['data']))->toBe(33_000_000);
});

// =================================================================================================
// Fix round 1, I5 — bảng số KHÔNG được cắt mất ô lọc tháng/quý/năm hay accessibility của vendor.
// =================================================================================================

it('still renders the vendor month/quarter/year filter select and the accessible canvas label', function () {
    $this->actingAs($this->lawyer, 'web');

    $html = Livewire::test(RevenueOverTimeWidget::class)->html();

    expect($html)->toContain(__('widgets.revenue_dashboard.over_time.filter_month'))
        ->toContain(__('widgets.revenue_dashboard.over_time.filter_quarter'))
        ->toContain(__('widgets.revenue_dashboard.over_time.filter_year'))
        ->toContain('role="img"');
});

// =================================================================================================
// Fix round 2, Important — bó theo giai đoạn còn bị đếm HAI LẦN khi một key bị xoá mềm rồi TẠO LẠI
// (round 1 chỉ sửa "JOIN cả giai đoạn xoá mềm", chưa sửa "nhiều dòng cùng key"). Sửa bằng bảng dẫn
// xuất chọn ĐÚNG MỘT id cho mỗi (matter_type_id, key); LEFT JOIN + bó "không còn trong cấu hình"
// cho đợt không khớp được dòng nào (xoá CỨNG, hoặc vụ việc đổi loại).
// =================================================================================================

/** Helper: tạo một MatterTypeStage sống mới, TRÙNG key với một stage đã xoá mềm — đúng thao tác
 * "xoá rồi tạo lại" mà quản trị viên có thể làm (ràng buộc trùng chỉ tính trên dòng còn sống). */
function recreateStage(MatterTypeStage $original): MatterTypeStage
{
    return MatterTypeStage::create([
        'matter_type_id' => $original->matter_type_id,
        'key' => $original->key,
        'label' => $original->label,
        'client_label' => $original->client_label,
        'client_description' => $original->client_description,
        'sort_order' => $original->sort_order,
        'is_terminal' => $original->is_terminal,
        'allowed_next' => $original->allowed_next,
        'default_next_update_days' => $original->default_next_update_days,
    ]);
}

/** Trả về đúng một đợt `stage` đã thu, gắn vào $matter (cùng matter_type với stage gốc). */
function payOnStage(Matter $matter, string $key, int $amount, User $lawyer): void
{
    $contract = Contract::factory()->for($matter)->create(['total_amount' => $amount, 'signed_at' => null]);
    $instalment = Instalment::factory()->for($contract)->onStage($key)->create(['sequence' => 1, 'amount' => $amount]);
    $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->toDateString()]);
    Payment::factory()->for($instalment)->create(['amount' => $amount, 'paid_on' => today()->toDateString(), 'attributed_lawyer_id' => $lawyer->id]);
}

/** (a) Xoá mềm giai đoạn K, tạo lại giai đoạn K — vẫn ĐÚNG MỘT bó, không hai. */
it('counts exactly one bucket for a stage key that was soft-deleted and recreated, matching the over-time total', function () {
    $matterBefore = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $originalStage = $matterBefore->matterType->stages->firstWhere('key', 'court_accepted');

    payOnStage($matterBefore, 'court_accepted', 8_000_000, $this->lawyer);

    $originalStage->delete();
    recreateStage($originalStage->fresh());

    $matterAfter = Matter::factory()->create([
        'matter_type_id' => $matterBefore->matter_type_id,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);
    payOnStage($matterAfter, 'court_accepted', 5_000_000, $this->lawyer);

    $this->actingAs($this->lawyer, 'web');
    $rows = widgetRows(RevenueByStageWidget::class);
    $matchingRows = collect($rows)->filter(fn (array $row): bool => str_contains($row['label'], 'Toà thụ lý'));

    // ĐÚNG MỘT bó (không hai, dù có hai dòng matter_type_stages cho cùng key: một xoá mềm, một mới).
    expect($matchingRows)->toHaveCount(1);
    expect(parseMoneyRow($matchingRows->sole()['value']))->toBe(13_000_000);

    $byStageTotal = (int) collect($rows)->sum(fn (array $row): int => parseMoneyRow($row['value']));
    $overTimeTotal = array_sum(widgetData(RevenueOverTimeWidget::class)['datasets'][0]['data']);
    expect($byStageTotal)->toBe($overTimeTotal);
});

/** (b) Xoá — tạo lại — xoá lần nữa: không dòng SỐNG nào còn lại, vẫn ĐÚNG MỘT bó. */
it('still counts exactly one bucket when a stage key was deleted, recreated, and deleted again, leaving two trashed rows', function () {
    $matterBefore = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $originalStage = $matterBefore->matterType->stages->firstWhere('key', 'court_accepted');

    payOnStage($matterBefore, 'court_accepted', 6_000_000, $this->lawyer);

    $originalStage->delete();
    $recreated = recreateStage($originalStage->fresh());
    $recreated->delete();

    expect(MatterTypeStage::withTrashed()
        ->where('matter_type_id', $matterBefore->matter_type_id)
        ->where('key', 'court_accepted')
        ->count())->toBe(2)
        ->and(MatterTypeStage::query()
            ->where('matter_type_id', $matterBefore->matter_type_id)
            ->where('key', 'court_accepted')
            ->count())->toBe(0); // không dòng sống nào

    $matterAfter = Matter::factory()->create([
        'matter_type_id' => $matterBefore->matter_type_id,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);
    payOnStage($matterAfter, 'court_accepted', 4_000_000, $this->lawyer);

    $this->actingAs($this->lawyer, 'web');
    $rows = widgetRows(RevenueByStageWidget::class);
    $matchingRows = collect($rows)->filter(fn (array $row): bool => str_contains($row['label'], 'Toà thụ lý'));

    expect($matchingRows)->toHaveCount(1);
    expect(parseMoneyRow($matchingRows->sole()['value']))->toBe(10_000_000);
});

/** (c) Giai đoạn bị xoá CỨNG — đợt vẫn được đếm, chỉ rơi vào bó "không còn trong cấu hình". */
it('lands a payment whose stage row was force-deleted in the unknown-stage bucket, and the totals still match', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $stage = $matter->matterType->stages->firstWhere('key', 'court_accepted');

    payOnStage($matter, 'court_accepted', 7_000_000, $this->lawyer);

    // Xoá CỨNG — không còn dòng nào cho (matter_type_id, key) này, kể cả xoá mềm.
    $stage->forceDelete();
    expect(MatterTypeStage::withTrashed()->whereKey($stage->id)->exists())->toBeFalse();

    $this->actingAs($this->lawyer, 'web');
    $rows = widgetRows(RevenueByStageWidget::class);
    $unknownRow = collect($rows)->firstWhere('label', __('widgets.revenue_dashboard.by_stage.unknown_stage_bucket'));

    expect($unknownRow)->not->toBeNull();
    expect(parseMoneyRow($unknownRow['value']))->toBe(7_000_000);

    $byStageTotal = (int) collect($rows)->sum(fn (array $row): int => parseMoneyRow($row['value']));
    $overTimeTotal = array_sum(widgetData(RevenueOverTimeWidget::class)['datasets'][0]['data']);
    expect($byStageTotal)->toBe($overTimeTotal);
});

/** @return list<array{label: string, value: string}> */
function widgetRowsAs(User $user, string $class, array $pageFilters = []): array
{
    test()->actingAs($user, 'web');

    return widgetRows($class, $pageFilters);
}

// =================================================================================================
// Lượt rà soát cuối M9 — M4 (nhãn quý tiếng Việt), M6 (màu số của bảng số đọc được ở chế độ tối),
// M7 (cơ cấu lĩnh vực bỏ hợp đồng đã huỷ, cùng quần thể với biểu đồ vành khuyên).
// =================================================================================================

it('labels a quarter bucket of the over-time chart in vietnamese, from the language file', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $contract = signedContract($matter, 10_000_000);
    Payment::factory()->for($contract->instalments()->sole())->create([
        'amount' => 1_000_000, 'paid_on' => '2026-08-15', 'attributed_lawyer_id' => $this->lawyer->id,
    ]);

    $this->actingAs($this->lawyer, 'web');

    $instance = Livewire::test(RevenueOverTimeWidget::class, ['pageFilters' => [
        'period' => 'custom', 'date_from' => '2026-07-01', 'date_to' => '2026-09-30',
    ]])->set('filter', 'quarter')->instance();
    $method = new ReflectionMethod($instance, 'getData');

    expect($method->invoke($instance)['labels'])->toBe([
        __('widgets.revenue_dashboard.over_time.quarter_label', ['quarter' => 3, 'year' => 2026]),
    ]);
});

it('paints the values of the number table in a colour that follows light and dark mode, not a fixed near-black', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    signedContract($matter, 10_000_000);

    $this->actingAs($this->lawyer, 'web');

    Livewire::test(ReceivablesDonutWidget::class)
        ->assertSee(__('widgets.revenue_dashboard.number_table_toggle'))
        ->assertDontSeeHtml('var(--gray-950)')
        ->assertSeeHtml('font-weight: 600; color: inherit;');
});

it('leaves a cancelled contract out of the practice-area mix, by amount and by count, like the donut', function () {
    $type = MatterType::factory()->withStages()->create(['name' => 'Lĩnh vực có hợp đồng huỷ']);

    signedContract(Matter::factory()->create(['matter_type_id' => $type->id, 'lead_lawyer_id' => $this->lawyer->id]), 10_000_000);

    $completed = signedContract(Matter::factory()->create(['matter_type_id' => $type->id, 'lead_lawyer_id' => $this->lawyer->id]), 20_000_000);
    $completed->instalments()->sole()->update(['status' => InstalmentStatus::Paid]);
    $completed->update(['status' => ContractStatus::Completed, 'ended_at' => today()->toDateString()]);

    $cancelled = signedContract(Matter::factory()->create(['matter_type_id' => $type->id, 'lead_lawyer_id' => $this->lawyer->id]), 50_000_000);
    $cancelled->update(['status' => ContractStatus::Cancelled, 'ended_at' => today()->toDateString(), 'ended_reason' => str_repeat('a', 20)]);

    $this->actingAs($this->admin, 'web');

    $byAmount = collect(widgetRows(MatterMixByPracticeAreaWidget::class))->firstWhere('label', 'Lĩnh vực có hợp đồng huỷ');
    $byCount = collect(widgetRows(MatterMixByPracticeAreaWidget::class, ['by_count' => true]))->firstWhere('label', 'Lĩnh vực có hợp đồng huỷ');

    expect($byAmount['value'])->toBe(Money::format(30_000_000))
        ->and($byCount['value'])->toBe('2');
});
