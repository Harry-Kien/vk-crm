<?php

use App\Actions\Matter\SeatsNeedingReassignment;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\TeamOverview;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn ops — dòng "Hồ sơ cần phân công lại: n" của "Theo dõi đội ngũ"
 * ({@see SeatsNeedingReassignment::handle()}) hỏi `Gate::view()` cho TỪNG ghế chưa ai bấm "Đã nhận hồ sơ",
 * và mỗi lần hỏi là một truy vấn EXISTS. Văn phòng thật có rất nhiều ghế như vậy (trợ lý, luật sư cộng sự
 * được thêm vào đội ngũ mà chưa bấm nhận): trên dữ liệu đo R11 của M13 (3.000 vụ) trang mở mất khoảng 20
 * giây với 5.935 truy vấn (`tests/Benchmark/TeamPerformanceBenchmarkTest.php`, ngân sách 300 ms). Số truy
 * vấn không được tăng theo số ghế, và kết quả phải giữ nguyên.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->gone = User::factory()->withRole(Role::Assistant)->create();
});

/** `$count` vụ đang mở, mỗi vụ một ghế trợ lý chưa nhận (người nhận vẫn làm được — không "kẹt"). */
function opsPendingSeats(int $count): void
{
    foreach (range(1, $count) as $_) {
        Matter::factory()->create(['lead_lawyer_id' => test()->lead->id])
            ->addTeamMember(test()->assistant, MatterRole::Assistant, test()->lead);
    }
}

function opsSeatQueries(User $viewer): int
{
    app(SeatsNeedingReassignment::class)->handle($viewer); // làm nóng: quyền và vai trò của người xem

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(SeatsNeedingReassignment::class)->handle($viewer);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('finds the stuck seats with the same number of queries however many seats await acceptance', function () {
    opsPendingSeats(2);
    $few = opsSeatQueries($this->manager->fresh());

    opsPendingSeats(10);
    $many = opsSeatQueries($this->manager->fresh());

    expect($many)->toBe($few);
});

it('still lists exactly the seats whose holder can no longer accept them', function () {
    opsPendingSeats(3);

    // Người nhận đã nghỉ (vô hiệu) — kẹt.
    $left = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0801']);
    $left->addTeamMember($this->gone, MatterRole::Assistant, $this->lead);
    $this->gone->forceFill(['is_active' => false])->save();

    // Người nhận đã xoá mềm — kẹt.
    $deleted = User::factory()->withRole(Role::Assistant)->create();
    $trashed = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0802']);
    $trashed->addTeamMember($deleted, MatterRole::Assistant, $this->lead);
    $deleted->forceFill(['is_active' => false])->save();
    $deleted->delete();

    // Vụ hạn chế: trợ lý không mở được — kẹt; người quản lý không thấy vụ hạn chế, chỉ admin thấy dòng này.
    $restricted = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0803']);
    $restricted->addTeamMember($this->assistant, MatterRole::Assistant, $this->lead);
    $restricted->forceFill(['confidentiality' => Confidentiality::Restricted])->save();

    $admin = User::factory()->withRole(Role::Admin)->create();

    $codes = fn (User $viewer): array => app(SeatsNeedingReassignment::class)->handle($viewer)
        ->map(fn ($seat): string => (string) $seat->matter->code)->all();

    expect($codes($this->manager->fresh()))->toBe(['VK-2026-DS-0801', 'VK-2026-DS-0802'])
        ->and($codes($admin->fresh()))->toBe(['VK-2026-DS-0801', 'VK-2026-DS-0802', 'VK-2026-DS-0803']);

    $this->actingAs($this->manager->fresh(), 'web');
    Livewire::test(TeamOverview::class)
        ->assertSee('VK-2026-DS-0801')
        ->assertSee('VK-2026-DS-0802')
        ->assertDontSee('VK-2026-DS-0803');
});
