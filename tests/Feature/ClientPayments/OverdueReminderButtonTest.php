<?php

use App\Actions\Billing\RemindClientOfOverdueInstalment;
use App\Enums\CommunicationType;
use App\Enums\InstalmentTrigger;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Exceptions\ClientReminderRefused;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Jobs\SendPaymentOverdueReminderMail;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\CommunicationLog;
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
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Mime\Email;

/*
|--------------------------------------------------------------------------
| Làn nt Task 2 — nút "Gửi thư nhắc" trên dòng đợt QUÁ HẠN của tab "Hợp đồng và thanh toán"
|--------------------------------------------------------------------------
|
| Đợt quá hạn KHÔNG tự gửi thư cho khách (nhắc nội bộ 08:00 giữ nguyên). Khi công tắc "Nhắc lịch thanh
| toán cho khách" bật, người quản lý được hợp đồng của vụ (luật sư trong đội, quản lý, quản trị viên —
| `contract.manage` và thấy tiền của vụ; không kế toán) thấy nút trên dòng đợt quá hạn. Hộp thoại xem
| trước đúng nội dung thư (người nhận, số tiền, ngày hạn), bấm gửi thì thư qua hàng đợi sau commit. Một
| lần mỗi đợt mỗi ngày. Ghi vào sổ liên lạc của vụ và nhật ký hoạt động: ai gửi, lúc nào, gửi cho ai.
|
| Mọi thao tác đi qua MÀN HÌNH (Livewire). Transport thư là `array` THẬT.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Phụ Trách']);
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->client = Client::factory()->create(['name' => 'Khách hàng Nhắc Quá Hạn']);
    $this->wife = ClientUser::factory()->activated()->create(['client_id' => $this->client->id, 'email' => 'vo@example.test', 'name' => 'Chị Vợ']);
    $this->husband = ClientUser::factory()->activated()->create(['client_id' => $this->client->id, 'email' => 'chong@example.test', 'name' => 'Anh Chồng']);
    ClientUser::factory()->create(['client_id' => $this->client->id, 'email' => 'chuakichhoat@example.test', 'activated_at' => null]);
    ClientUser::factory()->activated()->create(['client_id' => $this->client->id, 'email' => 'dakhoa@example.test', 'is_active' => false]);

    $this->matter = Matter::factory()->for($this->client)->create([
        'code' => 'VK-2026-DS-0042',
        'title' => 'Tiêu đề vụ TIEUDE-QUAHAN',
        'lead_lawyer_id' => $this->lead->id,
        'is_published_to_portal' => true,
    ]);

    // Nháp → hai đợt → kích hoạt (ghi thô): tổng lịch thu của M9 cân ở mọi bước.
    $this->contract = Contract::factory()->for($this->matter)->create(['total_amount' => 20_000_000]);
    $this->overdue = corInstalment($this->contract, 1, '2026-10-15');
    $this->upcoming = corInstalment($this->contract, 2, '2026-11-15');
    Contract::query()->whereKey($this->contract->id)->update(['status' => 'active', 'signed_at' => '2026-10-01']);
    $this->contract->refresh();
    // Đã thu một phần: còn phải trả 6.000.000 ₫ — vẫn quá hạn.
    Payment::factory()->create(['instalment_id' => $this->overdue->id, 'amount' => 4_000_000]);
});

function corInstalment(Contract $contract, int $sequence, string $dueDate): Instalment
{
    return Instalment::factory()->for($contract)->create([
        'sequence' => $sequence,
        'name' => 'Đợt '.$sequence.' TENDOT-QUAHAN',
        'amount' => 10_000_000,
        'trigger_type' => InstalmentTrigger::DueDate,
        'due_date' => $dueDate,
    ]);
}

function corSwitch(bool $on): void
{
    foreach ([
        ClientPaymentSettings::ENABLED => $on ? ClientPaymentSettings::ON : ClientPaymentSettings::OFF,
        ClientPaymentSettings::BANK_BIN => '970436',
        ClientPaymentSettings::ACCOUNT_NUMBER => '0071000123456',
        ClientPaymentSettings::ACCOUNT_HOLDER => 'CONG TY LUAT THU NGHIEM',
    ] as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}

function corTab(Matter $matter, User $actor)
{
    test()->actingAs($actor, 'web');

    return test()->livewire(BillingRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
}

function corSend(Matter $matter, Instalment $instalment, User $actor)
{
    return corTab($matter, $actor)->callAction(TestAction::make('remindClient')->table($instalment));
}

/** @return list<Email> */
function corSentMails(): array
{
    return Mail::mailer()->getSymfonyTransport()->innerTransport()->messages()
        ->map(fn ($sent) => $sent->getOriginalMessage())
        ->all();
}

/** @return list<string> */
function corRecipients(): array
{
    $to = [];

    foreach (corSentMails() as $mail) {
        foreach ($mail->getTo() as $address) {
            $to[] = $address->getAddress();
        }
    }

    sort($to);

    return $to;
}

/** Đúng thông báo lỗi mà `ReportsActionFailures` gửi cho một lời từ chối `DomainException`. */
function corAssertRefusedWith(string $message): void
{
    Notification::assertNotified(
        Notification::make()->title(__('actions.failed_title'))->body($message)->danger()->persistent(),
    );
}

// =========================================================================================
// Nút hiện ở đâu, cho ai
// =========================================================================================

/** Mutation probe: bỏ điều kiện công tắc ở `->visible()` → đỏ. */
it('shows no reminder button at all while the switch is OFF', function () {
    corSwitch(false);

    corTab($this->matter, $this->lead)
        ->assertActionHidden(TestAction::make('remindClient')->table($this->overdue));
});

it('shows the button on the overdue row only — not on an instalment that is not yet due', function () {
    corSwitch(true);

    corTab($this->matter, $this->lead)
        ->assertActionVisible(TestAction::make('remindClient')->table($this->overdue))
        ->assertActionHidden(TestAction::make('remindClient')->table($this->upcoming));
});

it('hides the button on an instalment due today (not overdue yet), paid in full, or of a contract no longer active', function (string $case) {
    corSwitch(true);

    match ($case) {
        'due today' => $this->overdue->update(['due_date' => '2026-10-20']),
        'paid in full' => Payment::factory()->create(['instalment_id' => $this->overdue->id, 'amount' => 6_000_000]),
        'waived' => Instalment::query()->whereKey($this->overdue->id)->update(['status' => 'waived', 'waived_reason' => 'Miễn theo thoả thuận riêng với khách hàng.', 'waived_at' => now()]),
        'completed contract' => Contract::query()->whereKey($this->contract->id)->update(['status' => 'completed']),
    };

    corTab($this->matter, $this->admin)
        ->assertActionHidden(TestAction::make('remindClient')->table($this->overdue->fresh()));
})->with([
    'due today' => ['due today'],
    'paid in full' => ['paid in full'],
    'waived' => ['waived'],
    'completed contract' => ['completed contract'],
]);

/** Mutation probe: `InstalmentPolicy::remindClient()` trả `true` → đỏ (kế toán thấy nút). */
it('shows the button to who manages the contract of the matter, never to the accountant', function () {
    corSwitch(true);

    corTab($this->matter, $this->lead)->assertActionVisible(TestAction::make('remindClient')->table($this->overdue));
    corTab($this->matter, $this->admin)->assertActionVisible(TestAction::make('remindClient')->table($this->overdue));
    corTab($this->matter, $this->accountant)->assertActionHidden(TestAction::make('remindClient')->table($this->overdue));
});

/**
 * Lớp thứ hai sau nút ẩn: Action tự hỏi quyền dưới khoá (Livewire không gọi được một nút ẩn, nên đo
 * thẳng Action). Mutation probe: bỏ `Gate::authorize('remindClient')` ở Action → đỏ.
 */
it('refuses the accountant in the action itself too, and mails nobody', function () {
    corSwitch(true);

    expect(fn () => app(RemindClientOfOverdueInstalment::class)->handle($this->accountant, $this->overdue))
        ->toThrow(AuthorizationException::class);

    expect(corSentMails())->toBe([])
        ->and(CommunicationLog::query()->count())->toBe(0);
});

/**
 * Nút ẩn khi công tắc tắt hay đợt hết quá hạn, và Filament hỏi lại `->visible()` lúc bấm — nên hai câu từ
 * chối này của Action chỉ tới được khi trạng thái đổi giữa lúc Filament hỏi và lúc Action khoá hàng. Đo
 * thẳng Action. Mutation probes: bỏ câu hỏi công tắc / bỏ kiểm "đang quá hạn" ở Action → đỏ.
 */
it('refuses in the action itself when the switch is OFF or the instalment is no longer overdue, and logs nothing', function (string $case) {
    corSwitch($case !== 'switch off');

    if ($case === 'paid in full') {
        Payment::factory()->create(['instalment_id' => $this->overdue->id, 'amount' => 6_000_000]);
    }

    $instalment = $case === 'not yet due' ? $this->upcoming : $this->overdue;

    expect(fn () => app(RemindClientOfOverdueInstalment::class)->handle($this->lead, $instalment))
        ->toThrow(ClientReminderRefused::class, $case === 'switch off'
            ? __('client_payments.remind_action.switch_off')
            : __('client_payments.remind_action.not_overdue'));

    expect(corSentMails())->toBe([])
        ->and(CommunicationLog::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'client_payment_reminder_sent')->count())->toBe(0);
})->with([
    'switch off' => ['switch off'],
    'paid in full' => ['paid in full'],
    'not yet due' => ['not yet due'],
]);

// =========================================================================================
// Hộp thoại xem trước
// =========================================================================================

it('previews the exact mail: who receives it, the amount left, the due date, the subject and the text', function () {
    corSwitch(true);

    corTab($this->matter, $this->lead)
        ->mountAction(TestAction::make('remindClient')->table($this->overdue))
        ->assertMountedActionModalSee('vo@example.test')
        ->assertMountedActionModalSee('chong@example.test')
        ->assertMountedActionModalDontSee('chuakichhoat@example.test')
        ->assertMountedActionModalDontSee('dakhoa@example.test')
        ->assertMountedActionModalSee('6.000.000')
        ->assertMountedActionModalSee('15/10/2026')
        ->assertMountedActionModalSee(__('client_payments.reminder_mail.overdue.subject', ['code' => 'VK-2026-DS-0042']))
        ->assertMountedActionModalSee('VK-2026-DS-0042 DOT 1')
        ->assertMountedActionModalSee('0071000123456');

    expect(corSentMails())->toBe([]);
});

it('previews only the matter code and the sign-in line for a restricted matter', function () {
    corSwitch(true);
    $this->matter->update(['confidentiality' => 'restricted']);

    corTab($this->matter, $this->lead)
        ->mountAction(TestAction::make('remindClient')->table($this->overdue))
        ->assertMountedActionModalSee(__('client_payments.reminder_mail.overdue.restricted_line', ['code' => 'VK-2026-DS-0042']))
        ->assertMountedActionModalDontSee('0071000123456');
});

// =========================================================================================
// Gửi
// =========================================================================================

/** Mutation probe: thay R12 bằng mọi tài khoản của khách → đỏ. */
it('mails exactly the active, activated accounts of the client with the overdue reminder', function () {
    corSwitch(true);

    corSend($this->matter, $this->overdue, $this->lead)->assertHasNoActionErrors();

    $mail = corSentMails()[0];
    $body = $mail->getHtmlBody()."\n".$mail->getTextBody();

    expect(corRecipients())->toBe(['chong@example.test', 'vo@example.test'])
        ->and($mail->getSubject())->toBe(__('client_payments.reminder_mail.overdue.subject', ['code' => 'VK-2026-DS-0042']))
        ->and($body)->toContain('Đợt 1 TENDOT-QUAHAN')
        ->toContain('6.000.000')
        ->toContain('15/10/2026')
        ->toContain('VK-2026-DS-0042 DOT 1')
        ->not->toContain('TIEUDE-QUAHAN');

    $rows = OutboundMessage::query()->withoutGlobalScopes()->where('template', 'client.payment_reminder.overdue')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->unique()->all())->toBe([OutboundStatus::Sent])
        ->and($rows->pluck('related_type')->unique()->all())->toBe(['matter'])
        ->and($rows->pluck('related_id')->unique()->all())->toBe([$this->matter->id])
        ->and($rows->pluck('payload')->pluck('tier')->unique()->all())->toBe(['overdue:'.$this->overdue->id.'@2026-10-20']);
});

it('writes who sent it, when and to whom into the communication log of the matter and the activity log', function () {
    corSwitch(true);

    corSend($this->matter, $this->overdue, $this->lead);

    $log = CommunicationLog::query()->sole();

    expect($log->matter_id)->toBe($this->matter->id)
        ->and($log->type)->toBe(CommunicationType::Email)
        ->and($log->created_by)->toBe($this->lead->id)
        ->and($log->occurred_at->toDateTimeString())->toBe('2026-10-20 10:00:00')
        ->and($log->is_visible_to_client)->toBeFalse()
        ->and($log->summary)->toContain('vo@example.test')
        ->toContain('chong@example.test')
        ->toContain('Đợt 1 TENDOT-QUAHAN')
        ->toContain('6.000.000')
        ->toContain('15/10/2026');

    $activity = Activity::query()->where('event', 'client_payment_reminder_sent')->sole();

    expect($activity->causer_id)->toBe($this->lead->id)
        ->and($activity->subject_type)->toBe('instalment')
        ->and($activity->subject_id)->toBe($this->overdue->id)
        ->and($activity->properties['recipients'])->toBe(['chong@example.test', 'vo@example.test'])
        ->and($activity->properties['due_date'])->toBe('2026-10-15')
        ->and(__('activity.events.client_payment_reminder_sent'))->not->toBe('activity.events.client_payment_reminder_sent');
});

it('queues the mail job to run only after the transaction commits', function () {
    corSwitch(true);
    Queue::fake();

    corSend($this->matter, $this->overdue, $this->lead);

    Queue::assertPushed(SendPaymentOverdueReminderMail::class, fn (SendPaymentOverdueReminderMail $job): bool => $job->afterCommit === true
        && $job->instalmentId === $this->overdue->id
        && $job->dueDate === '2026-10-15'
        && $job->requestedOn === '2026-10-20'
        && $job->actorId === $this->lead->id);
});

// =========================================================================================
// Một lần mỗi đợt mỗi ngày
// =========================================================================================

/** Mutation probe: bỏ kiểm "đã gửi hôm nay" ở Action → đỏ. */
it('refuses a second reminder for the same instalment the same day, says so clearly, and mails nothing more', function () {
    corSwitch(true);

    corSend($this->matter, $this->overdue, $this->lead);
    session()->forget(['filament.notifications', 'filament.claimed_notifications']);

    $this->travelTo(Carbon::parse('2026-10-20 16:30:00'));
    corSend($this->matter, $this->overdue, $this->admin);

    expect(corSentMails())->toHaveCount(2)
        ->and(CommunicationLog::query()->count())->toBe(1);

    corAssertRefusedWith(__('client_payments.remind_action.already_sent_today', ['time' => '10:00', 'name' => 'Luật sư Phụ Trách']));
});

it('tells the user in the preview that a reminder already went out today', function () {
    corSwitch(true);

    corSend($this->matter, $this->overdue, $this->lead);

    corTab($this->matter, $this->admin)
        ->mountAction(TestAction::make('remindClient')->table($this->overdue))
        ->assertMountedActionModalSee(__('client_payments.remind_action.already_sent_today', ['time' => '10:00', 'name' => 'Luật sư Phụ Trách']));
});

it('allows the next reminder on the next day', function () {
    corSwitch(true);

    corSend($this->matter, $this->overdue, $this->lead);

    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    corSend($this->matter, $this->overdue, $this->lead);

    expect(corSentMails())->toHaveCount(4)
        ->and(CommunicationLog::query()->count())->toBe(2);
});

// =========================================================================================
// Lúc job chạy: tính lại mọi thứ
// =========================================================================================

/** Mutation probe: bỏ lần hỏi lại công tắc trong job → đỏ. */
it('sends nothing if the switch was turned OFF between the click and the sending', function () {
    corSwitch(true);
    Queue::fake();

    corSend($this->matter, $this->overdue, $this->lead);
    $job = Queue::pushed(SendPaymentOverdueReminderMail::class)->sole();

    corSwitch(false);
    $job->handle();

    expect(corSentMails())->toBe([]);
});

it('sends nothing when the client paid in full between the click and the sending', function () {
    corSwitch(true);
    Queue::fake();

    corSend($this->matter, $this->overdue, $this->lead);
    $job = Queue::pushed(SendPaymentOverdueReminderMail::class)->sole();

    Payment::factory()->create(['instalment_id' => $this->overdue->id, 'amount' => 6_000_000]);
    $job->handle();

    expect(corSentMails())->toBe([]);
});

it('does not mail twice when the job runs again after a retry', function () {
    corSwitch(true);

    corSend($this->matter, $this->overdue, $this->lead);
    (new SendPaymentOverdueReminderMail($this->overdue->id, '2026-10-15', '2026-10-20', $this->lead->id))->handle();

    expect(corSentMails())->toHaveCount(2);
});

it('records the failure and rings the person who pressed the button when the mail fails for good', function () {
    corSwitch(true);

    (new SendPaymentOverdueReminderMail($this->overdue->id, '2026-10-15', '2026-10-20', $this->lead->id))
        ->failed(new RuntimeException('smtp down'));

    $audit = Activity::query()->where('event', 'client_payment_reminder_failed')->sole();

    expect($audit->subject_id)->toBe($this->overdue->id)
        ->and($this->lead->fresh()->notifications)->toHaveCount(1)
        ->and(json_encode($this->lead->fresh()->notifications->first()->data, JSON_UNESCAPED_UNICODE))->toContain('VK-2026-DS-0042');
});

// =========================================================================================
// Từ chối rõ ràng
// =========================================================================================

it('refuses when no client account can receive the mail, and logs nothing', function () {
    corSwitch(true);
    $this->matter->update(['is_published_to_portal' => false]);

    corSend($this->matter, $this->overdue, $this->lead);

    expect(corSentMails())->toBe([])
        ->and(CommunicationLog::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'client_payment_reminder_sent')->count())->toBe(0);

    corAssertRefusedWith(__('client_payments.remind_action.no_recipients'));
});

it('mails only the matter code and the sign-in line for a restricted matter', function () {
    corSwitch(true);
    $this->matter->update(['confidentiality' => 'restricted']);

    corSend($this->matter, $this->overdue, $this->lead);

    $mail = corSentMails()[0];
    $body = $mail->getHtmlBody()."\n".$mail->getTextBody();

    expect($body)->toContain(e(__('client_payments.reminder_mail.overdue.restricted_line', ['code' => 'VK-2026-DS-0042'])))
        ->not->toContain('6.000.000')
        ->not->toContain('TENDOT-QUAHAN')
        ->not->toContain('0071000123456');
});
