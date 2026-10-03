<?php

use App\Actions\Intake\AnonymiseProspect;
use App\Actions\Intake\ChangeIntakeStatus;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Actions\Schedule\RemindUnansweredIntakes;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use App\Jobs\SendUnansweredIntakeReminderMail;
use App\Mail\Staff\IntakeUnanswered;
use App\Models\IntakeRequest;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\IntakeUnansweredAlert;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/*
 * M10 Task 5 (R5) — nhắc một lần liên hệ chưa ai gọi lại quá ngưỡng phản hồi (mặc định 4 giờ làm
 * việc, Thứ Hai–Thứ Sáu 08:00–17:30, giờ `APP_TIMEZONE`): thư `staff.intake_unanswered` qua hàng
 * đợi + thông báo trong hệ thống, người nhận qua `ResolveStaffRecipients::forIntake()`, chống trùng
 * qua nhật ký thư (chỉ `sent`), thư không mang dữ liệu nào của người liên hệ.
 *
 * Mặc định của bộ test: hàng đợi `sync`, transport thư `array` (dòng `outbound_messages` thật).
 * Ngày cố định: 2026-10-07 Thứ Tư, 2026-10-09 Thứ Sáu, 2026-10-10 Thứ Bảy, 2026-10-12 Thứ Hai.
 *
 * Hàm toàn cục mang tiền tố `rui…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(ruiAt('2026-10-07 09:00'));
});

function ruiAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Ho_Chi_Minh');
}

function ruiStaff(Role $role = Role::Lawyer, array $attributes = []): User
{
    return User::factory()->withRole($role)->create($attributes);
}

/** Một lần liên hệ ghi NGAY BÂY GIỜ (theo đồng hồ của test), qua đúng Action của Task 2. */
function ruiRecord(User $actor, array $overrides = [], array $parties = []): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Người Gọi Mẫu',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides], $parties)->intake;
}

/** @return array<string, int> */
function ruiRun(): array
{
    return app(RemindUnansweredIntakes::class)->handle();
}

function ruiMailRows(?User $recipient = null, OutboundStatus $status = OutboundStatus::Sent): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', IntakeUnanswered::TEMPLATE)
        ->when($recipient, fn ($q) => $q->where('recipient', $recipient->email))
        ->where('status', $status)
        ->count();
}

function ruiAlerts(?User $recipient = null): int
{
    return DatabaseNotification::query()
        ->where('type', IntakeUnansweredAlert::class)
        ->when($recipient, fn ($q) => $q->where('notifiable_id', $recipient->id))
        ->count();
}

/** Một dòng nhật ký thư dựng tay — để đo luật chống trùng mà không cần gửi thư thật trước. */
function ruiLedgerRow(IntakeRequest $intake, User $recipient, string $template, OutboundStatus $status): OutboundMessage
{
    return OutboundMessage::query()->withoutGlobalScopes()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $recipient->email,
        'template' => $template,
        'payload' => ['subject' => 'Dòng có sẵn'],
        'related_type' => $intake->getMorphClass(),
        'related_id' => $intake->getKey(),
        'status' => $status,
        'sent_at' => $status === OutboundStatus::Sent ? now() : null,
    ]);
}

/**
 * "Máy chủ thư hỏng": `$failingAddress` null thì hỏng cho MỌI thư; có địa chỉ thì chỉ hỏng cho thư gửi
 * tới đúng địa chỉ đó (hộp thư một người từ chối), các thư khác "tới nơi".
 */
class IntakeReminderBrokenTransport implements TransportInterface
{
    public function __construct(private readonly ?string $failingAddress = null) {}

    public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SymfonySentMessage
    {
        $to = $message instanceof Email ? array_map(fn ($a) => $a->getAddress(), $message->getTo()) : [];

        if ($this->failingAddress === null || in_array($this->failingAddress, $to, true)) {
            throw new TransportException('SMTP không trả lời');
        }

        return new SymfonySentMessage($message, new SymfonyEnvelope(new Address('gui@vidu.test'), [new Address('nhan@vidu.test')]));
    }

    public function __toString(): string
    {
        return 'intake-reminder-broken://';
    }
}

function ruiBrokenMailer(?string $failingAddress = null): string
{
    config()->set('mail.mailers.intake_reminder_broken', ['transport' => 'intake_reminder_broken']);
    Mail::extend('intake_reminder_broken', fn (): TransportInterface => new IntakeReminderBrokenTransport($failingAddress));

    return 'intake_reminder_broken';
}

// ---------------------------------------------------------------------------------------------
// Đồng hồ: ngưỡng tính theo GIỜ LÀM VIỆC, không theo giờ đồng hồ
// ---------------------------------------------------------------------------------------------

it('reminds once four working hours have passed, not when only wall-clock hours have', function (string $received, array $notYet, string $due) {
    $lawyer = ruiStaff();
    $this->travelTo(ruiAt($received));
    ruiRecord($lawyer, ['assigned_to' => $lawyer->id]);

    foreach ($notYet as $at) {
        $this->travelTo(ruiAt($at));
        ruiRun();
        expect(ruiMailRows($lawyer))->toBe(0, "chưa được nhắc lúc {$at}");
    }

    $this->travelTo(ruiAt($due));
    ruiRun();

    expect(ruiMailRows($lawyer))->toBe(1)
        ->and(ruiAlerts($lawyer))->toBe(1);
})->with([
    'trong giờ làm việc' => ['2026-10-07 09:00', ['2026-10-07 12:45'], '2026-10-07 13:00'],
    'qua đêm' => ['2026-10-07 16:00', ['2026-10-08 08:00', '2026-10-08 10:15'], '2026-10-08 10:30'],
    'qua cuối tuần' => ['2026-10-09 15:00', ['2026-10-12 08:00', '2026-10-12 09:15'], '2026-10-12 09:30'],
]);

it('stays quiet outside working hours for a record already past the threshold, and speaks up to closing time inclusive', function () {
    $lawyer = ruiStaff();
    ruiRecord($lawyer, ['assigned_to' => $lawyer->id]);

    foreach (['2026-10-07 17:45', '2026-10-07 21:00', '2026-10-10 10:00', '2026-10-11 09:00'] as $closed) {
        $this->travelTo(ruiAt($closed));
        expect(ruiRun()['reminded'])->toBe(0);
        expect(ruiMailRows($lawyer))->toBe(0, "giờ đóng cửa {$closed}")
            ->and(ruiAlerts($lawyer))->toBe(0);
    }

    $this->travelTo(ruiAt('2026-10-07 17:30'));
    ruiRun();

    expect(ruiMailRows($lawyer))->toBe(1);
});

it('follows a changed threshold of working hours from the configuration', function () {
    config(['vkcrm.intake_response_hours' => 2]);
    $lawyer = ruiStaff();
    ruiRecord($lawyer, ['assigned_to' => $lawyer->id]);

    $this->travelTo(ruiAt('2026-10-07 10:45'));
    ruiRun();
    expect(ruiMailRows($lawyer))->toBe(0);

    $this->travelTo(ruiAt('2026-10-07 11:00'));
    ruiRun();
    expect(ruiMailRows($lawyer))->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// Người nhận — cổng mới `ResolveStaffRecipients::forIntake()` (R5)
// ---------------------------------------------------------------------------------------------

it('mails only the assignee when they are active and still see the record', function () {
    $lawyer = ruiStaff();
    $manager = ruiStaff(Role::Manager);
    $admin = ruiStaff(Role::Admin);
    ruiRecord(ruiStaff(Role::Assistant), ['assigned_to' => $lawyer->id]);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(ruiMailRows($lawyer))->toBe(1)
        ->and(ruiMailRows($manager))->toBe(0)
        ->and(ruiMailRows($admin))->toBe(0)
        ->and(ruiAlerts($manager))->toBe(0);
});

it('mails the managers and admins instead when the assignee has left, never a deactivated manager or another lawyer', function () {
    $lawyer = ruiStaff();
    $otherLawyer = ruiStaff();
    $manager = ruiStaff(Role::Manager);
    $leftManager = ruiStaff(Role::Manager);
    $admin = ruiStaff(Role::Admin);
    $assistant = ruiStaff(Role::Assistant);
    ruiRecord($assistant, ['assigned_to' => $lawyer->id]);

    $lawyer->forceFill(['is_active' => false])->save();
    $leftManager->forceFill(['is_active' => false])->save();

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(ruiMailRows($lawyer))->toBe(0)
        ->and(ruiMailRows($manager))->toBe(1)
        ->and(ruiMailRows($admin))->toBe(1)
        ->and(ruiMailRows($leftManager))->toBe(0)
        ->and(ruiMailRows($otherLawyer))->toBe(0)
        ->and(ruiMailRows($assistant))->toBe(0)
        ->and(ruiAlerts($manager))->toBe(1)
        ->and(ruiAlerts($lawyer))->toBe(0);
});

it('mails the managers when the assignee account has been deleted', function () {
    $lawyer = ruiStaff();
    $manager = ruiStaff(Role::Manager);
    ruiRecord(ruiStaff(Role::Assistant), ['assigned_to' => $lawyer->id]);

    $lawyer->delete();

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(ruiMailRows($lawyer))->toBe(0)
        ->and(ruiMailRows($manager))->toBe(1);
});

it('mails the managers when the assignee no longer sees the record', function () {
    $lawyer = ruiStaff();
    $manager = ruiStaff(Role::Manager);
    ruiRecord(ruiStaff(Role::Assistant), ['assigned_to' => $lawyer->id]);

    $lawyer->syncRoles([Role::Accountant->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(ruiMailRows($lawyer))->toBe(0)
        ->and(ruiMailRows($manager))->toBe(1);
});

it('mails everyone who sees every record when nobody is assigned, not the person who took the call', function () {
    $assistant = ruiStaff(Role::Assistant);
    $manager = ruiStaff(Role::Manager);
    $admin = ruiStaff(Role::Admin);
    ruiRecord($assistant);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(ruiMailRows($manager))->toBe(1)
        ->and(ruiMailRows($admin))->toBe(1)
        ->and(ruiMailRows($assistant))->toBe(0);
});

it('falls back to the active admins when nobody may see every record, never silent', function () {
    $assistant = ruiStaff(Role::Assistant);
    $manager = ruiStaff(Role::Manager);
    $admin = ruiStaff(Role::Admin);
    $leftAdmin = ruiStaff(Role::Admin);
    $leftAdmin->forceFill(['is_active' => false])->save();
    ruiRecord($assistant);

    foreach ([Role::Manager, Role::Admin] as $role) {
        SpatieRole::findByName($role->value, 'web')->revokePermissionTo('intake.viewAny');
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(ruiMailRows($admin))->toBe(1)
        ->and(ruiMailRows($leftAdmin))->toBe(0)
        ->and(ruiMailRows($manager))->toBe(0)
        ->and(ruiMailRows($assistant))->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Nội dung: mã, nguồn, thời gian đã chờ, liên kết — KHÔNG gì của người liên hệ
// ---------------------------------------------------------------------------------------------

it('mails the code, the source, the time waited and a link, and nothing about the contact', function () {
    $lawyer = ruiStaff(Role::Lawyer, ['name' => 'Luật Sư Nhận Thư']);
    $intake = ruiRecord($lawyer, [
        'contact_name' => 'ZZTEN Bí Mật',
        'contact_phone' => '0987 654 321',
        'contact_email' => 'zzthu@example.test',
        'referred_by' => 'ZZGIOITHIEU',
        'source' => IntakeSource::Zalo,
        'assigned_to' => $lawyer->id,
    ], [['name' => 'ZZBENKIA', 'role' => PartyRole::Defendant, 'phone' => '0911 000 999']]);
    app(RecordPrivacyNotice::class)->handle($lawyer, $intake, true);
    app(UpdateIntakeSummary::class)->handle($lawyer, $intake->fresh(), 'ZZCAUCHUYEN vợ chồng tranh chấp');

    $this->travelTo(ruiAt('2026-10-07 13:20'));
    ruiRun();

    $sent = Mail::mailer()->getSymfonyTransport()->innerTransport()->messages();
    expect($sent)->toHaveCount(1);

    /** @var Email $email */
    $email = $sent->first()->getOriginalMessage();
    $everything = implode("\n", [$email->getSubject(), $email->getHtmlBody(), $email->getTextBody()]);
    $url = IntakeRequestResource::getUrl('edit', ['record' => $intake], panel: 'admin');

    expect($email->getSubject())->toContain($intake->code)
        ->and($email->getHtmlBody())->toContain($intake->code)
        ->and($email->getTextBody())->toContain($intake->code)
        ->and($email->getHtmlBody())->toContain(IntakeSource::Zalo->label())
        ->and($email->getTextBody())->toContain(IntakeSource::Zalo->label())
        ->and($email->getTextBody())->toContain(__('intake.reminder.duration.hours_minutes', ['hours' => 4, 'minutes' => 20]))
        ->and($email->getHtmlBody())->toContain(e($url))
        ->and($email->getTextBody())->toContain($url)
        ->and($email->getTextBody())->toContain('Luật Sư Nhận Thư');

    $ledger = json_encode(OutboundMessage::query()->withoutGlobalScopes()->get()->toArray(), JSON_UNESCAPED_UNICODE);
    $alerts = json_encode(DatabaseNotification::query()->pluck('data')->all(), JSON_UNESCAPED_UNICODE);

    expect($alerts)->toContain($intake->code);

    foreach (['ZZTEN', 'Bí Mật', '0987', '987654321', 'zzthu', 'ZZGIOITHIEU', 'ZZBENKIA', '911000999', 'ZZCAUCHUYEN', 'tranh chấp'] as $needle) {
        expect($everything)->not->toContain($needle)
            ->and($ledger)->not->toContain($needle)
            ->and($alerts)->not->toContain($needle);
    }
});

it('writes an in-app alert the admin panel bell can render, with the code and no contact data', function () {
    $lawyer = ruiStaff();
    $intake = ruiRecord($lawyer, ['contact_name' => 'ZZTEN Chuông', 'assigned_to' => $lawyer->id]);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    $rendered = FilamentNotification::fromDatabase(DatabaseNotification::query()->sole());

    expect($rendered->getTitle())->toBe(__('intake.reminder.alert.title'))
        ->and($rendered->getBody())->toContain($intake->code)
        ->and($rendered->getBody())->toContain(IntakeSource::Phone->label())
        ->and($rendered->getBody())->not->toContain('ZZTEN')
        ->and($rendered->getColor())->toBe('warning');
});

// ---------------------------------------------------------------------------------------------
// Chống trùng (M6 R3, R4): nhật ký thư là trí nhớ, chỉ tính `sent`
// ---------------------------------------------------------------------------------------------

it('sends no second mail and no second alert when it runs again, the same day or the next', function () {
    $lawyer = ruiStaff();
    ruiRecord($lawyer, ['assigned_to' => $lawyer->id]);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    expect(ruiRun()['reminded'])->toBe(1);
    expect(ruiRun()['reminded'])->toBe(0);

    $this->travelTo(ruiAt('2026-10-07 13:15'));
    ruiRun();
    $this->travelTo(ruiAt('2026-10-08 08:00'));
    ruiRun();

    expect(ruiMailRows($lawyer))->toBe(1)
        ->and(ruiAlerts($lawyer))->toBe(1);
});

it('reminds a newly assigned person once, and not the one already reminded again', function () {
    $first = ruiStaff();
    $second = ruiStaff();
    $intake = ruiRecord($first, ['assigned_to' => $first->id]);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    $intake->forceFill(['assigned_to' => $second->id])->save();
    $this->travelTo(ruiAt('2026-10-07 13:15'));
    ruiRun();
    ruiRun();

    expect(ruiMailRows($first))->toBe(1)
        ->and(ruiMailRows($second))->toBe(1)
        ->and(ruiAlerts($second))->toBe(1);
});

it('keeps one reminder job per record in the queue while one is still waiting to run, and one for each record', function () {
    Queue::fake();
    $lawyer = ruiStaff();
    $intake = ruiRecord($lawyer, ['assigned_to' => $lawyer->id]);
    $other = ruiRecord($lawyer, ['contact_phone' => '0901222333', 'assigned_to' => $lawyer->id]);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();
    $this->travelTo(ruiAt('2026-10-07 13:15'));
    ruiRun();

    Queue::assertPushed(SendUnansweredIntakeReminderMail::class, 2);
    Queue::assertPushed(SendUnansweredIntakeReminderMail::class, fn (SendUnansweredIntakeReminderMail $job): bool => $job->intakeId === $intake->id);
    Queue::assertPushed(SendUnansweredIntakeReminderMail::class, fn (SendUnansweredIntakeReminderMail $job): bool => $job->intakeId === $other->id);
});

it('queues no job when everyone it would mail already has the reminder', function () {
    Queue::fake();
    $lawyer = ruiStaff();
    $intake = ruiRecord($lawyer, ['assigned_to' => $lawyer->id]);
    ruiLedgerRow($intake, $lawyer, IntakeUnanswered::TEMPLATE, OutboundStatus::Sent);

    $this->travelTo(ruiAt('2026-10-07 13:00'));

    expect(ruiRun()['reminded'])->toBe(0);
    Queue::assertNothingPushed();
});

it('reminds one person about each of two records, not once for both', function () {
    $lawyer = ruiStaff();
    ruiRecord($lawyer, ['assigned_to' => $lawyer->id]);
    ruiRecord($lawyer, ['contact_phone' => '0901222333', 'assigned_to' => $lawyer->id]);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(ruiMailRows($lawyer))->toBe(2)
        ->and(ruiAlerts($lawyer))->toBe(2);
});

it('does not count another kind of mail about the same record, or a failed reminder, as the reminder', function () {
    $lawyer = ruiStaff();
    $intake = ruiRecord($lawyer, ['assigned_to' => $lawyer->id]);
    ruiLedgerRow($intake, $lawyer, 'staff.something_else', OutboundStatus::Sent);
    ruiLedgerRow($intake, $lawyer, IntakeUnanswered::TEMPLATE, OutboundStatus::Failed);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(ruiMailRows($lawyer))->toBe(1);
});

it('mails a viewer who joins later once, and not again those already reminded', function () {
    $manager = ruiStaff(Role::Manager);
    $admin = ruiStaff(Role::Admin);
    ruiRecord(ruiStaff(Role::Assistant));

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    $newManager = ruiStaff(Role::Manager);
    $this->travelTo(ruiAt('2026-10-07 13:15'));
    ruiRun();

    expect(ruiMailRows($manager))->toBe(1)
        ->and(ruiMailRows($admin))->toBe(1)
        ->and(ruiMailRows($newManager))->toBe(1)
        ->and(ruiAlerts($newManager))->toBe(1);
});

it('still alerts the other recipients and still mails when the alert for one of them throws', function () {
    Exceptions::fake();
    $manager = ruiStaff(Role::Manager);
    $admin = ruiStaff(Role::Admin);
    ruiRecord(ruiStaff(Role::Assistant));

    Event::listen(NotificationSending::class, function ($event) use ($manager): void {
        if ($event->notification instanceof IntakeUnansweredAlert && $event->notifiable->is($manager)) {
            throw new RuntimeException('kênh database hỏng cho người này');
        }
    });

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(ruiAlerts($admin))->toBe(1)
        ->and(ruiAlerts($manager))->toBe(0)
        ->and(ruiMailRows($manager))->toBe(1)
        ->and(ruiMailRows($admin))->toBe(1);
    Exceptions::assertReported(RuntimeException::class);
});

// ---------------------------------------------------------------------------------------------
// Máy chủ thư hỏng: dòng `failed`, không 500, không thử lại mỗi 15 phút
// ---------------------------------------------------------------------------------------------

it('records a failed mail row without throwing when the mail server is down, and tries again the next day', function () {
    $lawyer = ruiStaff();
    $intake = ruiRecord($lawyer, ['assigned_to' => $lawyer->id]);
    $arrayMailer = config('mail.default');
    config(['mail.default' => ruiBrokenMailer()]);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(ruiMailRows($lawyer, OutboundStatus::Failed))->toBe(1)
        ->and(ruiMailRows($lawyer))->toBe(0)
        ->and(ruiAlerts($lawyer))->toBe(1)
        ->and(Activity::query()->where('event', 'intake_reminder_failed')->where('subject_id', $intake->id)->count())->toBe(1);

    // Cùng ngày: không xếp lại mỗi 15 phút.
    $this->travelTo(ruiAt('2026-10-07 13:15'));
    ruiRun();
    $this->travelTo(ruiAt('2026-10-07 16:00'));
    ruiRun();

    expect(ruiMailRows($lawyer, OutboundStatus::Failed))->toBe(1);

    // Hôm sau, máy chủ thư đã chạy lại: thử lại đúng một lần và tới nơi.
    config(['mail.default' => $arrayMailer]);
    $this->travelTo(ruiAt('2026-10-08 08:00'));
    ruiRun();

    expect(ruiMailRows($lawyer))->toBe(1)
        ->and(ruiAlerts($lawyer))->toBe(1);
});

it('still mails the others when one recipient\'s mailbox refuses, and records that one as failed', function () {
    $manager = ruiStaff(Role::Manager, ['email' => 'quanly-hong@vidu.test']);
    $admin = ruiStaff(Role::Admin);
    ruiRecord(ruiStaff(Role::Assistant));
    config(['mail.default' => ruiBrokenMailer('quanly-hong@vidu.test')]);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(ruiMailRows($manager, OutboundStatus::Failed))->toBe(1)
        ->and(ruiMailRows($manager))->toBe(0)
        ->and(ruiMailRows($admin))->toBe(1);
});

it('logs the permanent failure with no contact data', function () {
    $lawyer = ruiStaff();
    $intake = ruiRecord($lawyer, ['contact_name' => 'ZZTEN Nhật Ký', 'assigned_to' => $lawyer->id]);

    (new SendUnansweredIntakeReminderMail($intake->id))->failed(new RuntimeException('hết lượt thử'));

    $row = Activity::query()->where('event', 'intake_reminder_failed')->sole();

    expect($row->subject_id)->toBe($intake->id)
        ->and(json_encode($row->properties))->not->toContain('ZZTEN');
});

// ---------------------------------------------------------------------------------------------
// Điều kiện đọc lại lúc gửi, không tin ảnh chụp lúc xếp hàng
// ---------------------------------------------------------------------------------------------

it('sends nothing when the record was answered after the reminder was queued and before it ran', function () {
    config(['queue.default' => 'database']);
    $lawyer = ruiStaff();
    $intake = ruiRecord($lawyer, ['assigned_to' => $lawyer->id]);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(DB::table('jobs')->count())->toBe(1);

    app(ChangeIntakeStatus::class)->handle($lawyer, $intake, IntakeStatus::Contacted);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(ruiMailRows($lawyer))->toBe(0)
        ->and(OutboundMessage::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('does not remind a record answered between two runs', function () {
    $lawyer = ruiStaff();
    $intake = ruiRecord($lawyer, ['assigned_to' => $lawyer->id]);

    $this->travelTo(ruiAt('2026-10-07 12:45'));
    ruiRun();
    app(ChangeIntakeStatus::class)->handle($lawyer, $intake, IntakeStatus::Contacted);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(ruiMailRows($lawyer))->toBe(0)
        ->and(ruiAlerts($lawyer))->toBe(0);
});

it('does not remind a record that has left new, been anonymised or been deleted', function (Closure $state) {
    $lawyer = ruiStaff();
    $intake = ruiRecord($lawyer, ['assigned_to' => $lawyer->id]);
    $state($intake);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(ruiMailRows($lawyer))->toBe(0)
        ->and(ruiAlerts($lawyer))->toBe(0);
})->with([
    'đã liên hệ lại' => fn (IntakeRequest $i) => $i->forceFill(['status' => IntakeStatus::Contacted])->save(),
    'đã từ chối' => fn (IntakeRequest $i) => $i->forceFill(['status' => IntakeStatus::Declined])->save(),
    'đã gộp' => fn (IntakeRequest $i) => $i->forceFill(['status' => IntakeStatus::Merged])->save(),
    'đã chuyển thành vụ việc' => fn (IntakeRequest $i) => $i->forceFill(['status' => IntakeStatus::Won])->save(),
    'đã ẩn danh mà vẫn ở new' => fn (IntakeRequest $i) => $i->forceFill(['anonymised_at' => now()])->save(),
    'đã xoá mềm' => fn (IntakeRequest $i) => $i->delete(),
]);

/*
 * Gộp làn m10-t7 (Task 7) vào m10-intake: "xoá theo yêu cầu" THẬT (`AnonymiseProspect::erase()`), không
 * dựng tay `anonymised_at`. Xoá giữ trạng thái (R7c), nên bản vẫn là `new` — nhưng không còn chờ phản
 * hồi: job thư đã xếp trước khi xoá không gửi gì lúc chạy, và lượt sau không nhắc ai, kể cả một người
 * xem mới vào sau (người mà một bản còn chờ thật sẽ nhắc — "mails a viewer who joins later once"). Bản
 * đã xoá cũng không bao giờ nhận mốc phản hồi: đổi trạng thái bị từ chối, không chạm lại cột nào.
 */
it('neither mails nor alerts anyone about a record erased on request while still in new, even with its reminder already queued', function () {
    config(['queue.default' => 'database']);
    $manager = ruiStaff(Role::Manager);
    $admin = ruiStaff(Role::Admin);
    $assistant = ruiStaff(Role::Assistant);
    $intake = ruiRecord($assistant);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(ruiAlerts($manager))->toBe(1);

    app(AnonymiseProspect::class)->erase($admin, $intake, 'Người liên hệ gọi lại yêu cầu xoá toàn bộ dữ liệu của mình');
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(OutboundMessage::query()->withoutGlobalScopes()->count())->toBe(0);

    $newManager = ruiStaff(Role::Manager);
    $this->travelTo(ruiAt('2026-10-08 09:00'));
    ruiRun();

    expect(ruiAlerts($newManager))->toBe(0)
        ->and(ruiAlerts())->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(OutboundMessage::query()->withoutGlobalScopes()->count())->toBe(0);

    expect(fn () => app(ChangeIntakeStatus::class)->handle($manager, $intake->fresh(), IntakeStatus::Contacted))
        ->toThrow(ValidationException::class);

    $fresh = $intake->fresh();
    expect($fresh->status)->toBe(IntakeStatus::New)
        ->and($fresh->anonymised_at)->not->toBeNull()
        ->and($fresh->first_response_at)->toBeNull()
        ->and($fresh->contact_name)->toBeNull();
});

it('still reminds the same record while it stays in new, not anonymised and not deleted', function () {
    $lawyer = ruiStaff();
    ruiRecord($lawyer, ['assigned_to' => $lawyer->id]);

    $this->travelTo(ruiAt('2026-10-07 13:00'));
    ruiRun();

    expect(ruiMailRows($lawyer))->toBe(1);
});
