<?php

use App\Actions\Notification\ResendTargets;
use App\Enums\InstalmentTrigger;
use App\Enums\OutboundStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Filament\Admin\Pages\Receivables;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Jobs\SendPaymentReceivedMail;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Support\Billing\ClientPaymentSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Mime\Email;

/*
|--------------------------------------------------------------------------
| Làn nt Task 1 — thư `client.payment_received`: văn phòng ghi một khoản thu ⇒ khách nhận xác nhận
|--------------------------------------------------------------------------
|
| Chỉ khi công tắc "Nhắc lịch thanh toán cho khách" BẬT. Người nhận: tài khoản cổng đang hoạt động, đã
| kích hoạt, của đúng khách hàng (R12, `ResolveClientRecipients`). Thư qua hàng đợi SAU commit (R2),
| nội dung: mã hồ sơ, số tiền, ngày nhận, còn lại bao nhiêu — không tên đợt, không tiêu đề vụ. Vụ hạn
| chế: chỉ mã hồ sơ và lời mời đăng nhập cổng. Huỷ khoản thu sau đó không gửi thư "huỷ" nào.
|
| Mọi lần ghi khoản thu đi qua MÀN HÌNH (tab "Hợp đồng và thanh toán", trang "Công nợ") bằng
| Livewire; transport thư là `array` THẬT (không `Mail::fake()`), để nhật ký thư (`outbound_messages`)
| được ghi đúng như trên máy chủ.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();

    $this->client = Client::factory()->create(['name' => 'Khách hàng Thư Xác Nhận']);
    $this->wife = ClientUser::factory()->activated()->create(['client_id' => $this->client->id, 'email' => 'vo@example.test']);
    $this->husband = ClientUser::factory()->activated()->create(['client_id' => $this->client->id, 'email' => 'chong@example.test']);
    // Không nhận: chưa kích hoạt (chưa tự đổi mật khẩu lần đầu), đã bị khoá, và tài khoản của khách khác.
    ClientUser::factory()->create(['client_id' => $this->client->id, 'email' => 'chuakichhoat@example.test', 'activated_at' => null]);
    ClientUser::factory()->activated()->create(['client_id' => $this->client->id, 'email' => 'dakhoa@example.test', 'is_active' => false]);
    ClientUser::factory()->activated()->create(['client_id' => Client::factory()->create()->id, 'email' => 'khackhac@example.test']);

    $this->matter = Matter::factory()->for($this->client)->create([
        'code' => 'VK-2026-DS-0042',
        'title' => 'Tiêu đề vụ TIEUDE-XNHAN',
        'lead_lawyer_id' => $this->lead->id,
        'is_published_to_portal' => true,
    ]);

    [$this->contract, $this->instalment] = prmContract($this->matter);
});

/** @return array{0: Contract, 1: Instalment} hợp đồng đang hiệu lực 10 triệu, một đợt quá hạn. */
function prmContract(Matter $matter): array
{
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($contract)->create([
        'sequence' => 1,
        'name' => 'Tên đợt TENDOT-XNHAN',
        'amount' => 10_000_000,
        'trigger_type' => InstalmentTrigger::DueDate,
        'due_date' => '2026-10-15',
    ]);

    return [$contract, $instalment];
}

function prmSwitch(bool $on): void
{
    Setting::query()->updateOrCreate(['key' => ClientPaymentSettings::ENABLED], ['value' => $on ? ClientPaymentSettings::ON : ClientPaymentSettings::OFF]);
}

/** Kế toán bấm "Ghi khoản thu" trên tab của vụ việc. */
function prmRecordThroughTab(Matter $matter, Instalment $instalment, string $amount, ?User $actor = null): void
{
    test()->actingAs($actor ?? test()->accountant, 'web');

    test()->livewire(BillingRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->callAction(TestAction::make('recordPayment')->table($instalment), data: [
            'amount' => $amount,
            'paid_on' => '2026-10-19',
            'method' => PaymentMethod::BankTransfer->value,
            'reference' => 'UNC-NOIBO-XNHAN',
            'note' => 'Ghi chú nội bộ GHICHU-XNHAN',
        ])
        ->assertHasNoActionErrors();
}

/** @return list<Email> thư THẬT đã đi qua transport `array` */
function prmSentMails(): array
{
    return Mail::mailer()->getSymfonyTransport()->innerTransport()->messages()
        ->map(fn ($sent) => $sent->getOriginalMessage())
        ->all();
}

/** @return list<string> */
function prmRecipients(): array
{
    $to = [];

    foreach (prmSentMails() as $mail) {
        foreach ($mail->getTo() as $address) {
            $to[] = $address->getAddress();
        }
    }

    sort($to);

    return $to;
}

function prmBody(Email $mail): string
{
    return $mail->getHtmlBody()."\n".$mail->getTextBody();
}

// =========================================================================================
// Công tắc
// =========================================================================================

/** Mutation probe: bỏ lần hỏi công tắc trong `RecordPayment` → đỏ (job được xếp). */
it('sends nothing and queues nothing for the client while the switch is OFF', function () {
    Queue::fake();

    prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');

    expect(Payment::query()->count())->toBe(1);
    Queue::assertNotPushed(SendPaymentReceivedMail::class);
});

it('sends no client mail at all on the real queue while nothing has ever been configured', function () {
    prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');

    expect(Payment::query()->count())->toBe(1)
        ->and(prmSentMails())->toBe([])
        ->and(OutboundMessage::query()->withoutGlobalScopes()->where('template', 'client.payment_received')->count())->toBe(0);
});

// =========================================================================================
// Bật: ai nhận, nội dung, nhật ký thư
// =========================================================================================

/** Mutation probe: thay `ResolveClientRecipients` bằng mọi tài khoản của khách → đỏ. */
it('mails exactly the active, activated accounts of the client when the switch is ON', function () {
    prmSwitch(true);

    prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');

    expect(prmRecipients())->toBe(['chong@example.test', 'vo@example.test']);
});

it('says the matter code, the amount, the date received and what is left — nothing about the matter itself', function () {
    prmSwitch(true);

    prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');

    $mail = prmSentMails()[0];
    $body = prmBody($mail);

    expect($mail->getSubject())->toBe(__('client_payments.mail.subject', ['code' => 'VK-2026-DS-0042']))
        ->and($body)->toContain(e(__('client_payments.mail.amount', ['amount' => '4.000.000 ₫'])))
        ->toContain(e(__('client_payments.mail.paid_on', ['date' => '19/10/2026'])))
        ->toContain(e(__('client_payments.mail.outstanding', ['amount' => '6.000.000 ₫'])))
        ->not->toContain('TENDOT-XNHAN')
        ->not->toContain('TIEUDE-XNHAN')
        ->not->toContain('UNC-NOIBO-XNHAN')
        ->not->toContain('GHICHU-XNHAN');
});

it('says the contract is settled when the payment clears the last amount', function () {
    prmSwitch(true);

    prmRecordThroughTab($this->matter, $this->instalment, '10.000.000');

    expect(prmBody(prmSentMails()[0]))->toContain(e(__('client_payments.mail.settled')))
        ->not->toContain(e(__('client_payments.mail.outstanding', ['amount' => '0 ₫'])));
});

it('logs one sent row per recipient in the outbound log, tied to the matter and to that payment', function () {
    prmSwitch(true);

    prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');

    $payment = Payment::query()->sole();
    $rows = OutboundMessage::query()->withoutGlobalScopes()->where('template', 'client.payment_received')->orderBy('recipient')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('recipient')->all())->toBe(['chong@example.test', 'vo@example.test'])
        ->and($rows->pluck('status')->unique()->all())->toBe([OutboundStatus::Sent])
        ->and($rows->pluck('related_type')->unique()->all())->toBe(['matter'])
        ->and($rows->pluck('related_id')->unique()->all())->toBe([$this->matter->id])
        ->and($rows->pluck('payload')->pluck('tier')->unique()->all())->toBe(['payment:'.$payment->id])
        ->and((array) __('outbound.templates'))->toHaveKey('client.payment_received');
});

/** Mutation probe: bỏ nhánh vụ hạn chế ở mailable → đỏ (số tiền lọt vào thư). */
it('only names the matter code and invites the client to sign in for a restricted matter', function () {
    prmSwitch(true);

    $restricted = Matter::factory()->restricted()->for($this->client)->create([
        'code' => 'VK-2026-HC-0007',
        'lead_lawyer_id' => $this->lead->id,
        'is_published_to_portal' => true,
    ]);
    [, $instalment] = prmContract($restricted);

    prmRecordThroughTab($restricted, $instalment, '4.000.000', $this->lead);

    $mail = prmSentMails()[0];
    $body = prmBody($mail);

    expect($mail->getSubject())->toContain('VK-2026-HC-0007')
        ->and($body)->toContain(e(__('client_payments.mail.restricted_line', ['code' => 'VK-2026-HC-0007'])))
        ->not->toContain('4.000.000')
        ->not->toContain('6.000.000')
        ->not->toContain('19/10/2026')
        ->not->toContain('TENDOT-XNHAN');
});

it('also mails the client when the payment is recorded on the receivables page', function () {
    prmSwitch(true);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->callAction(TestAction::make('recordPayment')->table($this->instalment), data: [
            'amount' => '1.250.000',
            'paid_on' => '2026-10-19',
            'method' => PaymentMethod::Cash->value,
        ])
        ->assertHasNoActionErrors();

    expect(prmRecipients())->toBe(['chong@example.test', 'vo@example.test'])
        ->and(prmBody(prmSentMails()[0]))->toContain('1.250.000');
});

// =========================================================================================
// Hàng đợi, sau commit
// =========================================================================================

it('queues the mail job to run only after the transaction commits', function () {
    prmSwitch(true);
    Queue::fake();

    prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');

    Queue::assertPushed(SendPaymentReceivedMail::class, fn (SendPaymentReceivedMail $job): bool => $job->afterCommit === true
        && $job->paymentId === Payment::query()->sole()->id);
});

/** Mutation probe: bỏ `->afterCommit()` khi xếp job → đỏ (thư đi trong lúc transaction ngoài còn mở). */
it('sends nothing while a surrounding transaction is still open, and sends once it commits', function () {
    prmSwitch(true);

    DB::transaction(function () {
        prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');

        expect(prmSentMails())->toBe([]);
    });

    expect(prmRecipients())->toBe(['chong@example.test', 'vo@example.test']);
});

it('sends nothing when the surrounding transaction rolls back', function () {
    prmSwitch(true);

    try {
        DB::transaction(function () {
            prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(Payment::query()->count())->toBe(0)
        ->and(prmSentMails())->toBe([]);
});

// =========================================================================================
// Lúc job chạy: tính lại mọi thứ
// =========================================================================================

/** Mutation probe: bỏ lần hỏi lại công tắc trong job → đỏ. */
it('sends nothing if the switch was turned OFF between queueing and sending', function () {
    prmSwitch(true);
    Queue::fake();

    prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');

    $job = Queue::pushed(SendPaymentReceivedMail::class)->sole();

    prmSwitch(false);
    $job->handle();

    expect(prmSentMails())->toBe([]);
});

/**
 * Phán quyết: huỷ một khoản thu KHÔNG gửi thư "huỷ" nào cho khách; khoản thu đã huỷ trước khi job chạy
 * thì không gửi xác nhận. Mutation probe: bỏ điều kiện `voided_at` trong job → đỏ.
 */
it('sends no confirmation for a payment voided before the job ran, and no mail at all about the void', function () {
    prmSwitch(true);
    Queue::fake();

    prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');
    $job = Queue::pushed(SendPaymentReceivedMail::class)->sole();

    $this->livewire(BillingRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->callAction(TestAction::make('voidPayment')->table($this->instalment), data: [
            'reason' => 'Ghi nhầm số tiền của khoản thu này, ghi lại.',
        ])
        ->assertHasNoActionErrors();

    expect(Payment::query()->sole()->voided_at)->not->toBeNull();
    Queue::assertPushed(SendPaymentReceivedMail::class, 1);

    $job->handle();

    expect(prmSentMails())->toBe([]);
});

it('sends no mail when a payment that was already confirmed is voided later', function () {
    prmSwitch(true);

    prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');
    expect(prmSentMails())->toHaveCount(2);

    $this->livewire(BillingRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->callAction(TestAction::make('voidPayment')->table($this->instalment), data: [
            'reason' => 'Ghi nhầm số tiền của khoản thu này, ghi lại.',
        ])
        ->assertHasNoActionErrors();

    expect(prmSentMails())->toHaveCount(2);
});

/** Mutation probe: bỏ `alreadyDelivered()` → đỏ (bốn thư). */
it('does not mail the same payment twice to the same account when the job runs again', function () {
    prmSwitch(true);

    prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');

    (new SendPaymentReceivedMail(Payment::query()->sole()->id))->handle();

    expect(prmRecipients())->toBe(['chong@example.test', 'vo@example.test']);
});

it('reads the recipients at send time: an account locked after queueing receives nothing', function () {
    prmSwitch(true);
    Queue::fake();

    prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');
    $job = Queue::pushed(SendPaymentReceivedMail::class)->sole();

    $this->husband->update(['is_active' => false]);
    $job->handle();

    expect(prmRecipients())->toBe(['vo@example.test']);
});

/** Mutation probe: bỏ `whereNull('deleted_at')` khi job đọc lại vụ việc → đỏ (thư về một vụ đã xoá). */
it('sends nothing if the matter was deleted between queueing and sending', function () {
    prmSwitch(true);
    Queue::fake();

    prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');
    $job = Queue::pushed(SendPaymentReceivedMail::class)->sole();

    Matter::query()->whereKey($this->matter->id)->update(['deleted_at' => now()]);
    $job->handle();

    expect(prmSentMails())->toBe([]);
});

it('records the failure and rings the lead lawyer when the mail fails for good', function () {
    // Công tắc tắt lúc ghi (không thư nào đi), rồi dựng job của đúng khoản thu đó như hàng đợi sẽ dựng.
    prmRecordThroughTab($this->matter, $this->instalment, '4.000.000');
    $job = new SendPaymentReceivedMail(Payment::query()->sole()->id);

    $job->failed(new RuntimeException('smtp down'));

    $audit = Activity::query()->where('event', 'payment_received_mail_failed')->sole();

    expect($audit->subject_id)->toBe($this->matter->id)
        ->and($this->lead->fresh()->notifications)->toHaveCount(1)
        ->and(json_encode($this->lead->fresh()->notifications->first()->data, JSON_UNESCAPED_UNICODE))->toContain('VK-2026-DS-0042');
});

it('keeps the mail out of the resend button of the outbound log, with its own reason', function () {
    expect(ResendTargets::exclusionOf('client.payment_received'))->toBe('client.payment_received')
        ->and(__('outbound.resend.refused.template_reasons')['client.payment_received'] ?? null)->not->toBeNull();
});
