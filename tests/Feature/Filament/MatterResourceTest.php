<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Resources\Matters\Tables\MattersTable;
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
 * M9 Task 7: cột "còn phải thu" hiện cho ai có `billing.view` (kế toán; luật sư cũng có quyền đó
 * trên vụ của mình — SPEC §5), ẩn HẲN với ai không có (trợ lý không được cấp `billing.view`).
 */
it('shows the outstanding-balance column to the accountant and hides it from an assistant', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    Matter::factory()->create();

    $this->actingAs($accountant, 'web');
    $this->livewire(ListMatters::class)->assertTableColumnVisible('outstanding_balance');

    $this->actingAs($assistant, 'web');
    $this->livewire(ListMatters::class)->assertTableColumnHidden('outstanding_balance');
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
 *
 * Quan trọng: $normalWhereUserIsOnTeam phải thật sự có $user trong team (qua addTeamMember),
 * không chỉ đứng tên lead_lawyer_id của MỘT NGƯỜI KHÁC — nếu không, nhánh
 * `$this->team->contains('id', $user->getKey())` không bao giờ được khẳng định đúng ở chiều
 * "true": mọi so sánh chỉ có thể ra false==false, và một isListableBy() luôn trả false ở nhánh
 * này vẫn khiến test xanh trong khi trên thực tế nó ẩn ViewAction của chính vụ luật sư đó.
 */
it('agrees with scopeListableBy for every role, whether the matter is normal or restricted, team member or not', function () {
    $roles = Role::cases();

    foreach ($roles as $role) {
        $user = User::factory()->withRole($role)->create();

        // Vụ thường, lead lawyer là người khác, nhưng $user được thêm vào team (không phải lead)
        // — chiều "true" của nhánh team-contains.
        $normalWhereUserIsOnTeam = Matter::factory()->create();
        $normalWhereUserIsOnTeam->addTeamMember($user, MatterRole::Associate);

        // Vụ thường, $user không có mặt ở đâu cả — chiều "false".
        $normalWhereUserIsNotOnTeam = Matter::factory()->create();

        $restrictedOwnedByUser = Matter::factory()->restricted()->create(['lead_lawyer_id' => $user->id]);
        $restrictedNotOwnedByUser = Matter::factory()->restricted()->create();

        foreach ([$normalWhereUserIsOnTeam, $normalWhereUserIsNotOnTeam, $restrictedOwnedByUser, $restrictedNotOwnedByUser] as $matter) {
            $viaQuery = Matter::query()->whereKey($matter->getKey())->listableBy($user)->exists();
            $viaMemory = $matter->load('team')->isListableBy($user);

            expect($viaMemory)->toBe($viaQuery, "role={$role->value} matter={$matter->getKey()} confidentiality={$matter->confidentiality->value}");
        }
    }
});

/**
 * SPEC §7.2: "cập nhật gần nhất cho khách" tô vàng khi > 10 ngày, đỏ khi > 14 ngày. Không có màu
 * ở ngưỡng còn lại. Không có bản ghi nào (last_client_update_at null) thì không tô màu.
 */
it('colors the last-client-update column at the SPEC-defined day thresholds', function () {
    $recent = Matter::factory()->create(['last_client_update_at' => now()->subDays(5)]);
    $warning = Matter::factory()->create(['last_client_update_at' => now()->subDays(12)]);
    $danger = Matter::factory()->create(['last_client_update_at' => now()->subDays(20)]);
    $never = Matter::factory()->create(['last_client_update_at' => null]);

    expect(MattersTable::lastClientUpdateColor($recent))->toBeNull()
        ->and(MattersTable::lastClientUpdateColor($warning))->toBe('warning')
        ->and(MattersTable::lastClientUpdateColor($danger))->toBe('danger')
        ->and(MattersTable::lastClientUpdateColor($never))->toBeNull();
});
