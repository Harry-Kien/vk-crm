<?php

use App\Enums\NavigationGroup;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationManager;
use Illuminate\Support\Collection;

/**
 * Menu trái của panel nội bộ có nhóm (làn rv, 2026-10-15: "nhiều chữ nhìn rối quá, làm như các CRM
 * chuyên nghiệp"). Việc này CHỈ đổi nhóm và thứ tự, không đổi quyền: mỗi vai thấy ĐÚNG tập mục như
 * trước khi có nhóm. Các tập dưới đây chụp từ `main` 377b5259 (làn tk đã gộp, trước làn rv), mỗi vai một
 * tập: tập của `main` b7013415 cộng "Công việc" (`TaskResource` của làn tk, mở cho mọi nhân sự).
 *
 * Khi gộp làn rv (phán quyết controller): "Công việc" và "Việc của tôi" ở khu không nhóm trên cùng ngay
 * dưới Bảng điều khiển — việc hằng ngày đứng đầu, như "My tasks" của các CRM chuyên nghiệp; "Theo dõi đội
 * ngũ" và "Hiệu suất" vào nhóm "Đội ngũ".
 *
 * Đọc menu qua `Filament::getNavigation()` — chính hàm sidebar dùng để vẽ — sau khi đăng nhập vai
 * đó. `NavigationManager` là dịch vụ `scoped`, mỗi test một ứng dụng mới nên không lẫn giữa các vai.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/** @return array<string, ?string> nhãn mục => nhãn nhóm (null: khu không nhóm trên cùng) */
function rvNavigationFor(User $user): array
{
    test()->actingAs($user, 'web');
    app()->forgetInstance(NavigationManager::class);

    $items = [];

    foreach (Filament::getNavigation() as $group) {
        foreach ($group->getItems() as $item) {
            $items[$item->getLabel()] = $group->getLabel();
        }
    }

    return $items;
}

/** Nhóm mong đợi của từng mục — một bảng cho mọi vai, vì nhóm không phụ thuộc người xem. */
function rvExpectedGroup(string $label): ?string
{
    $groups = [
        'Bảng điều khiển' => null,
        'Công việc' => null,
        'Việc của tôi' => null,
        'Tìm kiếm' => null,
        'Tiếp nhận' => NavigationGroup::Clients,
        'Khách hàng' => NavigationGroup::Clients,
        'Vụ việc' => NavigationGroup::Clients,
        'Tài khoản portal' => NavigationGroup::Clients,
        'Doanh thu' => NavigationGroup::Finance,
        'Công nợ' => NavigationGroup::Finance,
        'Nhắc thanh toán cho khách' => NavigationGroup::Finance,
        'Bàn giao hàng loạt' => NavigationGroup::Team,
        'Nhân sự' => NavigationGroup::Team,
        'Hiệu suất' => NavigationGroup::Team,
        'Theo dõi đội ngũ' => NavigationGroup::Team,
        'Báo cáo tiếp nhận' => NavigationGroup::Reports,
        'Loại vụ việc' => NavigationGroup::System,
        'Thông tin văn phòng' => NavigationGroup::System,
        'Kho tài liệu' => NavigationGroup::System,
        'Kết nối AI' => NavigationGroup::System,
        'Kết nối AI của tôi' => NavigationGroup::System,
        'Thư đã gửi' => NavigationGroup::System,
        'Nhật ký hệ thống' => NavigationGroup::System,
    ];

    expect($groups)->toHaveKey($label);

    return $groups[$label]?->label();
}

it('keeps exactly the menu items each role saw before the grouping, and puts each in its group', function (Role $role, array $before) {
    $menu = rvNavigationFor(User::factory()->withRole($role)->create());

    expect(array_keys($menu))->toEqualCanonicalizing($before);

    foreach ($menu as $label => $group) {
        expect($group)->toBe(rvExpectedGroup($label), "Mục \"{$label}\" của vai {$role->value}");
    }
})->with([
    'quản trị' => [Role::Admin, [
        'Bảng điều khiển', 'Công việc', 'Tìm kiếm', 'Tiếp nhận', 'Khách hàng', 'Vụ việc', 'Tài khoản portal', 'Doanh thu', 'Công nợ',
        'Nhắc thanh toán cho khách', 'Bàn giao hàng loạt', 'Nhân sự', 'Hiệu suất', 'Theo dõi đội ngũ', 'Báo cáo tiếp nhận',
        'Loại vụ việc', 'Thông tin văn phòng', 'Kho tài liệu', 'Kết nối AI', 'Kết nối AI của tôi', 'Thư đã gửi', 'Nhật ký hệ thống',
    ]],
    'quản lý' => [Role::Manager, [
        'Bảng điều khiển', 'Công việc', 'Tìm kiếm', 'Tiếp nhận', 'Khách hàng', 'Vụ việc', 'Tài khoản portal', 'Doanh thu', 'Công nợ',
        'Bàn giao hàng loạt', 'Hiệu suất', 'Theo dõi đội ngũ', 'Việc của tôi', 'Báo cáo tiếp nhận', 'Loại vụ việc',
        'Kết nối AI của tôi', 'Thư đã gửi', 'Nhật ký hệ thống',
    ]],
    'luật sư' => [Role::Lawyer, [
        'Bảng điều khiển', 'Công việc', 'Tìm kiếm', 'Tiếp nhận', 'Khách hàng', 'Vụ việc', 'Tài khoản portal', 'Doanh thu', 'Hiệu suất',
        'Việc của tôi', 'Loại vụ việc', 'Kết nối AI của tôi', 'Thư đã gửi',
    ]],
    'trợ lý' => [Role::Assistant, [
        'Bảng điều khiển', 'Công việc', 'Tìm kiếm', 'Tiếp nhận', 'Khách hàng', 'Vụ việc', 'Tài khoản portal', 'Hiệu suất', 'Việc của tôi',
        'Loại vụ việc', 'Kết nối AI của tôi', 'Thư đã gửi',
    ]],
    'kế toán' => [Role::Accountant, ['Bảng điều khiển', 'Công việc', 'Tìm kiếm', 'Vụ việc', 'Doanh thu', 'Công nợ', 'Loại vụ việc']],
    'kinh doanh' => [Role::Sales, ['Bảng điều khiển', 'Công việc', 'Tiếp nhận', 'Loại vụ việc']],
]);

it('orders the groups as the enum declares them, and the items inside each group by their own sort', function () {
    test()->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    $groups = [];

    foreach (Filament::getNavigation() as $group) {
        $groups[$group->getLabel() ?? '—'] = array_values(array_map(
            fn ($item): string => $item->getLabel(),
            $group->getItems() instanceof Collection ? $group->getItems()->all() : $group->getItems(),
        ));
    }

    $grouped = array_values(array_filter(array_keys($groups), fn (string $label): bool => $label !== '—'));

    expect(array_key_first($groups))->toBe('—')
        ->and($grouped)->toBe(array_map(fn (NavigationGroup $group): string => $group->label(), NavigationGroup::cases()))
        ->and($groups[NavigationGroup::Clients->label()])->toBe(['Tiếp nhận', 'Khách hàng', 'Vụ việc', 'Tài khoản portal'])
        ->and($groups[NavigationGroup::Finance->label()])->toBe(['Doanh thu', 'Công nợ', 'Nhắc thanh toán cho khách'])
        ->and($groups[NavigationGroup::Team->label()])->toBe(['Bàn giao hàng loạt', 'Nhân sự', 'Theo dõi đội ngũ', 'Hiệu suất'])
        ->and($groups[NavigationGroup::Reports->label()])->toBe(['Báo cáo tiếp nhận'])
        ->and($groups[NavigationGroup::System->label()])->toBe([
            'Loại vụ việc', 'Thông tin văn phòng', 'Kho tài liệu', 'Kết nối AI', 'Kết nối AI của tôi', 'Thư đã gửi', 'Nhật ký hệ thống',
        ])
        // Khu không nhóm: Bảng điều khiển (`Dashboard::$navigationSort = -2`), việc hằng ngày (Công việc -1),
        // rồi Tìm kiếm (10). Quản trị viên có "Theo dõi đội ngũ" nên không có mục "Việc của tôi".
        ->and($groups['—'])->toBe(['Bảng điều khiển', 'Công việc', 'Tìm kiếm']);
});

it('puts daily work right under the dashboard: Công việc, then Việc của tôi, then Tìm kiếm', function () {
    test()->actingAs(User::factory()->withRole(Role::Lawyer)->create(), 'web');

    $top = collect(Filament::getNavigation())->first(fn ($group): bool => $group->getLabel() === null);
    $items = $top->getItems() instanceof Collection ? $top->getItems()->all() : $top->getItems();

    expect(array_values(array_map(fn ($item): string => $item->getLabel(), $items)))
        ->toBe(['Bảng điều khiển', 'Công việc', 'Việc của tôi', 'Tìm kiếm']);
});

it('hides a group the viewer has no item in', function () {
    test()->actingAs(User::factory()->withRole(Role::Sales)->create(), 'web');

    $labels = array_map(fn ($group): ?string => $group->getLabel(), Filament::getNavigation());

    expect($labels)->not->toContain(NavigationGroup::Finance->label())
        ->not->toContain(NavigationGroup::Team->label())
        ->not->toContain(NavigationGroup::Reports->label())
        ->toContain(NavigationGroup::Clients->label())
        ->toContain(NavigationGroup::System->label());
});

it('names every group in vietnamese from lang/vi/navigation.php, and gives Filament the same label', function () {
    foreach (NavigationGroup::cases() as $group) {
        expect($group->label())->toBe(__('navigation.groups.'.$group->value))
            ->not->toStartWith('navigation.')
            ->and($group->getLabel())->toBe($group->label());
    }

    expect(NavigationGroup::Clients->label())->toBe('Khách hàng & vụ việc')
        ->and(NavigationGroup::Finance->label())->toBe('Tài chính')
        ->and(NavigationGroup::Team->label())->toBe('Đội ngũ')
        ->and(NavigationGroup::Reports->label())->toBe('Báo cáo')
        ->and(NavigationGroup::System->label())->toBe('Hệ thống');
});
