<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use App\Models\IntakeRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| `docs/CAI-DAT.md` và `README.md` — nâng một máy chủ đã có dữ liệu lên bản M10 (tiếp nhận)
|--------------------------------------------------------------------------
|
| Việc sau gộp M9 + M10 (làn fu3, Task 2 mục A). Bước 5 của CAI-DAT có đoạn "Bản cập nhật M9 … làm gì
| trên máy chủ đã có dữ liệu" cho hai bản M9, nhưng không có đoạn nào cho M10 — mà bản M10 có một bước
| BẮT BUỘC (`db:seed --force`: thiếu nó thì menu "Tiếp nhận" không hiện với ai) và một tác vụ ĐÊM xoá
| dữ liệu không hoàn tác được (`prospects.anonymise`). Người vận hành đọc đoạn đó TRƯỚC khi nâng cấp,
| nên mọi con số trong nó phải là con số của mã, không phải của kế hoạch.
|
| Các test dưới đây đọc CHÍNH tài liệu rồi so với mã: tên tệp migration (thư mục `database/migrations`),
| tên quyền (`App\Enums\Permission`), tên và giờ chạy của tác vụ lịch (`Schedule::events()`), mặc định của
| hai biến `.env` (`IntakeRequest::retentionMonths()`, `config('vkcrm.intake_response_hours')`). Đổi một
| thứ trong mã mà không đổi tài liệu thì đỏ ở đây. Lời hứa "thiếu `db:seed --force` thì không ai thấy
| menu" được đo bằng đường đi thật: một CSDL có vai trò nhưng chưa có ba quyền `intake.*` (đúng như một
| máy chủ M9 vừa chạy `migrate --force`), rồi chạy lại seeder.
*/

/** Đoạn "**Bản cập nhật M10 (tiếp nhận) …" của CAI-DAT, tới trước đoạn in đậm kế tiếp. */
function m10UpgradeParagraph(): string
{
    $guide = (string) file_get_contents(base_path('docs/CAI-DAT.md'));
    $start = strpos($guide, '**Bản cập nhật M10 (tiếp nhận) làm gì trên máy chủ đã có dữ liệu');

    expect($start)->not->toBeFalse('CAI-DAT phải có đoạn "Bản cập nhật M10 (tiếp nhận) làm gì trên máy chủ đã có dữ liệu"');

    $end = strpos($guide, "\n\n**", $start);

    return substr($guide, $start, ($end === false ? strlen($guide) : $end) - $start);
}

/**
 * Khối của `$path` dưới tiêu đề `$heading` (tới tiêu đề cùng cấp hoặc cao hơn kế tiếp) có chứa
 * `$needle` — tách theo gạch đầu dòng và dòng trống, cùng cách `PreflightBillingInvariantsTest` đọc.
 */
function m10UpgradeBlock(string $path, string $heading, string $needle): string
{
    $text = (string) file_get_contents(base_path($path));
    $level = strspn($heading, '#');
    $start = strpos($text, "\n{$heading}\n");

    expect($start)->not->toBeFalse("{$path} phải có tiêu đề {$heading}");

    $rest = substr($text, $start + 1);
    $end = preg_match('/\n#{1,'.$level.'} /', $rest, $match, PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : strlen($rest);

    $blocks = array_values(array_filter(
        array_map('trim', preg_split('/\n(?=\s*- )|\n\s*\n/', substr($rest, 0, $end)) ?: []),
        fn (string $block): bool => str_contains($block, $needle),
    ));

    expect($blocks)->toHaveCount(1, "{$path}, {$heading}: phải có đúng một khối chứa {$needle}");

    return $blocks[0];
}

/** @return list<string> */
function m10IntakePermissions(): array
{
    return array_values(array_map(
        fn (Permission $permission): string => $permission->value,
        array_filter(Permission::cases(), fn (Permission $permission): bool => str_starts_with($permission->value, 'intake.')),
    ));
}

/** @return list<string> */
function m10MoneyPermissions(): array
{
    return array_values(array_map(
        fn (Permission $permission): string => $permission->value,
        array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => preg_match('/^(billing|contract|payment|revenue)\./', $permission->value) === 1,
        ),
    ));
}

function m10ScheduledEvent(string $name): Event
{
    $matches = collect(Schedule::events())->filter(fn (Event $event): bool => $event->description === $name)->values();

    expect($matches)->toHaveCount(1, "phải có đúng một tác vụ lịch tên {$name}");

    return $matches->first();
}

it('places the M10 upgrade paragraph in Bước 5, right after the M9 money paragraph', function () {
    $guide = (string) file_get_contents(base_path('docs/CAI-DAT.md'));

    $step5 = strpos($guide, "\n### Bước 5");
    $step6 = strpos($guide, "\n### Bước 6");
    $m9Money = strpos($guide, '**Bản cập nhật M9 (hợp đồng dịch vụ và thu phí theo đợt) làm gì trên máy chủ đã có dữ liệu');
    $m10 = strpos($guide, '**Bản cập nhật M10 (tiếp nhận) làm gì trên máy chủ đã có dữ liệu');
    $demo = strpos($guide, '**Muốn dữ liệu mẫu để demo cho khách trước khi dùng thật**');

    expect($m10)->not->toBeFalse()
        ->and($step5)->toBeLessThan($m9Money)
        ->and($m9Money)->toBeLessThan($m10)
        ->and($m10)->toBeLessThan($demo)
        ->and($demo)->toBeLessThan($step6);
});

it('names every M10 migration file, and only files that exist, with the two new tables', function () {
    $paragraph = m10UpgradeParagraph();

    $intakeMigrations = collect(File::files(database_path('migrations')))
        ->map(fn (SplFileInfo $file): string => $file->getBasename('.php'))
        ->filter(fn (string $name): bool => str_contains($name, 'intake'))
        ->sort()
        ->values()
        ->all();

    preg_match_all('/`(\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+)`/', $paragraph, $named);

    expect($intakeMigrations)->toHaveCount(4)
        ->and(collect($named[1])->sort()->values()->all())->toBe($intakeMigrations)
        ->and($paragraph)->toContain('`migrate --force`')
        ->toContain('`intake_requests`')
        ->toContain('`intake_parties`')
        ->and(Schema::hasTable('intake_requests'))->toBeTrue()
        ->and(Schema::hasTable('intake_parties'))->toBeTrue();
});

it('names the three intake permissions and says db:seed --force is mandatory for the Tiếp nhận menu', function () {
    $paragraph = m10UpgradeParagraph();
    $permissions = m10IntakePermissions();

    expect($permissions)->toHaveCount(3)
        ->and($paragraph)->toContain('`db:seed --force`')
        ->toContain('ba quyền')
        ->toContain('**Bắt buộc**')
        ->toContain(__('intake.resource.plural_label'));

    foreach ($permissions as $permission) {
        expect($paragraph)->toContain("`{$permission}`");
    }
});

it('keeps the Tiếp nhận menu from everyone, admin included, until db:seed --force brings the intake permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->withRole(Role::Admin)->create();
    $index = IntakeRequestResource::getUrl('index', panel: 'admin');

    // Một máy chủ M9 vừa `migrate --force`: vai trò có đủ, ba quyền `intake.*` chưa từng tồn tại.
    PermissionModel::query()->whereIn('name', m10IntakePermissions())->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Mục menu: Filament chỉ đăng ký mục của resource khi `canAccess()` đúng
    // (`Resource::registerNavigationItems()`); hỏi thẳng điều kiện đó, vì panel dựng menu MỘT lần cho cả
    // tiến trình test — trang chủ tải lần hai vẫn vẽ menu của lần đầu.
    $this->actingAs($admin->fresh(), 'web')->get($index)->assertNotFound();
    expect(IntakeRequestResource::canAccess())->toBeFalse();

    // Bước của tài liệu: `db:seed --force` (ReferenceDataSeeder → RolesAndPermissionsSeeder).
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->actingAs($admin->fresh(), 'web')->get($index)->assertOk();
    expect(IntakeRequestResource::canAccess())->toBeTrue();
});

it('lists the two new scheduled tasks with the times the scheduler really uses, and says the anonymisation cannot be undone', function () {
    $paragraph = m10UpgradeParagraph();

    expect(m10ScheduledEvent('intakes.remind-unanswered')->expression)->toBe('*/15 * * * *')
        ->and(m10ScheduledEvent('prospects.anonymise')->expression)->toBe('30 3 * * *')
        ->and($paragraph)->toContain('`intakes.remind-unanswered`')
        ->toContain('mỗi 15 phút')
        ->toContain('`prospects.anonymise`')
        ->toContain('03:30')
        ->toContain('không hoàn tác được')
        // Dưới dòng cron sẵn có — không dòng cron mới nào.
        ->toContain('không thêm dòng cron nào');
});

it('gives the two optional env vars with the defaults the code falls back to, and asks for the lawyer before the first record ages out', function () {
    $paragraph = m10UpgradeParagraph();

    config(['vkcrm.prospect_retention_months' => null]);

    expect(IntakeRequest::retentionMonths())->toBe(24)
        ->and(config('vkcrm.intake_response_hours'))->toBe(4)
        ->and($paragraph)->toContain('`PROSPECT_RETENTION_MONTHS`')
        ->toContain('mặc định 24')
        ->toContain('`INTAKE_RESPONSE_HOURS`')
        ->toContain('mặc định 4')
        ->toContain('luật sư');
});

it('names the three intake permissions next to the four money ones in both upgrade runbooks', function () {
    $readme = m10UpgradeBlock('README.md', '## Triển khai lên máy chủ thật', '`db:seed --force` chạy `ReferenceDataSeeder`');
    $guide = m10UpgradeBlock('docs/CAI-DAT.md', '## Nâng cấp lên bản mới', '`db:seed --force` an toàn để chạy lại');

    expect(m10MoneyPermissions())->toHaveCount(4);

    foreach ([...m10MoneyPermissions(), ...m10IntakePermissions()] as $permission) {
        expect($readme)->toContain("`{$permission}`")
            ->and($guide)->toContain("`{$permission}`");
    }

    expect($readme)->toContain('ba quyền tiếp nhận')
        ->and($guide)->toContain('ba quyền tiếp nhận');
});
