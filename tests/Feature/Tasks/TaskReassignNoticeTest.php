<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Jobs\SendTaskNotice;
use App\Mail\Staff\TaskNotice;
use App\Models\Client;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn tk, Task 1, vòng sửa 1 (I1) — "Giao lại" một việc người khác ĐANG GIỮ (chờ nhận hay đang làm):
 * việc biến khỏi danh sách của người cũ (họ không còn xem được nó), nên người cũ nhận MỘT chuông chung —
 * không tiêu đề việc, không mã vụ, không tên khách (họ không còn quyền xem việc đó), không thư. Việc đã bị
 * trả lại thì người cũ đã buông nó: không báo. Người cũ đã nghỉ: không báo. Mọi lần giao lại đi qua nút
 * "Giao lại" trên trang "Công việc" (Livewire).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->otherAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);

    $client = Client::factory()->create(['name' => 'DAUVET-TEN-KHACH']);
    $this->matter = Matter::factory()->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $this->lead->id,
        'code' => 'DAUVET-MAHS',
        'title' => 'DAUVET-TIEUDE-VU',
    ]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->otherAssistant, MatterRole::Assistant);
});

function tkHeldTask(TaskStatus $status, User $holder): Task
{
    return Task::factory()->forMatter(test()->matter)->status($status)->create([
        'title' => 'DAUVET-TIEUDE-VIEC',
        'assigned_by' => test()->lead->id,
        'assignee_id' => $holder->id,
    ]);
}

function tkReassignFromList(Task $task, User $to): void
{
    test()->actingAs(test()->lead, 'web');

    test()->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->callTableAction('reassign', $task, data: ['assignee_id' => $to->id])
        ->assertHasNoTableActionErrors();

    expect($task->fresh()->assignee_id)->toBe($to->id);
}

it('tells the person who was holding the task, with one bell that names nothing of the task, when it is moved away from them', function (TaskStatus $status) {
    $task = tkHeldTask($status, $this->assistant);

    tkReassignFromList($task, $this->otherAssistant);

    $bell = $this->assistant->notifications()->sole();
    $shown = FilamentNotification::fromDatabase($bell);
    $encoded = json_encode($bell->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($shown->getTitle())->toBe(__('tasks.alert.reassigned_away.title'))
        ->and($shown->getBody())->toContain('Luật sư Khang')
        ->and($shown->getActions()[0]->getUrl())->toContain('/admin/tasks')->toContain('tab=active');

    foreach (['DAUVET-TIEUDE-VIEC', 'DAUVET-MAHS', 'DAUVET-TIEUDE-VU', 'DAUVET-TEN-KHACH'] as $marker) {
        expect(str_contains($encoded, $marker))->toBeFalse("Chuông của người cũ lộ {$marker}");
    }

    // Chỉ chuông: người cũ không nhận thư nào về lần giao lại; người mới nhận lời "được giao" như thường.
    Mail::assertNotSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->assistant->email));
    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->kind === TaskNotice::KIND_ASSIGNED && $mail->hasTo($this->otherAssistant->email));
    expect($this->otherAssistant->notifications()->sole()->data['title'])->toBe(__('tasks.alert.assigned.title'));
})->with([
    'đang chờ nhận' => TaskStatus::Pending,
    'đang làm' => TaskStatus::Accepted,
]);

it('does not ring the person who had already declined the task', function () {
    $task = tkHeldTask(TaskStatus::Declined, $this->assistant);

    tkReassignFromList($task, $this->otherAssistant);

    expect($this->assistant->notifications()->count())->toBe(0);
});

it('does not ring a holder who has been deactivated', function () {
    $task = tkHeldTask(TaskStatus::Accepted, $this->assistant);
    $this->assistant->forceFill(['is_active' => false])->save();

    tkReassignFromList($task, $this->otherAssistant);

    expect($this->assistant->notifications()->count())->toBe(0)
        ->and($this->otherAssistant->notifications()->count())->toBe(1);
});

it('still rings a holder who was taken off the matter team, though they can no longer open the task', function () {
    $task = tkHeldTask(TaskStatus::Accepted, $this->assistant);
    $this->matter->team()->detach($this->assistant->id);

    tkReassignFromList($task, $this->otherAssistant);

    expect($this->assistant->notifications()->sole()->data['title'])->toBe(__('tasks.alert.reassigned_away.title'));
});

it('sends no "moved away" bell when the task has come back to that person by the time the job runs', function () {
    $task = tkHeldTask(TaskStatus::Accepted, $this->assistant);
    $event = Activity::query()->create(['log_name' => 'default', 'description' => 'task_reassigned', 'event' => 'task_reassigned']);

    (new SendTaskNotice($task->id, TaskAlert::REASSIGNED_AWAY, $this->assistant->id, $event->id))->handle(app(ResolveStaffRecipients::class));

    expect($this->assistant->notifications()->count())->toBe(0);
});
