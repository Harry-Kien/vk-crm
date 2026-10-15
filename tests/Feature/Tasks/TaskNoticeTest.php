<?php

use App\Actions\Notification\ResendTargets;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Task\AcceptTask;
use App\Actions\Task\AssignTask;
use App\Actions\Task\DeclineTask;
use App\Actions\Task\ReassignTask;
use App\Enums\MatterRole;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Jobs\SendTaskNotice;
use App\Mail\Staff\TaskNotice;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn tk, Task 1 — lời báo của luồng giao việc qua ĐƯỜNG THẬT (trình gửi thư `array`, không
 * `Mail::fake()`): dòng nhật ký thư, khoá chống trùng khi job thử lại, và ranh giới nội dung của chuông
 * và thư (tiêu đề thư không mang tên việc/mã vụ; mọi chữ người dùng gõ được escape).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function tkSentMails(): array
{
    return Mail::mailer()->getSymfonyTransport()->innerTransport()->messages()->all();
}

it('writes one ledger row per notice, tied to the task, with the event key, and sends no second copy when the job retries', function () {
    $task = app(AssignTask::class)->handle($this->lead, $this->matter, $this->assistant, 'Soạn đơn khởi kiện', null, null, TaskPriority::High);

    $row = OutboundMessage::query()->withoutGlobalScopes()->sole();
    $event = Activity::query()->where('event', 'task_assigned')->sole();

    expect($row->template)->toBe('staff.task.assigned')
        ->and($row->related_type)->toBe('task')
        ->and($row->related_id)->toBe($task->id)
        ->and($row->recipient)->toBe($this->assistant->email)
        ->and($row->status)->toBe(OutboundStatus::Sent)
        ->and($row->payload['tier'])->toBe(TaskNotice::ledgerKey($event->id));

    // Job chạy lại (hàng đợi thử lại sau một lỗi ở chặng sau): không thư thứ hai, không chuông thứ hai.
    (new SendTaskNotice($task->id, TaskAlert::ASSIGNED, $this->assistant->id, $event->id))->handle(app(ResolveStaffRecipients::class));

    expect(OutboundMessage::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(tkSentMails())->toHaveCount(1)
        ->and($this->assistant->notifications()->count())->toBe(1);
});

it('mails the assignee again when a declined task comes back to them, since that is a new assignment', function () {
    $task = app(AssignTask::class)->handle($this->lead, $this->matter, $this->assistant, 'Soạn đơn khởi kiện');
    app(DeclineTask::class)->handle($task, $this->assistant, 'Cần thêm tài liệu');
    app(ReassignTask::class)->handle($task, $this->lead, $this->assistant);

    expect(OutboundMessage::query()->withoutGlobalScopes()->where('template', 'staff.task.assigned')->where('recipient', $this->assistant->email)->count())->toBe(2)
        ->and($this->assistant->notifications()->count())->toBe(2);
});

it('keeps the task title and the matter code out of the mail subject, and puts them in the body', function () {
    app(AssignTask::class)->handle($this->lead, $this->matter, $this->assistant, 'Soạn đơn khởi kiện bí mật', null, today()->addDays(2)->toDateString(), TaskPriority::Urgent);

    $message = tkSentMails()[0]->getOriginalMessage();

    expect($message->getSubject())->toBe(__('tasks.email.subject.assigned', ['name' => 'Luật sư Khang']))
        ->not->toContain('Soạn đơn')
        ->not->toContain($this->matter->code)
        ->and($message->getHtmlBody())->toContain('Soạn đơn khởi kiện bí mật')
        ->toContain($this->matter->code)
        ->toContain(TaskPriority::Urgent->label())
        ->toContain(today()->addDays(2)->format('d/m/Y'))
        ->not->toContain($this->matter->client->name)
        ->and($message->getTextBody())->toContain('Soạn đơn khởi kiện bí mật');
});

it('escapes what people type, in the bell and in the mail', function () {
    $task = app(AssignTask::class)->handle($this->lead, $this->matter, $this->assistant, '<a href="https://evil.test">Bấm</a>');
    app(DeclineTask::class)->handle($task, $this->assistant, '<img src=x onerror=alert(1)>');

    $bell = $this->lead->notifications()->sole();
    expect($bell->data['body'])->not->toContain('<a href')
        ->not->toContain('<img')
        ->toContain('&lt;a href=')
        ->toContain('&lt;img src=x');

    $html = collect(tkSentMails())->map(fn ($sent) => $sent->getOriginalMessage()->getHtmlBody())->implode("\n");
    expect($html)->not->toContain('<a href="https://evil.test">')
        ->not->toContain('<img src=x');
});

it('gives the bell a Vietnamese title and an open button that leads to the right tab of the task page', function () {
    $task = app(AssignTask::class)->handle($this->lead, $this->matter, $this->assistant, 'Nộp hồ sơ');
    app(AcceptTask::class)->handle($task, $this->assistant);

    $assigned = FilamentNotification::fromDatabase($this->assistant->notifications()->sole());
    $accepted = FilamentNotification::fromDatabase($this->lead->notifications()->sole());

    expect($assigned->getTitle())->toBe(__('tasks.alert.assigned.title'))
        ->and($assigned->getBody())->toContain('Nộp hồ sơ')->toContain($this->matter->code)->toContain('Luật sư Khang')
        ->and($assigned->getActions()[0]->getUrl())->toContain('/admin/tasks')->toContain('tab=awaiting')
        ->and($accepted->getTitle())->toBe(__('tasks.alert.accepted.title'))
        ->and($accepted->getBody())->toContain('Trợ lý Lan')
        ->and($accepted->getActions()[0]->getUrl())->toContain('tab=assigned');
});

it('shows the ledger rows of task mails only to an admin, never to the lead of the matter', function () {
    app(AssignTask::class)->handle($this->lead, $this->matter, $this->assistant, 'Soạn đơn');
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect(OutboundMessage::query()->withoutGlobalScopes()->visibleTo($admin)->count())->toBe(1)
        ->and(OutboundMessage::query()->withoutGlobalScopes()->visibleTo($this->lead)->count())->toBe(0)
        ->and(OutboundMessage::query()->withoutGlobalScopes()->visibleTo($this->assistant)->count())->toBe(0);
});

it('declares the four task mails as not resendable, with a reason of their own', function () {
    foreach ([TaskNotice::KIND_ASSIGNED, TaskNotice::KIND_ACCEPTED, TaskNotice::KIND_DECLINED, TaskNotice::KIND_COMPLETED] as $kind) {
        expect(ResendTargets::exclusionOf('staff.task.'.$kind))->toBe('staff.task.*')
            ->and((array) __('outbound.templates'))->toHaveKey('staff.task.'.$kind);
    }
});

it('sends nothing at all when the task is gone by the time the job runs', function () {
    $task = Task::factory()->forMatter($this->matter)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id]);
    $event = Activity::query()->create(['log_name' => 'default', 'description' => 'task_assigned', 'event' => 'task_assigned']);
    $task->delete();

    (new SendTaskNotice($task->id, TaskAlert::ASSIGNED, $this->assistant->id, $event->id))->handle(app(ResolveStaffRecipients::class));

    expect(tkSentMails())->toBe([])
        ->and($this->assistant->notifications()->count())->toBe(0);
});
