<?php

use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\ClientUsers\Pages\EditClientUser;
use App\Filament\Admin\Resources\MatterTypes\Pages\EditMatterType;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Gate;

/**
 * Một cái nút không ai bấm được là một lời nói dối về hệ thống. Bốn trang sửa của M3 được sinh ra
 * từ khuôn mẫu `make:filament-resource` với `ForceDeleteAction` và `RestoreAction`, nhưng KHÔNG
 * policy nào của bốn model đó định nghĩa `restore` hay `forceDelete` — mà Laravel từ chối một
 * ability không có phương thức tương ứng khi model đã có policy, nên hai nút ấy luôn bị từ chối.
 * Ở `Matter` thì `forceDelete` còn bị chặn thêm một tầng nữa ở model (`MatterNotDestroyable`).
 *
 * Test này không liệt kê tên hai lớp bị gỡ — nó phát biểu LUẬT: mọi thao tác trên thanh tiêu đề
 * của một trang resource phải có một phương thức policy cùng tên. Ai thêm lại một nút chết ở bất
 * kỳ trang nào, dù tên gì, cũng đỏ ở đây.
 */
function headerActionNames(EditRecord $page): array
{
    $method = new ReflectionMethod($page, 'getHeaderActions');
    $method->setAccessible(true);

    return array_map(fn (object $action): string => $action->getName(), $method->invoke($page));
}

it('registers no header action whose ability the policy does not define', function (string $pageClass) {
    /** @var EditRecord $page */
    $page = new $pageClass;
    $model = $pageClass::getResource()::getModel();
    $policy = Gate::getPolicyFor($model);

    expect($policy)->not->toBeNull();

    $names = headerActionNames($page);

    expect($names)->not->toBe([]);

    $undefined = array_values(array_filter(
        $names,
        fn (string $name): bool => ! method_exists($policy, $name),
    ));

    expect($undefined)->toBe([]);
})->with([
    EditClient::class,
    EditClientUser::class,
    EditMatterType::class,
    EditUser::class,
]);
