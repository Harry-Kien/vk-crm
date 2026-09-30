<?php

use App\Actions\Schedule\RemindOverdueInstalments;
use App\Enums\Role;
use App\Mail\Staff\InstalmentOverdue;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Billing\AccountantBillingRow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * M9 Task 11, fix vòng 1 — câu "việc cần làm" cuối thư `staff.instalment_overdue` phải khớp với
 * cái người NHẬN thật sự làm được (`PaymentPolicy::create`, `ContractPolicy::update`), không phải
 * một câu chung cho mọi vai. Trước fix, kế toán được bảo "cập nhật phụ lục hợp đồng" (không có
 * `contract.manage`) và luật sư/quản lý được bảo "ghi khoản thu" (không có nút, trừ vụ restricted).
 *
 * Mỗi test dựng thư trực tiếp cho MỘT người nhận và đo cả bản HTML lẫn bản văn bản thuần: hai bản
 * dùng chung một khoá ngôn ngữ nhưng là hai view riêng, một view có thể quên đổi.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
});

/** @return array{0: Instalment, 1: AccountantBillingRow} */
function overdueParts(Matter $matter): array
{
    $contract = Contract::factory()->for($matter)->create(['total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($contract)->create([
        'name' => 'Đợt hai khi nộp đơn',
        'amount' => 10_000_000,
        'due_date' => today()->subDays(3)->toDateString(),
    ]);
    DB::table('contracts')->where('id', $contract->id)->update(['status' => 'active']);

    $instalment = RemindOverdueInstalments::candidates()->whereKey($instalment->id)->firstOrFail();

    return [$instalment, AccountantBillingRow::fromInstalment(
        $instalment,
        (int) $instalment->getAttribute('collected_amount'),
        (int) $instalment->getAttribute('outstanding_amount'),
        $instalment->state(),
    )];
}

const OVERDUE_RECORD_SELF = 'Anh/chị ghi khoản thu khi đã nhận được tiền.';
const OVERDUE_RECORD_OTHER = 'Kế toán ghi khoản thu khi đã nhận được tiền.';
const OVERDUE_AMEND_SELF = 'anh/chị cập nhật phụ lục hợp đồng';
const OVERDUE_AMEND_OTHER = 'luật sư phụ trách cập nhật phụ lục hợp đồng';
const OVERDUE_REPEAT = 'Thư này nhắc lại sau bảy ngày';

function overdueMailFor(Matter $matter, User $recipient): InstalmentOverdue
{
    [$instalment, $row] = overdueParts($matter);

    return new InstalmentOverdue($instalment, $row, $recipient);
}

/** Khẳng định cả hai bản thư mang đủ `$present` và không mang `$absent`. */
function expectActionSentences(InstalmentOverdue $mail, array $present, array $absent): void
{
    foreach ($present as $sentence) {
        $mail->assertSeeInHtml($sentence);
        $mail->assertSeeInText($sentence);
    }

    foreach ($absent as $sentence) {
        $mail->assertDontSeeInHtml($sentence);
        $mail->assertDontSeeInText($sentence);
    }
}

it('tells an accountant to record the payment and to ask the lead lawyer for a contract amendment', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);

    expectActionSentences(overdueMailFor($matter, $accountant),
        [OVERDUE_RECORD_SELF, OVERDUE_AMEND_OTHER, OVERDUE_REPEAT],
        [OVERDUE_RECORD_OTHER, OVERDUE_AMEND_SELF]);
});

it('tells the lead lawyer of a normal matter that the accountant records the payment, and that they amend the contract', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);

    expectActionSentences(overdueMailFor($matter, $this->lead),
        [OVERDUE_RECORD_OTHER, OVERDUE_AMEND_SELF, OVERDUE_REPEAT],
        [OVERDUE_RECORD_SELF, OVERDUE_AMEND_OTHER]);
});

it('tells the lead lawyer of a restricted matter to record the payment and amend the contract themselves', function () {
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);

    expectActionSentences(overdueMailFor($matter, $this->lead),
        [OVERDUE_RECORD_SELF, OVERDUE_AMEND_SELF, OVERDUE_REPEAT],
        [OVERDUE_RECORD_OTHER, OVERDUE_AMEND_OTHER]);
});

it('tells a manager that the accountant records the payment, and that they amend the contract', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);

    expectActionSentences(overdueMailFor($matter, $manager),
        [OVERDUE_RECORD_OTHER, OVERDUE_AMEND_SELF, OVERDUE_REPEAT],
        [OVERDUE_RECORD_SELF, OVERDUE_AMEND_OTHER]);
});

it('tells an admin to record the payment and to amend the contract', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);

    expectActionSentences(overdueMailFor($matter, $admin),
        [OVERDUE_RECORD_SELF, OVERDUE_AMEND_SELF, OVERDUE_REPEAT],
        [OVERDUE_RECORD_OTHER, OVERDUE_AMEND_OTHER]);
});
