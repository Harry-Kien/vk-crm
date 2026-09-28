<?php

use App\Enums\ContractStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Resources\Matters\Tables\MattersTable;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

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
 * M9 Task 8, phán quyết controller 1 ("SQL-aggregate outstanding"): "còn phải thu" phải là MỘT
 * round-trip cho toàn bộ danh sách — {@see MattersTable}'s `modifyQueryUsing()` giờ đọc một cột
 * `selectRaw()` tính sẵn (`BillingSummary::outstandingPerMatterExpression()`), thay vì gọi
 * `BillingSummary::outstandingForMatter()` (một SUM() riêng) cho MỖI dòng — bản trước đó là N+1.
 *
 * Ngưỡng: đo bằng SO SÁNH 2 vụ việc với 6 vụ việc (mỗi vụ một hợp đồng đang hiệu lực, hai đợt còn
 * `pending`) thay vì một con số tuyệt đối cố định — con số tuyệt đối phụ thuộc quá nhiều vào bao
 * nhiêu quan hệ `MatterResource::getEloquentQuery()` nạp kèm (`client`, `matterType.stages`,
 * `leadLawyer`, `team`) và có thể đổi khi những phần đó đổi mà không liên quan gì tới cột này.
 * Điều BẤT BIẾN mà phán quyết controller 1 đòi hỏi là: số truy vấn KHÔNG tăng theo số dòng. Nếu
 * cột quay lại gọi `outstandingForMatter()` mỗi dòng (N+1), 6 vụ việc sẽ tốn nhiều truy vấn hơn
 * hẳn 2 vụ việc — đúng thứ test này bắt được mà một ngưỡng tuyệt đối không bắt được.
 */
it('runs the same number of queries for the outstanding-balance column whether there are two matters or six', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->actingAs($accountant, 'web');

    // Soạn nháp, thêm đủ đợt, rồi kích hoạt bằng một lần ghi thẳng — constraint (b), Task 4: mỗi
    // đợt tạo ra trên một hợp đồng ĐANG active bị hook bất biến so với TOÀN BỘ total_amount ngay
    // khi vừa tạo, nên hai đợt phải dựng lúc còn draft.
    $matterWithBalance = function (): Matter {
        $matter = Matter::factory()->create();
        $contract = Contract::factory()->for($matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 20_000_000, 'signed_at' => null]);
        Instalment::factory()->for($contract)->create(['sequence' => 1, 'amount' => 10_000_000, 'due_date' => today()->addDays(10)->toDateString()]);
        Instalment::factory()->for($contract)->create(['sequence' => 2, 'amount' => 10_000_000, 'due_date' => today()->addDays(20)->toDateString()]);
        $contract->forceFill(['status' => ContractStatus::Active, 'signed_at' => today()->subDay()->toDateString(), 'activated_by' => $matter->lead_lawyer_id])->save();

        return $matter;
    };

    $matterWithBalance();
    $matterWithBalance();

    // Một lần vẽ bảng KHÔNG đo, để hâm nóng cache quyền của Spatie (permissions/roles nạp một
    // lần cho cả tiến trình, không phải một chi phí phụ thuộc số dòng) — không hâm nóng trước,
    // lần đo ĐẦU sẽ cõng thêm bốn truy vấn nạp quyền mà lần đo THỨ HAI không còn, làm phép so
    // sánh sai lệch vì một lý do không liên quan gì tới cột "còn phải thu".
    $this->livewire(ListMatters::class);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->livewire(ListMatters::class);
    $queriesForTwo = count(DB::getQueryLog());

    $matterWithBalance();
    $matterWithBalance();
    $matterWithBalance();
    $matterWithBalance();

    DB::flushQueryLog();
    $this->livewire(ListMatters::class);
    $queriesForSix = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($queriesForSix)->toBe($queriesForTwo);
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

/**
 * Fix round 1, finding minor: `wasRecentlyCreated` đếm là "biết `deleted_at`, và biết nó là
 * `null`". Một `Matter` VỪA `factory()->create()` — chưa qua một lần truy vấn CSDL nào để nạp
 * lại — không mang khoá `deleted_at` trong `getAttributes()` (INSERT không refetch cột nullable
 * chưa từng set), y hệt dấu hiệu một select rút gọn; không có điều kiện này, nó bị từ chối SAI
 * dù chắc chắn chưa ai xoá mềm được nó trong chính request đang tạo ra nó.
 */
it('does not deny a freshly created normal matter with team loaded, even though deleted_at was never reselected', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();

    $justCreated = Matter::factory()->create();

    expect(array_key_exists('deleted_at', $justCreated->getAttributes()))->toBeFalse()
        ->and($justCreated->wasRecentlyCreated)->toBeTrue()
        ->and($justCreated->load('team')->isListableBy($manager))->toBeTrue();
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
 * Đọc markup THẬT của một ô cột `last_client_update_at`, cho đúng một bản ghi — không phải gọi
 * thẳng `MattersTable::lastClientUpdateColor()` (fix round 1, finding S2: một hàm tĩnh không đo
 * được liệu Filament có thật sự VẼ màu và chữ ra màn hình hay không; bản trước để màu đúng nhưng
 * ô rỗng cho một vụ chưa từng cập nhật — điều mà một lời gọi hàm tĩnh không bao giờ bắt được).
 * `wire:key` của Filament mang đúng khoá bản ghi và tên cột, nên tìm theo đó rồi cắt tới `</td>`
 * đóng ô là cách duy nhất không lẫn sang ô/dòng khác trên cùng bảng.
 */
function lastClientUpdateCellHtml(string $html, Matter $matter): string
{
    $marker = 'table.record.'.$matter->getKey().'.column.last_client_update_at';
    $start = strpos($html, $marker);

    expect($start)->not->toBeFalse("Không tìm thấy ô last_client_update_at của vụ việc {$matter->getKey()} trong HTML.");

    $end = strpos($html, '</td>', $start);

    return substr($html, $start, $end - $start);
}

/**
 * SPEC §7.2: "cập nhật gần nhất cho khách" tô vàng khi > 10 ngày, đỏ khi > 14 ngày. Không có màu
 * ở ngưỡng còn lại. Đi qua `Livewire::test(ListMatters::class)` thật, đọc markup đã render —
 * không gọi thẳng hàm tĩnh (fix round 1, finding S2).
 */
it('colors the last-client-update column at the SPEC-defined day thresholds, on the real rendered table', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $recent = Matter::factory()->create(['last_client_update_at' => now()->subDays(5)]);
    $warning = Matter::factory()->create(['last_client_update_at' => now()->subDays(12)]);
    $danger = Matter::factory()->create(['last_client_update_at' => now()->subDays(20)]);

    $this->actingAs($admin, 'web');

    $html = $this->livewire(ListMatters::class)->html();

    expect(lastClientUpdateCellHtml($html, $recent))->not->toContain('fi-color-warning')->not->toContain('fi-color-danger')
        ->and(lastClientUpdateCellHtml($html, $warning))->toContain('fi-color-warning')
        ->and(lastClientUpdateCellHtml($html, $danger))->toContain('fi-color-danger');
});

/**
 * M6.5 Task 5 (finding `stage/stage-09`), fix round 1 finding S2: cột danh sách và
 * `StaleMattersWidget` phải dùng chung một định nghĩa "quá hạn" (`App\Support\MatterStaleness`).
 * Ba ca lệch cũ: vụ đã đóng và vụ chưa công bố portal không được tô dù `last_client_update_at`
 * cũ, và một vụ CHƯA TỪNG cập nhật cho khách — ca widget coi là XẤU NHẤT — không chỉ phải mang
 * màu đỏ mà còn phải hiện một dòng chữ đọc được ("Chưa cập nhật lần nào"), vì Filament không bao
 * giờ vẽ màu lên một ô trống.
 */
it('shares the same staleness definition as StaleMattersWidget, and shows visible text for a never-updated stale matter', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
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

    $this->actingAs($admin, 'web');

    $html = $this->livewire(ListMatters::class)->html();

    expect(lastClientUpdateCellHtml($html, $closedButOld))->not->toContain('fi-color-danger')
        ->and(lastClientUpdateCellHtml($html, $unpublishedButOld))->not->toContain('fi-color-danger')
        ->and(lastClientUpdateCellHtml($html, $neverUpdatedButStale))
        ->toContain('fi-color-danger')
        ->toContain(__('matters.fields.never_updated'));
});
