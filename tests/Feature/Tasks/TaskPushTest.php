<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\MatterRole;
use App\Enums\PushTopic;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\TasksRelationManager;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Jobs\SendTaskNotice;
use App\Models\Client;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\PushAlert;
use App\Notifications\Staff\TaskAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakePushServer;
use Tests\Support\WebPushTestKeys;

/**
 * Làn tk, Task 1, vòng sửa 1 (I2) — thông báo đẩy `staff.task.assigned` (brief §5: "thông báo đẩy điện
 * thoại nếu hạ tầng M12 sẵn — chỉ một câu chung, không lộ nội dung"). Đi CÙNG thư "được giao" (M12 R10):
 * người nhận push là ĐÚNG người vừa nhận thư đó, chống trùng là sổ thư. Ba lời báo cho người giao (đã
 * nhận, trả lại, hoàn thành) cố ý không đẩy — chuông và thư đủ (SPEC §9, bảng R10).
 *
 * Trình gửi thư `array` thật (không `Mail::fake()`): sổ thư `outbound_messages` là thứ chống trùng của
 * push qua một lần job chạy lại. Mọi người đã bật máy. Mọi lần giao, giao lại, nhận, trả lại, hoàn thành
 * đi qua màn hình (Livewire); chỉ lần "job chạy lại" gọi thẳng job, như hàng đợi thử lại.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    config(WebPushTestKeys::config());
    Notification::fake();

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

    foreach ([$this->lead, $this->assistant, $this->otherAssistant] as $user) {
        FakePushServer::device($user, 'viec-'.$user->id);
    }
});

/** Email đã nhận thư `staff.task.<kind>` (dòng `sent` của sổ thư). */
function tkPushMailedTo(string $kind): array
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', 'staff.task.'.$kind)
        ->pluck('recipient')
        ->sort()
        ->values()
        ->all();
}

function tkPushAssignFromTab(User $actor, User $assignee, string $title): Task
{
    test()->actingAs($actor, 'web');

    test()->livewire(TasksRelationManager::class, ['ownerRecord' => test()->matter, 'pageClass' => ViewMatter::class])
        ->callTableAction('assign', data: ['assignee_id' => $assignee->id, 'title' => $title])
        ->assertHasNoTableActionErrors();

    return Task::query()->where('title', $title)->sole();
}

it('buzzes the assignee with one line that names nothing of the task, opening the awaiting tab, alongside the mail', function () {
    $task = tkPushAssignFromTab($this->lead, $this->assistant, 'DAUVET-TIEUDE-VIEC');

    $alert = Notification::sent($this->assistant, PushAlert::class)->sole();
    $payload = $alert->toWebPush($this->assistant, $alert)->toArray();
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($alert->message->topic)->toBe(PushTopic::StaffTaskAssigned->value)
        ->and($alert->message->relatedType)->toBe($task->getMorphClass())
        ->and($alert->message->relatedId)->toBe($task->id)
        ->and($alert->message->url)->toBe('/admin/tasks?tab=awaiting')
        ->and($payload['body'])->toBe(__('push.alerts.staff.task.assigned'))
        ->and($payload['tag'])->toBe('staff.task.assigned:'.$task->id);

    foreach (['DAUVET-TIEUDE-VIEC', 'DAUVET-MAHS', 'DAUVET-TIEUDE-VU', 'DAUVET-TEN-KHACH', 'Luật sư Khang'] as $marker) {
        expect(str_contains($encoded, $marker))->toBeFalse("Thông báo đẩy lộ {$marker}");
    }

    // Người nhận push = người nhận thư (R10): đúng trợ lý, không ai khác — kể cả người giao.
    expect(tkPushMailedTo('assigned'))->toBe([$this->assistant->email]);
    Notification::assertNotSentTo($this->lead, PushAlert::class);
    Notification::assertNotSentTo($this->otherAssistant, PushAlert::class);
});

it('does not buzz the assigner when the task is accepted, declined or completed — bell and mail only', function (string $action, array $data, string $kind) {
    $task = tkPushAssignFromTab($this->lead, $this->assistant, 'Soạn đơn');

    $this->actingAs($this->assistant, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'awaiting'])
        ->callTableAction($action, $task, data: $data)
        ->assertHasNoTableActionErrors();

    expect(tkPushMailedTo($kind))->toBe([$this->lead->email]);
    Notification::assertSentTo($this->lead, TaskAlert::class);
    Notification::assertNotSentTo($this->lead, PushAlert::class);
})->with([
    'đã nhận' => ['accept', [], 'accepted'],
    'trả lại' => ['decline', ['reason' => 'Cần thêm tài liệu'], 'declined'],
    'hoàn thành' => ['complete', [], 'completed'],
]);

it('buzzes the new holder of a reassigned task, and never the person it was taken from', function () {
    $task = tkPushAssignFromTab($this->lead, $this->assistant, 'Thu thập chứng cứ');
    $task->forceFill(['status' => TaskStatus::Accepted, 'accepted_at' => now()])->save();

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->callTableAction('reassign', $task, data: ['assignee_id' => $this->otherAssistant->id])
        ->assertHasNoTableActionErrors();

    expect(Notification::sent($this->otherAssistant, PushAlert::class))->toHaveCount(1)
        ->and(Notification::sent($this->assistant, PushAlert::class))->toHaveCount(1);
});

it('does not buzz a second time when the notice job runs again after the mail went out', function () {
    $task = tkPushAssignFromTab($this->lead, $this->assistant, 'Nộp hồ sơ');
    $event = Activity::query()->where('event', 'task_assigned')->sole();

    (new SendTaskNotice($task->id, TaskAlert::ASSIGNED, $this->assistant->id, $event->id))->handle(app(ResolveStaffRecipients::class));

    expect(Notification::sent($this->assistant, PushAlert::class))->toHaveCount(1)
        ->and(tkPushMailedTo('assigned'))->toBe([$this->assistant->email]);
});
