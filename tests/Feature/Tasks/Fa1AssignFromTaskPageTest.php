<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Mail\Staff\TaskNotice;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Mail;

/**
 * Làn fa1, phán quyết 3, 4 và phần trang của phán quyết 7 — nút "Giao việc" đầu trang "Công việc":
 *
 *  - mọi nhân sự có `matter.view` (luật sư, trợ lý, trưởng phòng, quản trị viên) giao được việc chung cho bất kỳ
 *    nhân sự đang hoạt động; kế toán, kinh doanh thì không;
 *  - ô "Vụ việc" (để trống = việc chung) bày đúng các vụ người giao được giao việc; người nhận đổi theo vụ;
 *  - ô người nhận ghi số việc đang giữ / quá hạn của mỗi người, CHỈ đếm việc người giao xem được;
 *  - chống bấm trùng theo mã một lần của hộp thoại, không theo tiêu đề;
 *  - dòng mô tả đầu trang, và câu rỗng của "Chờ tôi nhận" chỉ chỗ nhận hồ sơ.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $this->travelTo('2026-10-16 09:00:00');

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Vũ']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Quản lý Minh']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->otherAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);
    $this->accountant = User::factory()->withRole(Role::Accountant)->create(['name' => 'Kế toán Ngân']);
    $this->sales = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh doanh Hùng']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'FA1-GV-01', 'title' => 'Tranh chấp đất đai']);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);

    $this->otherMatter = Matter::factory()->create(['code' => 'FA1-GV-02']);
    $this->closedMatter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'FA1-GV-03', 'closed_at' => now()]);
    $this->secretLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Kín']);
    $this->secret = Matter::factory()->restricted()->create(['code' => 'FA1-KIN-01', 'lead_lawyer_id' => $this->secretLead->id]);
});

function fa1AssigneeOptions($page): array
{
    $options = null;

    $page->assertFormFieldExists('assignee_id', function (Select $field) use (&$options): bool {
        $options = $field->getOptions();

        return true;
    });

    return $options;
}

// =============================================================================================
// 3. AI GIAO ĐƯỢC VIỆC CHUNG
// =============================================================================================

/** Mutation probe: đưa `TaskPolicy::create` về "chỉ quản lý/quản trị" → đỏ. */
it('lets a lawyer give a general task to any active colleague from the "Giao việc" button, and records them as the assigner', function () {
    $this->actingAs($this->lead, 'web');

    $this->livewire(ListTasks::class)
        ->assertActionVisible('create')
        ->callAction('create', data: [
            'assignee_id' => $this->otherAssistant->id,
            'title' => 'Cập nhật mẫu hợp đồng dịch vụ',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified(__('tasks.actions.assign_success'));

    $task = Task::query()->where('title', 'Cập nhật mẫu hợp đồng dịch vụ')->sole();
    expect($task->matter_id)->toBeNull()
        ->and($task->assigned_by)->toBe($this->lead->id)
        ->and($task->assignee_id)->toBe($this->otherAssistant->id);

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->otherAssistant->email));

    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])->assertCanSeeTableRecords([$task]);
});

it('lets an assistant give a general task too, but not the accountant or a sales colleague', function () {
    $this->actingAs($this->assistant, 'web');
    $this->livewire(ListTasks::class)
        ->callAction('create', data: ['assignee_id' => $this->accountant->id, 'title' => 'Gửi bảng kê chi phí'])
        ->assertHasNoActionErrors();
    expect(Task::query()->where('title', 'Gửi bảng kê chi phí')->sole()->assigned_by)->toBe($this->assistant->id);

    foreach ([$this->accountant, $this->sales] as $who) {
        $this->actingAs($who, 'web');
        $this->livewire(ListTasks::class)->assertActionHidden('create');
    }
});

// =============================================================================================
// 3. GIAO VIỆC TRONG MỘT VỤ TỪ TRANG CÔNG VIỆC
// =============================================================================================

/** Mutation probe: bày mọi vụ người xem mở được (không qua `assignOnMatter`) → đỏ (vụ đã kết thúc, vụ của người khác). */
it('offers in the matter field only the open matters the person may assign on, and then only that matter\'s team', function () {
    $this->actingAs($this->lead, 'web');

    $page = $this->livewire(ListTasks::class)->mountAction('create');

    $page->assertFormFieldExists('matter_id', function (Select $field): bool {
        $options = $field->getOptions();

        return array_keys($options) === [$this->matter->id]
            && str_contains($options[$this->matter->id], 'FA1-GV-01');
    });

    $page->setActionData(['matter_id' => $this->matter->id]);
    expect(array_keys(fa1AssigneeOptions($page)))->toBe([$this->assistant->id]);

    $page->setActionData(['matter_id' => $this->matter->id, 'assignee_id' => $this->assistant->id, 'title' => 'Soạn ghi nhớ trước buổi tư vấn'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $task = Task::query()->where('title', 'Soạn ghi nhớ trước buổi tư vấn')->sole();
    expect($task->matter_id)->toBe($this->matter->id)->and($task->assigned_by)->toBe($this->lead->id);
});

it('refuses a matter id the person may not assign on, even when forced into the form', function () {
    $this->actingAs($this->lead, 'web');

    $this->livewire(ListTasks::class)
        ->callAction('create', data: ['matter_id' => $this->secret->id, 'assignee_id' => $this->secretLead->id, 'title' => 'Dò vụ kín'])
        ->assertHasActionErrors(['matter_id']);

    expect(Task::query()->where('title', 'Dò vụ kín')->exists())->toBeFalse();
});

// =============================================================================================
// 3. KHỐI LƯỢNG VIỆC TRÊN Ô NGƯỜI NHẬN
// =============================================================================================

/** Mutation probe: đếm trên mọi việc (bỏ `visibleTo`) → đỏ (con số lộ việc của vụ kín). */
it('shows how many tasks each colleague holds and how many are overdue, counting only tasks the assigner can see', function () {
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'assigned_by' => $this->lead->id, 'assignee_id' => $this->assistant->id, 'due_date' => '2026-10-10',
    ]);
    Task::factory()->forMatter($this->matter)->create([
        'assigned_by' => $this->lead->id, 'assignee_id' => $this->assistant->id, 'due_date' => '2026-10-30',
    ]);
    // Hai việc của vụ kín: luật sư Khang không xem được — không vào con số của Luật sư Kín.
    Task::factory()->forMatter($this->secret)->count(2)->create([
        'assigned_by' => $this->admin->id, 'assignee_id' => $this->secretLead->id, 'due_date' => '2026-10-01',
    ]);

    $this->actingAs($this->lead, 'web');
    $options = fa1AssigneeOptions($this->livewire(ListTasks::class)->mountAction('create'));

    expect($options[$this->assistant->id])->toContain('Trợ lý Lan')
        ->toContain(__('tasks.assign.workload', ['held' => 2, 'overdue' => 1]))
        ->and($options[$this->secretLead->id])->toContain('Luật sư Kín')
        ->toContain(__('tasks.assign.workload_none'));

    $this->actingAs($this->admin, 'web');
    $options = fa1AssigneeOptions($this->livewire(ListTasks::class)->mountAction('create'));
    expect($options[$this->secretLead->id])->toContain(__('tasks.assign.workload', ['held' => 2, 'overdue' => 2]));
});

// =============================================================================================
// 4. CHỐNG BẤM TRÙNG THEO HỘP THOẠI, KHÔNG THEO TIÊU ĐỀ
// =============================================================================================

/** Mutation probe: quay về khoá chống trùng theo tiêu đề → đỏ (lần giao thứ hai cùng tiêu đề biến mất). */
it('creates two tasks for two real assignments with the same title within a minute', function () {
    $this->actingAs($this->lead, 'web');

    foreach (['2026-10-20', '2026-10-18'] as $due) {
        $this->livewire(ListTasks::class)
            ->callAction('create', data: ['assignee_id' => $this->otherAssistant->id, 'title' => 'Photo hồ sơ', 'due_date' => $due])
            ->assertHasNoActionErrors();
    }

    expect(Task::query()->where('title', 'Photo hồ sơ')->pluck('due_date')->map->toDateString()->sort()->values()->all())
        ->toBe(['2026-10-18', '2026-10-20']);
});

/** Mutation probe: bỏ khoá `submission_key` → đỏ (hai việc). */
it('creates one task when the same dialog is submitted twice', function () {
    $this->actingAs($this->lead, 'web');

    $page = $this->livewire(ListTasks::class)->mountAction('create');
    $key = $page->instance()->mountedActions[0]['data']['submission_key'] ?? null;
    expect($key)->toBeString()->not->toBe('');

    $data = ['assignee_id' => $this->otherAssistant->id, 'title' => 'Soạn công văn', 'submission_key' => $key];
    $page->setActionData($data)->callMountedAction()->assertHasNoActionErrors();
    $this->livewire(ListTasks::class)->callAction('create', data: $data)->assertHasNoActionErrors();

    expect(Task::query()->where('title', 'Soạn công văn')->count())->toBe(1);
    Mail::assertSent(TaskNotice::class, 1);
});

// =============================================================================================
// 7. DÒNG MÔ TẢ ĐẦU TRANG, CÂU RỖNG CỦA "CHỜ TÔI NHẬN"
// =============================================================================================

it('says at the top of the page what it holds, and points an empty "Chờ tôi nhận" to the matters waiting for acceptance', function () {
    $this->actingAs($this->assistant, 'web');

    $this->livewire(ListTasks::class, ['activeTab' => 'awaiting'])
        ->assertSee(__('tasks.page.subheading'))
        ->assertDontSee(__('tasks.page.awaiting_empty_seats', ['count' => 1, 'where' => __('tasks.seat.where.my_work')]));

    $waiting = Matter::factory()->create(['code' => 'FA1-GV-09']);
    $waiting->addTeamMember($this->assistant, MatterRole::Assistant, $this->lead);

    $this->livewire(ListTasks::class, ['activeTab' => 'awaiting'])
        ->assertSee(__('tasks.page.awaiting_empty_seats', ['count' => 1, 'where' => __('tasks.seat.where.my_work')]));
});
