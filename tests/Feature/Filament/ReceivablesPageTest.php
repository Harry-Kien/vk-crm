<?php

use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Filament\Admin\Pages\Receivables;
use App\Filament\Admin\Widgets\Billing\RecentPaymentsWidget;
use App\Http\Middleware\AnswerDeniedPanelRequestsWithNotFound;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Trang "Công nợ" cho kế toán (M9 Task 8). Kế toán không mở được trang vụ việc
 * (`matter.view`) — đây là màn hình DUY NHẤT của họ về tiền.
 *
 * Cổng của cả TRANG là `revenue.viewAny` ({@see Receivables::canAccess()}): admin, quản lý, kế
 * toán vào được; luật sư và trợ lý nhận 404 ({@see AnswerDeniedPanelRequestsWithNotFound}).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);
});

/**
 * Hợp đồng ACTIVE với đúng một đợt bằng giá trị hợp đồng (bất biến tổng M9 giữ sạch mà không cần
 * dựng nhiều đợt) — cùng thành ngữ `BillingRelationManagerTest::activeContractOneInstalment()`.
 */
function receivableOn(Matter $matter, int $amount = 10_000_000, array $instalmentOverrides = []): Instalment
{
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => $amount]);

    return Instalment::factory()->for($contract)->create([
        'amount' => $amount,
        'due_date' => today()->subDays(3)->toDateString(),
        ...$instalmentOverrides,
    ]);
}

// =================================================================================================
// Ai mở được trang
// =================================================================================================

it('lets the accountant open the receivables page', function () {
    $this->actingAs($this->accountant, 'web')
        ->get(Receivables::getUrl(panel: 'admin'))
        ->assertOk();
});

it('lets the manager open the receivables page', function () {
    $this->actingAs($this->manager, 'web')
        ->get(Receivables::getUrl(panel: 'admin'))
        ->assertOk();
});

it('lets the admin open the receivables page', function () {
    $this->actingAs($this->admin, 'web')
        ->get(Receivables::getUrl(panel: 'admin'))
        ->assertOk();
});

it('answers 404 to a lawyer, who has billing.view but not revenue.viewAny', function () {
    $this->actingAs($this->lawyer, 'web')
        ->get(Receivables::getUrl(panel: 'admin'))
        ->assertNotFound();
});

it('answers 404 to an assistant', function () {
    $this->actingAs($this->assistant, 'web')
        ->get(Receivables::getUrl(panel: 'admin'))
        ->assertNotFound();
});

// =================================================================================================
// Ranh giới của kế toán: không tiêu đề vụ việc, tóm tắt nội bộ, hay tên các bên — bất kỳ đâu (DTO
// AccountantBillingRow). Fix vòng 1, I1: bản trước chỉ `assertDontSee`, một bảng RỖNG cũng qua
// được test đó — thêm khẳng định DƯƠNG (mã hồ sơ, tên khách hàng thật sự có trên trang) để chứng
// minh dòng ĐANG hiện ra, không phải hiện KHÔNG GÌ CẢ.
// =================================================================================================

it('never leaks the matter title, internal summary, or a party name, while still showing the matter code and client name', function () {
    $secretTitle = 'TIEU DE TUYET MAT KHONG DUOC LO XXQ123';
    $secretSummary = 'TOM TAT NOI BO TUYET MAT KHONG DUOC LO YYQ456';
    $secretPartyName = 'BEN LIEN QUAN TUYET MAT KHONG DUOC LO ZZQ789';

    $matter = Matter::factory()->create([
        'title' => $secretTitle,
        'summary_for_client' => $secretSummary,
    ]);
    MatterParty::factory()->for($matter)->create(['name' => $secretPartyName]);
    receivableOn($matter);

    $response = $this->actingAs($this->accountant, 'web')->get(Receivables::getUrl(panel: 'admin'));

    $response->assertOk();

    // Dương: dòng THẬT SỰ hiện ra, không phải một bảng rỗng tình cờ qua được các assertDontSee bên
    // dưới.
    $response->assertSee($matter->code);
    $response->assertSee($matter->client->name);

    // Âm: ba trường nội bộ không bao giờ đi qua AccountantBillingRow.
    $response->assertDontSee($secretTitle);
    $response->assertDontSee($secretSummary);
    $response->assertDontSee($secretPartyName);
});

// =================================================================================================
// Đếm truy vấn ở tầng TRANG (fix vòng 1, I2). Ghim phán quyết controller 6 ("eager loading"):
// `->authorize()` của hai nút hỏi Gate cho MỖI dòng khi Filament vẽ bảng — nếu `contract.matter`
// nạp thiếu ba cột mà `ChecksBillingAccess::matterForBillingGate()` cần
// (`confidentiality`/`lead_lawyer_id`/`deleted_at`), mỗi lần hỏi lại là một truy vấn NẠP LẠI vụ
// việc, và số truy vấn của trang sẽ TĂNG THEO SỐ DÒNG. So sánh N dòng với 3N dòng (không phải một
// ngưỡng tuyệt đối — lý do nêu ở `MatterResourceTest`'s test tương tự): SQL-aggregate cộng eager
// loading đầy đủ giữ số truy vấn CỐ ĐỊNH bất kể N.
// =================================================================================================

it('runs the same number of queries for the accountant whether the page has N rows or 3N', function () {
    $this->actingAs($this->accountant, 'web');

    $makeRow = fn () => receivableOn(Matter::factory()->create());

    $makeRow();
    $makeRow();
    $makeRow();

    // Hâm nóng cache quyền Spatie trước khi đo — nạp một lần cho cả tiến trình test, không phải
    // chi phí phụ thuộc số dòng (cùng lý do `MatterResourceTest`'s test tương tự).
    $this->livewire(Receivables::class);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->livewire(Receivables::class);
    $queriesForN = count(DB::getQueryLog());

    $makeRow();
    $makeRow();
    $makeRow();
    $makeRow();
    $makeRow();
    $makeRow();

    DB::flushQueryLog();
    $this->livewire(Receivables::class);
    $queriesFor3N = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($queriesFor3N)->toBe($queriesForN);
});

/** Cùng phép đo, nhưng có MỘT dòng của vụ `restricted` trong cả hai lần — admin thấy được dòng đó
 * (nhánh `restricted` của `isListableBy()`), và `->authorize()` của nút trên dòng đó cũng phải
 * không tốn thêm truy vấn nào, đúng như mọi dòng khác. */
it('runs the same number of queries for the admin, including one restricted matter row, whether the page has N rows or 3N', function () {
    $this->actingAs($this->admin, 'web');

    $makeRow = fn () => receivableOn(Matter::factory()->create());

    receivableOn($this->restricted);
    $makeRow();
    $makeRow();

    $this->livewire(Receivables::class);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->livewire(Receivables::class);
    $queriesForN = count(DB::getQueryLog());

    $makeRow();
    $makeRow();
    $makeRow();
    $makeRow();
    $makeRow();
    $makeRow();

    DB::flushQueryLog();
    $this->livewire(Receivables::class);
    $queriesFor3N = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($queriesFor3N)->toBe($queriesForN);
});

// =================================================================================================
// Tập dòng — Matter::listableBy(), vụ restricted không xuất hiện với kế toán/quản lý.
// =================================================================================================

it('shows the accountant an ordinary matters instalment but hides a restricted one, which only the admin sees', function () {
    $ordinary = receivableOn($this->matter);
    $restricted = receivableOn($this->restricted);

    $this->actingAs($this->accountant, 'web');
    $this->livewire(Receivables::class)
        ->assertCanSeeTableRecords([$ordinary])
        ->assertCanNotSeeTableRecords([$restricted]);

    $this->actingAs($this->manager, 'web');
    $this->livewire(Receivables::class)
        ->assertCanSeeTableRecords([$ordinary])
        ->assertCanNotSeeTableRecords([$restricted]);

    $this->actingAs($this->admin, 'web');
    $this->livewire(Receivables::class)
        ->assertCanSeeTableRecords([$ordinary, $restricted]);
});

// =================================================================================================
// Bộ lọc (fix vòng 1, I4). Options của bộ lọc "khách hàng" không được rộng hơn tập dòng mà chính
// người xem thấy — cùng ranh giới `rowsQuery()` đã áp cho danh sách; ba bộ lọc còn lại chỉ cần một
// bài kiểm tra khói mỗi cái (chúng tái dùng `scopeOverdue()`/điều kiện `whereHas` đơn giản, đã kiểm
// kỹ ở nơi khác).
// =================================================================================================

it('excludes a client whose only debt is on a restricted matter from the accountants filter options, but includes it for the admin', function () {
    $client = Client::factory()->create();
    $restrictedForClient = Matter::factory()->restricted()->for($client)->create(['lead_lawyer_id' => $this->lawyer->id]);
    receivableOn($restrictedForClient);

    $this->actingAs($this->accountant, 'web');
    $accountantOptions = $this->livewire(Receivables::class)->instance()->getTable()->getFilter('client_id')->getOptions();
    expect($accountantOptions)->not->toHaveKey($client->id);

    $this->actingAs($this->admin, 'web');
    $adminOptions = $this->livewire(Receivables::class)->instance()->getTable()->getFilter('client_id')->getOptions();
    expect($adminOptions)->toHaveKey($client->id);
});

it('filters to only overdue instalments when the overdue filter is applied', function () {
    $overdue = receivableOn($this->matter, 10_000_000, ['due_date' => today()->subDays(5)->toDateString()]);
    $notYetDue = receivableOn(Matter::factory()->create(), 10_000_000, ['due_date' => today()->addDays(5)->toDateString()]);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$notYetDue]);
});

it('filters to only instalments due within 7 days when that filter is applied', function () {
    $dueSoon = receivableOn($this->matter, 10_000_000, ['due_date' => today()->addDays(3)->toDateString()]);
    $dueFar = receivableOn(Matter::factory()->create(), 10_000_000, ['due_date' => today()->addDays(20)->toDateString()]);
    $alreadyOverdue = receivableOn(Matter::factory()->create(), 10_000_000, ['due_date' => today()->subDays(5)->toDateString()]);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->filterTable('due_within_7_days')
        ->assertCanSeeTableRecords([$dueSoon])
        ->assertCanNotSeeTableRecords([$dueFar, $alreadyOverdue]);
});

it('filters to only instalments of a closed matter still carrying a balance when that filter is applied', function () {
    $closedMatter = Matter::factory()->create(['closed_at' => today()->subDay()->toDateString()]);
    $closedWithBalance = receivableOn($closedMatter);
    $openWithBalance = receivableOn($this->matter);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->filterTable('closed_with_balance')
        ->assertCanSeeTableRecords([$closedWithBalance])
        ->assertCanNotSeeTableRecords([$openWithBalance]);
});

// =================================================================================================
// Ghi khoản thu — kế toán ghi được, quản lý chỉ xem, "1.250.000" -> 1250000.
// =================================================================================================

it('lets the accountant record a payment through the button, parsing "1.250.000" as 1250000 dong', function () {
    $instalment = receivableOn($this->matter, 10_000_000);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->assertActionVisible(TestAction::make('recordPayment')->table($instalment))
        ->callAction(TestAction::make('recordPayment')->table($instalment), data: [
            'amount' => '1.250.000',
            'paid_on' => today()->toDateString(),
            'method' => PaymentMethod::BankTransfer->value,
        ])
        ->assertHasNoActionErrors();

    $payment = Payment::query()->where('instalment_id', $instalment->id)->sole();
    expect($payment->amount)->toBe(1_250_000)
        ->and($payment->receipt_document_id)->toBeNull()
        ->and($instalment->fresh()->status)->toBe(InstalmentStatus::Pending);
});

/**
 * "kế toán ghi khoản thu qua Livewire và đợt chuyển trạng thái" (test bắt buộc, brief Task 8):
 * ghi ĐỦ số tiền còn lại của đợt phải đưa `status` từ `pending` sang `paid` — cùng bước 8 của
 * `RecordPayment::handle()`, qua đúng đường Livewire của trang này, không gọi thẳng Action.
 */
it('transitions the instalment to paid once the accountant records enough through the page', function () {
    $instalment = receivableOn($this->matter, 10_000_000);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->callAction(TestAction::make('recordPayment')->table($instalment), data: [
            'amount' => '10.000.000',
            'paid_on' => today()->toDateString(),
            'method' => PaymentMethod::BankTransfer->value,
        ])
        ->assertHasNoActionErrors();

    expect($instalment->fresh()->status)->toBe(InstalmentStatus::Paid);
});

it('hides both money buttons from the manager, who can only look', function () {
    $instalment = receivableOn($this->matter);

    $this->actingAs($this->manager, 'web');

    $this->livewire(Receivables::class)
        ->assertActionHidden(TestAction::make('recordPayment')->table($instalment))
        ->assertActionHidden(TestAction::make('voidPayment')->table($instalment));
});

/**
 * Fix vòng 1, I3(a). `->callAction()` (dùng ở mọi test khác) tự `assertActionVisible()` TRƯỚC KHI
 * mount — một cổng của KHUNG TEST, không phải của sản xuất. Ở đây gọi thẳng phương thức Livewire
 * `mountAction()` (bỏ qua macro `TestsActions::callAction()` và cái `assertTrue()` của nó), đúng
 * đường một request `wire:submit` giả có thể đi: `InteractsWithActions::mountAction()` (vendor,
 * dòng ~163) tự hỏi `$action->isDisabled()` — đọc lại `->authorize()` — và trả `null` NGAY, không
 * mount, không ném lỗi, khi nó `false`. Đây là chỗ SẢN XUẤT thật sự chặn, không phải một giả định.
 */
it('refuses recordPayment for the manager even when mounted directly through Livewire, bypassing the hidden button', function () {
    $instalment = receivableOn($this->matter);

    $this->actingAs($this->manager, 'web');

    $this->livewire(Receivables::class)
        ->call('mountAction', 'recordPayment', [], ['table' => true, 'recordKey' => (string) $instalment->id])
        // Bằng chứng CHÍNH: không có gì được MOUNT — không chỉ "không có Payment" (điều đó vẫn
        // đúng dù mount có thành công hay không, vì chưa nộp dữ liệu nào). `mountedActions` rỗng
        // là dấu vết trực tiếp của nhánh `isDisabled()` (vendor, dòng ~163) đã chặn TRƯỚC khi mở
        // modal.
        ->assertActionNotMounted();

    expect(Payment::query()->where('instalment_id', $instalment->id)->count())->toBe(0);
});

/**
 * Fix vòng 1, I3(b). Kế toán không thấy dòng của vụ `restricted` trong tập dòng của mình
 * (`rowsQuery()` đã lọc), nên `getTableRecord()` (vendor) chạy lại ĐÚNG truy vấn đó với
 * `recordKey` giả mạo — không tìm thấy — và `mountAction()` bắt `ActionNotResolvableException` rồi
 * trả `null`, cùng một kết quả im lặng như test trên. Không có Payment nào được ghi dù `recordKey`
 * trỏ thẳng vào một đợt thật.
 */
it('refuses recordPayment for the accountant when the recordKey belongs to a restricted matters instalment', function () {
    $instalment = receivableOn($this->restricted);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->call('mountAction', 'recordPayment', [], ['table' => true, 'recordKey' => (string) $instalment->id])
        ->assertActionNotMounted();

    expect(Payment::query()->where('instalment_id', $instalment->id)->count())->toBe(0);
});

it('turns PaymentExceedsInstalment into a notification instead of a 500, and does not record anything', function () {
    $instalment = receivableOn($this->matter, 10_000_000);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)->callAction(TestAction::make('recordPayment')->table($instalment), data: [
        'amount' => '20.000.000',
        'paid_on' => today()->toDateString(),
        'method' => PaymentMethod::Cash->value,
    ]);

    Notification::assertNotified(__('actions.failed_title'));
    expect(Payment::query()->where('instalment_id', $instalment->id)->count())->toBe(0);
});

// =================================================================================================
// Huỷ khoản thu — ĐÚNG một khoản được chọn, không giới hạn khoản gần nhất (phán quyết controller 2).
// =================================================================================================

it('lets the accountant void an older payment while a newer one on the same instalment stays untouched', function () {
    $instalment = receivableOn($this->matter, 10_000_000);
    $older = Payment::factory()->for($instalment)->create(['amount' => 4_000_000, 'paid_on' => today()->subDays(5)->toDateString()]);
    $newer = Payment::factory()->for($instalment)->create(['amount' => 4_000_000, 'paid_on' => today()->subDay()->toDateString()]);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->callAction(TestAction::make('voidPayment')->table($instalment), data: [
            'payment_id' => $older->id,
            'reason' => str_repeat('a', 20),
        ])
        ->assertHasNoActionErrors();

    expect($older->fresh()->voided_at)->not->toBeNull()
        ->and($newer->fresh()->voided_at)->toBeNull();
});

it('lets the accountant void the newer payment by id while an older one on the same instalment stays untouched', function () {
    $instalment = receivableOn($this->matter, 10_000_000);
    $older = Payment::factory()->for($instalment)->create(['amount' => 4_000_000, 'paid_on' => today()->subDays(5)->toDateString()]);
    $newer = Payment::factory()->for($instalment)->create(['amount' => 4_000_000, 'paid_on' => today()->subDay()->toDateString()]);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->callAction(TestAction::make('voidPayment')->table($instalment), data: [
            'payment_id' => $newer->id,
            'reason' => str_repeat('a', 20),
        ])
        ->assertHasNoActionErrors();

    expect($newer->fresh()->voided_at)->not->toBeNull()
        ->and($older->fresh()->voided_at)->toBeNull();
});

/**
 * Fix vòng 1, minor: không tin `payment_id` từ Livewire — xem chú thích ở `voidPaymentAction()`.
 * Ô `Select` chỉ hiện các khoản CỦA ĐÚNG đợt đang mở modal, nhưng một request dựng tay có thể gửi
 * id của một khoản thu thuộc đợt KHÁC (cả hai vụ đều thường, kế toán có quyền huỷ trên cả hai) —
 * đợt đang mở modal phải không đổi gì, dù id đó có thật và kế toán có quyền chung trên vụ của nó.
 *
 * **Giới hạn thành thật của test này:** đo qua `callAction()` (đúng đường Livewire thật), validation
 * "in options" của chính `Select` (vendor) đã chặn TRƯỚC khi request chạm tới điều kiện
 * `instalment_id !== $record->id` trong action — nên xoá điều kiện đó KHÔNG làm test này đỏ (đã
 * thử). Test vẫn giữ vì nó đo đúng KẾT QUẢ cần có (không huỷ nhầm), và ghim rằng validation của
 * `Select` đang là chốt chặn thật sự cho đường này — xem docblock `voidPaymentAction()`.
 */
it('refuses to void a payment whose id belongs to a different instalment, even one the accountant can also act on', function () {
    $instalmentA = receivableOn($this->matter, 10_000_000);
    // Un khoản THẬT trên A, để nút "Huỷ khoản thu" hiện ra (visible() đòi còn khoản chưa huỷ) —
    // đây không phải khoản bị nhắm tới; test chỉ cần nút hiện, không cần huỷ đúng khoản này.
    Payment::factory()->for($instalmentA)->create(['amount' => 1_000_000]);

    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $instalmentB = receivableOn($otherMatter, 10_000_000);
    $paymentOnB = Payment::factory()->for($instalmentB)->create(['amount' => 4_000_000]);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->callAction(TestAction::make('voidPayment')->table($instalmentA), data: [
            'payment_id' => $paymentOnB->id,
            'reason' => str_repeat('a', 20),
        ]);

    expect($paymentOnB->fresh()->voided_at)->toBeNull();
});

it('hides the void button once every payment on the instalment has already been voided', function () {
    $instalment = receivableOn($this->matter, 10_000_000);
    Payment::factory()->for($instalment)->voided()->create(['amount' => 4_000_000]);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->assertActionHidden(TestAction::make('voidPayment')->table($instalment));
});

/** Lượt rà soát cuối M9, M5: ô số tiền của khoản thu có câu hướng dẫn RIÊNG, không mượn câu "giá trị hợp đồng". */
it('explains the payment amount field as the money actually received this time', function () {
    $instalment = receivableOn($this->matter);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->mountAction(TestAction::make('recordPayment')->table($instalment))
        ->assertMountedActionModalSee(__('billing.tab.fields.payment_amount_help'))
        ->assertMountedActionModalDontSee(__('billing.tab.fields.total_amount_help'));
});

// =================================================================================================
// Lượt rà soát cuối M9, I2 — "Khoản thu gần đây": một đợt đã thu đủ/đã miễn rời bảng công nợ, nên
// kế toán (không mở được trang vụ việc) không còn đường nào huỷ một khoản thu ghi nhầm trên nó. Mục
// thứ hai của trang liệt kê khoản thu CHƯA HUỶ trong 90 ngày theo `paid_on`, cùng phạm vi
// `listableBy()`, dữ liệu chỉ qua `AccountantPaymentRow`, mỗi dòng một nút huỷ qua `VoidPayment`.
// =================================================================================================

/**
 * Một đợt ĐÃ THU ĐỦ (rời bảng công nợ) cùng khoản thu của nó.
 *
 * @return array{0: Instalment, 1: Payment}
 */
function paidInstalmentOn(Matter $matter, int $amount = 10_000_000, array $paymentOverrides = []): array
{
    $instalment = receivableOn($matter, $amount, ['status' => InstalmentStatus::Paid]);
    $payment = Payment::factory()->for($instalment)->create([
        'amount' => $amount,
        'paid_on' => today()->subDays(2)->toDateString(),
        ...$paymentOverrides,
    ]);

    return [$instalment, $payment];
}

it('puts the recent-payments section on the receivables page', function () {
    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)->assertSeeLivewire(RecentPaymentsWidget::class);
});

it('keeps the recent-payments section off the home dashboard', function () {
    expect(RecentPaymentsWidget::isDiscovered())->toBeFalse()
        ->and(Filament::getPanel('admin')->getWidgets())->not->toContain(RecentPaymentsWidget::class);
});

it('lets the accountant void the payment of an instalment that is already paid and no longer in the receivables rows', function () {
    [$instalment, $payment] = paidInstalmentOn($this->matter);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)->assertCanNotSeeTableRecords([$instalment]);

    $this->livewire(RecentPaymentsWidget::class)
        ->assertCanSeeTableRecords([$payment])
        ->assertActionVisible(TestAction::make('voidPayment')->table($payment))
        ->callAction(TestAction::make('voidPayment')->table($payment), data: [
            'reason' => 'Ghi nhầm khoản thu, khách chưa chuyển khoản.',
        ])
        ->assertHasNoActionErrors();

    expect($payment->fresh()->voided_at)->not->toBeNull()
        ->and($payment->fresh()->voided_by)->toBe($this->accountant->id)
        ->and($instalment->fresh()->status)->toBe(InstalmentStatus::Pending);
});

it('never lists a restricted matters payment for the accountant or the manager, while the admin sees it', function () {
    [, $ordinary] = paidInstalmentOn($this->matter);
    [, $restricted] = paidInstalmentOn($this->restricted);

    foreach ([$this->accountant, $this->manager] as $viewer) {
        $this->actingAs($viewer, 'web');
        $this->livewire(RecentPaymentsWidget::class)
            ->assertCanSeeTableRecords([$ordinary])
            ->assertCanNotSeeTableRecords([$restricted]);
    }

    $this->actingAs($this->admin, 'web');
    $this->livewire(RecentPaymentsWidget::class)->assertCanSeeTableRecords([$ordinary, $restricted]);
});

it('shows the manager the recent payments without a void button', function () {
    [, $payment] = paidInstalmentOn($this->matter);

    $this->actingAs($this->manager, 'web');

    $this->livewire(RecentPaymentsWidget::class)
        ->assertCanSeeTableRecords([$payment])
        ->assertActionHidden(TestAction::make('voidPayment')->table($payment));
});

/** Cùng lối tấn công `mountAction()` thẳng như nút ghi khoản thu ở trên: cổng thật là `->authorize()`. */
it('refuses voidPayment for the manager even when mounted directly through Livewire', function () {
    [, $payment] = paidInstalmentOn($this->matter);

    $this->actingAs($this->manager, 'web');

    $this->livewire(RecentPaymentsWidget::class)
        ->call('mountAction', 'voidPayment', [], ['table' => true, 'recordKey' => (string) $payment->id])
        ->assertActionNotMounted();

    expect($payment->fresh()->voided_at)->toBeNull();
});

it('lists only unvoided payments paid within the last 90 days, newest first', function () {
    [, $today] = paidInstalmentOn($this->matter, 10_000_000, ['paid_on' => today()->toDateString()]);
    [, $edge] = paidInstalmentOn(Matter::factory()->create(), 10_000_000, ['paid_on' => today()->subDays(90)->toDateString()]);
    [, $tooOld] = paidInstalmentOn(Matter::factory()->create(), 10_000_000, ['paid_on' => today()->subDays(91)->toDateString()]);
    [, $voided] = paidInstalmentOn(Matter::factory()->create(), 10_000_000, [
        'voided_at' => now(), 'voided_by' => $this->admin->id, 'void_reason' => str_repeat('v', 20),
    ]);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(RecentPaymentsWidget::class)
        ->assertCanSeeTableRecords([$today, $edge], inOrder: true)
        ->assertCanNotSeeTableRecords([$tooOld, $voided]);
});

it('filters recent payments by client and by matter code', function () {
    $client = Client::factory()->create();
    [, $mine] = paidInstalmentOn(Matter::factory()->for($client)->create());
    [, $other] = paidInstalmentOn($this->matter);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(RecentPaymentsWidget::class)
        ->filterTable('client_id', $client->id)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other]);

    $this->livewire(RecentPaymentsWidget::class)
        ->filterTable('matter_code', ['code' => $this->matter->code])
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$mine]);
});

/**
 * M9 Task 13, vòng sửa 1 (I1): khoản thu ghi lùi ngày lúc nhập hợp đồng go-live (`paid_on` cũ hơn
 * 90 ngày) trên một đợt đã thu đủ thì vừa rời bảng công nợ vừa nằm ngoài cửa sổ 90 ngày của mục
 * này — trước bản sửa, kế toán không còn đường nào huỷ nó. Gõ mã hồ sơ thì bỏ cửa sổ: thấy mọi
 * khoản thu chưa huỷ của hồ sơ đó, và huỷ được.
 */
it('lets the accountant find and void a back-dated payment older than 90 days by typing its matter code', function () {
    [$instalment, $old] = paidInstalmentOn($this->matter, 10_000_000, ['paid_on' => today()->subDays(200)->toDateString()]);
    [, $otherOld] = paidInstalmentOn(Matter::factory()->create(), 10_000_000, ['paid_on' => today()->subDays(200)->toDateString()]);

    $this->actingAs($this->accountant, 'web');

    // Mặc định vẫn là cửa sổ 90 ngày, và mục nói rõ cách bỏ nó.
    $this->livewire(RecentPaymentsWidget::class)
        ->assertCanNotSeeTableRecords([$old, $otherOld])
        ->assertSee('Gõ mã hồ sơ vào bộ lọc "Mã hồ sơ" thì thấy mọi khoản thu chưa huỷ của hồ sơ đó, kể cả cũ hơn 90 ngày');

    $this->livewire(RecentPaymentsWidget::class)
        ->filterTable('matter_code', ['code' => $this->matter->code])
        ->assertCanSeeTableRecords([$old])
        ->assertCanNotSeeTableRecords([$otherOld])
        ->assertActionVisible(TestAction::make('voidPayment')->table($old))
        ->callAction(TestAction::make('voidPayment')->table($old), data: [
            'reason' => 'Ghi lùi nhầm đợt lúc nhập hợp đồng go-live.',
        ])
        ->assertHasNoActionErrors();

    expect($old->fresh()->voided_at)->not->toBeNull()
        ->and($old->fresh()->voided_by)->toBe($this->accountant->id)
        ->and($instalment->fresh()->status)->toBe(InstalmentStatus::Pending);
});

/** Một ô mã hồ sơ chỉ có khoảng trắng là ô trống: cửa sổ 90 ngày vẫn còn, không thành "mọi khoản thu". */
it('keeps the 90-day window when the matter code filter holds only spaces', function () {
    [, $recent] = paidInstalmentOn($this->matter);
    [, $old] = paidInstalmentOn(Matter::factory()->create(), 10_000_000, ['paid_on' => today()->subDays(200)->toDateString()]);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(RecentPaymentsWidget::class)
        ->filterTable('matter_code', ['code' => '   '])
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$old]);
});

/**
 * Bỏ cửa sổ 90 ngày theo mã hồ sơ KHÔNG bỏ hai ranh giới còn lại của mục: khoản đã huỷ vẫn không
 * hiện, và vụ `restricted` vẫn chỉ admin thấy — kế toán gõ đúng mã của vụ đó cũng không ra gì.
 */
it('keeps voided payments out and a restricted matters old payment admin-only when the matter code lifts the 90-day window', function () {
    [, $voidedOld] = paidInstalmentOn($this->matter, 10_000_000, [
        'paid_on' => today()->subDays(200)->toDateString(),
        'voided_at' => now(), 'voided_by' => $this->admin->id, 'void_reason' => str_repeat('v', 20),
    ]);
    [, $restrictedOld] = paidInstalmentOn($this->restricted, 10_000_000, ['paid_on' => today()->subDays(200)->toDateString()]);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(RecentPaymentsWidget::class)
        ->filterTable('matter_code', ['code' => $this->matter->code])
        ->assertCanNotSeeTableRecords([$voidedOld]);

    $this->livewire(RecentPaymentsWidget::class)
        ->filterTable('matter_code', ['code' => $this->restricted->code])
        ->assertCanNotSeeTableRecords([$restrictedOld]);

    $this->actingAs($this->admin, 'web');

    $this->livewire(RecentPaymentsWidget::class)
        ->filterTable('matter_code', ['code' => $this->restricted->code])
        ->assertCanSeeTableRecords([$restrictedOld]);
});

it('excludes a client whose only payment is on a restricted matter from the accountants client filter', function () {
    $client = Client::factory()->create();
    paidInstalmentOn(Matter::factory()->restricted()->for($client)->create(['lead_lawyer_id' => $this->lawyer->id]));

    $this->actingAs($this->accountant, 'web');
    $options = $this->livewire(RecentPaymentsWidget::class)->instance()->getTable()->getFilter('client_id')->getOptions();
    expect($options)->not->toHaveKey($client->id);

    $this->actingAs($this->admin, 'web');
    $options = $this->livewire(RecentPaymentsWidget::class)->instance()->getTable()->getFilter('client_id')->getOptions();
    expect($options)->toHaveKey($client->id);
});

it('never leaks the matter title, a party name, or the internal payment note, while still showing the code, client, amount and reference', function () {
    $secretTitle = 'TIEU DE TUYET MAT KHONG DUOC LO PAY123';
    $secretParty = 'BEN LIEN QUAN TUYET MAT KHONG DUOC LO PAY456';
    $secretNote = 'GHI CHU NOI BO KHOAN THU KHONG DUOC LO PAY789';

    $matter = Matter::factory()->create(['title' => $secretTitle]);
    MatterParty::factory()->for($matter)->create(['name' => $secretParty]);
    paidInstalmentOn($matter, 7_000_000, ['note' => $secretNote, 'reference' => 'UNC-2026-0042']);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(RecentPaymentsWidget::class)
        ->assertSee($matter->code)
        ->assertSee($matter->client->name)
        ->assertSee(Money::format(7_000_000))
        ->assertSee('UNC-2026-0042')
        ->assertDontSee($secretTitle)
        ->assertDontSee($secretParty)
        ->assertDontSee($secretNote);
});

it('runs the same number of queries for the recent-payments section whether it has N rows or 3N', function () {
    $this->actingAs($this->accountant, 'web');

    $makeRow = fn () => paidInstalmentOn(Matter::factory()->create());

    $makeRow();
    $makeRow();
    $makeRow();

    $this->livewire(RecentPaymentsWidget::class);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->livewire(RecentPaymentsWidget::class);
    $queriesForN = count(DB::getQueryLog());

    foreach (range(1, 6) as $ignored) {
        $makeRow();
    }

    DB::flushQueryLog();
    $this->livewire(RecentPaymentsWidget::class);
    $queriesFor3N = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($queriesFor3N)->toBe($queriesForN);
});

/**
 * C1 qua đúng màn hình kế toán dùng (lượt sửa thứ hai sau rà soát cuối M9, minor): `VoidPayment`
 * LUÔN từ chối khoản thu của hợp đồng đã hoàn tất, nên nút "Huỷ khoản thu" không hiện trên dòng
 * đó — một nút chỉ để nhận lời từ chối là một nút nói dối. Hợp đồng đang hiệu lực và đã huỷ vẫn
 * có nút (C1: hai trạng thái đó huỷ được).
 */
it('hides the void button on a payment whose contract is completed, and keeps it on active and cancelled contracts', function () {
    [$completedInstalment, $onCompleted] = paidInstalmentOn($this->matter);
    $completedInstalment->contract->forceFill(['status' => ContractStatus::Completed, 'ended_at' => today()->toDateString()])->save();

    [$cancelledInstalment, $onCancelled] = paidInstalmentOn(Matter::factory()->create());
    $cancelledInstalment->contract->forceFill(['status' => ContractStatus::Cancelled, 'ended_at' => today()->toDateString(), 'ended_reason' => str_repeat('c', 20)])->save();

    [, $onActive] = paidInstalmentOn(Matter::factory()->create());

    $this->actingAs($this->accountant, 'web');

    $this->livewire(RecentPaymentsWidget::class)
        ->assertCanSeeTableRecords([$onCompleted, $onCancelled, $onActive])
        ->assertActionHidden(TestAction::make('voidPayment')->table($onCompleted))
        ->assertActionVisible(TestAction::make('voidPayment')->table($onCancelled))
        ->assertActionVisible(TestAction::make('voidPayment')->table($onActive));
});

/** Gọi thẳng `mountAction()` (bỏ qua nút bị ẩn) trên khoản thu của hợp đồng đã hoàn tất: không mount, không huỷ gì. */
it('voids nothing when the hidden void action of a completed contract is mounted directly through Livewire', function () {
    [$instalment, $payment] = paidInstalmentOn($this->matter);
    $instalment->contract->forceFill(['status' => ContractStatus::Completed, 'ended_at' => today()->toDateString()])->save();

    $this->actingAs($this->accountant, 'web');

    $this->livewire(RecentPaymentsWidget::class)
        ->call('mountAction', 'voidPayment', [], ['table' => true, 'recordKey' => (string) $payment->id])
        ->assertActionNotMounted();

    expect($payment->fresh()->voided_at)->toBeNull();
});

// Định nghĩa đầy đủ "ai ghi được payment.record trên vụ restricted" (chỉ lead lawyer/admin) đã có
// bộ test riêng ở tests/Feature/Authorization/BillingAccessTest.php — không lặp lại ở đây; hai
// test trên chỉ ghim rằng TRANG NÀY tôn trọng đúng cổng đó, kể cả khi bị gọi thẳng qua Livewire.

/**
 * M9 Task 13 — cùng lỗi ngày cuối kỳ của trang doanh thu (`RevenueFilters::bounds()`): trên SQLite,
 * cast `date` ghi `due_date` thành `Y-m-d 00:00:00`, lớn hơn cận trên `Y-m-d` của
 * `whereBetween`, nên đợt đến hạn ĐÚNG ngày thứ bảy (và hôm nay ở cận dưới thì vẫn đúng nhờ so lớn
 * hơn) rơi khỏi bộ lọc "đến hạn trong 7 ngày". MariaDB (cột DATE) không sai; sửa ở mã cho đúng cả hai.
 */
it('keeps an instalment due exactly seven days from today, and one due today, in the due-within-7-days filter, but not one due on the eighth day', function () {
    $dueToday = receivableOn($this->matter, 10_000_000, ['due_date' => today()->toDateString()]);
    $dueOnDaySeven = receivableOn(Matter::factory()->create(), 10_000_000, ['due_date' => today()->addDays(7)->toDateString()]);
    $dueOnDayEight = receivableOn(Matter::factory()->create(), 10_000_000, ['due_date' => today()->addDays(8)->toDateString()]);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->filterTable('due_within_7_days')
        ->assertCanSeeTableRecords([$dueToday, $dueOnDaySeven])
        ->assertCanNotSeeTableRecords([$dueOnDayEight]);
});
