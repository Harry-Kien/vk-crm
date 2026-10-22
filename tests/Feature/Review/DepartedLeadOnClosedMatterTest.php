<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn matter — hồ sơ đã kết thúc phải còn nói ai đã xử lý nó.
 *
 * Người nghỉ việc bị xoá MỀM (`DeleteStaffMember`); luật nghỉ việc chỉ chặn khi còn việc trên hồ sơ ĐANG MỞ,
 * nên luật sư phụ trách của các hồ sơ đã kết thúc nghỉ được. Sau đó trang hồ sơ không còn tên họ: ô "Luật sư
 * phụ trách" trống, khối "Đội ngũ" và tab "Đội ngũ" nói "chưa có ai ngoài luật sư phụ trách", cột danh sách
 * trống — đúng loại hồ sơ văn phòng giữ nhiều năm để trả lời "ai đã làm vụ này". Tên còn hiện, kèm nhãn
 * "(đã nghỉ việc)" như trang Nhật ký hệ thống (làn fb, A6).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Đã Nghỉ']);
    $this->associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Cộng Sự Đã Nghỉ']);
    $this->matter = Matter::factory()->atStage('closed')->create([
        'lead_lawyer_id' => $this->lead->id,
        'closed_at' => now()->subMonths(3),
    ]);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);

    $this->lead->delete();
    $this->associate->delete();

    $this->departedLead = __('staff_access.departed_name', ['name' => 'Luật Sư Đã Nghỉ']);
    $this->departedAssociate = __('staff_access.departed_name', ['name' => 'Cộng Sự Đã Nghỉ']);

    $this->actingAs($this->admin, 'web');
});

it('names the departed lead and team on the overview of a closed matter', function () {
    $this->get(MatterResource::getUrl('view', ['record' => $this->matter]))
        ->assertOk()
        ->assertSee($this->departedLead)
        ->assertSee($this->departedAssociate);
});

it('lists departed members on the team tab of a closed matter, without a remove button', function () {
    $this->livewire(TeamRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->assertSee($this->departedLead)
        ->assertSee($this->departedAssociate)
        ->assertTableActionHidden('removeMember', $this->associate->fresh() ?? User::withTrashed()->find($this->associate->id));
});

it('names the departed lead in the matters list', function () {
    $this->livewire(ListMatters::class)
        ->assertSee($this->departedLead);
});
