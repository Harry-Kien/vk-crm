<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * M2 chỉ cài policy cho từng bản ghi; danh sách không tự giới hạn (SPEC §4.7: "kể cả trong kết
 * quả tìm kiếm"). MatterResource::getEloquentQuery() phải tự áp listableBy(), không dựa vào
 * policy để lọc danh sách.
 */
it('hides a matter from a lawyer who is not on its team', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create();

    $this->actingAs($outsider, 'web');

    $this->livewire(ListMatters::class)
        ->assertCanNotSeeTableRecords([$matter]);
});

it('shows a lawyer their own matter in the list', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(ListMatters::class)
        ->assertCanSeeTableRecords([$matter]);
});

it('lets a manager see an ordinary matter but hides a restricted one that is not theirs', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $normal = Matter::factory()->create();
    $restricted = Matter::factory()->restricted()->create();

    $this->actingAs($manager, 'web');

    $this->livewire(ListMatters::class)
        ->assertCanSeeTableRecords([$normal])
        ->assertCanNotSeeTableRecords([$restricted]);
});

/**
 * Kế toán chỉ có matter.viewAny, không có matter.view: danh sách rút gọn, không lộ nội dung vụ
 * việc (SPEC §5).
 */
it('gives the accountant the list without the title or client-summary columns', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->create();

    $this->actingAs($accountant, 'web');

    $this->livewire(ListMatters::class)
        ->assertCanSeeTableRecords([$matter])
        ->assertTableColumnHidden('title')
        ->assertTableColumnHidden('summary_for_client');
});

/**
 * getRecordRouteBindingEloquentQuery() phải áp cùng listableBy() như getEloquentQuery():
 * nếu không, một luật sư ngoài đội ngũ không thấy vụ việc trong danh sách vẫn có thể mở thẳng
 * URL trang xem và đọc được nội dung.
 */
it('returns 404 when opening the view page of a matter outside the users scope', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create();

    $this->actingAs($outsider, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'))
        ->assertNotFound();
});

/**
 * Matter::isListableBy() là bản kiểm tra trong bộ nhớ của scopeListableBy(): hai đường phải
 * luôn ra cùng kết quả, cho mọi vai trò và mọi tổ hợp đội ngũ/độ mật, không chỉ những trường hợp
 * đã thử ở các test bên trên.
 */
it('agrees with scopeListableBy for every role, whether the matter is normal or restricted, team member or not', function () {
    $roles = Role::cases();

    foreach ($roles as $role) {
        $user = User::factory()->withRole($role)->create();
        $onTeamUser = User::factory()->withRole(Role::Lawyer)->create();

        $normalOwnedByUser = Matter::factory()->create(['lead_lawyer_id' => $onTeamUser->id]);
        $normalNotOwnedByUser = Matter::factory()->create();
        $restrictedOwnedByUser = Matter::factory()->restricted()->create(['lead_lawyer_id' => $user->id]);
        $restrictedNotOwnedByUser = Matter::factory()->restricted()->create();

        foreach ([$normalOwnedByUser, $normalNotOwnedByUser, $restrictedOwnedByUser, $restrictedNotOwnedByUser] as $matter) {
            $viaQuery = Matter::query()->whereKey($matter->getKey())->listableBy($user)->exists();
            $viaMemory = $matter->load('team')->isListableBy($user);

            expect($viaMemory)->toBe($viaQuery, "role={$role->value} matter={$matter->getKey()} confidentiality={$matter->confidentiality->value}");
        }
    }
});
