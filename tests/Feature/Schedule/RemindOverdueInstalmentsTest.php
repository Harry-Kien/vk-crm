<?php

use App\Actions\Schedule\RemindOverdueInstalments;
use App\Enums\ContractStatus;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Filament\Admin\Pages\Receivables;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Jobs\SendInstalmentOverdueMail;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\OutboundMessage;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * M9 Task 11 — nhắc nội bộ cho đợt thanh toán quá hạn (`staff.instalment_overdue`), chạy 08:00
 * hằng ngày. Không dùng `Mail::fake()` ở phần lớn tệp này: sổ thư `outbound_messages` LÀ trí nhớ
 * chống trùng của tác vụ (M6 R3), và `MailFake` vô hiệu hoá đúng cơ chế đó. `phpunit.xml` ghim
 * `MAIL_MAILER=array` và `QUEUE_CONNECTION=sync`, nên job chạy đồng bộ ngay trong `handle()` và
 * thư đi qua đường thật (transport ghi sổ) rồi dừng lại trong bộ nhớ.
 *
 * Mốc thời gian: mọi test đóng băng giờ ở 08:00 hôm nay (`travelTo`) để `today()` không nhảy ngày
 * giữa chừng, và dựng `due_date` theo `today()`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(today()->setTime(8, 0));

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

/**
 * Một vụ việc có hợp đồng ACTIVE và MỘT đợt bằng đúng tổng hợp đồng (bất biến tổng M9 giữ sạch mà
 * không cần dựng nhiều đợt), đến hạn cách hôm nay `$daysOverdue` ngày.
 *
 * @return array{0: Matter, 1: Instalment, 2: Contract}
 */
function overdueInstalment(array $matterAttributes = [], int $daysOverdue = 3, int $amount = 10_000_000, array $instalmentAttributes = []): array
{
    $matter = Matter::factory()->create($matterAttributes);
    // Dựng hợp đồng ở `draft` rồi đưa thẳng sang `active` bằng SQL: bất biến tổng của M9 canh mọi
    // lần ghi vào một hợp đồng đang `active`, còn ở đây ta cần những tổ hợp (đợt đã miễn, đã huỷ, hai
    // đợt) mà lúc dựng dở dang chưa cân — đúng cách fixture của các test tiền khác đi vòng nó.
    $contract = Contract::factory()->for($matter)->create(['total_amount' => $amount]);
    $instalment = Instalment::factory()->for($contract)->create([
        'name' => 'Đợt hai khi nộp đơn',
        'amount' => $amount,
        'due_date' => today()->subDays($daysOverdue)->toDateString(),
        ...$instalmentAttributes,
    ]);
    activateOverdueContract($contract);

    return [$matter, $instalment, $contract->refresh()];
}

function activateOverdueContract(Contract $contract): void
{
    DB::table('contracts')->where('id', $contract->id)->update([
        'status' => ContractStatus::Active->value,
        'signed_at' => today()->subMonth()->toDateString(),
    ]);
}

/**
 * Hai đợt (10 triệu mỗi đợt) của MỘT hợp đồng active, đến hạn cách hôm nay 5 và 2 ngày.
 *
 * @return array{0: Matter, 1: Instalment, 2: Instalment}
 */
function overdueTwoInstalments(array $matterAttributes = []): array
{
    $matter = Matter::factory()->create($matterAttributes);
    $contract = Contract::factory()->for($matter)->create(['total_amount' => 20_000_000]);
    $first = Instalment::factory()->for($contract)->create(['sequence' => 1, 'name' => 'Đợt một', 'amount' => 10_000_000, 'due_date' => today()->subDays(5)->toDateString()]);
    $second = Instalment::factory()->for($contract)->create(['sequence' => 2, 'name' => 'Đợt hai', 'amount' => 10_000_000, 'due_date' => today()->subDays(2)->toDateString()]);
    activateOverdueContract($contract);

    return [$matter, $first, $second];
}

/** @return Collection<int, OutboundMessage> */
function overdueLedger(?string $status = null)
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', 'staff.instalment_overdue')
        ->when($status !== null, fn ($query) => $query->where('status', $status))
        ->orderBy('id')
        ->get();
}

function overdueRecipients(?string $status = 'sent'): array
{
    return overdueLedger($status)->pluck('recipient')->sort()->values()->all();
}

/** @return list<Email> Thư THẬT đã đi qua transport `array`. */
function overdueSentEmails(): array
{
    return Mail::mailer()->getSymfonyTransport()->innerTransport()->messages()
        ->map(fn ($sent) => $sent->getOriginalMessage())
        ->filter(fn ($message) => $message instanceof Email)
        ->values()
        ->all();
}

function overdueEmailTo(User $user): ?Email
{
    return collect(overdueSentEmails())
        ->first(fn (Email $mail) => $mail->getTo()[0]->getAddress() === $user->email);
}

function runOverdueReminders(): array
{
    return app(RemindOverdueInstalments::class)->handle();
}

function overdueEmails(User ...$users): array
{
    return collect($users)->pluck('email')->sort()->values()->all();
}

// =================================================================================================
// Ai nhận (P3): vụ thường, vụ restricted
// =================================================================================================

it('mails the lead lawyer and every active accountant of a normal matter — and neither the manager nor the admin', function () {
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);

    runOverdueReminders();

    expect(overdueRecipients())->toBe(overdueEmails($this->lead, $this->accountant));
});

it('mails the lead lawyer and every admin of a restricted matter — and not the accountant or the manager', function () {
    overdueInstalment(['lead_lawyer_id' => $this->lead->id, 'confidentiality' => 'restricted']);

    runOverdueReminders();

    expect(overdueRecipients())->toBe(overdueEmails($this->lead, $this->admin));
});

it('does not mail a deactivated accountant', function () {
    $this->accountant->update(['is_active' => false]);
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);

    runOverdueReminders();

    expect(overdueRecipients())->toBe(overdueEmails($this->lead));
});

/**
 * Ca "người phụ trách nghỉ việc → dự phòng": trên vụ THƯỜNG, dự phòng chỉ chạy khi TOÀN BỘ ưu tiên
 * rớt. Ở đây kế toán DUY NHẤT bị vô hiệu hoá VÀ luật sư nghỉ việc — không còn ai trong audience —
 * nên thư đi theo chuỗi R3 tới MỘT quản lý (không phải mọi quản lý, không phải admin).
 */
it('falls back to one manager when the lead lawyer left and no accountant is active', function () {
    $this->accountant->update(['is_active' => false]);
    $this->lead->update(['is_active' => false]);
    User::factory()->withRole(Role::Manager)->create();
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);

    runOverdueReminders();

    $recipients = overdueRecipients();
    expect($recipients)->toHaveCount(1)
        ->and(User::query()->where('email', $recipients[0])->first()->hasRole(Role::Manager->value))->toBeTrue();
});

it('does not send the fallback copy while an accountant is still active, even if the lead lawyer left', function () {
    $this->lead->update(['is_active' => false]);
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);

    runOverdueReminders();

    expect(overdueRecipients())->toBe(overdueEmails($this->accountant));
});

/**
 * Ca dự phòng của vụ `restricted`: luật sư nghỉ việc và admin DUY NHẤT là người của audience —
 * không cần chuỗi dự phòng, admin nhận thẳng. Quản lý (có mặt, nhưng không thấy vụ hạn chế) và kế
 * toán (không thấy vụ hạn chế) không nhận.
 */
it('mails only the admin of a restricted matter whose lead lawyer left', function () {
    $this->lead->update(['is_active' => false]);
    overdueInstalment(['lead_lawyer_id' => $this->lead->id, 'confidentiality' => 'restricted']);

    runOverdueReminders();

    expect(overdueRecipients())->toBe(overdueEmails($this->admin));
});

// =================================================================================================
// Chọn đợt
// =================================================================================================

it('reminds about a partially paid instalment that is past its due date, with the remaining amount', function () {
    [, $instalment] = overdueInstalment(['lead_lawyer_id' => $this->lead->id], 3, 10_000_000);
    Payment::factory()->create(['instalment_id' => $instalment->id, 'amount' => 4_000_000]);

    runOverdueReminders();

    $email = overdueEmailTo($this->accountant);
    expect(overdueRecipients())->toHaveCount(2)
        ->and($email->getHtmlBody())->toContain(Money::format(6_000_000))
        ->and($email->getHtmlBody())->not->toContain(Money::format(10_000_000));
});

it('still reminds about a matter that has been closed — a debt does not vanish when the file is closed', function () {
    overdueInstalment(['lead_lawyer_id' => $this->lead->id, 'closed_at' => now()->subDay()]);

    runOverdueReminders();

    expect(overdueRecipients())->toBe(overdueEmails($this->lead, $this->accountant));
});

it('does not remind about a soft-deleted matter', function () {
    // Không được là "sập rồi bị nuốt": `report()` của tác vụ sẽ che một lỗi null, nên khẳng định thêm
    // rằng không có ngoại lệ nào được báo — vụ xoá mềm phải bị LỌC RA, không phải làm tác vụ vấp.
    Exceptions::fake();
    [$matter] = overdueInstalment(['lead_lawyer_id' => $this->lead->id]);
    // Bỏ qua hook `Matter::deleting` (chặn xoá khi còn công nợ, M9 Task 5): đây là dữ liệu đã lỡ có.
    DB::table('matters')->where('id', $matter->id)->update(['deleted_at' => now()]);

    runOverdueReminders();

    expect(overdueLedger())->toBeEmpty();
    Exceptions::assertNothingReported();
});

it('does not remind about an instalment of a cancelled contract', function () {
    [, , $contract] = overdueInstalment(['lead_lawyer_id' => $this->lead->id]);
    DB::table('contracts')->where('id', $contract->id)->update(['status' => ContractStatus::Cancelled->value]);

    runOverdueReminders();

    expect(overdueLedger())->toBeEmpty();
});

it('does not remind about an instalment of a completed contract', function () {
    [, , $contract] = overdueInstalment(['lead_lawyer_id' => $this->lead->id]);
    DB::table('contracts')->where('id', $contract->id)->update(['status' => ContractStatus::Completed->value]);

    runOverdueReminders();

    expect(overdueLedger())->toBeEmpty();
});

it('does not remind about an instalment of a draft contract', function () {
    [, , $contract] = overdueInstalment(['lead_lawyer_id' => $this->lead->id]);
    DB::table('contracts')->where('id', $contract->id)->update(['status' => ContractStatus::Draft->value]);

    runOverdueReminders();

    expect(overdueLedger())->toBeEmpty();
});

it('does not remind about a paid, a waived or a cancelled instalment', function () {
    overdueInstalment(['lead_lawyer_id' => $this->lead->id], 3, 10_000_000, ['status' => 'paid']);
    overdueInstalment(['lead_lawyer_id' => $this->lead->id], 3, 10_000_000, ['status' => 'waived', 'waived_reason' => str_repeat('Miễn theo thoả thuận. ', 3), 'waived_at' => now()]);
    overdueInstalment(['lead_lawyer_id' => $this->lead->id], 3, 10_000_000, ['status' => 'cancelled']);

    runOverdueReminders();

    expect(overdueLedger())->toBeEmpty();
});

it('does not remind about an instalment due today, nor one with no due date yet', function () {
    overdueInstalment(['lead_lawyer_id' => $this->lead->id], 0);
    overdueInstalment(['lead_lawyer_id' => $this->lead->id], 3, 10_000_000, ['due_date' => null]);

    runOverdueReminders();

    expect(overdueLedger())->toBeEmpty();
});

it('reminds about each overdue instalment separately', function () {
    overdueTwoInstalments(['lead_lawyer_id' => $this->lead->id]);

    $result = runOverdueReminders();

    expect($result['reminded'])->toBe(2)
        ->and(overdueLedger('sent'))->toHaveCount(4);
});

// =================================================================================================
// Nhịp: ngày đầu tiên quá hạn, rồi 7 ngày một lần; không trùng
// =================================================================================================

it('does not send a second mail when the task runs twice in a row', function () {
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);

    runOverdueReminders();
    $afterFirst = overdueLedger()->count();
    runOverdueReminders();

    expect($afterFirst)->toBe(2)
        ->and(overdueLedger())->toHaveCount(2);
});

/**
 * Đợt đến hạn D; ngày 1 quá hạn = D+1. Ngày 1 gửi, ngày 7 (sáu ngày sau) KHÔNG, ngày 8 (đủ 7
 * ngày) gửi, ngày 14 KHÔNG, ngày 15 gửi. Mỗi mốc so theo NGÀY, không theo giờ.
 */
it('reminds on day 1 of being overdue, then only every seventh day', function () {
    [, $instalment] = overdueInstalment(['lead_lawyer_id' => $this->lead->id], 1);
    $dueDate = Carbon::parse($instalment->due_date);
    $counts = [];

    foreach ([1, 7, 8, 14, 15] as $day) {
        $this->travelTo($dueDate->copy()->addDays($day)->setTime(8, 0));
        runOverdueReminders();
        $counts[$day] = overdueLedger('sent')->count();
    }

    expect($counts)->toBe([1 => 2, 7 => 2, 8 => 4, 14 => 4, 15 => 6]);
});

it('counts days, not hours: a mail sent late on day one still blocks the run just after midnight on day seven', function () {
    [, $instalment] = overdueInstalment(['lead_lawyer_id' => $this->lead->id], 1);
    $dueDate = Carbon::parse($instalment->due_date);

    $this->travelTo($dueDate->copy()->addDay()->setTime(23, 30));
    runOverdueReminders();
    $this->travelTo($dueDate->copy()->addDays(7)->setTime(0, 5));
    runOverdueReminders();

    // 23:30 ngày 1 → 00:05 ngày 7: mới sáu ngày lịch, dù chỉ cách nhau vài phút.
    expect(overdueLedger('sent'))->toHaveCount(2);
});

it('counts days, not hours: a mail sent early on day one no longer blocks the run late on day eight', function () {
    [, $instalment] = overdueInstalment(['lead_lawyer_id' => $this->lead->id], 1);
    $dueDate = Carbon::parse($instalment->due_date);

    $this->travelTo($dueDate->copy()->addDay()->setTime(23, 30));
    runOverdueReminders();
    $this->travelTo($dueDate->copy()->addDays(8)->setTime(0, 5));
    runOverdueReminders();

    // 23:30 ngày 1 → 00:05 ngày 8: bảy ngày lịch (dù chưa đủ 168 giờ) — đủ nhịp.
    expect(overdueLedger('sent'))->toHaveCount(4);
});

it('stops reminding once the instalment is paid in full between two runs', function () {
    [, $instalment] = overdueInstalment(['lead_lawyer_id' => $this->lead->id], 1);
    $dueDate = Carbon::parse($instalment->due_date);

    $this->travelTo($dueDate->copy()->addDay()->setTime(8, 0));
    runOverdueReminders();
    Payment::factory()->create(['instalment_id' => $instalment->id, 'amount' => 10_000_000]);
    $this->travelTo($dueDate->copy()->addDays(8)->setTime(8, 0));
    runOverdueReminders();

    expect(overdueLedger('sent'))->toHaveCount(2);
});

it('stops reminding once the instalment is waived between two runs', function () {
    [, $instalment] = overdueInstalment(['lead_lawyer_id' => $this->lead->id], 1);
    $dueDate = Carbon::parse($instalment->due_date);

    $this->travelTo($dueDate->copy()->addDay()->setTime(8, 0));
    runOverdueReminders();
    DB::table('instalments')->where('id', $instalment->id)->update(['status' => 'waived']);
    $this->travelTo($dueDate->copy()->addDays(8)->setTime(8, 0));
    runOverdueReminders();

    expect(overdueLedger('sent'))->toHaveCount(2);
});

it('sends the day-one reminder for a new due date even within seven days of one for the old due date', function () {
    [, $instalment] = overdueInstalment(['lead_lawyer_id' => $this->lead->id], 5);
    runOverdueReminders();
    // Phụ lục dời hạn sang một ngày khác (vẫn đã qua) — đó là một "đợt quá hạn mới", không phải
    // cùng một lời nhắc: khoá chống trùng mang ngày đến hạn.
    DB::table('instalments')->where('id', $instalment->id)->update(['due_date' => today()->subDays(2)->toDateString()]);

    runOverdueReminders();

    expect(overdueLedger('sent'))->toHaveCount(4);
});

// =================================================================================================
// Hàng đợi, sau commit
// =================================================================================================

it('queues one job per overdue instalment carrying only the id and the due date', function () {
    Queue::fake();
    [, $instalment] = overdueInstalment(['lead_lawyer_id' => $this->lead->id]);

    runOverdueReminders();

    Queue::assertPushed(SendInstalmentOverdueMail::class, 1);
    Queue::assertPushed(SendInstalmentOverdueMail::class, fn (SendInstalmentOverdueMail $job): bool => $job->instalmentId === $instalment->id
        && $job->dueDate === $instalment->due_date->toDateString());
});

it('does not queue a job when every recipient already got the reminder within seven days', function () {
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);
    runOverdueReminders();

    Queue::fake();
    $result = runOverdueReminders();

    Queue::assertNothingPushed();
    expect($result['reminded'])->toBe(0);
});

/**
 * `Queue::fake()` bỏ qua `afterCommit` (nó ghi mọi job ngay khi `push`), nên không thể đo "chưa đẩy
 * khi transaction còn mở" qua nó — thay vào đó ghim rằng job được đánh dấu `afterCommit`, và Laravel
 * thật giữ nó lại tới khi transaction ngoài commit (M6.5 R2). Lệnh `->afterCommit()` là thứ duy
 * nhất bảo đảm điều đó khi một nơi gọi bọc tác vụ trong transaction của nó.
 */
it('marks the queued job to be pushed only after the surrounding transaction commits', function () {
    Queue::fake();
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);

    runOverdueReminders();

    Queue::assertPushed(SendInstalmentOverdueMail::class, fn (SendInstalmentOverdueMail $job): bool => $job->afterCommit === true);
});

it('dispatches nothing when not even an admin is active to receive the reminder', function () {
    User::query()->update(['is_active' => false]);
    Queue::fake();
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);

    runOverdueReminders();

    Queue::assertNothingPushed();
});

// =================================================================================================
// Nội dung: chỉ các trường của AccountantBillingRow + số ngày quá hạn; không tiêu đề vụ việc
// =================================================================================================

it('carries the matter code, matter type, client, instalment, amount left, due date and days overdue', function () {
    $type = MatterType::factory()->withStages()->create(['name' => 'Tranh chấp thương mại XKQ']);
    $client = Client::factory()->create(['name' => 'Công ty Khách Hàng ZQX']);
    [$matter, $instalment] = overdueInstalment([
        'lead_lawyer_id' => $this->lead->id,
        'matter_type_id' => $type->id,
        'client_id' => $client->id,
    ], 3, 12_500_000);

    runOverdueReminders();

    $email = overdueEmailTo($this->accountant);
    $due = Carbon::parse($instalment->due_date)->format('d/m/Y');

    foreach ([$email->getHtmlBody(), $email->getTextBody(), $email->getSubject()] as $index => $content) {
        // Tiêu đề chỉ mang mã hồ sơ, tên đợt và số ngày; hai thân thư mang đủ các thông tin.
        expect($content)->toContain($matter->code)->toContain('Đợt hai khi nộp đơn')->toContain('3 ngày');

        if ($index < 2) {
            expect($content)->toContain('Tranh chấp thương mại XKQ')
                ->toContain('Công ty Khách Hàng ZQX')
                ->toContain(Money::format(12_500_000))
                ->toContain($due);
        }
    }
});

it('never puts the matter title, the internal summary or a party name into the mail or the ledger', function () {
    $secretTitle = 'TIEU DE TUYET MAT KHONG DUOC LO XXQ123';
    $secretSummary = 'TOM TAT NOI BO TUYET MAT KHONG DUOC LO YYQ456';
    overdueInstalment([
        'lead_lawyer_id' => $this->lead->id,
        'title' => $secretTitle,
        'summary_for_client' => $secretSummary,
    ]);

    runOverdueReminders();

    $emails = overdueSentEmails();
    expect($emails)->toHaveCount(2);

    foreach ($emails as $email) {
        expect($email->getSubject())->not->toContain($secretTitle)
            ->and($email->getHtmlBody())->not->toContain($secretTitle)->not->toContain($secretSummary)
            ->and($email->getTextBody())->not->toContain($secretTitle)->not->toContain($secretSummary);
    }

    foreach (overdueLedger() as $row) {
        expect(json_encode($row->payload))->not->toContain($secretTitle);
    }
});

it('writes the ledger row of the mail against the instalment, keyed by the due date', function () {
    [, $instalment] = overdueInstalment(['lead_lawyer_id' => $this->lead->id]);

    runOverdueReminders();

    $row = overdueLedger('sent')->first();
    expect($row->related_type)->toBe('instalment')
        ->and($row->related_id)->toBe($instalment->id)
        ->and($row->payload['tier'])->toBe('overdue@'.$instalment->due_date->toDateString())
        ->and($row->sent_at)->not->toBeNull();
});

// =================================================================================================
// Liên kết: trang Công nợ cho người vào được nó, tab tiền của vụ cho người còn lại
// =================================================================================================

it('links an accountant to the receivables page', function () {
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);

    runOverdueReminders();

    $email = overdueEmailTo($this->accountant);
    $url = Receivables::getUrl(panel: 'admin');
    expect($email->getHtmlBody())->toContain($url)
        ->and($email->getTextBody())->toContain($url);
});

it('links the lead lawyer, who cannot open the receivables page, to the billing tab of the matter', function () {
    [$matter] = overdueInstalment(['lead_lawyer_id' => $this->lead->id]);

    runOverdueReminders();

    $email = overdueEmailTo($this->lead);
    $tab = array_search(BillingRelationManager::class, MatterResource::getRelations(), true);
    $url = MatterResource::getUrl('view', ['record' => $matter, 'relation' => $tab], panel: 'admin');

    expect($tab)->not->toBeFalse()
        ->and($email->getHtmlBody())->toContain(htmlspecialchars($url, ENT_QUOTES))
        ->and($email->getTextBody())->toContain($url)
        ->and($email->getHtmlBody())->not->toContain(Receivables::getUrl(panel: 'admin'));
});

it('links an admin to the receivables page even on a restricted matter', function () {
    overdueInstalment(['lead_lawyer_id' => $this->lead->id, 'confidentiality' => 'restricted']);

    runOverdueReminders();

    expect(overdueEmailTo($this->admin)->getTextBody())->toContain(Receivables::getUrl(panel: 'admin'));
});

/**
 * Liên kết là URL TUYỆT ĐỐI dưới `APP_URL` + `/admin`, dựng bằng `getUrl(panel: 'admin')` — không
 * đi qua panel "hiện hành" (hàng đợi không có panel nào hiện hành) và không là đường dẫn tương đối
 * (một hộp thư không biết gốc của nó). Việc tên miền quản trị tách riêng (`ADMIN_DOMAIN`) chưa được
 * đo ở đây: routes của panel gắn tên miền lúc khởi động, không đổi được trong một test.
 */
it('builds an absolute link under the application URL, without a current panel', function () {
    Filament\Facades\Filament::setCurrentPanel(null);
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);

    runOverdueReminders();

    expect(overdueEmailTo($this->accountant)->getTextBody())
        ->toContain(rtrim((string) config('app.url'), '/').'/admin/');
});

// =================================================================================================
// Thư hỏng: dòng `failed`, không lỗi 500, lượt sau gửi lại; một người hỏng không chặn người khác
// =================================================================================================

/** Transport hỏng cho MỘT địa chỉ (có thể bật/tắt), thành công cho địa chỉ khác. */
class InstalmentSelectiveFailTransport implements TransportInterface
{
    public static ?string $failing = null;

    public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SymfonySentMessage
    {
        if ($message instanceof Email && self::$failing !== null) {
            foreach ($message->getTo() as $address) {
                if ($address->getAddress() === self::$failing) {
                    throw new TransportException('SMTP từ chối '.self::$failing);
                }
            }
        }

        return new SymfonySentMessage($message, new SymfonyEnvelope(new Address('gui@vidu.test'), [new Address('nhan@vidu.test')]));
    }

    public function __toString(): string
    {
        return 'instalment-selective-fail://';
    }
}

function useInstalmentSelectiveFailMailer(?string $failingAddress): void
{
    InstalmentSelectiveFailTransport::$failing = $failingAddress;
    config()->set('mail.mailers.instalment_selective_fail', ['transport' => 'instalment_selective_fail']);
    Mail::extend('instalment_selective_fail', fn () => new InstalmentSelectiveFailTransport);
    config(['mail.default' => 'instalment_selective_fail']);
}

it('leaves a failed ledger row, does not blow up, and sends again on the next run', function () {
    Exceptions::fake();
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);
    useInstalmentSelectiveFailMailer($this->accountant->email);

    $result = runOverdueReminders();

    $failed = overdueLedger(OutboundStatus::Failed->value);
    expect($failed)->toHaveCount(1)
        ->and($failed->first()->recipient)->toBe($this->accountant->email)
        ->and($result['reminded'])->toBe(1);

    // Ngày hôm sau máy chủ thư đã sống lại: chỉ dòng `sent` mới chặn, nên kế toán được gửi lại —
    // còn luật sư (đã `sent`) thì không thêm thư nào.
    useInstalmentSelectiveFailMailer(null);
    $this->travelTo(now()->addDay());
    runOverdueReminders();

    expect(overdueRecipients())->toBe(overdueEmails($this->lead, $this->accountant));
});

it('does not let one failing recipient stop the others from being mailed', function () {
    Exceptions::fake();
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);
    // Người ĐẦU danh sách (luật sư phụ trách) hỏng; kế toán đứng sau vẫn phải nhận.
    useInstalmentSelectiveFailMailer($this->lead->email);

    runOverdueReminders();

    expect(overdueRecipients())->toBe(overdueEmails($this->accountant));
});

it('carries on with the next instalment when the mail for one of them blows up', function () {
    Exceptions::fake();
    overdueTwoInstalments(['lead_lawyer_id' => $this->lead->id]);
    useInstalmentSelectiveFailMailer($this->accountant->email);

    runOverdueReminders();

    // Cả hai đợt đều chạy tới cùng: luật sư nhận cả hai, kế toán hỏng cả hai.
    expect(overdueLedger('sent')->where('recipient', $this->lead->email))->toHaveCount(2)
        ->and(overdueLedger('failed')->where('recipient', $this->accountant->email))->toHaveCount(2);
});

// =================================================================================================
// Liên kết dẫn tới đúng chỗ (Livewire/HTTP, không chỉ so chuỗi URL)
// =================================================================================================

it('opens the billing tab of the matter when the lead lawyer follows the link in the mail', function () {
    Filament\Facades\Filament::setCurrentPanel('admin');
    [$matter] = overdueInstalment(['lead_lawyer_id' => $this->lead->id]);
    runOverdueReminders();

    preg_match('~https?://\S+~', overdueEmailTo($this->lead)->getTextBody(), $found);
    parse_str((string) parse_url($found[0], PHP_URL_QUERY), $query);
    $tab = array_search(BillingRelationManager::class, MatterResource::getRelations(), true);

    $this->actingAs($this->lead, 'web');
    Livewire\Livewire::withQueryParams($query)
        ->test(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertSet('activeRelationManager', (string) $tab)
        ->assertSee(__('billing.tab.title'));
});

it('lets the accountant open the page the mail links to', function () {
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);
    runOverdueReminders();

    preg_match('~https?://\S+~', overdueEmailTo($this->accountant)->getTextBody(), $found);

    $this->actingAs($this->accountant, 'web')->get($found[0])->assertOk();
});

it('lets the lead lawyer open the page the mail links to', function () {
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);
    runOverdueReminders();

    preg_match('~https?://\S+~', overdueEmailTo($this->lead)->getTextBody(), $found);

    $this->actingAs($this->lead, 'web')->get($found[0])->assertOk();
});

// =================================================================================================
// Nhật ký thư: dòng của thư này chỉ admin thấy (SPEC §9 đính chính 2026-09-30)
// =================================================================================================

it('shows the ledger rows of these mails to an admin only, not to a lawyer or a manager who can view matters', function () {
    overdueInstalment(['lead_lawyer_id' => $this->lead->id]);
    runOverdueReminders();

    $visibleTo = fn (User $user): int => OutboundMessage::query()->withoutGlobalScopes()->visibleTo($user)
        ->where('template', 'staff.instalment_overdue')->count();

    expect($visibleTo($this->admin))->toBe(2)
        ->and($visibleTo($this->lead))->toBe(0)
        ->and($visibleTo($this->manager))->toBe(0);
});
