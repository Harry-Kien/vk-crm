<?php

use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Pages\IntakeReport;
use App\Filament\Admin\Widgets\IntakeReport\IntakeConversionWidget;
use App\Filament\Admin\Widgets\IntakeReport\IntakeOutcomesWidget;
use App\Filament\Admin\Widgets\IntakeReport\IntakeResponseTimeWidget;
use App\Filament\Admin\Widgets\IntakeReport\IntakesBySourceWidget;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\User;
use App\Support\Intake\IntakeReportFilters;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

/**
 * Bức tranh đầu vào (M10 Task 6): trang báo cáo tự viết trên panel admin, bốn widget biểu đồ theo
 * khuôn M9 Task 9 (ChartWidget + bảng số, `$isDiscovered = false`).
 *
 * Ba tầng test, cùng tinh thần `RevenueDashboardTest`: cổng của trang (HTTP và Livewire), cổng của
 * từng widget (mount thẳng qua Livewire), và dữ liệu từng widget đọc qua Livewire — `irpData()` /
 * `irpRows()` đọc `getData()` / `numberTableRows()` của widget đã MOUNT thật, `pageFilters` truyền
 * lúc mount (prop `#[Reactive]`, không `->set()` được).
 *
 * Giờ được ghim ở giữa tháng 10/2026: kỳ mặc định "tháng này" tính từ `today()`, và bộ test M9 đã
 * từng đỏ vì một ngày cuối tháng.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản Trị Viên']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng']);
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Một']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Trực Máy']);
    $this->accountant = User::factory()->withRole(Role::Accountant)->create(['name' => 'Kế Toán']);
});

/** Bốn widget của trang, theo thứ tự trang liệt kê. */
function irpWidgets(): array
{
    return [
        IntakesBySourceWidget::class,
        IntakeConversionWidget::class,
        IntakeResponseTimeWidget::class,
        IntakeOutcomesWidget::class,
    ];
}

/**
 * Một bản ghi tiếp nhận, dựng bằng factory (không qua `RecordIntake`: báo cáo chỉ đọc cột, và
 * `first_response_at` là việc của Task 5). `$responseMinutes` null = chưa phản hồi.
 */
function irpRecord(User $recorder, IntakeSource $source, IntakeStatus $status, string $receivedAt, ?int $responseMinutes, array $extra = []): IntakeRequest
{
    $received = Carbon::parse($receivedAt);

    return IntakeRequest::factory()->create([
        'created_by' => $recorder->id,
        'source' => $source,
        'status' => $status,
        'received_at' => $received,
        'first_response_at' => $responseMinutes === null ? null : $received->copy()->addMinutes($responseMinutes),
        ...$extra,
    ]);
}

/**
 * Dữ liệu seed của báo cáo — mười lăm bản ghi trong tháng 10/2026 và một bản tháng 9, đếm ra những
 * con số KHÁC NHAU ở mọi nhóm (để một phép đếm bị tráo nhóm không vô tình ra đúng):
 *
 * | nguồn       | trạng thái (phút tới phản hồi đầu)                                        |
 * |-------------|---------------------------------------------------------------------------|
 * | điện thoại  | won (30), declined vì xung đột (120), new (—), contacted (240), lost (20) |
 * | zalo        | won (60), lost (90), lost (45)                                            |
 * | đến VP      | declined lý do khác (10), declined lý do khác (15)                         |
 * | giới thiệu  | won (1500)                                                                |
 * | gộp ×4      | khác ×2, điện thoại ×1, zalo ×1 — status merged (5)                       |
 * | tháng 9     | điện thoại, won (15) — ngoài kỳ "tháng này"                               |
 */
function irpFixture(User $recorder): void
{
    $won = irpRecord($recorder, IntakeSource::Phone, IntakeStatus::Won, '2026-10-01 08:00', 30);
    irpRecord($recorder, IntakeSource::Phone, IntakeStatus::Declined, '2026-10-02 08:00', 120, [
        'decline_reason' => 'Bên kia là khách hàng Trần Thị Bí Mật của văn phòng',
        'decline_reason_is_conflict' => true,
    ]);
    irpRecord($recorder, IntakeSource::Phone, IntakeStatus::New, '2026-10-03 08:00', null);
    irpRecord($recorder, IntakeSource::Phone, IntakeStatus::Contacted, '2026-10-04 08:00', 240);
    irpRecord($recorder, IntakeSource::Phone, IntakeStatus::Lost, '2026-10-05 08:00', 20);

    irpRecord($recorder, IntakeSource::Zalo, IntakeStatus::Won, '2026-10-06 08:00', 60);
    irpRecord($recorder, IntakeSource::Zalo, IntakeStatus::Lost, '2026-10-07 08:00', 90);
    irpRecord($recorder, IntakeSource::Zalo, IntakeStatus::Lost, '2026-10-08 08:00', 45);

    irpRecord($recorder, IntakeSource::WalkIn, IntakeStatus::Declined, '2026-10-09 08:00', 10, [
        'decline_reason' => 'Ngoài lĩnh vực hành nghề của văn phòng',
    ]);
    irpRecord($recorder, IntakeSource::WalkIn, IntakeStatus::Declined, '2026-10-10 08:00', 15, [
        'decline_reason' => 'Khách muốn một kết quả văn phòng không hứa được',
    ]);

    irpRecord($recorder, IntakeSource::Referral, IntakeStatus::Won, '2026-10-11 08:00', 1500);

    foreach ([IntakeSource::Other, IntakeSource::Other, IntakeSource::Phone, IntakeSource::Zalo] as $source) {
        irpRecord($recorder, $source, IntakeStatus::Merged, '2026-10-12 08:00', 5, ['merged_into_id' => $won->id]);
    }

    irpRecord($recorder, IntakeSource::Phone, IntakeStatus::Won, '2026-09-20 08:00', 15);
}

/** `getData()` của widget đã mount thật qua Livewire (xem docblock tệp). */
function irpData(string $class, array $pageFilters = []): array
{
    $instance = Livewire::test($class, ['pageFilters' => $pageFilters])->instance();
    $method = new ReflectionMethod($instance, 'getData');
    $method->setAccessible(true);

    return $method->invoke($instance);
}

/** @return list<array{label: string, value: string}> */
function irpRows(string $class, array $pageFilters = []): array
{
    return Livewire::test($class, ['pageFilters' => $pageFilters])->instance()->numberTableRows();
}

function irpDescription(string $class, array $pageFilters = []): string
{
    return (string) Livewire::test($class, ['pageFilters' => $pageFilters])->instance()->getDescription();
}

/** Nhãn của sáu nguồn, theo thứ tự enum — trục chung của ba biểu đồ theo nguồn. */
function irpSourceLabels(): array
{
    return array_map(fn (IntakeSource $source): string => $source->label(), IntakeSource::cases());
}

// =================================================================================================
// Cổng của trang — intake.viewAny qua Gate::forUser(), 404 cho mọi người khác.
// =================================================================================================

it('answers 404 to a lawyer, an assistant and an accountant who open the intake report', function () {
    foreach (['lawyer', 'assistant', 'accountant'] as $key) {
        $this->actingAs($this->{$key}, 'web')
            ->get(IntakeReport::getUrl(panel: 'admin'))
            ->assertNotFound();
    }
});

it('opens the intake report for a manager and an admin', function () {
    foreach (['manager', 'admin'] as $key) {
        $this->actingAs($this->{$key}, 'web')
            ->get(IntakeReport::getUrl(panel: 'admin'))
            ->assertOk()
            ->assertSee(__('intake_report.title'));
    }
});

it('answers 404 from the page component itself, not only through the panel middleware', function () {
    $this->actingAs($this->lawyer, 'web');

    Livewire::test(IntakeReport::class)->assertStatus(404);
});

it('reads the page gate from the permission intake.viewAny, not from a role', function () {
    $witness = User::factory()->create();
    $witness->givePermissionTo(Permission::IntakeViewAny->value);

    $this->actingAs($witness, 'web')
        ->get(IntakeReport::getUrl(panel: 'admin'))
        ->assertOk();

    // Đủ mọi quyền tiếp nhận khác của một luật sư, thiếu đúng intake.viewAny: không vào được.
    $without = User::factory()->create();
    $without->givePermissionTo([Permission::IntakeCreate->value, Permission::IntakeConvert->value, Permission::MatterCreate->value]);

    $this->actingAs($without, 'web')
        ->get(IntakeReport::getUrl(panel: 'admin'))
        ->assertNotFound();
});

// Hai test riêng, không hai GET trong một test: panel giữ menu đã dựng trong bộ nhớ của tiến trình, nên
// GET thứ hai trong cùng một test đọc lại menu của người dùng thứ nhất.
it('shows the report in the navigation of a manager', function () {
    $this->actingAs($this->manager, 'web')
        ->get(Filament::getPanel('admin')->getUrl())
        ->assertOk()
        ->assertSee(__('intake_report.navigation_label'));
});

it('leaves the report out of the navigation of a lawyer, who cannot open it', function () {
    $this->actingAs($this->lawyer, 'web')
        ->get(Filament::getPanel('admin')->getUrl())
        ->assertOk()
        ->assertDontSee(__('intake_report.navigation_label'));
});

// =================================================================================================
// Cổng của từng widget — tự hỏi lại quyền, và 404 khi mount thẳng (không chỉ ẩn khỏi trang).
// =================================================================================================

it('refuses every report widget to a lawyer, an assistant and an accountant with 404, even mounted directly', function () {
    foreach (['lawyer', 'assistant', 'accountant'] as $key) {
        $this->actingAs($this->{$key}, 'web');

        foreach (irpWidgets() as $widget) {
            expect($widget::canView())->toBeFalse("{$key} không được thấy {$widget}");

            Livewire::test($widget)->assertStatus(404);
        }
    }
});

it('gives every report widget to a manager, an admin and a bare intake.viewAny witness', function () {
    $witness = User::factory()->create();
    $witness->givePermissionTo(Permission::IntakeViewAny->value);

    foreach ([$this->manager, $this->admin, $witness] as $user) {
        $this->actingAs($user, 'web');

        foreach (irpWidgets() as $widget) {
            expect($widget::canView())->toBeTrue();

            Livewire::test($widget)->assertOk();
        }
    }
});

// =================================================================================================
// Trang chủ không có widget nào của trang này (test bắt buộc); trang này là nơi DUY NHẤT liệt kê chúng.
// =================================================================================================

it('never lets an intake report widget onto the home dashboard', function () {
    $registered = Filament::getWidgets();

    foreach (irpWidgets() as $widget) {
        expect($registered)->not->toContain($widget);
    }
});

it('lists exactly the four intake report widgets on the report page', function () {
    $this->actingAs($this->manager, 'web');

    expect(Livewire::test(IntakeReport::class)->instance()->getWidgets())->toBe(irpWidgets());
});

// =================================================================================================
// Số liệu khớp dữ liệu seed (test bắt buộc) — mỗi widget một test.
// =================================================================================================

it('counts the contacts of the period by source, every source listed, merged duplicates not counted again', function () {
    irpFixture($this->assistant);
    $this->actingAs($this->manager, 'web');

    $data = irpData(IntakesBySourceWidget::class);

    expect($data['labels'])->toBe(irpSourceLabels())
        ->and($data['datasets'][0]['data'])->toBe([5, 3, 2, 1, 0, 0]);

    expect(irpRows(IntakesBySourceWidget::class))->toBe([
        ['label' => IntakeSource::Phone->label(), 'value' => '5'],
        ['label' => IntakeSource::Zalo->label(), 'value' => '3'],
        ['label' => IntakeSource::WalkIn->label(), 'value' => '2'],
        ['label' => IntakeSource::Referral->label(), 'value' => '1'],
        ['label' => IntakeSource::WebsiteForm->label(), 'value' => '0'],
        ['label' => IntakeSource::Other->label(), 'value' => '0'],
        ['label' => __('intake_report.by_source.total'), 'value' => '11'],
    ]);
});

it('computes the conversion rate per source and overall, leaving a source without contacts out of the bars', function () {
    irpFixture($this->assistant);
    $this->actingAs($this->manager, 'web');

    $data = irpData(IntakeConversionWidget::class);

    expect($data['labels'])->toBe(irpSourceLabels())
        ->and($data['datasets'][0]['data'])->toBe([20.0, 33.3, 0.0, 100.0, null, null]);

    $none = __('intake_report.conversion.no_contacts');

    expect(irpRows(IntakeConversionWidget::class))->toBe([
        ['label' => IntakeSource::Phone->label(), 'value' => '1/5 (20,0 %)'],
        ['label' => IntakeSource::Zalo->label(), 'value' => '1/3 (33,3 %)'],
        ['label' => IntakeSource::WalkIn->label(), 'value' => '0/2 (0,0 %)'],
        ['label' => IntakeSource::Referral->label(), 'value' => '1/1 (100,0 %)'],
        ['label' => IntakeSource::WebsiteForm->label(), 'value' => $none],
        ['label' => IntakeSource::Other->label(), 'value' => $none],
        ['label' => __('intake_report.conversion.overall'), 'value' => '3/11 (27,3 %)'],
    ]);

    expect(irpDescription(IntakeConversionWidget::class))->toContain('3/11 (27,3 %)');
});

it('computes the median first-response time per source and overall, in clock time, counting the unanswered apart', function () {
    irpFixture($this->assistant);
    $this->actingAs($this->manager, 'web');

    $data = irpData(IntakeResponseTimeWidget::class);

    // Điện thoại [20, 30, 120, 240] → (30+120)/2 = 75 phút; zalo [45, 60, 90] → 60; đến VP [10, 15]
    // → 12,5; giới thiệu [1500]. Trục là GIỜ, một chữ số thập phân.
    expect($data['labels'])->toBe(irpSourceLabels())
        ->and($data['datasets'][0]['data'])->toBe([1.3, 1.0, 0.2, 25.0, null, null]);

    expect(irpRows(IntakeResponseTimeWidget::class))->toBe([
        // Toàn bộ [10, 15, 20, 30, 45, 60, 90, 120, 240, 1500] → (45+60)/2 = 52,5 → 53 phút.
        ['label' => __('intake_report.response_time.overall'), 'value' => '53 phút'],
        ['label' => __('intake_report.response_time.responded'), 'value' => '10'],
        ['label' => __('intake_report.response_time.unanswered'), 'value' => '1'],
        ['label' => IntakeSource::Phone->label(), 'value' => '1 giờ 15 phút (4 bản ghi)'],
        ['label' => IntakeSource::Zalo->label(), 'value' => '1 giờ (3 bản ghi)'],
        ['label' => IntakeSource::WalkIn->label(), 'value' => '13 phút (2 bản ghi)'],
        ['label' => IntakeSource::Referral->label(), 'value' => '1 ngày 1 giờ (1 bản ghi)'],
        ['label' => IntakeSource::WebsiteForm->label(), 'value' => '—'],
        ['label' => IntakeSource::Other->label(), 'value' => '—'],
    ]);

    expect(irpDescription(IntakeResponseTimeWidget::class))
        ->toContain('53 phút')
        ->toContain(__('intake_report.response_time.clock_note'));
});

it('groups the records that did not become a matter, the conflict declines apart, for a manager', function () {
    irpFixture($this->assistant);
    $this->actingAs($this->manager, 'web');

    $data = irpData(IntakeOutcomesWidget::class);

    $labels = [
        __('intake_report.outcomes.groups.declined_conflict'),
        __('intake_report.outcomes.groups.declined_other'),
        IntakeStatus::Lost->label(),
        IntakeStatus::Merged->label(),
    ];

    expect($data['labels'])->toBe($labels)
        ->and($data['datasets'][0]['data'])->toBe([1, 2, 3, 4]);

    expect(irpRows(IntakeOutcomesWidget::class))->toBe([
        ['label' => $labels[0], 'value' => '1'],
        ['label' => $labels[1], 'value' => '2'],
        ['label' => $labels[2], 'value' => '3'],
        ['label' => $labels[3], 'value' => '4'],
        ['label' => __('intake_report.outcomes.total'), 'value' => '10'],
    ]);

    // Quản lý thấy NHÓM "xung đột" (R8: họ có intake.viewAny) — nhưng không bao giờ thấy lý do chữ.
    Livewire::test(IntakeOutcomesWidget::class)
        ->assertSee(__('intake_report.outcomes.groups.declined_conflict'))
        ->assertDontSee('Trần Thị Bí Mật')
        ->assertDontSee('Ngoài lĩnh vực hành nghề');
});

it('never prints a contact name, phone or free-text reason anywhere on the report', function () {
    irpRecord($this->assistant, IntakeSource::Phone, IntakeStatus::Declined, '2026-10-02 08:00', 30, [
        'contact_name' => 'Người Gọi Bí Mật',
        'contact_phone' => '0912345678',
        'decline_reason' => 'Lý do riêng tư không được hiện',
        'decline_reason_is_conflict' => true,
    ]);
    $this->actingAs($this->manager, 'web');

    $this->get(IntakeReport::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertDontSee('Người Gọi Bí Mật')
        ->assertDontSee('0912345678')
        ->assertDontSee('Lý do riêng tư');

    foreach (irpWidgets() as $widget) {
        Livewire::test($widget)
            ->assertDontSee('Người Gọi Bí Mật')
            ->assertDontSee('0912345678')
            ->assertDontSee('Lý do riêng tư');
    }
});

// =================================================================================================
// Bản ghi đã ẩn danh vẫn được đếm (R7b giữ lại trạng thái, nguồn, mốc thời gian).
// =================================================================================================

it('still counts an anonymised record by its source, its status and its timestamps', function () {
    irpRecord($this->assistant, IntakeSource::Zalo, IntakeStatus::Lost, '2026-10-02 08:00', 45, [
        'contact_name' => null,
        'contact_phone' => null,
        'contact_email' => null,
        'referred_by' => null,
        'summary' => null,
        'anonymised_at' => now(),
        'anonymised_reason' => 'Hết hạn lưu',
        'retention_until' => today()->subDay(),
    ]);
    $this->actingAs($this->manager, 'web');

    expect(irpData(IntakesBySourceWidget::class)['datasets'][0]['data'])->toBe([0, 1, 0, 0, 0, 0])
        ->and(irpData(IntakeOutcomesWidget::class)['datasets'][0]['data'])->toBe([0, 0, 1, 0])
        ->and(irpRows(IntakeResponseTimeWidget::class)[0]['value'])->toBe('45 phút')
        ->and(irpRows(IntakeConversionWidget::class)[1]['value'])->toBe('0/1 (0,0 %)');
});

// =================================================================================================
// Bộ lọc: thời gian (theo ngày nhận liên hệ) và người tiếp nhận (người đã ghi bản ghi).
// =================================================================================================

it('leaves the september record out of this month, and finds it again through a custom range', function () {
    irpFixture($this->assistant);
    $this->actingAs($this->manager, 'web');

    $september = ['period' => 'custom', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30'];

    expect(irpData(IntakesBySourceWidget::class, $september)['datasets'][0]['data'])->toBe([1, 0, 0, 0, 0, 0])
        ->and(irpRows(IntakeConversionWidget::class, $september)[6]['value'])->toBe('1/1 (100,0 %)')
        ->and(irpRows(IntakeResponseTimeWidget::class, $september)[0]['value'])->toBe('15 phút')
        ->and(irpData(IntakeOutcomesWidget::class, $september)['datasets'][0]['data'])->toBe([0, 0, 0, 0]);

    // Cả năm: tháng 9 cộng tháng 10.
    expect(irpData(IntakesBySourceWidget::class, ['period' => 'this_year'])['datasets'][0]['data'])->toBe([6, 3, 2, 1, 0, 0]);

    // Mỗi widget tự in khoảng ngày nó đang đếm lên chính nó (khuôn M9).
    foreach (irpWidgets() as $widget) {
        expect(irpDescription($widget, $september))->toContain('01/09/2026 – 30/09/2026');
    }
});

it('reads the receiver filter as a staff id, and as no filter at all when it is empty', function () {
    expect(IntakeReportFilters::fromPageFilters([])->receiverId)->toBeNull()
        ->and(IntakeReportFilters::fromPageFilters(null)->receiverId)->toBeNull()
        ->and(IntakeReportFilters::fromPageFilters(['receiver_id' => ''])->receiverId)->toBeNull()
        ->and(IntakeReportFilters::fromPageFilters(['receiver_id' => '7'])->receiverId)->toBe(7);
});

it('includes a record received on the last second of the last day of the range', function () {
    irpRecord($this->assistant, IntakeSource::Phone, IntakeStatus::New, '2026-09-30 23:59:59', null);
    irpRecord($this->assistant, IntakeSource::Zalo, IntakeStatus::New, '2026-09-01 00:00:00', null);
    irpRecord($this->assistant, IntakeSource::Referral, IntakeStatus::New, '2026-10-01 00:00:00', null);
    $this->actingAs($this->manager, 'web');

    $september = ['period' => 'custom', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30'];

    expect(irpData(IntakesBySourceWidget::class, $september)['datasets'][0]['data'])->toBe([1, 1, 0, 0, 0, 0]);
});

it('narrows every widget to the staff member who recorded the records, not to the one they are assigned to', function () {
    $other = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Khác']);

    // Do trợ lý ghi, giao cho người khác — và ngược lại.
    irpRecord($this->assistant, IntakeSource::Phone, IntakeStatus::Won, '2026-10-02 08:00', 30, ['assigned_to' => $other->id]);
    irpRecord($other, IntakeSource::Zalo, IntakeStatus::Declined, '2026-10-03 08:00', 90, [
        'assigned_to' => $this->assistant->id,
        'decline_reason_is_conflict' => true,
        'decline_reason' => 'x',
    ]);
    $this->actingAs($this->manager, 'web');

    $mine = ['receiver_id' => (string) $this->assistant->id];

    expect(irpData(IntakesBySourceWidget::class, $mine)['datasets'][0]['data'])->toBe([1, 0, 0, 0, 0, 0])
        ->and(irpRows(IntakeConversionWidget::class, $mine)[6]['value'])->toBe('1/1 (100,0 %)')
        ->and(irpRows(IntakeResponseTimeWidget::class, $mine)[0]['value'])->toBe('30 phút')
        ->and(irpData(IntakeOutcomesWidget::class, $mine)['datasets'][0]['data'])->toBe([0, 0, 0, 0]);

    // Không lọc: cả hai.
    expect(irpData(IntakesBySourceWidget::class)['datasets'][0]['data'])->toBe([1, 1, 0, 0, 0, 0])
        ->and(irpData(IntakeOutcomesWidget::class)['datasets'][0]['data'])->toBe([1, 0, 0, 0]);
});

it('offers in the receiver filter only the staff who recorded a record the viewer can see, never a contact', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Phụ Trách Vụ Kín']);
    $hiddenRecorder = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Chỉ Ghi Vụ Kín']);
    User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Chưa Ghi Gì']);

    irpRecord($this->assistant, IntakeSource::Phone, IntakeStatus::New, '2026-10-02 08:00', null, ['contact_name' => 'Người Liên Hệ Một']);
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lead->id]);
    irpRecord($hiddenRecorder, IntakeSource::Phone, IntakeStatus::Won, '2026-10-03 08:00', 10, [
        'contact_name' => 'Khách Vụ Kín',
        'client_id' => $restricted->client_id,
        'matter_id' => $restricted->id,
    ]);

    $this->actingAs($this->manager, 'web');
    $managerOptions = Livewire::test(IntakeReport::class)->instance()
        ->getSchema('filtersForm')->getComponent('receiver_id')->getOptions();

    expect($managerOptions)->toBe([$this->assistant->id => 'Trợ Lý Trực Máy']);

    $this->actingAs($this->admin, 'web');
    $adminOptions = Livewire::test(IntakeReport::class)->instance()
        ->getSchema('filtersForm')->getComponent('receiver_id')->getOptions();

    // Theo tên: "Trợ Lý C…" trước "Trợ Lý T…".
    expect($adminOptions)->toBe([
        $hiddenRecorder->id => 'Trợ Lý Chỉ Ghi Vụ Kín',
        $this->assistant->id => 'Trợ Lý Trực Máy',
    ]);
});

// =================================================================================================
// Một định nghĩa "thấy được": bản ghi đã thành vụ `restricted` mà người xem không xem được vụ thì
// không có trong số liệu của họ — cũng không có câu "đã ẩn N bản ghi" nào (SPEC §10.10).
// =================================================================================================

it('leaves an intake converted into a restricted matter out of a managers numbers, but counts it for the admin', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lead->id, 'title' => 'Vụ kín tuyệt mật']);

    irpRecord($this->assistant, IntakeSource::Phone, IntakeStatus::Won, '2026-10-02 08:00', 30);
    irpRecord($this->assistant, IntakeSource::Phone, IntakeStatus::Won, '2026-10-03 08:00', 90, [
        'client_id' => $restricted->client_id,
        'matter_id' => $restricted->id,
    ]);

    $this->actingAs($this->manager, 'web');
    expect(irpRows(IntakeConversionWidget::class)[6]['value'])->toBe('1/1 (100,0 %)')
        ->and(irpRows(IntakeResponseTimeWidget::class)[0]['value'])->toBe('30 phút');

    foreach (irpWidgets() as $widget) {
        Livewire::test($widget)
            ->assertDontSee($restricted->code)
            ->assertDontSee('Vụ kín tuyệt mật');
    }

    $this->actingAs($this->admin, 'web');
    expect(irpRows(IntakeConversionWidget::class)[6]['value'])->toBe('2/2 (100,0 %)')
        ->and(irpRows(IntakeResponseTimeWidget::class)[0]['value'])->toBe('1 giờ');
});

// =================================================================================================
// Thời gian phản hồi: những chỗ dễ sai.
// =================================================================================================

it('never counts a response logged before the receipt time as negative time', function () {
    irpRecord($this->assistant, IntakeSource::Phone, IntakeStatus::Contacted, '2026-10-02 10:00', -60);
    $this->actingAs($this->manager, 'web');

    expect(irpRows(IntakeResponseTimeWidget::class)[0]['value'])->toBe('0 phút')
        ->and(irpData(IntakeResponseTimeWidget::class)['datasets'][0]['data'][0])->toBe(0.0);
});

it('formats days, hours and minutes, leaving out the parts that are zero', function () {
    // Mỗi nguồn một bản ghi: điện thoại 2 ngày tròn, zalo 1 ngày 5 phút, đến VP 3 giờ 7 phút.
    irpRecord($this->assistant, IntakeSource::Phone, IntakeStatus::Contacted, '2026-10-02 08:00', 2 * 1440);
    irpRecord($this->assistant, IntakeSource::Zalo, IntakeStatus::Contacted, '2026-10-02 08:00', 1440 + 5);
    irpRecord($this->assistant, IntakeSource::WalkIn, IntakeStatus::Contacted, '2026-10-02 08:00', 187);
    $this->actingAs($this->manager, 'web');

    $rows = irpRows(IntakeResponseTimeWidget::class);

    expect($rows[3]['value'])->toBe('2 ngày (1 bản ghi)')
        ->and($rows[4]['value'])->toBe('1 ngày 5 phút (1 bản ghi)')
        ->and($rows[5]['value'])->toBe('3 giờ 7 phút (1 bản ghi)');
});

it('says so when nothing was answered yet instead of printing a median of nothing', function () {
    irpRecord($this->assistant, IntakeSource::Phone, IntakeStatus::New, '2026-10-02 08:00', null);
    $this->actingAs($this->manager, 'web');

    $rows = irpRows(IntakeResponseTimeWidget::class);

    expect($rows[0]['value'])->toBe(__('intake_report.response_time.none'))
        ->and($rows[1]['value'])->toBe('0')
        ->and($rows[2]['value'])->toBe('1')
        ->and(irpDescription(IntakeResponseTimeWidget::class))->toContain(__('intake_report.response_time.none'));
});

it('says so when there is no contact at all instead of printing a rate of nothing', function () {
    $this->actingAs($this->manager, 'web');

    expect(irpRows(IntakeConversionWidget::class)[6]['value'])->toBe(__('intake_report.conversion.no_contacts'))
        ->and(irpDescription(IntakeConversionWidget::class))->toContain(__('intake_report.conversion.no_contacts'));
});

// =================================================================================================
// Khuôn M9: view dùng chung có bảng số, ô lọc của vendor và nhãn canvas truy cập được; không tooltip
// callback, không RawJs (phán quyết CSP của M8 R4) — getOptions() là mảng PHP thuần.
// =================================================================================================

it('renders every widget with the shared number table and the accessible canvas label', function () {
    irpFixture($this->assistant);
    $this->actingAs($this->manager, 'web');

    foreach (irpWidgets() as $widget) {
        Livewire::test($widget)
            ->assertSee(__('widgets.revenue_dashboard.number_table_toggle'))
            ->assertSeeHtml('role="img"');
    }
});

it('keeps chart options plain php arrays, with no javascript callback for tooltips', function () {
    $this->actingAs($this->manager, 'web');

    foreach (irpWidgets() as $widget) {
        $instance = Livewire::test($widget)->instance();
        $method = new ReflectionMethod($instance, 'getOptions');
        $method->setAccessible(true);

        expect($method->invoke($instance))->toBeArray();
    }
});
