<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Widgets\MatterCountsWidget;
use App\Filament\Admin\Widgets\MattersByStageWidget;
use App\Filament\Admin\Widgets\MattersMissingDocumentsWidget;
use App\Filament\Admin\Widgets\PendingChecklistReviewsWidget;
use App\Filament\Admin\Widgets\StaleMattersWidget;
use App\Filament\Admin\Widgets\UnseenUpdatesWidget;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Đọc các ô số qua reflection: getStats() là protected, và đây chính là hàm dựng con số
 * người dùng nhìn thấy. Trả về [nhãn => giá trị] để test nói bằng ngôn ngữ của màn hình.
 */
function matterCountsWidgetStats(MatterCountsWidget $widget): array
{
    $method = new ReflectionMethod($widget, 'getStats');
    $method->setAccessible(true);

    $out = [];

    foreach ($method->invoke($widget) as $stat) {
        $out[(string) $stat->getLabel()] = (string) $stat->getValue();
    }

    return $out;
}

function openMatterFor(User $lawyer, MatterType $type): Matter
{
    return Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'matter_type_id' => $type->id,
        'stage' => $type->stages->reject(fn ($s) => $s->is_terminal)->first()->key,
        'closed_at' => null,
    ]);
}

it('counts only the matters the viewer is allowed to list', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $stranger = User::factory()->withRole(Role::Lawyer)->create();
    $type = MatterType::factory()->withStages()->create();

    openMatterFor($lawyer, $type);
    openMatterFor($stranger, $type);
    openMatterFor($stranger, $type);

    $this->actingAs($lawyer, 'web');

    $stats = matterCountsWidgetStats(new MatterCountsWidget);

    // Cái bẫy của mọi ô số trên trang chủ: nó rất dễ trở thành con số của CẢ VĂN PHÒNG trong khi
    // mọi danh sách bên dưới lại là con số của riêng người đang xem. Hai con số nói hai chuyện
    // khác nhau trên cùng một màn hình là cách nhanh nhất để không ai tin màn hình đó nữa.
    expect($stats[__('widgets.matter_counts.total')])->toBe('1');
});

it('separates matters still being worked from matters already closed', function () {
    $lawyer = User::factory()->withRole(Role::Manager)->create();
    $type = MatterType::factory()->withStages()->create();

    openMatterFor($lawyer, $type);
    openMatterFor($lawyer, $type);
    openMatterFor($lawyer, $type)->update(['closed_at' => now()->subDay()]);

    $this->actingAs($lawyer, 'web');

    $stats = matterCountsWidgetStats(new MatterCountsWidget);

    expect($stats[__('widgets.matter_counts.total')])->toBe('3')
        ->and($stats[__('widgets.matter_counts.open')])->toBe('2')
        ->and($stats[__('widgets.matter_counts.closed')])->toBe('1');
});

it('counts matters opened this month and ignores one opened last month', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $type = MatterType::factory()->withStages()->create();

    openMatterFor($manager, $type)->update(['opened_at' => now()->startOfMonth()]);
    openMatterFor($manager, $type)->update(['opened_at' => now()->startOfMonth()->subDay()]);

    $this->actingAs($manager, 'web');

    $stats = matterCountsWidgetStats(new MatterCountsWidget);

    expect($stats[__('widgets.matter_counts.opened_this_month')])->toBe('1');
});

it('still shows the closed matters of a withdrawn matter nowhere', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $type = MatterType::factory()->withStages()->create();

    openMatterFor($manager, $type);
    $withdrawn = openMatterFor($manager, $type);
    $withdrawn->delete();

    $this->actingAs($manager, 'web');

    $stats = matterCountsWidgetStats(new MatterCountsWidget);

    // Một hồ sơ đã rút không còn là hồ sơ của văn phòng. Nếu nó vẫn nằm trong tổng thì con số
    // trên trang chủ sẽ không bao giờ khớp với danh sách vụ việc, và người dùng sẽ tin danh sách.
    expect($stats[__('widgets.matter_counts.total')])->toBe('1');
});

it('shows the tiles to an accountant, who may see counts but not matter content', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web');

    expect(MatterCountsWidget::canView())->toBeTrue();
});

/**
 * **Nửa QUYỀN của `canView()` cần nhân chứng riêng.**
 *
 * Hai test trên chỉ dùng hai loại người: một nhân sự CÓ quyền, và một tài khoản portal. Đo được:
 * xoá hẳn vế quyền khỏi `canView()` thì cả bảy test của tệp này vẫn xanh — vì tài khoản portal bị
 * từ chối bởi riêng `instanceof User`, và mọi nhân sự trong các test khác đều có quyền. Cùng hình
 * dạng mà rà soát Task 3 đã gọi tên hai lần: một test đi tới đúng kết luận vì một lý do khác với
 * lý do nó mang tên.
 *
 * Nên nhân chứng ở đây là một nhân sự THẬT, đăng nhập trên guard `web`, không có `matter.view` và
 * không có `matter.viewAny` — tình huống có thật khi văn phòng dựng một vai chỉ để làm việc khác
 * (SPEC §10.1 cho phép đặt vai theo quyền). Test khẳng định TRƯỚC rằng vế `instanceof` đã qua,
 * nên nó không thể xanh vì một lần từ chối ở cổng khác.
 */
it('hides the tiles from a staff account that holds neither matter permission', function () {
    $stranger = User::factory()->create();
    $stranger->syncRoles([]);
    $stranger->syncPermissions([]);

    $this->actingAs($stranger, 'web');

    // Tiền đề: vế `instanceof User` của `canView()` ĐÃ qua, nên câu trả lời chỉ còn phụ thuộc
    // vào quyền. Không có hai dòng này, test xanh y hệt khi guard rỗng.
    expect(Auth::user())->toBeInstanceOf(User::class)
        ->and($stranger->can(Permission::MatterView->value))->toBeFalse()
        ->and($stranger->can(Permission::MatterViewAny->value))->toBeFalse();

    expect(MatterCountsWidget::canView())->toBeFalse();
});

it('hides the tiles from a client portal account', function () {
    $this->actingAs(ClientUser::factory()->create(), 'client');

    expect(MatterCountsWidget::canView())->toBeFalse();
});

it('sits above every other dashboard widget, because it is a one line summary', function () {
    $sort = new ReflectionProperty(MatterCountsWidget::class, 'sort');
    $sort->setAccessible(true);

    $others = [
        StaleMattersWidget::class,
        PendingChecklistReviewsWidget::class,
        MattersMissingDocumentsWidget::class,
        UnseenUpdatesWidget::class,
        MattersByStageWidget::class,
    ];

    foreach ($others as $widget) {
        $theirs = new ReflectionProperty($widget, 'sort');
        $theirs->setAccessible(true);

        expect($sort->getValue())->toBeLessThan($theirs->getValue());
    }
});
