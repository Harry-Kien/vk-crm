<?php

use App\Actions\Billing\RescheduleInstalment;
use App\Actions\Notification\ResendTargets;
use App\Actions\Schedule\RemindClientsOfUpcomingInstalments;
use App\Enums\InstalmentTrigger;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Jobs\SendPaymentDueSoonMail;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\OutboundMessage;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Support\Billing\ClientPaymentSettings;
use App\Support\Billing\VietQr;
use chillerlan\QRCode\QRCode;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Mime\Email;

/*
|--------------------------------------------------------------------------
| Làn nt Task 2 — thư báo trước hạn `client.payment_reminder.due_soon`
|--------------------------------------------------------------------------
|
| Chỉ khi công tắc "Nhắc lịch thanh toán cho khách" BẬT. Mỗi sáng (08:15 giờ Việt Nam) lịch
| `client-payments.due-soon` tìm các đợt còn phải trả của hợp đồng đang hiệu lực có ngày hạn từ HÔM NAY
| tới hôm nay + N ngày (N = "Báo trước hạn (số ngày)", mặc định 3), và gửi MỘT thư cho mỗi tài khoản
| cổng đang hoạt động, đã kích hoạt của khách (R12) mà cổng còn cho xem vụ. Không gửi lại cho cùng đợt,
| cùng ngày hạn, cùng người — chạy lại hay đổi N đều không thành thư thứ hai.
|
| Lịch không phải một màn hình: test gọi đúng Action mà mục lịch gọi. Transport thư là `array` THẬT
| (không `Mail::fake()`), để sổ thư (`outbound_messages`) được ghi như trên máy chủ.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-20 08:15:00'));
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();

    $this->client = Client::factory()->create(['name' => 'Khách hàng Báo Trước Hạn']);
    $this->wife = ClientUser::factory()->activated()->create(['client_id' => $this->client->id, 'email' => 'vo@example.test']);
    $this->husband = ClientUser::factory()->activated()->create(['client_id' => $this->client->id, 'email' => 'chong@example.test']);
    // Không nhận: chưa kích hoạt, đã khoá, và tài khoản của khách khác.
    ClientUser::factory()->create(['client_id' => $this->client->id, 'email' => 'chuakichhoat@example.test', 'activated_at' => null]);
    ClientUser::factory()->activated()->create(['client_id' => $this->client->id, 'email' => 'dakhoa@example.test', 'is_active' => false]);
    ClientUser::factory()->activated()->create(['client_id' => Client::factory()->create()->id, 'email' => 'khackhac@example.test']);

    $this->matter = Matter::factory()->for($this->client)->create([
        'code' => 'VK-2026-DS-0042',
        'title' => 'Tiêu đề vụ TIEUDE-SAPHAN',
        'description_internal' => 'Ghi chú nội bộ GHICHU-SAPHAN',
        'lead_lawyer_id' => $this->lead->id,
        'is_published_to_portal' => true,
    ]);

    $this->contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);
    $this->instalment = cdrInstalment($this->contract, 2, '2026-10-22');
});

function cdrInstalment(Contract $contract, int $sequence, ?string $dueDate, array $overrides = []): Instalment
{
    return Instalment::factory()->for($contract)->create([
        'sequence' => $sequence,
        'name' => 'Đợt '.$sequence.' TENDOT-SAPHAN',
        'amount' => 10_000_000,
        'trigger_type' => InstalmentTrigger::DueDate,
        'due_date' => $dueDate,
        ...$overrides,
    ]);
}

/** Công tắc theo `$on`, thông tin chuyển khoản rõ là giả, N = `$days`. */
function cdrSettings(bool $on, int $days = 3, bool $withBank = true): void
{
    $values = [
        ClientPaymentSettings::ENABLED => $on ? ClientPaymentSettings::ON : ClientPaymentSettings::OFF,
        ClientPaymentSettings::DAYS_BEFORE_DUE => (string) $days,
    ];

    if ($withBank) {
        $values += [
            ClientPaymentSettings::BANK_BIN => '970436',
            ClientPaymentSettings::ACCOUNT_NUMBER => '0071000123456',
            ClientPaymentSettings::ACCOUNT_HOLDER => 'CONG TY LUAT THU NGHIEM',
            ClientPaymentSettings::BANK_BRANCH => 'Chi nhánh Thử nghiệm',
        ];
    }

    foreach ($values as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}

/** Đúng việc mục lịch làm lúc 08:15. */
function cdrRunSchedule(): array
{
    return app(RemindClientsOfUpcomingInstalments::class)->handle();
}

/** @return list<Email> */
function cdrSentMails(): array
{
    return Mail::mailer()->getSymfonyTransport()->innerTransport()->messages()
        ->map(fn ($sent) => $sent->getOriginalMessage())
        ->all();
}

/** @return list<string> */
function cdrRecipients(): array
{
    $to = [];

    foreach (cdrSentMails() as $mail) {
        foreach ($mail->getTo() as $address) {
            $to[] = $address->getAddress();
        }
    }

    sort($to);

    return $to;
}

function cdrBody(Email $mail): string
{
    return $mail->getHtmlBody()."\n".$mail->getTextBody();
}

function cdrEvent(): ?CallbackEvent
{
    return collect(Schedule::events())->first(fn ($event) => $event->description === 'client-payments.due-soon');
}

// =========================================================================================
// Mục lịch
// =========================================================================================

it('registers the due-soon reminder under a stable machine name with an overlap lock of an hour at most', function () {
    $event = cdrEvent();

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});

it('runs the RemindClientsOfUpcomingInstalments action, not something else', function () {
    $callback = (new ReflectionProperty(CallbackEvent::class, 'callback'))->getValue(cdrEvent());

    expect($callback)->toBe(RemindClientsOfUpcomingInstalments::class.'@handle');
});

it('is due at 08:15 Vietnam time every day, and at no other minute', function () {
    $event = cdrEvent();
    $day = today()->startOfDay();

    expect(config('app.timezone'))->toBe('Asia/Ho_Chi_Minh');

    $this->travelTo($day->copy()->setTime(8, 15));
    expect($event->isDue(app()))->toBeTrue('phải tới hạn lúc 08:15');

    foreach (['00:00', '01:15', '08:00', '08:14', '08:16', '08:30', '20:15'] as $notDue) {
        [$hour, $minute] = explode(':', $notDue);
        $this->travelTo($day->copy()->setTime((int) $hour, (int) $minute));
        expect($event->isDue(app()))->toBeFalse("không được tới hạn lúc {$notDue}");
    }

    $this->travelTo($day->copy()->addDays(9)->setTime(8, 15));
    expect($event->isDue(app()))->toBeTrue('ngày khác cũng phải tới hạn lúc 08:15');
});

/** `docs/QUY-TRINH.md` hướng dẫn chạy ngay lượt báo trước hạn trên bản xem thử bằng đúng lệnh này. */
it('runs right away with the command the operating guide gives for the preview', function () {
    cdrSettings(true);

    $this->artisan('schedule:test', ['--name' => 'client-payments.due-soon'])->assertSuccessful();

    expect(cdrRecipients())->toBe(['chong@example.test', 'vo@example.test']);
});

// =========================================================================================
// Công tắc
// =========================================================================================

/** Mutation probe: bỏ câu hỏi công tắc ở đầu Action → đỏ. */
it('queues nothing and mails nobody while the switch is OFF', function () {
    cdrSettings(false);
    Queue::fake();

    expect(cdrRunSchedule())->toBe(['queued' => 0, 'skipped' => 0]);

    Queue::assertNothingPushed();
});

it('mails nobody while nothing has ever been configured', function () {
    cdrRunSchedule();

    expect(cdrSentMails())->toBe([]);
});

/** Mutation probe: bỏ lần hỏi lại công tắc trong job → đỏ. */
it('sends nothing if the switch was turned OFF between queueing and sending', function () {
    cdrSettings(true);
    Queue::fake();

    cdrRunSchedule();
    $job = Queue::pushed(SendPaymentDueSoonMail::class)->sole();

    cdrSettings(false);
    $job->handle();

    expect(cdrSentMails())->toBe([]);
});

it('queues the job to run only after a surrounding transaction commits', function () {
    cdrSettings(true);
    Queue::fake();

    cdrRunSchedule();

    Queue::assertPushed(SendPaymentDueSoonMail::class, fn (SendPaymentDueSoonMail $job): bool => $job->afterCommit === true
        && $job->instalmentId === $this->instalment->id
        && $job->dueDate === '2026-10-22');
});

// =========================================================================================
// Người nhận
// =========================================================================================

/** Mutation probe: thay R12 bằng mọi tài khoản của khách → đỏ. */
it('mails exactly the active, activated accounts of the client, one mail each', function () {
    cdrSettings(true);

    expect(cdrRunSchedule())->toBe(['queued' => 1, 'skipped' => 0])
        ->and(cdrRecipients())->toBe(['chong@example.test', 'vo@example.test']);
});

it('reads the recipients at send time: an account locked after queueing receives nothing', function () {
    cdrSettings(true);
    Queue::fake();

    cdrRunSchedule();
    $job = Queue::pushed(SendPaymentDueSoonMail::class)->sole();

    $this->husband->update(['is_active' => false]);
    $job->handle();

    expect(cdrRecipients())->toBe(['vo@example.test']);
});

/** Mutation probe: bỏ `onPortal()` → đỏ (mã hồ sơ tới người cổng không cho xem vụ). */
it('mails nobody when the portal does not show the matter to the client', function (string $case) {
    cdrSettings(true);

    match ($case) {
        'unpublished' => $this->matter->update(['is_published_to_portal' => false]),
        'restricted, unpublished' => $this->matter->update(['is_published_to_portal' => false, 'confidentiality' => 'restricted']),
        'lookup expired' => MatterArchive::factory()->create(['matter_id' => $this->matter->id, 'client_access_until' => '2026-10-19']),
    };

    expect(Gate::forUser($this->wife)->allows('view', $this->matter->fresh()))->toBeFalse();

    cdrRunSchedule();

    expect(cdrSentMails())->toBe([]);
})->with([
    'unpublished' => ['unpublished'],
    'restricted, unpublished' => ['restricted, unpublished'],
    'lookup expired' => ['lookup expired'],
]);

// =========================================================================================
// Đợt nào được báo
// =========================================================================================

/** Mutation probes: đổi biên dưới thành ngày mai, hoặc biên trên thành hôm nay + N − 1 → đỏ. */
it('mails about an instalment due from today up to N days ahead, and not a day later', function (string $dueDate, bool $mailed) {
    cdrSettings(true);
    $this->instalment->update(['due_date' => $dueDate]);

    cdrRunSchedule();

    expect(cdrSentMails() !== [])->toBe($mailed);
})->with([
    'due today' => ['2026-10-20', true],
    'due tomorrow' => ['2026-10-21', true],
    'due in exactly N days' => ['2026-10-23', true],
    'due in N + 1 days' => ['2026-10-24', false],
    'overdue since yesterday (no automatic mail to the client)' => ['2026-10-19', false],
]);

it('follows the configured number of days', function () {
    cdrSettings(true, 7);
    $this->instalment->update(['due_date' => '2026-10-27']);

    cdrRunSchedule();

    expect(cdrRecipients())->toBe(['chong@example.test', 'vo@example.test']);
});

it('never mails about an instalment the client does not have to pay now', function (string $case) {
    cdrSettings(true);

    match ($case) {
        'stage instalment not yet reached' => $this->instalment->update(['trigger_type' => InstalmentTrigger::Stage, 'trigger_stage_key' => 'filed', 'due_date' => null]),
        'paid in full' => Payment::factory()->create(['instalment_id' => $this->instalment->id, 'amount' => 10_000_000]),
        // Ghi thô (không qua hook tổng lịch thu của M9): chỉ dựng trạng thái cần đo.
        'marked paid' => Instalment::query()->whereKey($this->instalment->id)->update(['status' => 'paid']),
        'waived' => Instalment::query()->whereKey($this->instalment->id)->update(['status' => 'waived', 'waived_reason' => 'Miễn theo thoả thuận riêng với khách hàng.', 'waived_at' => now()]),
        'cancelled instalment' => Instalment::query()->whereKey($this->instalment->id)->update(['status' => 'cancelled']),
        'cancelled contract' => Contract::query()->whereKey($this->contract->id)->update(['status' => 'cancelled']),
        'completed contract' => Contract::query()->whereKey($this->contract->id)->update(['status' => 'completed']),
        'draft contract' => Contract::query()->whereKey($this->contract->id)->update(['status' => 'draft']),
        'matter deleted' => Matter::query()->whereKey($this->matter->id)->update(['deleted_at' => now()]),
    };

    // Không lỗi nào bị nuốt: đợt bị loại ở TẬP ỨNG VIÊN, không phải rơi vào `catch` của từng đợt
    // (mutation probe: bỏ `whereHas('contract.matter')` → vụ đã xoá ra `null`, lỗi bị `report()` → đỏ).
    Exceptions::fake();

    expect(cdrRunSchedule())->toBe(['queued' => 0, 'skipped' => 0])
        ->and(cdrSentMails())->toBe([]);

    Exceptions::assertNothingReported();
})->with([
    'stage instalment not yet reached' => ['stage instalment not yet reached'],
    'paid in full' => ['paid in full'],
    'marked paid' => ['marked paid'],
    'waived' => ['waived'],
    'cancelled instalment' => ['cancelled instalment'],
    'cancelled contract' => ['cancelled contract'],
    'completed contract' => ['completed contract'],
    'draft contract' => ['draft contract'],
    'matter deleted' => ['matter deleted'],
]);

it('sends nothing for an instalment paid in full between queueing and sending', function () {
    cdrSettings(true);
    Queue::fake();

    cdrRunSchedule();
    $job = Queue::pushed(SendPaymentDueSoonMail::class)->sole();

    Payment::factory()->create(['instalment_id' => $this->instalment->id, 'amount' => 10_000_000]);
    $job->handle();

    expect(cdrSentMails())->toBe([]);
});

it('sends nothing when the due date moved between queueing and sending', function () {
    cdrSettings(true);
    Queue::fake();

    cdrRunSchedule();
    $job = Queue::pushed(SendPaymentDueSoonMail::class)->sole();

    $this->instalment->update(['due_date' => '2026-10-23']);
    $job->handle();

    expect(cdrSentMails())->toBe([]);
});

// =========================================================================================
// Một lần cho mỗi đợt, mỗi ngày hạn, mỗi người
// =========================================================================================

/** Mutation probe: bỏ `alreadyDelivered()` ở job → đỏ. */
it('does not mail the same instalment twice when the schedule runs again, the same day or the next', function () {
    cdrSettings(true);

    cdrRunSchedule();
    expect(cdrRunSchedule())->toBe(['queued' => 0, 'skipped' => 1]);

    $this->travelTo(Carbon::parse('2026-10-21 08:15:00'));
    cdrRunSchedule();

    expect(cdrRecipients())->toBe(['chong@example.test', 'vo@example.test']);
});

it('does not mail again when the job itself runs a second time', function () {
    cdrSettings(true);

    cdrRunSchedule();
    (new SendPaymentDueSoonMail($this->instalment->id, '2026-10-22'))->handle();

    expect(cdrSentMails())->toHaveCount(2);
});

it('does not mail again when the office changes the number of days', function () {
    cdrSettings(true, 3);
    cdrRunSchedule();

    cdrSettings(true, 10);
    $this->travelTo(Carbon::parse('2026-10-21 08:15:00'));
    cdrRunSchedule();

    expect(cdrSentMails())->toHaveCount(2);
});

/**
 * Phán quyết của làn: khoá chống trùng mang NGÀY HẠN. Văn phòng "Dời hạn" (khách xin khất) sang một
 * ngày khác thì khách được báo trước hạn MỘT lần nữa cho ngày mới — thư cũ nói một ngày không còn đúng.
 */
it('mails once more for the new date after the office reschedules the instalment', function () {
    cdrSettings(true);
    cdrRunSchedule();

    app(RescheduleInstalment::class)->handle($this->lead, $this->instalment, '2026-10-23', 'Khách xin khất thêm một ngày vì chờ tiền về tài khoản.');
    cdrRunSchedule();

    expect(cdrSentMails())->toHaveCount(4)
        ->and(cdrSentMails()[3]->getTextBody())->toContain('23/10/2026');
});

it('logs one sent row per recipient in the outbound log, tied to the matter and keyed by instalment and due date', function () {
    cdrSettings(true);

    cdrRunSchedule();

    $rows = OutboundMessage::query()->withoutGlobalScopes()->where('template', 'client.payment_reminder.due_soon')->orderBy('recipient')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('recipient')->all())->toBe(['chong@example.test', 'vo@example.test'])
        ->and($rows->pluck('status')->unique()->all())->toBe([OutboundStatus::Sent])
        ->and($rows->pluck('related_type')->unique()->all())->toBe(['matter'])
        ->and($rows->pluck('related_id')->unique()->all())->toBe([$this->matter->id])
        ->and($rows->pluck('payload')->pluck('tier')->unique()->all())->toBe(['due-soon:'.$this->instalment->id.'@2026-10-22']);
});

it('keeps the reminders out of the resend button of the outbound log, with their own reason', function () {
    expect(ResendTargets::exclusionOf('client.payment_reminder.due_soon'))->toBe('client.payment_reminder.*')
        ->and(ResendTargets::exclusionOf('client.payment_reminder.overdue'))->toBe('client.payment_reminder.*')
        ->and(__('outbound.resend.refused.template_reasons')['client.payment_reminder.*'] ?? null)->not->toBeNull()
        ->and((array) __('outbound.templates'))->toHaveKeys(['client.payment_reminder.due_soon', 'client.payment_reminder.overdue']);
});

// =========================================================================================
// Nội dung thư
// =========================================================================================

it('says the matter code, the instalment, what is left to pay, the due date and how to transfer — nothing about the matter itself', function () {
    cdrSettings(true);
    Payment::factory()->create(['instalment_id' => $this->instalment->id, 'amount' => 4_000_000]);

    cdrRunSchedule();

    $mail = cdrSentMails()[0];
    $body = cdrBody($mail);

    expect($mail->getSubject())->toBe(__('client_payments.reminder_mail.due_soon.subject', ['code' => 'VK-2026-DS-0042']))
        ->and($body)->toContain('VK-2026-DS-0042')
        ->toContain('Đợt 2 TENDOT-SAPHAN')
        ->toContain('6.000.000')
        ->toContain('22/10/2026')
        ->toContain('Vietcombank')
        ->toContain('0071000123456')
        ->toContain('CONG TY LUAT THU NGHIEM')
        ->toContain('VK-2026-DS-0042 DOT 2')
        ->not->toContain('10.000.000')
        ->not->toContain('TIEUDE-SAPHAN')
        ->not->toContain('GHICHU-SAPHAN');
});

/** Mutation probe: bỏ ảnh QR khỏi view HTML → đỏ; đổi số tiền trong QR thành giá trị đợt → đỏ. */
it('embeds a QR image in the mail itself that decodes to exactly the amount left and the transfer note', function () {
    cdrSettings(true);
    Payment::factory()->create(['instalment_id' => $this->instalment->id, 'amount' => 4_000_000]);

    cdrRunSchedule();

    $mail = cdrSentMails()[0];
    $images = collect($mail->getAttachments())->filter(fn ($part) => $part->getMediaType() === 'image');

    expect($images)->toHaveCount(1)
        ->and($mail->getHtmlBody())->toContain('cid:')
        ->and((string) (new QRCode)->readFromBlob($images->first()->getBody()))
        ->toBe(VietQr::payload('970436', '0071000123456', 6_000_000, 'VK-2026-DS-0042 DOT 2'));
});

/**
 * Trang cấu hình không cho bật khi thiếu thông tin chuyển khoản; thiếu chỉ xảy ra với dữ liệu sửa tay.
 * Khi đó thư vẫn báo đợt, số tiền, ngày hạn — không in nửa vời phần chuyển khoản, không ảnh QR.
 * Mutation probe: bỏ kiểm `account === null` ở mailable → đỏ (lỗi khi dựng QR).
 */
it('leaves the transfer details and the QR image out when the bank account is incomplete', function () {
    cdrSettings(true, withBank: false);

    cdrRunSchedule();

    $mail = cdrSentMails()[0];

    expect($mail->getAttachments())->toBe([])
        ->and(cdrBody($mail))->toContain('10.000.000')
        ->toContain('22/10/2026')
        ->not->toContain(e(__('client_payments.reminder_mail.transfer_heading')));
});

/** Mutation probe: `$restricted = false` ở mailable → đỏ. */
it('only names the matter code and invites the client to sign in for a restricted matter', function () {
    cdrSettings(true);
    $this->matter->update(['confidentiality' => 'restricted']);

    cdrRunSchedule();

    $mail = cdrSentMails()[0];
    $body = cdrBody($mail);

    expect($mail->getSubject())->toContain('VK-2026-DS-0042')
        ->and($body)->toContain(e(__('client_payments.reminder_mail.due_soon.restricted_line', ['code' => 'VK-2026-DS-0042'])))
        ->not->toContain('10.000.000')
        ->not->toContain('22/10/2026')
        ->not->toContain('TENDOT-SAPHAN')
        ->not->toContain('0071000123456')
        ->and($mail->getAttachments())->toBe([]);
});
