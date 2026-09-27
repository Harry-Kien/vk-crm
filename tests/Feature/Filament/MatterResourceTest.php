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
