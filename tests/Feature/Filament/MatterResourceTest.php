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
 *
 * **`->fresh()` trước `->load('team')` (M6.5 Task 5).** Một `Matter` vừa `factory()->create()`
 * mà CHƯA qua một lần truy vấn CSDL nào không mang khoá `deleted_at` trong mảng thuộc tính của nó
 * (INSERT không refetch các cột nullable chưa từng được set) — đúng tín hiệu mà
 * `isListableBy()` giờ đọc để phát hiện một select rút gọn (xem docblock hàm đó), nên nó sẽ luôn
 * rơi vào nhánh restricted một cách SAI, không phải vì phát hiện đúng. `->fresh()` nạp lại bản ghi
 * qua một truy vấn SELECT thật — đúng hình dạng MỌI nơi gọi hợp lệ của hàm này trong `app/` (luôn
 * là một bản ghi đã tồn tại trong CSDL, không phải một instance vừa dựng trong bộ nhớ).
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
            $viaMemory = $matter->fresh()->load('team')->isListableBy($user);

            expect($viaMemory)->toBe($viaQuery, "role={$role->value} matter={$matter->getKey()} confidentiality={$matter->confidentiality->value}");
        }
    }
});

/**
 * Rà soát mang sang từ một lane khác (fail-open tiềm ẩn): `isListableBy()` so
 * `confidentiality === Restricted`. Trên một `Matter` nạp qua một select rút gọn (ví dụ
 * `with('matter:id,code,lead_lawyer_id')`), `confidentiality` trả về `null`, và `null !==
 * Restricted` đi vào nhánh THƯỜNG — mở toang một vụ việc hạn chế cho MỌI người có
 * `matter.viewAny` (kế toán, trưởng phòng), qua đúng đường trong bộ nhớ mà
 * `MatterPolicy::view()` dùng khi `team` đã nạp. Sửa: `confidentiality` không rõ (hoặc không
 * phải `Normal`) đi vào ĐÚNG nhánh `restricted`, cùng lúc với `deleted_at` không rõ.
 */
it('fails closed on a partial-select restricted matter: neither an accountant nor a manager can list it', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $restricted = Matter::factory()->restricted()->create();

    // Đúng hình dạng phát hiện: chỉ id/code/lead_lawyer_id quay về, nên confidentiality VÀ
    // deleted_at đều null dù dòng thật KHÔNG bị xoá mềm và THẬT SỰ là `restricted`.
    $partial = Matter::query()
        ->select('id', 'code', 'lead_lawyer_id')
        ->with('team')
        ->whereKey($restricted->getKey())
        ->first();

    expect($partial->confidentiality)->toBeNull()
        ->and($partial->isListableBy($manager))->toBeFalse()
        ->and($partial->isListableBy($accountant))->toBeFalse();
});

/**
 * Cô lập riêng điều kiện `confidentiality`, tách khỏi `deleted_at`: select mang `deleted_at`
 * (nên điều kiện kia không tự bắt được ca này) nhưng bỏ `confidentiality` ra, trên một vụ
 * `restricted` thật. Không cô lập được ca này thì mutation probe xoá `!== Normal` không chứng
 * minh được gì, vì test kia (chọn thiếu CẢ HAI cột) vẫn đỏ nhờ đúng mỗi điều kiện `deleted_at`.
 */
it('fails closed when deleted_at is known but confidentiality was left out of the select', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $restricted = Matter::factory()->restricted()->create();

    $partial = Matter::query()
        ->select('id', 'code', 'lead_lawyer_id', 'deleted_at')
        ->with('team')
        ->whereKey($restricted->getKey())
        ->first();

    expect($partial->confidentiality)->toBeNull()
        ->and($partial->isListableBy($manager))->toBeFalse();
});

/** Cặp dương bắt buộc: một vụ thường, nạp ĐẦY ĐỦ, vẫn thấy được bởi một trưởng phòng như cũ. */
it('still lists a fully loaded normal matter for a manager', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $normal = Matter::factory()->create();

    $full = Matter::query()->whereKey($normal->getKey())->with('team')->first();

    expect($full->isListableBy($manager))->toBeTrue();
});

/**
 * Vế thứ hai của cùng phán quyết, tách riêng khỏi `confidentiality`: một vụ THƯỜNG (không
 * `restricted`), select có mang `confidentiality` (nên `!== Normal` một mình không bắt được ca
 * này) nhưng CỐ Ý bỏ `deleted_at` ra khỏi SELECT — đúng hình dạng "không biết vụ này có bị xoá
 * mềm hay không". Không rõ `deleted_at` cũng phải đi vào nhánh restricted, không phải ngầm định
 * là "chưa xoá mềm".
 */
it('fails closed when confidentiality is known-normal but deleted_at was left out of the select', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $normal = Matter::factory()->create();

    $partial = Matter::query()
        ->select('id', 'code', 'lead_lawyer_id', 'confidentiality')
        ->with('team')
        ->whereKey($normal->getKey())
        ->first();

    expect($partial->confidentiality)->not->toBeNull()
        ->and($partial->isListableBy($manager))->toBeFalse();
});

/**
 * SPEC §7.2: "cập nhật gần nhất cho khách" tô vàng khi > 10 ngày, đỏ khi > 14 ngày. Không có màu
 * ở ngưỡng còn lại. Không có bản ghi nào (last_client_update_at null) thì không tô màu — ở đây vì
 * `stage_entered_at` mặc định của factory là "vừa mới", nên đồng hồ dự phòng của
 * `App\Support\MatterStaleness` cũng ra 0 ngày.
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

/**
 * M6.5 Task 5 (finding `stage/stage-09`): trước bản sửa này, cột danh sách và
 * `StaleMattersWidget` dùng hai định nghĩa "quá hạn" khác nhau — cột tô đỏ cả vụ đã đóng lẫn vụ
 * chưa công bố portal, và bỏ sót vụ CHƯA TỪNG cập nhật cho khách (không tô màu vì
 * `last_client_update_at` null, dù widget coi đó là ca xấu nhất). `App\Support\MatterStaleness`
 * giờ là một định nghĩa DÙNG CHUNG giữa hai nơi; ba ca dưới đây là đúng ba khoảng lệch cũ.
 */
it('shares the same staleness definition as StaleMattersWidget: closed, unpublished, and never-updated matters', function () {
    $closedButOld = Matter::factory()->create([
        'last_client_update_at' => now()->subDays(20),
        'closed_at' => now()->subDay(),
    ]);
    $unpublishedButOld = Matter::factory()->unpublished()->create([
        'last_client_update_at' => now()->subDays(20),
    ]);
    $neverUpdatedButStale = Matter::factory()->create([
        'last_client_update_at' => null,
        'stage_entered_at' => now()->subDays(20),
    ]);

    expect(MattersTable::lastClientUpdateColor($closedButOld))->toBeNull()
        ->and(MattersTable::lastClientUpdateColor($unpublishedButOld))->toBeNull()
        ->and(MattersTable::lastClientUpdateColor($neverUpdatedButStale))->toBe('danger');
});
