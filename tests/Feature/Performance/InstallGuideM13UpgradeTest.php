<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Pages\TeamOverview;
use App\Models\PerformanceSnapshot;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| `docs/CAI-DAT.md` — nâng một máy chủ đã có dữ liệu lên bản M13 (theo dõi đội ngũ)
|--------------------------------------------------------------------------
|
| M13 Task 8 (kế hoạch: "`docs/CAI-DAT.md`: bản cập nhật M13 làm gì trên máy chủ đã có dữ liệu"). Theo khuôn
| `tests/Feature/Deployment/InstallGuideM10UpgradeTest.php`: các test đọc CHÍNH đoạn tài liệu rồi so với mã —
| tên tệp migration, tên quyền, tên và giờ của tác vụ lịch, số tháng giữ ảnh chụp. Lời hứa "chưa chạy
| `db:seed --force` thì không ai thấy Theo dõi đội ngũ, kể cả quản trị viên" đo bằng một CSDL có vai trò
| nhưng chưa có quyền `performance.viewAny`, rồi chạy lại seeder. Hàm toàn cục mang tiền tố `m13t8Guide`.
*/

/** Đoạn "**Bản cập nhật M13 (theo dõi đội ngũ) …" của CAI-DAT, tới trước đoạn in đậm kế tiếp. */
function m13t8GuideParagraph(): string
{
    $guide = (string) file_get_contents(base_path('docs/CAI-DAT.md'));
    $start = strpos($guide, '**Bản cập nhật M13 (theo dõi đội ngũ) làm gì trên máy chủ đã có dữ liệu');

    expect($start)->not->toBeFalse('CAI-DAT phải có đoạn "Bản cập nhật M13 (theo dõi đội ngũ) làm gì trên máy chủ đã có dữ liệu"');

    $end = strpos($guide, "\n\n**", $start);

    return substr($guide, $start, ($end === false ? strlen($guide) : $end) - $start);
}

it('places the M13 upgrade paragraph in Bước 5, right after the M10 paragraph', function () {
    $guide = (string) file_get_contents(base_path('docs/CAI-DAT.md'));

    $step5 = strpos($guide, '### Bước 5');
    $m10 = strpos($guide, '**Bản cập nhật M10 (tiếp nhận)');
    $m13 = strpos($guide, '**Bản cập nhật M13 (theo dõi đội ngũ)');
    $step6 = strpos($guide, '### Bước 6');

    expect($m13)->toBeGreaterThan($m10)->toBeGreaterThan($step5)->toBeLessThan($step6)
        ->and(strpos($guide, "\n\n**", $m10 + 1))->toBe($m13 - 2);
});

it('names every M13 migration file, and only files that exist', function () {
    $paragraph = m13t8GuideParagraph();
    preg_match_all('/`(\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+)`/', $paragraph, $named);

    $m13 = collect(File::files(database_path('migrations')))
        ->map(fn ($file): string => $file->getFilenameWithoutExtension())
        ->filter(fn (string $name): bool => str_contains($name, 'performance_snapshots') || str_contains($name, 'event_created_at_index_to_activity_log'))
        ->sort()->values()->all();

    expect($m13)->toHaveCount(2)
        ->and(collect($named[1])->sort()->values()->all())->toBe($m13);
});

it('names the new permission, and keeps Theo dõi đội ngũ from everyone, admin included, until db:seed --force brings it', function () {
    expect(m13t8GuideParagraph())->toContain('`'.Permission::PerformanceViewAny->value.'`')
        ->toContain('`db:seed --force`');

    // Một máy chủ M10 vừa `migrate --force`: vai trò và quyền cũ có, quyền M13 chưa có.
    $this->seed(RolesAndPermissionsSeeder::class);
    PermissionModel::query()->where('name', Permission::PerformanceViewAny->value)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = User::factory()->withRole(Role::Admin)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $this->actingAs($admin, 'web')->get(TeamOverview::getUrl(panel: 'admin'))->assertNotFound();
    $this->actingAs($manager->fresh(), 'web')->get(TeamOverview::getUrl(panel: 'admin'))->assertNotFound();

    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($admin->fresh()->can(Permission::PerformanceViewAny->value))->toBeTrue()
        ->and($manager->fresh()->can(Permission::PerformanceViewAny->value))->toBeTrue();
});

it('lists the daily snapshot task with the time the scheduler really uses, and the months it keeps', function () {
    $events = collect(Schedule::events())->filter(fn (Event $event): bool => $event->description === 'performance.snapshot')->values();

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('50 23 * * *')
        ->and(m13t8GuideParagraph())->toContain('`performance.snapshot`')
        ->toContain('23:50')
        ->toContain(PerformanceSnapshot::KEEP_MONTHS.' tháng');
});

it('says the trends start on the day of the upgrade, the holder history is complete only from then, and no env var is new', function () {
    expect(m13t8GuideParagraph())
        ->toContain('Xu hướng bắt đầu từ ngày nâng cấp')
        ->toContain('chỉ đầy đủ từ ngày nâng cấp')
        ->toContain('không có biến `.env` mới');
});
