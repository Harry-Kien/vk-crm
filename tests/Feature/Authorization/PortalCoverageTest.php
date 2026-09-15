<?php

use App\Models\ClientUser;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Models\MatterType;
use App\Models\MatterTypeStage;
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
     */
    $exempt = [User::class, ClientUser::class, MatterType::class, MatterTypeStage::class];

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
