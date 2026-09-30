<?php

use App\Enums\ContractStatus;
use App\Enums\Role;
use App\Jobs\SendInstalmentOverdueMail;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * M9 Task 11 — hành vi RIÊNG của job `SendInstalmentOverdueMail`: nó đọc lại MỌI THỨ lúc chạy (đợt,
 * hợp đồng, vụ việc, người nhận, chống trùng), vì giữa lúc `RemindOverdueInstalments` xếp job và
 * lúc job thật sự chạy (hàng đợi trễ, hoặc `backoff()` sau một lần hỏng) sự thật có thể đã đổi.
 * Câu hỏi "tác vụ có xếp đúng đợt, đúng nhịp hay không" là việc của
 * `tests/Feature/Schedule/RemindOverdueInstalmentsTest.php`. Mọi test ở đây gọi `->handle()` TRỰC
 * TIẾP trên một job tự dựng, cùng phong cách `SendDeadlineReminderMailTest`.
 *
 * Không `Mail::fake()`: cả sổ thư `outbound_messages` lẫn header khoá chống trùng đều là một phần
 * của cái đang được đo (xem tệp Schedule).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(today()->setTime(8, 0));

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);
    $this->instalment = Instalment::factory()->for($this->contract)->create([
        'name' => 'Đợt hai khi nộp đơn',
        'amount' => 10_000_000,
        'due_date' => today()->subDays(3)->toDateString(),
    ]);
});

function jobFor(Instalment $instalment, ?string $dueDate = null): SendInstalmentOverdueMail
{
    return new SendInstalmentOverdueMail($instalment->id, $dueDate ?? $instalment->due_date->toDateString());
}

/** @return list<string> Địa chỉ đã nhận thư `sent` của mẫu này, sắp xếp. */
function jobSentTo(): array
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', 'staff.instalment_overdue')
        ->where('status', 'sent')
        ->pluck('recipient')
        ->sort()
        ->values()
        ->all();
}

function jobEmails(User ...$users): array
{
    return collect($users)->pluck('email')->sort()->values()->all();
}

it('is a queued job that retries with a growing backoff, carrying only ids', function () {
    $job = jobFor($this->instalment);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([60, 300, 900, 3600])
        ->and(get_object_vars($job))->toHaveKeys(['instalmentId', 'dueDate'])
        ->and($job->instalmentId)->toBe($this->instalment->id);
});

it('mails the lead lawyer and the accountant, computed fresh from the database', function () {
    jobFor($this->instalment)->handle();

    expect(jobSentTo())->toBe(jobEmails($this->lead, $this->accountant));
});

// =================================================================================================
// Đọc lại người nhận lúc gửi (ruling "re-derive the audience at send time")
// =================================================================================================

it('mails an accountant who joined after the job was queued', function () {
    $job = jobFor($this->instalment);
    $late = User::factory()->withRole(Role::Accountant)->create();

    $job->handle();

    expect(jobSentTo())->toBe(jobEmails($this->lead, $this->accountant, $late));
});

it('does not mail an accountant who was deactivated after the job was queued', function () {
    $job = jobFor($this->instalment);
    $this->accountant->update(['is_active' => false]);

    $job->handle();

    expect(jobSentTo())->toBe(jobEmails($this->lead));
});

// =================================================================================================
// Đọc lại đợt/hợp đồng/vụ việc lúc gửi
// =================================================================================================

it('sends nothing when the instalment was paid in full after the job was queued', function () {
    $job = jobFor($this->instalment);
    Payment::factory()->create(['instalment_id' => $this->instalment->id, 'amount' => 10_000_000]);

    $job->handle();

    expect(jobSentTo())->toBeEmpty();
});

it('sends nothing when the instalment was waived after the job was queued', function () {
    $job = jobFor($this->instalment);
    DB::table('instalments')->where('id', $this->instalment->id)->update(['status' => 'waived']);

    $job->handle();

    expect(jobSentTo())->toBeEmpty();
});

it('sends nothing when the instalment was cancelled after the job was queued', function () {
    $job = jobFor($this->instalment);
    DB::table('instalments')->where('id', $this->instalment->id)->update(['status' => 'cancelled']);

    $job->handle();

    expect(jobSentTo())->toBeEmpty();
});

it('sends nothing when the contract stopped being active after the job was queued', function () {
    $job = jobFor($this->instalment);
    DB::table('contracts')->where('id', $this->contract->id)->update(['status' => ContractStatus::Cancelled->value]);

    $job->handle();

    expect(jobSentTo())->toBeEmpty();
});

it('sends nothing when the matter was soft-deleted after the job was queued', function () {
    $job = jobFor($this->instalment);
    DB::table('matters')->where('id', $this->matter->id)->update(['deleted_at' => now()]);

    $job->handle();

    expect(jobSentTo())->toBeEmpty();
});

it('sends nothing when the instalment no longer exists', function () {
    $job = jobFor($this->instalment);
    DB::table('payments')->where('instalment_id', $this->instalment->id)->delete();
    DB::table('instalments')->where('id', $this->instalment->id)->delete();

    $job->handle();

    expect(jobSentTo())->toBeEmpty();
});

it('sends nothing when the due date was moved to the future after the job was queued', function () {
    $job = jobFor($this->instalment);
    DB::table('instalments')->where('id', $this->instalment->id)->update(['due_date' => today()->addDays(10)->toDateString()]);

    $job->handle();

    expect(jobSentTo())->toBeEmpty();
});

/**
 * Ngày đến hạn đã đổi sang một ngày KHÁC nhưng vẫn quá hạn: job này được xếp cho ngày CŨ nên bỏ
 * qua — lượt chạy kế tiếp của tác vụ sẽ xếp một job mới mang ngày mới. Nếu job gửi thư nói về ngày
 * MỚI dưới khoá của ngày CŨ, khoá chống trùng sẽ nói dối.
 */
it('skips when the due date changed to another past date since the job was queued', function () {
    $job = jobFor($this->instalment);
    DB::table('instalments')->where('id', $this->instalment->id)->update(['due_date' => today()->subDay()->toDateString()]);

    $job->handle();

    expect(jobSentTo())->toBeEmpty();
});

// =================================================================================================
// Chống trùng tại lúc gửi
// =================================================================================================

it('does not mail a recipient twice when two jobs for the same instalment sit in the queue', function () {
    jobFor($this->instalment)->handle();
    jobFor($this->instalment)->handle();

    expect(jobSentTo())->toBe(jobEmails($this->lead, $this->accountant));
});

it('mails again once seven calendar days have passed since the last sent mail', function () {
    jobFor($this->instalment)->handle();
    $this->travelTo(now()->addDays(7));

    jobFor($this->instalment)->handle();

    expect(OutboundMessage::query()->withoutGlobalScopes()->where('template', 'staff.instalment_overdue')->where('status', 'sent')->count())->toBe(4);
});

it('does not mail again before seven calendar days have passed', function () {
    jobFor($this->instalment)->handle();
    $this->travelTo(now()->addDays(6));

    jobFor($this->instalment)->handle();

    expect(OutboundMessage::query()->withoutGlobalScopes()->where('template', 'staff.instalment_overdue')->where('status', 'sent')->count())->toBe(2);
});

it('does not count a failed or a queued ledger row as a reminder already delivered', function () {
    foreach (['failed', 'queued'] as $status) {
        OutboundMessage::query()->create([
            'recipient' => $this->accountant->email,
            'template' => 'staff.instalment_overdue',
            'payload' => ['subject' => 'x', 'tier' => 'overdue@'.$this->instalment->due_date->toDateString()],
            'related_type' => 'instalment',
            'related_id' => $this->instalment->id,
            'status' => $status,
            'sent_at' => now(),
        ]);
    }

    jobFor($this->instalment)->handle();

    expect(jobSentTo())->toBe(jobEmails($this->lead, $this->accountant));
});

it('does not count a sent reminder for another instalment, another template or another record type as delivered', function () {
    $otherContract = Contract::factory()->for(Matter::factory()->create())->active()->create(['total_amount' => 5_000_000]);
    $other = Instalment::factory()->for($otherContract)->create(['amount' => 5_000_000, 'due_date' => today()->subDays(3)->toDateString()]);
    foreach ([['staff.instalment_overdue', $other->id, 'instalment'], ['staff.deadline_reminder', $this->instalment->id, 'instalment'], ['staff.instalment_overdue', $this->instalment->id, 'deadline']] as [$template, $relatedId, $relatedType]) {
        OutboundMessage::query()->create([
            'recipient' => $this->accountant->email,
            'template' => $template,
            'payload' => ['subject' => 'x', 'tier' => 'overdue@'.$this->instalment->due_date->toDateString()],
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    jobFor($this->instalment)->handle();

    $mine = OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', 'staff.instalment_overdue')->where('related_type', 'instalment')->where('related_id', $this->instalment->id)->where('status', 'sent')->count();
    expect($mine)->toBe(2);
});

it('does not count a sent reminder addressed to somebody else as delivered to this recipient', function () {
    OutboundMessage::query()->create([
        'recipient' => 'nguoi-khac@vidu.test',
        'template' => 'staff.instalment_overdue',
        'payload' => ['subject' => 'x', 'tier' => 'overdue@'.$this->instalment->due_date->toDateString()],
        'related_type' => 'instalment',
        'related_id' => $this->instalment->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    jobFor($this->instalment)->handle();

    expect(jobSentTo())->toContain($this->accountant->email)->toContain($this->lead->email);
});

/** Transport hỏng cho đúng MỘT địa chỉ — dùng cho test thử lại bên dưới. */
class InstalmentJobFailTransport implements TransportInterface
{
    public function __construct(private readonly string $failingAddress) {}

    public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SymfonySentMessage
    {
        if ($message instanceof Email) {
            foreach ($message->getTo() as $address) {
                if ($address->getAddress() === $this->failingAddress) {
                    throw new TransportException('SMTP từ chối '.$this->failingAddress);
                }
            }
        }

        return new SymfonySentMessage($message, new SymfonyEnvelope(new Address('gui@vidu.test'), [new Address('nhan@vidu.test')]));
    }

    public function __toString(): string
    {
        return 'instalment-job-fail://';
    }
}

it('rethrows the first failure after trying everyone, and does not re-mail the first recipient when retried', function () {
    config()->set('mail.mailers.instalment_job_fail', ['transport' => 'instalment_job_fail']);
    $failing = $this->accountant->email;
    Mail::extend('instalment_job_fail', fn () => new InstalmentJobFailTransport($failing));
    config(['mail.default' => 'instalment_job_fail']);
    $job = jobFor($this->instalment);

    $thrown = 0;
    foreach ([1, 2] as $attempt) {
        try {
            $job->handle();
        } catch (TransportException) {
            $thrown++;
        }
    }

    // Cả hai lần thử đều ném lại (kế toán luôn hỏng, để hàng đợi thấy job hỏng và thử lại), nhưng
    // luật sư chỉ nhận đúng MỘT thư — lần thử thứ hai bỏ qua người đã `sent`.
    expect($thrown)->toBe(2)
        ->and(jobSentTo())->toBe(jobEmails($this->lead));
});

// =================================================================================================
// Khoá chống trùng mang ngày đến hạn
// =================================================================================================

it('keys the ledger row by the due date the reminder is about', function () {
    jobFor($this->instalment)->handle();

    $tiers = OutboundMessage::query()->withoutGlobalScopes()->where('template', 'staff.instalment_overdue')->get()->pluck('payload.tier')->unique()->all();
    expect($tiers)->toBe(['overdue@'.$this->instalment->due_date->toDateString()]);
});

it('sends a fresh reminder for a new due date even within seven days of one for the old due date', function () {
    jobFor($this->instalment)->handle();
    DB::table('instalments')->where('id', $this->instalment->id)->update(['due_date' => today()->subDay()->toDateString()]);
    $fresh = Instalment::query()->find($this->instalment->id);

    jobFor($fresh)->handle();

    expect(OutboundMessage::query()->withoutGlobalScopes()->where('template', 'staff.instalment_overdue')->where('status', 'sent')->count())->toBe(4);
});

/**
 * Job của đợt A không được nhặt đợt B chỉ vì B còn quá hạn khi A đã hết: nó chỉ nói về ĐÚNG đợt nó
 * được xếp cho (`whereKey`).
 */
it('does not fall through to another overdue instalment when its own is no longer overdue', function () {
    $otherContract = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]))->active()->create(['total_amount' => 5_000_000]);
    Instalment::factory()->for($otherContract)->create(['amount' => 5_000_000, 'due_date' => today()->subDays(3)->toDateString()]);
    $job = jobFor($this->instalment);
    Payment::factory()->create(['instalment_id' => $this->instalment->id, 'amount' => 10_000_000]);

    $job->handle();

    expect(jobSentTo())->toBeEmpty();
});
