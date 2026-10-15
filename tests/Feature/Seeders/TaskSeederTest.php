<?php

use App\Actions\Matter\AddTeamMember;
use App\Actions\Performance\BuildTaskPerformance;
use App\Actions\Task\TaskAssignees;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\TaskStatus;
use App\Mail\Staff\TaskNotice;
use App\Models\MatterUser;
use App\Models\Task;
use App\Models\User;
use App\Support\Performance\PerformancePeriod;
use App\Support\Performance\TeamRoster;
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

/**
 * Làn tk, Task 2 — "Nhận hồ sơ" và "Việc hoàn thành đúng hạn" có dòng để xem ngay trên bản demo: một hồ sơ
 * chờ nhận quá lâu, một hồ sơ bị trả lại kèm lý do, và số của tháng trước đủ ngưỡng để tỉ lệ hiện ra.
 */
it('seeds a seat waiting too long and a returned seat, both on matters the member can open', function () {
    // Ngoài ghế này còn ghế luật sư phụ trách của các lần bàn giao mẫu (`TeamPerformanceSeeder` gọi
    // `ReassignMatter`) — cũng chờ người nhận bấm "Đã nhận hồ sơ", đúng như ngoài đời.
    $waiting = MatterUser::query()->awaitingAcceptance()->where('assigned_at', '<', now()->subDays(2))->with(['matter', 'user'])->get();
    $returned = MatterUser::query()->returned()->with(['matter', 'user'])->get();

    expect($waiting)->toHaveCount(1)
        ->and($waiting[0]->role_in_matter)->toBe(MatterRole::Associate)
        ->and($returned)->toHaveCount(1)
        ->and($returned[0]->decline_reason)->not->toBeEmpty();

    foreach ([...$waiting, ...$returned] as $seat) {
        expect(Gate::forUser($seat->user)->allows('view', $seat->matter))->toBeTrue()
            ->and(AddTeamMember::eligibleForRole($seat->user, $seat->role_in_matter))->toBeTrue();
    }
});

it('gives last month a measurable "Việc hoàn thành đúng hạn" and two hand-backs for one assistant', function () {
    $manager = User::query()->where('email', 'quanly@luatvukhang.com')->sole();
    $period = PerformancePeriod::fromFilters(['period' => 'last_month']);
    $subjects = TeamRoster::subjectsForPeriod($manager, $period, false);

    $report = app(BuildTaskPerformance::class)->handle($manager, $subjects, $period);

    $measured = collect($report['rows'])->filter(fn (array $row): bool => $row['taskOnTime']->isMeasurable());

    expect($measured)->toHaveCount(1)
        ->and($measured->first()['taskOnTime']->label())->toBe('80% (4/5)')
        ->and($measured->first()['tasksDeclined'])->toBe(2);
});
