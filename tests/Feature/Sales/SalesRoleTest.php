<?php

use App\Actions\Matter\AddTeamMember;
use App\Actions\Mcp\SetUserAiAccess;
use App\Enums\AiAccessMode;
use App\Enums\MatterRole;
use App\Enums\McpAccessRefusal;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Mcp\McpAccess;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

/*
 * Làn kd, Task 1 — vai "Nhân viên kinh doanh" (`Role::Sales`, chức danh `UserPosition::Sales`). Chủ văn
 * phòng (chat 2026-10-10): nhân viên kinh doanh cập nhật lead, chỉ thấy lead của mình, không thấy vụ việc,
 * hồ sơ khách, tài liệu hay tiền. Bộ quyền đúng MỘT quyền: `intake.create`. Hàm toàn cục mang tiền tố `srl…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('gives the sales role exactly intake.create, and nothing of matters, clients, documents, money or AI', function () {
    expect(Role::Sales->value)->toBe('sales')
        ->and(Role::Sales->label())->toBe('Nhân viên kinh doanh')
        ->and(Role::Sales->permissions())->toBe([Permission::IntakeCreate]);

    $sales = User::factory()->withRole(Role::Sales)->create();

    foreach (Permission::cases() as $permission) {
        expect($sales->can($permission->value))->toBe($permission === Permission::IntakeCreate, "nhân viên kinh doanh và {$permission->value}");
    }
});

it('maps the sales position to the sales role, with a vietnamese label', function () {
    expect(UserPosition::Sales->value)->toBe('sales')
        ->and(UserPosition::Sales->label())->toBe('Nhân viên kinh doanh')
        ->and(Role::fromPosition(UserPosition::Sales))->toBe(Role::Sales);

    $user = User::factory()->create(['position' => UserPosition::Sales]);
    $user->assignRoleFromPosition();

    expect($user->fresh()->hasRole(Role::Sales->value))->toBeTrue()
        ->and($user->fresh()->can(Permission::IntakeCreate->value))->toBeTrue()
        ->and($user->fresh()->can(Permission::MatterView->value))->toBeFalse();
});

it('never lets a sales person sit on a matter team, not even as an observer', function () {
    $sales = User::factory()->withRole(Role::Sales)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();

    foreach (MatterRole::cases() as $role) {
        expect(AddTeamMember::eligibleForRole($sales, $role))->toBeFalse("nhân viên kinh doanh làm {$role->value}");
    }

    // Cặp dương: trợ lý vẫn làm người theo dõi được — luật chỉ loại đúng vai mới.
    expect(AddTeamMember::eligibleForRole($assistant, MatterRole::Observer))->toBeTrue();
});

it('refuses to turn a lawyer who still leads an open matter into a sales person', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['position' => UserPosition::Lawyer]);
    Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => null]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->fillForm(['position' => UserPosition::Sales->value])
        ->call('save')
        ->assertHasFormErrors(['position']);

    expect($lawyer->fresh()->position)->toBe(UserPosition::Lawyer)
        ->and($lawyer->fresh()->hasRole(Role::Sales->value))->toBeFalse();
});

it('lets an admin turn a staff member with no open work into a sales person, and the role follows', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create(['position' => UserPosition::Assistant]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $assistant->getRouteKey()])
        ->fillForm(['position' => UserPosition::Sales->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($assistant->fresh()->position)->toBe(UserPosition::Sales)
        ->and($assistant->fresh()->hasRole(Role::Sales->value))->toBeTrue()
        ->and($assistant->fresh()->hasRole(Role::Assistant->value))->toBeFalse();
});

it('keeps sales people out of the AI connection: they cannot hold it, and an admin cannot switch it on', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $sales = User::factory()->withRole(Role::Sales)->create();

    expect(McpAccess::canHold($sales))->toBeFalse();

    expect(fn () => app(SetUserAiAccess::class)->handle($sales, AiAccessMode::Read, $admin))
        ->toThrow(ValidationException::class, __('ai_access.validation.needs_matter_view'));

    expect($sales->fresh()->ai_access)->toBe(AiAccessMode::Off);

    // Kể cả khi cột bị đặt thẳng trong CSDL, máy chủ MCP vẫn từ chối người này.
    $sales->forceFill(['ai_access' => AiAccessMode::Read])->save();

    expect(McpAccess::refusal($sales->fresh()))->toBe(McpAccessRefusal::NoMatterView);
});

it('refuses to turn a lawyer who only holds an unfinished deadline (no lead matter) into a sales person, like an accountant', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['position' => UserPosition::Lawyer]);
    Deadline::factory()->for(Matter::factory()->create())->create([
        'responsible_user_id' => $lawyer->id,
        'is_completed' => false,
    ]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->fillForm(['position' => UserPosition::Sales->value])
        ->call('save')
        ->assertHasFormErrors(['position']);

    expect($lawyer->fresh()->position)->toBe(UserPosition::Lawyer);
});
