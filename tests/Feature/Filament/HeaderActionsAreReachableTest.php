<?php

use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\ClientUsers\Pages\EditClientUser;
use App\Filament\Admin\Resources\ClientUsers\Pages\ListClientUsers;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Resources\MatterTypes\Pages\EditMatterType;
use App\Filament\Admin\Resources\MatterTypes\Pages\ListMatterTypes;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;

/**
 * Một cái nút không ai bấm được là một lời nói dối về hệ thống. Bốn trang sửa của M3 được sinh ra
 * từ khuôn mẫu `make:filament-resource` với `ForceDeleteAction` và `RestoreAction`, nhưng KHÔNG
 * policy nào của bốn model đó định nghĩa `restore` hay `forceDelete` — mà Laravel từ chối một
 * ability không có phương thức tương ứng khi model đã có policy, nên hai nút ấy luôn bị từ chối.
 * Ở `Matter` thì `forceDelete` còn bị chặn thêm một tầng nữa ở model (`MatterNotDestroyable`).
 *
 * Test này không liệt kê tên hai lớp bị gỡ — nó phát biểu LUẬT: **một thao tác trên thanh tiêu đề
 * mang TÊN CỦA MỘT ABILITY phải có một phương thức policy cùng tên**, vì Filament tự hỏi `Gate`
 * bằng đúng cái tên đó và Laravel từ chối một ability không có phương thức tương ứng.
 *
 * **Phạm vi của nó, nói thẳng, vì bản đầu của docblock này viết "mọi thao tác trên thanh tiêu đề
 * của một trang resource" và câu đó rộng hơn cái test làm được.** Dataset là các trang `List` và
 * `Edit` — chỗ Filament sinh ra những nút TỰ hỏi `Gate` theo tên (`create`, `delete`,
 * `forceDelete`, `restore`). `ViewMatter` KHÔNG nằm trong đó và không thể nằm trong đó: nút duy
 * nhất của nó tên `togglePortalPublication`, không có (và không nên có) một phương thức policy
 * tên như vậy — nó tự gác bằng `->visible(fn () => Gate::allows('update', ...))`, và
 * `SetMatterPortalPublication` hỏi lại `Gate` lần nữa. Luật phát biểu ở đây không nói gì về những
 * cái nút tự gác như vậy; thứ ghim chúng là `ViewMatterTest`.
 *
 * Nói cách khác: cái test này canh đúng một hình dạng lỗi — một nút mang tên ability mà policy
 * không có — và nó canh hình dạng đó trên MỌI trang `List`/`Edit` của panel.
 */
function headerActionNames(Page $page): array
{
    $method = new ReflectionMethod($page, 'getHeaderActions');
    $method->setAccessible(true);

    return array_map(fn (object $action): string => $action->getName(), $method->invoke($page));
}

it('registers no header action whose ability the policy does not define', function (string $pageClass) {
    /** @var Page $page */
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
    ListClients::class,
    ListClientUsers::class,
    ListMatters::class,
    ListMatterTypes::class,
    ListUsers::class,
]);
