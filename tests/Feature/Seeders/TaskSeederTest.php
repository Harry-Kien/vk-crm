<?php

use App\Actions\Task\TaskAssignees;
use App\Enums\Confidentiality;
use App\Enums\TaskStatus;
use App\Mail\Staff\TaskNotice;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TaskSeeder;
use Database\Seeders\TeamPerformanceSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Làn tk, Task 1 — dữ liệu mẫu của luồng giao việc (chỉ local/testing, qua `DemoDataSeeder`): việc ở
 * MỌI trạng thái giữa các tài khoản demo, để chủ văn phòng đăng nhập là thấy được luồng.
 */
beforeEach(function () {
    Storage::fake('private');
    Mail::fake();

    $this->seed(DatabaseSeeder::class);
});

it('seeds tasks in every status, and the situations the owner should see', function () {
    foreach (TaskStatus::cases() as $status) {
        expect(Task::query()->where('status', $status->value)->exists())->toBeTrue("thiếu việc {$status->value}");
    }

    expect(Task::query()->overdue()->exists())->toBeTrue()
        ->and(Task::query()->where('status', TaskStatus::Pending->value)->where('assigned_at', '<', now()->subDay())->exists())->toBeTrue()
        ->and(Task::query()->where('status', TaskStatus::Declined->value)->whereNotNull('decline_reason')->exists())->toBeTrue();

    $manager = User::query()->where('email', 'quanly@luatvukhang.com')->sole();
    $accountant = User::query()->where('email', 'ketoan@luatvukhang.com')->sole();

    expect(Task::query()->whereNull('matter_id')->where('assigned_by', $manager->id)->where('assignee_id', $accountant->id)->exists())->toBeTrue();
});

it('seeds only tasks the rules would have allowed: every open matter task sits with a working team member who can open the matter', function () {
    $tasks = Task::query()->open()->whereNotNull('matter_id')->with('matter')->get();

    expect($tasks)->not->toBeEmpty();

    foreach ($tasks as $task) {
        $assignee = User::query()->find($task->assignee_id);

        expect(app(TaskAssignees::class)->qualifies($assignee, $task->matter))->toBeTrue("việc #{$task->id} giao cho người không nhận được")
            ->and(Gate::forUser(User::query()->find($task->assigned_by))->allows('view', $task))->toBeTrue();
    }
});

it('keeps the restricted matter\'s task away from the manager', function () {
    $secret = Task::query()->whereHas('matter', fn ($matter) => $matter->where('confidentiality', Confidentiality::Restricted->value))->sole();
    $manager = User::query()->where('email', 'quanly@luatvukhang.com')->sole();

    expect(Task::query()->visibleTo($manager)->whereKey($secret->id)->exists())->toBeFalse();
});

it('leaves an open general task with the departed lawyer, so the needs-reassignment filter has a row', function () {
    $departed = User::query()->where('email', TeamPerformanceSeeder::DEPARTED_EMAIL)->sole();

    expect(Task::query()->open()->where('assignee_id', $departed->id)->exists())->toBeTrue();
});

it('does not seed twice and sends no mail', function () {
    $count = Task::query()->count();

    $this->seed(TaskSeeder::class);

    expect(Task::query()->count())->toBe($count);
    Mail::assertNotSent(TaskNotice::class);
});
