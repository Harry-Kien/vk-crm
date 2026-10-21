<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindPendingWork;
use App\Actions\Task\UpdateTask;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Jobs\SendTaskReminderDigest;
use App\Jobs\SendWorkReminder;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskReminderAlert;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * Kiểm toán luồng Công việc 2026-10-21 — Q3: lời nhắc VIỆC gộp theo (loại, người nhận) mỗi lượt, như lời nhắc hồ sơ
 * (`SendSeatReminderDigest`) — phán quyết làn tk "nhắc/leo thang một thư mỗi người mỗi lượt". Một việc: thư như cũ;
 * nhiều việc: MỘT chuông + MỘT thư liệt kê tiêu đề, hạn của các việc người nhận xem được; khoá từng việc vẫn ghi trên
 * dòng sổ thư (`|<khoá đầy đủ>|…`) nên lượt sau không gửi lại việc nào; nhịp nhắc không đổi.
 *
 * Giờ làm việc mặc định: Thứ Hai–Thứ Sáu 08:00–17:30. Giao 09:00 Thứ Hai 12/10/2026 → 24 giờ làm việc chạm 14:00
 * Thứ Tư 14/10; 48 giờ làm việc chạm 09:30 Thứ Hai 19/10.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    rdaTravel('2026-10-12 09:00');

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'RDA-01']);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function rdaTravel(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh'));
}

function rdaMails(string $kind, User $to): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', 'staff.task.reminder.'.$kind)
        ->where('recipient', $to->email)
        ->where('status', OutboundStatus::Sent)
        ->count();
}

function rdaBells(User $user): array
{
    return $user->notifications()->where('type', TaskReminderAlert::class)->get()->pluck('data')->all();
}

/** @return list<Task> */
function rdaTasks(int $count, TaskStatus $status = TaskStatus::Pending, array $extra = []): array
{
    return collect(range(1, $count))->map(fn (int $i): Task => Task::factory()->forMatter(test()->matter)->status($status)->create([
        'title' => "Việc chuyển số {$i}", 'assignee_id' => test()->assistant->id, 'assigned_by' => test()->lead->id,
        'assigned_at' => now(), ...$extra,
    ]))->all();
}

/** Nội dung (HTML) của thư gửi `$to` cuối cùng. */
function rdaLastMailBody(User $to): string
{
    $messages = collect(Mail::mailer()->getSymfonyTransport()->innerTransport()->messages()->all())
        ->filter(fn ($sent): bool => collect($sent->getOriginalMessage()->getTo())->contains(fn ($a) => $a->getAddress() === $to->email));

    return (string) $messages->last()?->getOriginalMessage()->getHtmlBody();
}

/** Mutation probe: xếp mỗi việc một `SendWorkReminder` như trước → đỏ (8 thư, 8 chuông). */
it('sends one awaiting reminder listing every task to the holder of eight tasks, and one escalation to the assigner', function () {
    $tasks = rdaTasks(8);

    rdaTravel('2026-10-14 14:00');
    expect(app(RemindPendingWork::class)->handle()['queued'])->toBe(8);

    expect(rdaMails('awaiting', $this->assistant))->toBe(1)
        ->and(rdaBells($this->assistant))->toHaveCount(1);

    $bell = rdaBells($this->assistant)[0];
    expect($bell['title'])->toBe(__('tasks.reminder_digest.alert.awaiting.title', ['count' => 8]));

    foreach ($tasks as $task) {
        expect(rdaLastMailBody($this->assistant))->toContain($task->title);
    }

    // Dòng sổ thư mang khoá của TỪNG việc.
    $tier = OutboundMessage::query()->withoutGlobalScopes()->where('template', 'staff.task.reminder.awaiting')->sole()->payload['tier'];
    foreach ($tasks as $task) {
        expect($tier)->toContain('|'.SendWorkReminder::fullKey(RemindPendingWork::SUBJECT_TASK, $task->id, RemindPendingWork::AWAITING, RemindPendingWork::awaitingKey($task->awaitingSince())).'|');
    }

    // Lượt sau cùng ngày và hôm sau: không xếp lại việc nào.
    foreach (['2026-10-14 14:30', '2026-10-15 09:00'] as $at) {
        rdaTravel($at);
        expect(app(RemindPendingWork::class)->handle()['queued'])->toBe(0);
    }

    expect(rdaMails('awaiting', $this->assistant))->toBe(1);

    rdaTravel('2026-10-19 09:30');
    app(RemindPendingWork::class)->handle();

    expect(rdaMails('unaccepted', $this->lead))->toBe(1)
        ->and(rdaBells($this->lead))->toHaveCount(1)
        ->and(rdaBells($this->lead)[0]['title'])->toBe(__('tasks.reminder_digest.alert.unaccepted.title', ['count' => 8]));
});

it('sends one overdue reminder a day to the holder and one to the assigner for three overdue tasks', function () {
    rdaTasks(3, TaskStatus::Accepted, ['due_date' => '2026-10-09', 'assigned_at' => now()->subWeeks(2)]);

    foreach (['2026-10-12 09:00', '2026-10-12 09:30', '2026-10-13 09:00'] as $at) {
        rdaTravel($at);
        app(RemindPendingWork::class)->handle();
    }

    // Hai ngày làm việc: hai thư mỗi người (không 6).
    expect(rdaMails('overdue', $this->assistant))->toBe(2)
        ->and(rdaMails('overdue_assigner', $this->lead))->toBe(2)
        ->and(rdaBells($this->assistant))->toHaveCount(2)
        ->and(rdaBells($this->lead))->toHaveCount(2);

    expect(rdaLastMailBody($this->lead))->toContain('Việc chuyển số 1')->toContain('Việc chuyển số 3')->toContain('Trợ lý Lan');
});

it('keeps the single-task reminder exactly as before', function () {
    rdaTasks(1, TaskStatus::Accepted, ['due_date' => '2026-10-09', 'assigned_at' => now()->subWeeks(2)]);

    app(RemindPendingWork::class)->handle();

    expect(rdaBells($this->assistant))->toHaveCount(1)
        ->and(rdaBells($this->assistant)[0]['title'])->toBe(__('tasks.reminder.alert.overdue.title'))
        ->and(rdaMails('overdue', $this->assistant))->toBe(1);
});

/** Mutation probe: lần hỏi lại lúc gửi bỏ `forTask()` (người nhận còn xem được việc) → tiêu đề việc vụ hạn chế lộ ra → đỏ. */
it('lists, at sending time, only the tasks the recipient can still open and still has to hear about', function () {
    $other = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'RDA-02']);
    $other->addTeamMember($this->assistant, MatterRole::Assistant);
    $tasks = rdaTasks(2, TaskStatus::Accepted, ['due_date' => '2026-10-09', 'assigned_at' => now()->subWeeks(2)]);
    $secret = Task::factory()->forMatter($other)->status(TaskStatus::Accepted)->create([
        'title' => 'Việc của hồ sơ kín', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
        'due_date' => '2026-10-09', 'assigned_at' => now()->subWeeks(2),
    ]);
    $done = $tasks[1];

    $key = RemindPendingWork::overdueKey();
    $job = new SendTaskReminderDigest(RemindPendingWork::OVERDUE_ASSIGNER, $this->lead->id, [$tasks[0]->id => $key, $done->id => $key, $secret->id => $key]);

    // Giữa lúc xếp và lúc gửi: hồ sơ thứ hai thành hạn chế với luật sư phụ trách khác; việc thứ hai đã xong.
    $other->update(['confidentiality' => Confidentiality::Restricted, 'lead_lawyer_id' => $this->manager->id]);
    $done->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);

    $job->handle(app(ResolveStaffRecipients::class));

    // Còn một việc: lời nhắc một việc như cũ, không thư gộp.
    expect(rdaMails('overdue_assigner', $this->lead))->toBe(1)
        ->and(rdaBells($this->lead)[0]['title'])->toBe(__('tasks.reminder.alert.overdue_assigner.title'))
        ->and(rdaLastMailBody($this->lead))->not->toContain('Việc của hồ sơ kín');
});

it('records the failure of a digest for every task in it, so the next round skips them for the day', function () {
    $tasks = rdaTasks(2);
    $job = new SendTaskReminderDigest(RemindPendingWork::AWAITING, $this->assistant->id, [$tasks[0]->id => 'k1', $tasks[1]->id => 'k2']);

    $job->failed(new RuntimeException('smtp down'));

    expect(Activity::query()->where('event', 'work_reminder_failed')->count())->toBe(2)
        ->and(SendWorkReminder::failedForGoodToday(RemindPendingWork::SUBJECT_TASK, $tasks[0]->id, $this->assistant->id))->toBeTrue();
});

it('escapes task titles in the digest bell', function () {
    rdaTasks(1, TaskStatus::Accepted, ['due_date' => '2026-10-09', 'assigned_at' => now()->subWeeks(2)]);
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => '<a href="https://x.test">bấm</a>', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
        'due_date' => '2026-10-09', 'assigned_at' => now()->subWeeks(2),
    ]);

    app(RemindPendingWork::class)->handle();

    expect(rdaBells($this->assistant)[0]['body'])->not->toContain('<a href')->toContain('&lt;a href');
});

/** Mutation probe: lần hỏi lại lúc gửi bỏ `stillAddressedTo()` → việc người giao vừa sửa hôm nay vẫn nằm trong thư → đỏ. */
it('leaves out of a queued digest the task the assigner acted on after it was queued', function () {
    $tasks = rdaTasks(3, TaskStatus::Accepted, ['due_date' => '2026-10-09', 'assigned_at' => now()->subWeeks(2)]);
    $key = RemindPendingWork::overdueKey();
    $job = new SendTaskReminderDigest(RemindPendingWork::OVERDUE_ASSIGNER, $this->lead->id, collect($tasks)->mapWithKeys(fn (Task $t) => [$t->id => $key])->all());

    app(UpdateTask::class)->handle($tasks[2], $this->lead, 'Việc đã sửa hôm nay', null, '2026-10-09', TaskPriority::Urgent, null);
    $job->handle(app(ResolveStaffRecipients::class));

    expect(rdaBells($this->lead))->toHaveCount(1)
        ->and(rdaBells($this->lead)[0]['title'])->toBe(__('tasks.reminder_digest.alert.overdue_assigner.title', ['count' => 2]))
        ->and(rdaLastMailBody($this->lead))->toContain('Việc chuyển số 1')->not->toContain('Việc đã sửa hôm nay');
});
