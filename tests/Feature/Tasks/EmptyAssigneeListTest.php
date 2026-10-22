<?php

use App\Actions\Task\TaskAssignees;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\TasksRelationManager;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

/**
 * Sửa 2026-10-21 — chủ văn phòng gửi ảnh: hộp thoại "Giao lại việc này cho người khác", ô "Giao cho" rỗng,
 * gõ "D" ra "Không có kết quả tìm kiếm phù hợp", không một lời giải thích. Nguyên nhân: việc của một hồ sơ chỉ
 * giao được cho người trong đội ngũ GIỮ VIỆC của hồ sơ (`TaskAssignees::eligible()`), mà đội ngũ đó chỉ còn người
 * giao và người đang giữ việc — danh sách đúng là rỗng (dữ liệu mẫu có bốn trường hợp như vậy). Luật giữ nguyên;
 * màn hình nay nói VÌ SAO rỗng và PHẢI LÀM GÌ, ngay dưới ô (câu gợi ý và câu lỗi khi bấm gửi), ở cả ba hộp thoại
 * dùng ô này: "Giao lại", "Giao việc" ở tab Công việc của hồ sơ, "Giao việc" ở trang Công việc.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Dũng']);
    $this->outsider = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Đào']);

    // Đội ngũ giữ việc: chỉ luật sư phụ trách (người giao) và một trợ lý (người đang giữ việc).
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);

    $this->task = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Soạn đơn khởi kiện',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->assistant->id,
        'due_date' => today()->subDays(4),
    ]);
});

it('explains why nobody can take the task, and what to do, when reassigning on a matter whose team has nobody else', function () {
    $this->actingAs($this->lead, 'web');

    expect(app(TaskAssignees::class)->options($this->matter, $this->lead, $this->task))->toBe([]);

    test()->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->callTableAction('reassign', $this->task, data: ['assignee_id' => null])
        ->assertHasTableActionErrors(['assignee_id' => app(TaskAssignees::class)->emptyListReason($this->matter, $this->lead)]);

    expect(app(TaskAssignees::class)->emptyListReason($this->matter, $this->lead))
        ->toBe(__('tasks.assign.empty_matter_can_manage', ['code' => $this->matter->code]))
        ->and($this->task->fresh())->assignee_id->toBe($this->assistant->id)
        ->status->toBe(TaskStatus::Pending);
});

it('reassigns as soon as someone is added to the matter team — the same dialog, no other change', function () {
    $this->actingAs($this->lead, 'web');
    $this->matter->addTeamMember($this->outsider, MatterRole::Assistant);

    test()->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->callTableAction('reassign', $this->task, data: ['assignee_id' => $this->outsider->id])
        ->assertHasNoTableActionErrors();

    expect($this->task->fresh()->assignee_id)->toBe($this->outsider->id);
});

it('explains the empty list on the matter tab "Giao việc" too', function () {
    $this->actingAs($this->lead, 'web');
    // Người duy nhất khác trong đội ngũ đã trả lại hồ sơ: không còn ai nhận được việc mới.
    $this->matter->team()->updateExistingPivot($this->assistant->id, ['declined_at' => now(), 'accepted_at' => null]);

    test()->livewire(TasksRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->callTableAction('assign', data: ['assignee_id' => null, 'title' => 'Nộp đơn'])
        ->assertHasTableActionErrors(['assignee_id' => __('tasks.assign.empty_matter_can_manage', ['code' => $this->matter->code])]);
});

it('tells someone who cannot change the matter team to ask the lead lawyer or a manager instead', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(app(TaskAssignees::class)->emptyListReason($this->matter, $accountant))
        ->toBe(__('tasks.assign.empty_matter_ask', ['code' => $this->matter->code]))
        ->and(app(TaskAssignees::class)->emptyListReason(null, $accountant))
        ->toBe(__('tasks.assign.empty_general'));
});

it('says plainly on a restricted matter that only the lead lawyer and an admin of the team can take its tasks', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);

    expect(app(TaskAssignees::class)->options($restricted, $this->lead))->toBe([])
        ->and(app(TaskAssignees::class)->emptyListReason($restricted, $this->lead))
        ->toBe(__('tasks.assign.empty_matter_restricted', ['code' => $restricted->code]));

    $this->actingAs($this->lead, 'web');

    test()->livewire(TasksRelationManager::class, ['ownerRecord' => $restricted, 'pageClass' => ViewMatter::class])
        ->callTableAction('assign', data: ['assignee_id' => null, 'title' => 'Soạn bản tự khai'])
        ->assertHasTableActionErrors(['assignee_id' => __('tasks.assign.empty_matter_restricted', ['code' => $restricted->code])]);
});
