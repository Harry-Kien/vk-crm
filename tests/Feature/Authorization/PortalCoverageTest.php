<?php

use App\Models\ClientUser;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Models\MatterType;
use App\Models\MatterTypeStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Pivot;

it('makes every model state whether the portal may read it', function () {
    /**
     * Cố ý không giới hạn, có lý do ghi trong docblock của từng class:
     * - User, ClientUser: model xác thực, gọi auth() trong scope của chúng sẽ đệ quy.
     * - MatterType, MatterTypeStage: dữ liệu cấu hình, portal cần đọc nhãn giai đoạn (SPEC §8.3).
     */
    $exempt = [User::class, ClientUser::class, MatterType::class, MatterTypeStage::class];

    $files = glob(app_path('Models/*.php')) ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);
        $uses = class_uses_recursive($class);
        $restricted = in_array(RestrictedToClientPortal::class, $uses, true);

        in_array($class, $exempt, true)
            ? expect($restricted)->toBeFalse("{$class} nằm trong danh sách miễn trừ nhưng lại dùng trait")
            : expect($restricted)->toBeTrue("{$class} chưa quyết định: dùng RestrictedToClientPortal, hoặc thêm vào danh sách miễn trừ kèm lý do");
    }
});

it('gives every restricted model a policy', function () {
    $files = glob(app_path('Models/*.php')) ?: [];

    foreach ($files as $file) {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $class = 'App\\Models\\'.$name;

        if (! in_array(RestrictedToClientPortal::class, class_uses_recursive($class), true)) {
            continue;
        }

        // Pivot không bao giờ là resource Filament nên không cần policy.
        if (is_subclass_of($class, Pivot::class)) {
            continue;
        }

        expect(class_exists('App\\Policies\\'.$name.'Policy'))
            ->toBeTrue("Thiếu App\\Policies\\{$name}Policy cho model bị giới hạn portal");
    }
});
