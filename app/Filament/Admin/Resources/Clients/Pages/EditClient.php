<?php

namespace App\Filament\Admin\Resources\Clients\Pages;

use App\Filament\Admin\Resources\Clients\ClientResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * **`hasDatabaseTransactions()` khoá cứng `false` (fix round 3, minor).** Panel admin không bật
 * `databaseTransactions()` hôm nay, cùng hình dạng `CreateMatter` — nhưng lưu hồ sơ khách hàng ở
 * đây gọi `$record->update()`, và `Client::updated()` gắn `SyncClientPartyIdentities::handle()`
 * (M6.5 Task 8). Từ fix round 3, `handle()` đó dispatch một job `afterCommit()` — nếu
 * `EditRecord::save()` một ngày nào đó chạy trong một transaction NGOÀI (panel bật
 * `databaseTransactions()`), transaction của CHÍNH `handle()` trở thành một savepoint, và việc
 * job có thật sự chạy hay không phụ thuộc vào transaction NGOÀI đó có commit hay không — một
 * ràng buộc `SyncClientPartyIdentities` không kiểm soát được. Khoá cứng `false` ở đây để một thay
 * đổi cấu hình panel sau này không âm thầm phá vỡ tính đúng đắn của việc dispatch sau commit.
 */
class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    public function hasDatabaseTransactions(): bool
    {
        return false;
    }

    /**
     * Chỉ `DeleteAction`. Khuôn mẫu `make:filament-resource` sinh thêm `ForceDeleteAction` và
     * `RestoreAction`, nhưng policy của model này không định nghĩa `restore` lẫn `forceDelete`,
     * và Laravel từ chối một ability không có phương thức tương ứng khi model đã có policy — nên
     * hai nút đó luôn bị từ chối. `HeaderActionsAreReachableTest` giữ luật này cho mọi trang.
     *
     * Task 2 (rà soát cuối): `ClientPolicy::delete()` giờ có thể từ chối kèm một thông điệp (khách
     * hàng còn vụ việc đang mở). Mặc định Filament, `CanBeHidden::isAuthorizedOrNotHiddenWhenUnauthorized()`
     * BỎ QUA thông điệp đó và ẩn hẳn nút khi không có `authorizationNotification()` hay
     * `authorizationTooltip()` — admin sẽ không hiểu vì sao nút biến mất. `authorizationNotification()`
     * giữ nút hiển thị (miễn `Response::deny()` có message) và đổi một lần bấm bị từ chối thành
     * một thông báo nêu đúng số vụ, thay vì xoá trót lọt hoặc một nút chết không lời giải thích.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorizationNotification(),
        ];
    }
}
