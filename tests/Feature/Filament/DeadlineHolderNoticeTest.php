<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\CheckDeadlines;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Jobs\SendDeadlineAssignmentNotice;
use App\Mail\Staff\DeadlineAssigned;
use App\Mail\Staff\DeadlineReminder;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\Staff\DeadlineAssignedAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * Làn fa2, phán quyết 6 — đổi người phụ trách mốc hạn (nút "Đổi người phụ trách", "Sửa", hay thêm mốc giao cho người
 * khác): người giữ mốc mới nhận NGAY chuông + thư kèm hạn và số ngày còn lại; bậc nhắc đã gửi cho người cũ không gửi
 * lại (bậc gần nhất đến với người mới qua chính lời báo này, một lần). Người tự giao cho mình thì không báo.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0303']);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function fa2DeadlineTab(Matter $matter)
{
    return test()->livewire(DeadlinesRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
}

function fa2DeadlineBells(User $user): array
{
    return $user->notifications()->where('type', DeadlineAssignedAlert::class)->get()->pluck('data')->all();
}

it('tells the new holder at once, with the due date and the days left, when the holder is changed', function () {
    $deadline = Deadline::factory()->for($this->matter)->create([
        'name' => 'Hạn nộp bản tự khai',
        'due_date' => today()->addDays(3),
        'responsible_user_id' => $this->lead->id,
        'reminders_sent' => ['d7', 'd3'],
    ]);

    $this->actingAs($this->lead, 'web');

    fa2DeadlineTab($this->matter)
        ->callTableAction('changeResponsible', $deadline, data: ['responsible_user_id' => $this->assistant->id])
        ->assertHasNoTableActionErrors();

    $bells = fa2DeadlineBells($this->assistant);

    expect($bells)->toHaveCount(1)
        ->and($bells[0]['body'])->toContain('Hạn nộp bản tự khai')->toContain('VK-2026-DS-0303')
        ->toContain(__('internal_flow.deadline_assigned.due_in', ['date' => today()->addDays(3)->format('d/m/Y'), 'days' => 3]))
        ->toContain('Luật sư Khang')
        ->and(fa2DeadlineBells($this->lead))->toBe([]);

    Mail::assertSent(DeadlineAssigned::class, fn (DeadlineAssigned $mail): bool => $mail->hasTo($this->assistant->email)
        && $mail->deadline->is($deadline));
    Mail::assertSent(DeadlineAssigned::class, 1);

    // Bậc 3 ngày đã gửi cho người cũ: lượt nhắc kế tiếp không gửi lại nó cho ai — người mới đã có nó ở lời báo trên.
    app(CheckDeadlines::class)->handle();

    Mail::assertNotSent(DeadlineReminder::class);
});

it('tells the new holder when the edit form changes the holder, and when a deadline is added for someone else', function () {
    $deadline = Deadline::factory()->for($this->matter)->create([
        'name' => 'Phiên hoà giải',
        'due_date' => today()->addDays(10),
        'responsible_user_id' => $this->lead->id,
    ]);

    $this->actingAs($this->lead, 'web');

    fa2DeadlineTab($this->matter)->callTableAction('edit', $deadline, data: [
        'name' => 'Phiên hoà giải',
        'due_date' => today()->addDays(10)->toDateString(),
        'severity' => $deadline->severity->value,
        'responsible_user_id' => $this->assistant->id,
    ])->assertHasNoTableActionErrors();

    fa2DeadlineTab($this->matter)->callTableAction('create', data: [
        'name' => 'Nộp tạm ứng án phí',
        'due_date' => today()->addDays(5)->toDateString(),
        'responsible_user_id' => $this->assistant->id,
    ])->assertHasNoTableActionErrors();

    expect(fa2DeadlineBells($this->assistant))->toHaveCount(2);
    Mail::assertSent(DeadlineAssigned::class, 2);
});

it('does not tell someone who gives the deadline to themselves, or adds one they hold by default', function () {
    $deadline = Deadline::factory()->for($this->matter)->create([
        'due_date' => today()->addDays(4),
        'responsible_user_id' => $this->assistant->id,
    ]);

    $this->actingAs($this->lead, 'web');

    fa2DeadlineTab($this->matter)
        ->callTableAction('changeResponsible', $deadline, data: ['responsible_user_id' => $this->lead->id])
        ->assertHasNoTableActionErrors();

    fa2DeadlineTab($this->matter)->callTableAction('create', data: [
        'name' => 'Phiên toà sơ thẩm',
        'due_date' => today()->addDays(6)->toDateString(),
    ])->assertHasNoTableActionErrors();

    expect(fa2DeadlineBells($this->lead))->toBe([]);
    Mail::assertNotSent(DeadlineAssigned::class);
});

it('drops the notice when the new holder can no longer open the matter by the time it is sent', function () {
    Queue::fake();

    $deadline = Deadline::factory()->for($this->matter)->create([
        'due_date' => today()->addDays(4),
        'responsible_user_id' => $this->lead->id,
    ]);

    $this->actingAs($this->lead, 'web');

    fa2DeadlineTab($this->matter)
        ->callTableAction('changeResponsible', $deadline, data: ['responsible_user_id' => $this->assistant->id])
        ->assertHasNoTableActionErrors();

    $job = Queue::pushed(SendDeadlineAssignmentNotice::class)->sole();

    $this->matter->team()->detach($this->assistant->id);

    $job->handle(app(ResolveStaffRecipients::class));

    expect(fa2DeadlineBells($this->assistant))->toBe([]);
    Mail::assertNotSent(DeadlineAssigned::class);
});

it('drops a stale notice: the holder changed again, the deadline was completed, or the matter was closed', function (string $change) {
    Queue::fake();

    $deadline = Deadline::factory()->for($this->matter)->create([
        'due_date' => today()->addDays(4),
        'responsible_user_id' => $this->lead->id,
    ]);

    $this->actingAs($this->lead, 'web');

    fa2DeadlineTab($this->matter)
        ->callTableAction('changeResponsible', $deadline, data: ['responsible_user_id' => $this->assistant->id])
        ->assertHasNoTableActionErrors();

    $job = Queue::pushed(SendDeadlineAssignmentNotice::class)->sole();

    match ($change) {
        'holder' => $deadline->fresh()->forceFill(['responsible_user_id' => $this->lead->id])->save(),
        'completed' => $deadline->forceFill(['is_completed' => true, 'completed_at' => now()])->save(),
        'closed' => $this->matter->forceFill(['closed_at' => now()])->save(),
    };

    $job->handle(app(ResolveStaffRecipients::class));

    expect(fa2DeadlineBells($this->assistant))->toBe([]);
    Mail::assertNotSent(DeadlineAssigned::class);
})->with(['holder', 'completed', 'closed']);
