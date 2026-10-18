<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Pages\BulkReassign;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\Mail;

/**
 * Làn fa2, phán quyết 3 — "Bàn giao hàng loạt" mở từ màn hình cho nghỉ việc (`?from=`) mặc định KHÔNG giữ
 * luật sư sắp rời trong đội ngũ, để việc nội bộ của họ đi theo hồ sơ; mở thường thì công tắc vẫn bật sẵn,
 * và câu gợi ý (cả nút "Bàn giao" một vụ) nói rõ: giữ làm cộng sự thì việc nội bộ người đó đang giữ KHÔNG
 * chuyển. Mọi đường đi qua Livewire.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->oldLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Sắp Nghỉ']);
    $this->newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Nhận']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id]);
    $this->task = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Soạn đơn khởi kiện',
        'assignee_id' => $this->oldLead->id,
        'assigned_by' => $this->admin->id,
    ]);
});

it('starts with "keep the old lead" off when opened from the offboarding link, so their tasks follow the matter', function () {
    $this->actingAs($this->admin, 'web');

    $page = $this->livewire(BulkReassign::class, ['from' => $this->oldLead->id])
        ->assertSet('data.keep_old_lead_as_associate', false)
        ->set('data.matter_ids', [$this->matter->id])
        ->set('data.new_lead_id', $this->newLead->id)
        ->set('data.reason', 'Luật sư nghỉ việc, bàn giao hết.')
        ->call('reassignSelected')
        ->assertHasNoErrors();

    expect((int) $page->get('data.lead_lawyer_id'))->toBe($this->oldLead->id)
        ->and($this->matter->team()->whereKey($this->oldLead->id)->exists())->toBeFalse()
        ->and($this->task->fresh()->assignee_id)->toBe($this->newLead->id)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::Pending);
});

it('keeps the switch on when opened normally, says the held tasks then do not move, and leaves them with the old lead', function () {
    $this->actingAs($this->admin, 'web');

    $this->livewire(BulkReassign::class)
        ->assertSet('data.keep_old_lead_as_associate', true)
        ->assertSee(__('reassign.bulk.fields.keep_old_lead_as_associate_hint'))
        ->set('data.lead_lawyer_id', $this->oldLead->id)
        ->set('data.matter_ids', [$this->matter->id])
        ->set('data.new_lead_id', $this->newLead->id)
        ->set('data.reason', 'Chia lại việc trong phòng.')
        ->call('reassignSelected')
        ->assertHasNoErrors();

    expect(__('reassign.bulk.fields.keep_old_lead_as_associate_hint'))->toContain('KHÔNG chuyển')
        ->and($this->matter->team()->whereKey($this->oldLead->id)->first()?->pivot->role_in_matter)->toBe(MatterRole::Associate)
        ->and($this->task->fresh()->assignee_id)->toBe($this->oldLead->id);
});

it('says on the single-matter handover that keeping the old lead leaves their held tasks with them', function () {
    $this->actingAs($this->admin, 'web');

    expect(__('reassign.fields.keep_old_lead_as_associate_hint'))->toContain('KHÔNG chuyển');

    $this->livewire(ViewMatter::class, ['record' => $this->matter->getRouteKey()])
        ->mountAction('reassignMatter')
        ->assertFormFieldExists('keep_old_lead_as_associate', fn (Toggle $field): bool => $field->getDefaultState() === true);
});
