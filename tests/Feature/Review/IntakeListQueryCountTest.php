<?php

use App\Actions\Intake\RecordIntake;
use App\Enums\IntakeSource;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ListIntakeRequests;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

/*
 * Rà soát toàn hệ thống 2026-10-22 (làn intake, mục chưa sửa 2): cột "Kết quả kiểm tra" của danh sách
 * Tiếp nhận hỏi `awaitsConflictResolution()` cho từng dòng, hai lần (màu và nhãn), mỗi lần dò "người gọi
 * lại" bằng truy vấn riêng — số truy vấn tăng theo số dòng. Danh sách phải tốn số truy vấn gần như không
 * đổi khi thêm dòng. Hàm toàn cục mang tiền tố `ilq…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function ilqQueriesForRows(int $rows): int
{
    $manager = User::factory()->withRole(Role::Manager)->create();

    foreach (range(1, $rows) as $i) {
        app(RecordIntake::class)->handle($manager, [
            'contact_name' => "Người Gọi {$i}", 'contact_phone' => sprintf('0912 %03d %03d', $i, $i),
            'contact_role' => PartyRole::Plaintiff, 'source' => IntakeSource::Phone,
        ]);
    }

    test()->actingAs($manager, 'web');
    $component = test()->livewire(ListIntakeRequests::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $component->call('$refresh');
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('renders the intake list with a query count that does not grow with the rows', function () {
    $five = ilqQueriesForRows(5);
    DB::table('intake_requests')->delete();
    $twenty = ilqQueriesForRows(20);

    expect($twenty - $five)->toBeLessThanOrEqual(3, "5 dòng: {$five} truy vấn, 20 dòng: {$twenty} truy vấn");
});
