<?php

use App\Models\ClientUser;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Models\MatterType;
use App\Models\MatterTypeStage;
use App\Models\Setting;
use App\Models\SystemHealth;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Quét đệ quy app/Models thay vì glob nông, để một model đặt trong thư mục con
 * (app/Models/Foo/Bar.php -> App\Models\Foo\Bar) không lọt khỏi lưới an toàn này.
 *
 * Chỉ giữ lại class Eloquent Model thật: app/Models/Concerns chứa trait (HasBlameable,
 * RestrictedToClientPortal, HidesInternalAttributesFromPortal), không phải model, nên phải lọc
 * bằng is_subclass_of thay vì liệt kê mọi file .php tìm thấy.
 *
 * @return list<class-string<Model>>
 */
function portalCoverageModelClasses(): array
{
    $basePath = app_path('Models');

    $classes = [];

    foreach (Finder::create()->files()->name('*.php')->in($basePath) as $file) {
        $relative = Str::of($file->getRelativePathname())
            ->replace(['/', '\\'], '\\')
            ->beforeLast('.php');

        $class = 'App\\Models\\'.$relative;

        if (! class_exists($class)
            || ! is_subclass_of($class, Model::class)
            || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $classes[] = $class;
    }

    return $classes;
}

it('makes every model state whether the portal may read it', function () {
    /**
     * Cố ý không giới hạn, có lý do ghi trong docblock của từng class:
     * - User, ClientUser: model xác thực, gọi auth() trong scope của chúng sẽ đệ quy.
     * - MatterType, MatterTypeStage: dữ liệu cấu hình, portal cần đọc nhãn giai đoạn (SPEC §8.3).
     * - SystemHealth: dữ liệu VẬN HÀNH, không phải dữ liệu hồ sơ — một dòng duy nhất nói lịch
     *   chạy tự động còn sống hay không (SPEC §2). Nó không mang thông tin của khách hàng nào,
     *   nên một scope theo khách hàng ở đây là vô nghĩa. Ranh giới thật là ở màn hình:
     *   `SystemHealthWidget::canView()` chỉ trả true cho nhân sự nội bộ, và không màn hình nào
     *   của cổng khách đọc bảng này. Có test cho cả hai điều đó.
     * - Setting (M7 Task 10): cấu hình khoá–giá trị của hệ thống, không mang dữ liệu của khách nào.
     *   Cổng PHẢI đọc được chín thông tin văn phòng trong đó (hotline trên trang lỗi, chân trang
     *   đăng nhập) qua `App\Support\OfficeProfile`; một scope theo khách ở đây sẽ lặng lẽ đưa cổng
     *   về giá trị `.env` cũ. Ranh giới thật: không màn hình nào của cổng đọc `Setting` trực tiếp,
     *   chỉ đọc chín trường công khai qua `OfficeProfile` (test cấu trúc ở `OfficeProfileTest`).
     */
    $exempt = [
        User::class,
        ClientUser::class,
        MatterType::class,
        MatterTypeStage::class,
        SystemHealth::class,
        Setting::class,
    ];

    $classes = portalCoverageModelClasses();
    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        $uses = class_uses_recursive($class);
        $restricted = in_array(RestrictedToClientPortal::class, $uses, true);

        in_array($class, $exempt, true)
            ? expect($restricted)->toBeFalse("{$class} nằm trong danh sách miễn trừ nhưng lại dùng trait")
            : expect($restricted)->toBeTrue("{$class} chưa quyết định: dùng RestrictedToClientPortal, hoặc thêm vào danh sách miễn trừ kèm lý do");
    }
});

it('gives every restricted model a policy', function () {
    foreach (portalCoverageModelClasses() as $class) {
        if (! in_array(RestrictedToClientPortal::class, class_uses_recursive($class), true)) {
            continue;
        }

        // Pivot không bao giờ là resource Filament nên không cần policy.
        if (is_subclass_of($class, Pivot::class)) {
            continue;
        }

        $name = class_basename($class);

        expect(class_exists('App\\Policies\\'.$name.'Policy'))
            ->toBeTrue("Thiếu App\\Policies\\{$name}Policy cho model bị giới hạn portal");
    }
});
