<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Performance\BuildTaskWorkload;
use App\Actions\Schedule\RemindPendingWork;
use App\Actions\Task\ReassignTask;
use App\Enums\MatterRole;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Pages\TeamOverview;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Jobs\SendWorkReminder;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Notifications\Staff\TaskReminderAlert;
use App\Support\Tasks\AcceptanceClock;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn fa1, Task 1 — vòng sửa 1 (rà soát `review-t1.md`: I1, I2, m1–m12, và tham số `notifyFormerHolder` của
 * `ReassignTask` cho làn fa2). Mọi lần bấm qua trang (Livewire); lịch nhắc qua `RemindPendingWork::handle()`.
 *
 * Giờ làm việc mặc định: Thứ Hai–Thứ Sáu 08:00–17:30. 2026-10-12 là Thứ Hai.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    fr1Travel('2026-10-12 09:00');

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->otherManager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Dũng']);
    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Vũ']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Hà']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->otherAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);
    $this->accountant = User::factory()->withRole(Role::Accountant)->create(['name' => 'Kế toán Ngân']);
    $this->sales = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh doanh Hùng']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'FR1-01']);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->otherAssistant, MatterRole::Assistant);
});

function fr1Travel(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh'));
}

function fr1Mails(string $template, User $to): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', $template)
        ->where('recipient', $to->email)
        ->where('status', OutboundStatus::Sent)
        ->count();
}

/** @return list<FilamentNotification> */
function fr1Bells(User $user, string $title): array
{
    return $user->notifications()->where('type', TaskAlert::class)->get()
        ->map(fn ($row) => FilamentNotification::fromDatabase($row))
        ->filter(fn (FilamentNotification $bell): bool => $bell->getTitle() === $title)
        ->values()
        ->all();
}

/** Câu gợi ý (`helperText()` — nội dung "bên dưới" của ô, Filament 5) của `$field` có chứa `$text`. */
function fr1HelperHas(Field $field, string $text): bool
{
    return str_contains((string) $field->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->toHtml(), e($text));
}

/** Trợ lý Lan chuyển sang "người theo dõi" của vụ — vẫn xem được vụ, không còn giữ được việc. */
function fr1Observer(): void
{
    test()->matter->team()->detach(test()->assistant->id);
    test()->matter->addTeamMember(test()->assistant, MatterRole::Observer);
}

function fr1Task(TaskStatus $status, array $extra = [], ?Matter $matter = null): Task
{
    return Task::factory()->forMatter($matter ?? test()->matter)->status($status)->create([
        'title' => 'Nộp đơn khởi kiện',
        'assignee_id' => test()->assistant->id,
        'assigned_by' => test()->associate->id,
        ...$extra,
    ]);
}

/** Một vụ hạn chế của Luật sư Khang: trợ lý Lan còn ngồi ghế nhưng không mở được vụ. */
function fr1Secret(): Matter
{
    $secret = Matter::factory()->restricted()->create(['lead_lawyer_id' => test()->lead->id, 'code' => 'FR1-KIN-01', 'title' => 'Vụ kín']);
    $secret->addTeamMember(test()->assistant, MatterRole::Assistant);

    return $secret;
}

// =============================================================================================
// I1. NGƯỜI NHẬN KHÔNG CÒN GIỮ ĐƯỢC VIỆC: NGƯỜI GIAO ĐƯỢC NHẮC "SẮP ĐẾN HẠN" (VÀ "CHỜ NHẬN" SAU 24 GIỜ)
// =============================================================================================

/** Mutation probe: bỏ lời báo `DUE_SOON_ASSIGNER` khỏi lượt "sắp đến hạn" của `RemindPendingWork::handle()` → đỏ. */
it('tells the assigner, once per due date, that a task due tomorrow is held by someone who can no longer do it', function () {
    fr1Task(TaskStatus::Accepted, ['assigned_by' => $this->lead->id, 'due_date' => '2026-10-13']);
    fr1Observer();

    expect(app(RemindPendingWork::class)->handle()['queued'])->toBe(1);

    expect(fr1Mails('staff.task.reminder.due_soon_assigner', $this->lead))->toBe(1)
        ->and(fr1Mails('staff.task.reminder.due_soon', $this->assistant))->toBe(0)
        ->and($this->assistant->notifications()->count())->toBe(0);

    $bell = $this->lead->notifications()->where('type', TaskReminderAlert::class)->sole();
    expect($bell->data['title'])->toBe(__('tasks.reminder.alert.due_soon_assigner.title'))
        ->and($bell->data['body'])->toContain('Nộp đơn khởi kiện')->toContain('13/10/2026')->toContain('FR1-01')
        ->toContain(__('tasks.reminder_needs_reassignment', ['who' => 'Trợ lý Lan']));

    // Lượt sau cùng ngày: không nhắc lại cùng hạn.
    fr1Travel('2026-10-12 11:00');
    expect(app(RemindPendingWork::class)->handle()['queued'])->toBe(0);
});

/** Mutation probe: bỏ vế "tài khoản còn hoạt động" khỏi `RemindPendingWork::assigneeCanStillWork()` → đỏ. */
it('tells the assigner that a general task due today is held by a colleague whose account was switched off', function () {
    Task::factory()->status(TaskStatus::Accepted)->create([
        'title' => 'Cập nhật mẫu hợp đồng', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'due_date' => '2026-10-12',
    ]);
    $this->assistant->forceFill(['is_active' => false])->save();

    app(RemindPendingWork::class)->handle();

    expect(fr1Mails('staff.task.reminder.due_soon_assigner', $this->lead))->toBe(1)
        ->and($this->lead->notifications()->where('type', TaskReminderAlert::class)->sole()->data['body'])
        ->toContain('Cập nhật mẫu hợp đồng')->toContain(__('tasks.reminder_needs_reassignment', ['who' => 'Trợ lý Lan']));
});

/** Mutation probe: xếp `DUE_SOON_ASSIGNER` cả khi người nhận còn giữ ghế → đỏ. */
it('keeps "due soon" with the holder, and away from the assigner, while the holder still holds the task', function () {
    fr1Task(TaskStatus::Accepted, ['assigned_by' => $this->lead->id, 'due_date' => '2026-10-13']);

    app(RemindPendingWork::class)->handle();

    expect(fr1Mails('staff.task.reminder.due_soon', $this->assistant))->toBe(1)
        ->and(fr1Mails('staff.task.reminder.due_soon_assigner', $this->lead))->toBe(0);
});

/** Mutation probe: bỏ vế "người nhận vẫn không giữ được việc" khỏi `RemindPendingWork::taskReminderHolds()` → đỏ. */
it('drops, at sending time, the assigner notice when the holder holds the task again', function () {
    $task = fr1Task(TaskStatus::Accepted, ['assigned_by' => $this->lead->id, 'due_date' => '2026-10-13']);
    fr1Observer();
    $job = new SendWorkReminder(RemindPendingWork::SUBJECT_TASK, $task->id, RemindPendingWork::DUE_SOON_ASSIGNER, $this->lead->id, RemindPendingWork::dueSoonKey($task));

    // Trước khi job chạy, trợ lý được xếp lại vào ghế trợ lý.
    $this->matter->team()->detach($this->assistant->id);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);

    $job->handle(app(ResolveStaffRecipients::class));

    expect($this->lead->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(0)
        ->and(fr1Mails('staff.task.reminder.due_soon_assigner', $this->lead))->toBe(0);
});

/** Mutation probe: bỏ vế "người nhận không giữ được việc" của lời báo người giao sau 24 giờ → đỏ (đợi tới 48 giờ). */
it('tells the assigner after 24 working hours, not 48, when a task waits for someone who can no longer accept it', function () {
    $task = fr1Task(TaskStatus::Pending, ['assigned_by' => $this->lead->id, 'assigned_at' => now()]);
    fr1Observer();

    fr1Travel('2026-10-14 16:00');
    $clock = AcceptanceClock::fromConfig();
    expect($clock->reached($task->assigned_at, AcceptanceClock::REMIND_AFTER_HOURS))->toBeTrue()
        ->and($clock->reached($task->assigned_at, AcceptanceClock::ESCALATE_AFTER_HOURS))->toBeFalse();

    app(RemindPendingWork::class)->handle();

    expect(fr1Mails('staff.task.reminder.unaccepted', $this->lead))->toBe(1)
        ->and(fr1Mails('staff.task.reminder.awaiting', $this->assistant))->toBe(0);
    expect($this->lead->notifications()->where('type', TaskReminderAlert::class)->sole()->data['body'])
        ->toContain(__('tasks.reminder_needs_reassignment', ['who' => 'Trợ lý Lan']));

    // Người nhận còn giữ được việc: người giao vẫn chỉ được báo ở mốc 48 giờ.
    $held = fr1Task(TaskStatus::Pending, ['title' => 'Việc còn người giữ', 'assignee_id' => $this->otherAssistant->id, 'assigned_by' => $this->lead->id, 'assigned_at' => CarbonImmutable::parse('2026-10-12 09:00', 'Asia/Ho_Chi_Minh')]);
    app(RemindPendingWork::class)->handle();
    expect(fr1Mails('staff.task.reminder.awaiting', $this->otherAssistant))->toBe(1)
        ->and(fr1Mails('staff.task.reminder.unaccepted', $this->lead))->toBe(1)
        ->and($held->fresh()->status)->toBe(TaskStatus::Pending);
});

// =============================================================================================
// I2. SỐ VIỆC BÊN TÊN NGƯỜI NHẬN NÓI ĐÚNG PHẠM VI
// =============================================================================================

it('says next to each name that the workload counts only the tasks the assigner can see', function () {
    fr1Task(TaskStatus::Accepted, ['assigned_by' => $this->lead->id]);

    $this->actingAs($this->lead, 'web');
    // Câu gợi ý của ô "Giao cho" nói phạm vi của con số.
    $this->livewire(ListTasks::class)->mountAction('create')
        ->assertFormFieldExists('assignee_id', function (Select $field): bool {
            $options = $field->getOptions();

            return str_contains($options[test()->assistant->id], __('tasks.assign.workload', ['held' => 1, 'overdue' => 0]))
                && str_contains($options[test()->otherAssistant->id], __('tasks.assign.workload_none'))
                && fr1HelperHas($field, __('tasks.assign.workload_scope'));
        });

    expect(__('tasks.assign.workload', ['held' => 1, 'overdue' => 0]))->toContain('anh/chị thấy được')
        ->and(__('tasks.assign.workload_none'))->toContain('anh/chị thấy được')
        ->and(__('tasks.assign.workload_scope'))->not->toBe('tasks.assign.workload_scope');

    // "Giao lại" bày cùng nhãn — cùng câu gợi ý.
    $task = fr1Task(TaskStatus::Accepted, ['assigned_by' => $this->lead->id, 'title' => 'Việc giao lại']);
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->mountAction(TestAction::make('reassign')->table($task))
        ->assertFormFieldExists('assignee_id', fn (Select $field): bool => fr1HelperHas($field, __('tasks.assign.workload_scope')));
});

// =============================================================================================
// m1. KHÔI PHỤC VIỆC BỊ HUỶ KHI ĐANG "BỊ TRẢ LẠI"
// =============================================================================================

/** Mutation probe: bỏ nhánh "huỷ từ Bị trả lại" của `RestoreTask` → đỏ (việc về "Chờ nhận" cho người đã trả lại). */
it('restores a task cancelled while returned back to "Bị trả lại", not onto the person who returned it', function () {
    $task = fr1Task(TaskStatus::Declined, ['decline_reason' => 'Quá tải tuần này']);

    $this->actingAs($this->associate, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->callTableAction('cancel', $task, data: ['reason' => 'Để giao người khác sau'])
        ->assertHasNoTableActionErrors();

    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('restore', $task->fresh(), data: ['reason' => 'Huỷ nhầm'])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('tasks.restore.success_declined'));

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::Declined)
        ->and($fresh->assignee_id)->toBe($this->assistant->id)
        ->and($fresh->decline_reason)->toBe('Quá tải tuần này')
        ->and($fresh->cancelled_at)->toBeNull()
        ->and($fresh->cancel_reason)->toBeNull();

    expect(Activity::query()->where('event', 'task_restored')->sole()->properties['to'])->toBe('declined')
        ->and(fr1Bells($this->assistant, __('tasks.notice.restored.title')))->toBe([])
        ->and(fr1Mails('staff.task.restored', $this->assistant))->toBe(0);

    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])->assertCanSeeTableRecords([$fresh]);
});

// =============================================================================================
// m2. MỞ LẠI CHO NGƯỜI KHÔNG CÒN GIỮ ĐƯỢC VIỆC
// =============================================================================================

/** Mutation probe: bỏ lần hỏi `qualifies()` của `ReopenTask` (luôn báo người giữ) → đỏ. */
it('reopens for a holder who has since become an observer as "Cần giao lại", telling them nothing, and says so', function () {
    $task = fr1Task(TaskStatus::Completed, ['completed_at' => now()->subHours(2)]);
    fr1Observer();

    $this->actingAs($this->associate, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->callTableAction('reopen', $task, data: ['reason' => 'Thiếu chữ ký'])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('tasks.reopen.success_needs_reassignment'));

    expect($task->fresh()->status)->toBe(TaskStatus::Accepted)
        ->and(Task::query()->whereKey($task->id)->needsReassignment()->exists())->toBeTrue()
        ->and(fr1Bells($this->assistant, __('tasks.notice.reopened.title')))->toBe([])
        ->and(fr1Mails('staff.task.reopened', $this->assistant))->toBe(0)
        ->and(Activity::query()->where('event', 'task_reopened')->sole()->properties['needs_reassignment'])->toBeTrue();

    // Câu của hộp thoại không hứa "họ được báo" vô điều kiện.
    expect(__('tasks.reopen.description'))->toContain('Cần giao lại')
        ->and(__('tasks.reopen.success_needs_reassignment'))->not->toContain('được báo');
});

// =============================================================================================
// m3. DÒNG MÔ TẢ ĐẦU TRANG CHỈ NÓI "HỒ SƠ CHỜ NHẬN Ở VIỆC CỦA TÔI" VỚI NGƯỜI NHẬN HỒ SƠ Ở ĐÓ
// =============================================================================================

/** Mutation probe: luôn in câu đầy đủ → đỏ với quản trị viên, kế toán, kinh doanh. */
it('mentions matters waiting in "Việc của tôi" in the page line only to people who receive them there', function (string $who, bool $myWork) {
    $this->actingAs($this->{$who}, 'web');

    $page = $this->livewire(ListTasks::class, ['activeTab' => 'all']);

    $myWork
        ? $page->assertSee(__('tasks.page.subheading'))
        : $page->assertSee(__('tasks.page.subheading_short'))->assertDontSee(__('tasks.page.subheading'));
})->with([
    'trợ lý' => ['assistant', true],
    'luật sư' => ['lead', true],
    'trưởng phòng' => ['manager', true],
    'quản trị viên' => ['admin', false],
    'kế toán' => ['accountant', false],
    'kinh doanh' => ['sales', false],
]);

// =============================================================================================
// m5. TIN "ĐÃ NHẬN / ĐÃ HOÀN THÀNH" KHÔNG RƠI TỚI MỌI TRƯỞNG PHÒNG
// =============================================================================================

/** Mutation probe: cho ba loại tin rơi tới `supervisorsFor()` như loại cần người quyết định → đỏ. */
it('does not send "accepted" or "completed" of a task the lead holds to every manager once its assigner has gone', function () {
    $task = fr1Task(TaskStatus::Pending, ['assignee_id' => $this->lead->id, 'title' => 'Soạn bản luận cứ']);
    $returned = fr1Task(TaskStatus::Pending, ['assignee_id' => $this->lead->id, 'title' => 'Việc trả lại']);
    $this->matter->team()->detach($this->associate->id);
    $this->associate->forceFill(['is_active' => false])->save();

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'awaiting'])
        ->callTableAction('accept', $task)
        ->assertHasNoTableActionErrors();
    $this->livewire(ListTasks::class, ['activeTab' => 'active'])
        ->callTableAction('complete', $task->fresh(), data: ['note' => 'Đã xong'])
        ->assertHasNoTableActionErrors();

    foreach ([$this->manager, $this->otherManager, $this->admin] as $who) {
        expect(fr1Bells($who, __('tasks.notice.accepted_on_behalf.title')))->toBe([])
            ->and(fr1Bells($who, __('tasks.notice.completed_on_behalf.title')))->toBe([])
            ->and(fr1Mails('staff.task.accepted', $who))->toBe(0)
            ->and(fr1Mails('staff.task.completed', $who))->toBe(0);
    }

    // Lời báo cần một người quyết định ("bị trả lại") vẫn tới người quản lý của vụ.
    $this->livewire(ListTasks::class, ['activeTab' => 'awaiting'])
        ->callTableAction('decline', $returned, data: ['reason' => 'Xung đột lợi ích'])
        ->assertHasNoTableActionErrors();

    expect(fr1Bells($this->manager, __('tasks.alert.declined_on_behalf.title')))->toHaveCount(1);
});

// =============================================================================================
// m6. NGƯỜI ĐÃ XOÁ MỀM KHÔNG "GIỮ" VIỆC NÀO
// =============================================================================================

/** Mutation probe: bỏ vế `users.deleted_at` khỏi `Task::scopeHeldBySeatedAssignee()` → đỏ. */
it('leaves the task of a soft-deleted holder out of "Chỉ việc quá hạn", as holdsSeat does', function () {
    $gone = fr1Task(TaskStatus::Accepted, ['due_date' => '2026-10-09']);
    $kept = fr1Task(TaskStatus::Accepted, ['title' => 'Việc còn người giữ', 'assignee_id' => $this->otherAssistant->id, 'due_date' => '2026-10-09']);
    $this->assistant->delete();

    $this->actingAs($this->admin, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->filterTable('overdue', true)
        ->assertCanSeeTableRecords([$kept])
        ->assertCanNotSeeTableRecords([$gone]);

    expect(Task::query()->whereKey($gone->id)->heldBySeatedAssignee()->exists())->toBeFalse();
});

// =============================================================================================
// m7. NGƯỜI GIAO GỐC ĐƯỢC BÁO KHI VIỆC CỦA HỌ ĐƯỢC KHÔI PHỤC
// =============================================================================================

/** Mutation probe: bỏ lời báo `RESTORED_BY_OTHER` trong `RestoreTask` → đỏ. */
it('tells the original assigner by bell when the lead restores the task a manager cancelled, and nobody else', function () {
    $task = fr1Task(TaskStatus::Accepted, ['title' => 'Liên hệ nhân chứng']);

    $this->actingAs($this->manager, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('cancel', $task, data: ['reason' => 'Khách rút yêu cầu'])
        ->assertHasNoTableActionErrors();
    expect(fr1Bells($this->associate, __('tasks.notice.cancelled_by_other.title')))->toHaveCount(1);

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('restore', $task->fresh(), data: ['reason' => 'Khách vẫn cần'])
        ->assertHasNoTableActionErrors();

    $bells = fr1Bells($this->associate, __('tasks.notice.restored_by_other.title'));
    expect($bells)->toHaveCount(1)
        ->and($bells[0]->getBody())->toContain('Luật sư Khang')->toContain('Khách vẫn cần')->toContain('Liên hệ nhân chứng')->toContain('FR1-01')
        ->and(fr1Bells($this->lead, __('tasks.notice.restored_by_other.title')))->toBe([])
        ->and(fr1Bells($this->manager, __('tasks.notice.restored_by_other.title')))->toBe([]);

    // Chính người giao huỷ rồi khôi phục: không tự báo mình.
    $own = fr1Task(TaskStatus::Accepted, ['title' => 'Việc tự huỷ']);
    $this->actingAs($this->associate, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->callTableAction('cancel', $own, data: ['reason' => 'Bấm nhầm'])
        ->assertHasNoTableActionErrors();
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('restore', $own->fresh(), data: ['reason' => 'Huỷ nhầm'])
        ->assertHasNoTableActionErrors();

    expect(fr1Bells($this->associate, __('tasks.notice.restored_by_other.title')))->toHaveCount(1);
});

// =============================================================================================
// m8. Ô "VỤ VIỆC" HỎI CỔNG MỘT LẦN MỖI VỤ MỖI REQUEST
// =============================================================================================

/** Mutation probe: bỏ ghi nhớ của `ListTasks::assignableMatters()` → đỏ (hai, ba lần mỗi vụ). */
it('asks the "may assign on this matter" gate once per matter per request in the "Giao việc" dialog', function () {
    Matter::factory()->count(2)->create();
    $open = Matter::query()->open()->count();

    // Mỗi request Livewire dựng một bản ListTasks mới: đếm số lần hỏi cổng theo TỪNG bản (từng request).
    $checks = [];
    Gate::after(function ($user, string $ability) use (&$checks): void {
        if ($ability !== 'assignOnMatter') {
            return;
        }

        $page = collect(debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 80))
            ->first(fn (array $frame): bool => ($frame['object'] ?? null) instanceof ListTasks)['object'] ?? null;
        $key = $page === null ? 'khác' : (string) spl_object_id($page);
        $checks[$key] = ($checks[$key] ?? 0) + 1;
    });

    $this->actingAs($this->manager, 'web');
    $page = $this->livewire(ListTasks::class);

    $checks = [];
    $page->mountAction('create')->setActionData(['matter_id' => $this->matter->id]);

    expect($checks)->not->toBeEmpty()
        ->and(array_unique(array_values($checks)))->toBe([$open]);
});

// =============================================================================================
// m10. THEO DÕI ĐỘI NGŨ: MỘT ĐỊNH NGHĨA "ĐANG GIỮ" CHO BA CỘT
// =============================================================================================

/** Mutation probe: bỏ `heldBySeatedAssignee()` khỏi cột "Việc chờ nhận" của `BuildTaskWorkload` → đỏ. */
it('counts on Theo dõi đội ngũ neither waiting, in progress nor overdue the tasks of someone who no longer holds them', function () {
    fr1Task(TaskStatus::Pending);
    fr1Task(TaskStatus::Accepted);
    fr1Task(TaskStatus::Accepted, ['due_date' => '2026-10-09']);
    fr1Task(TaskStatus::Pending, ['assignee_id' => $this->otherAssistant->id]);
    fr1Observer();

    $this->actingAs($this->manager, 'web');
    $rows = Livewire::test(TeamOverview::class)->instance()->getTableRecords();

    expect([$rows[$this->assistant->id]['tasksInProgress'], $rows[$this->assistant->id]['tasksAwaiting'], $rows[$this->assistant->id]['tasksOverdue']])
        ->toBe([0, 0, 0])
        ->and($rows[$this->otherAssistant->id]['tasksAwaiting'])->toBe(1)
        ->and(app(BuildTaskWorkload::class)->needsReassignment($this->manager))->toBe(3);
});

// =============================================================================================
// m11. VỤ HẠN CHẾ: LỜI BÁO KHÔNG TỚI NGƯỜI KHÔNG MỞ ĐƯỢC VỤ; NÚT ẨN VỚI TRƯỞNG PHÒNG
// =============================================================================================

/** Mutation probe: cho REASSIGNED_BY_OTHER / CANCELLED_BY_OTHER đi qua `forFormerTaskHolder()` (không cổng xem) → đỏ. */
it('tells an original assigner who cannot open a restricted matter nothing when its task is reassigned or cancelled', function () {
    $secret = fr1Secret();
    $moved = fr1Task(TaskStatus::Accepted, ['title' => 'Việc kín một'], $secret);
    $dropped = fr1Task(TaskStatus::Accepted, ['title' => 'Việc kín hai'], $secret);

    $this->actingAs($this->admin, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('reassign', $moved, data: ['assignee_id' => $this->lead->id, 'due_date' => null])
        ->assertHasNoTableActionErrors()
        ->callTableAction('cancel', $dropped, data: ['reason' => 'Không còn cần'])
        ->assertHasNoTableActionErrors();

    expect($moved->fresh()->assignee_id)->toBe($this->lead->id)
        ->and($this->associate->notifications()->count())->toBe(0);

    // Người vừa mất việc nhận chuông chung, không mã vụ, không tiêu đề.
    $away = fr1Bells($this->assistant, __('tasks.alert.reassigned_away.title'));
    expect($away)->toHaveCount(1)
        ->and($away[0]->getBody())->not->toContain('FR1-KIN-01')->not->toContain('Việc kín');
});

/** Mutation probe: cho REOPENED_BY_HOLDER đi qua `forFormerTaskHolder()` (không cổng xem) → đỏ. */
it('tells nobody who cannot open a restricted matter that its lead reopened a task they had completed', function () {
    $secret = fr1Secret();
    $task = fr1Task(TaskStatus::Completed, ['assignee_id' => $this->lead->id, 'completed_at' => now()->subHours(2), 'title' => 'Việc kín ba'], $secret);

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('reopen', $task, data: ['reason' => ''])
        ->assertHasNoTableActionErrors();

    expect($task->fresh()->status)->toBe(TaskStatus::Accepted);

    foreach ([$this->associate, $this->manager, $this->otherManager, $this->admin] as $who) {
        expect($who->notifications()->count())->toBe(0);
    }
});

/** Mutation probe: bỏ REOPENED_BY_HOLDER khỏi `TaskAlert::ON_BEHALF_KINDS` → đỏ (luật sư phụ trách không nhận thay). */
it('sends "reopened by the holder" to the lead on behalf of an assigner who has left', function () {
    $task = fr1Task(TaskStatus::Completed, ['completed_at' => now()->subHours(2), 'title' => 'Sao y hồ sơ']);
    $this->matter->team()->detach($this->associate->id);
    $this->associate->forceFill(['is_active' => false])->save();

    $this->actingAs($this->assistant, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('reopen', $task, data: ['reason' => ''])
        ->assertHasNoTableActionErrors();

    $bells = fr1Bells($this->lead, __('tasks.notice.reopened_by_holder_on_behalf.title'));
    expect($bells)->toHaveCount(1)
        ->and($bells[0]->getBody())->toContain('Trợ lý Lan')->toContain('Luật sư Hà')->toContain('Sao y hồ sơ');
});

/** Mutation probe: bỏ cổng `view` khỏi `TaskPolicy::restoreCancelled()` hay khỏi `manages()` → đỏ. */
it('hides "Mở lại" and "Khôi phục" from a manager on a restricted matter they cannot open', function () {
    $secret = fr1Secret();
    $done = fr1Task(TaskStatus::Completed, ['assignee_id' => $this->lead->id, 'assigned_by' => $this->admin->id, 'completed_at' => now()->subHours(2)], $secret);
    $cancelled = fr1Task(TaskStatus::Cancelled, ['assignee_id' => $this->lead->id, 'assigned_by' => $this->admin->id], $secret);

    $this->actingAs($this->manager, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])->assertCanNotSeeTableRecords([$done, $cancelled]);

    expect(Gate::forUser($this->manager)->denies('reopen', $done))->toBeTrue()
        ->and(Gate::forUser($this->manager)->denies('restoreCancelled', $cancelled))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('reopen', $done))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('restoreCancelled', $cancelled))->toBeTrue();
});

// =============================================================================================
// m9. "KHÔI PHỤC" LÀ CỔNG RIÊNG, KHÔNG TRÙNG `restore` CỦA XOÁ MỀM
// =============================================================================================

it('keeps "Khôi phục" a task ability of its own, not the soft-delete "restore" ability', function () {
    $task = fr1Task(TaskStatus::Cancelled);

    expect(Gate::forUser($this->manager)->allows('restoreCancelled', $task))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('restore', $task))->toBeFalse();
});

// =============================================================================================
// LÀN fa2: GIAO LẠI HÀNG LOẠT KHÔNG BÁO TỪNG VIỆC CHO NGƯỜI CŨ
// =============================================================================================

/**
 * `ReassignTask::handle(notifyFormerHolder: false)` là tham số cho người gọi hàng loạt (làn fa2 — gỡ khỏi đội ngũ,
 * nghỉ việc: một chuông tổng hợp riêng). Tham số của Action, không phải hành vi màn hình — gọi thẳng Action.
 *
 * Mutation probe: bỏ qua `$notifyFormerHolder` (luôn báo) → đỏ ở `false`; luôn bỏ → đỏ ở `true`.
 */
it('skips only the "given to someone else" bell to the former holder when asked to', function (bool $notify) {
    $task = fr1Task(TaskStatus::Accepted, ['title' => 'Việc chuyển tay']);

    app(ReassignTask::class)->handle($task, $this->lead, $this->otherAssistant, notifyFormerHolder: $notify);

    expect(fr1Bells($this->assistant, __('tasks.alert.reassigned_away.title')))->toHaveCount($notify ? 1 : 0)
        ->and(fr1Bells($this->otherAssistant, __('tasks.alert.assigned.title')))->toHaveCount(1)
        ->and(fr1Bells($this->associate, __('tasks.notice.reassigned_by_other.title')))->toHaveCount(1);
})->with(['báo người cũ' => true, 'không báo người cũ' => false]);
