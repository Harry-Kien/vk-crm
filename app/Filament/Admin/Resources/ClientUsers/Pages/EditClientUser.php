<?php

namespace App\Filament\Admin\Resources\ClientUsers\Pages;

use App\Filament\Admin\Resources\ClientUsers\ClientUserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditClientUser extends EditRecord
{
    protected static string $resource = ClientUserResource::class;

    /**
     * Chỉ `DeleteAction`. Khuôn mẫu `make:filament-resource` sinh thêm `ForceDeleteAction` và
     * `RestoreAction`, nhưng policy của model này không định nghĩa `restore` lẫn `forceDelete`,
     * và Laravel từ chối một ability không có phương thức tương ứng khi model đã có policy — nên
     * hai nút đó luôn bị từ chối. `HeaderActionsAreReachableTest` giữ luật này cho mọi trang.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Task 2 (`roles/roles-01`, critical): bản trước gọi
     * `Gate::authorize('create', [ClientUser::class, $client])` với $client suy từ
     * `$data['client_id']` — tức chỉ xác nhận CLIENT_ID MỚI có nằm trong tầm với của người sửa
     * hay không. Với một luật sư, client_id mới nằm trong tầm với của CHÍNH HỌ (một khách khác
     * họ cũng liệt kê được) đi qua trót lọt, và tài khoản portal bị chuyển sang khách hàng khác —
     * khách cũ mất tài khoản, khách mới (kẻ tấn công chọn) thấy hồ sơ không phải của mình ngay
     * lần đăng nhập kế tiếp bằng mật khẩu cũ.
     *
     * client_id là bất biến sau khi tạo, với BẤT KỲ ai, kể cả admin — đổi khách nghĩa là tạo tài
     * khoản mới (Hành vi phải đạt, task brief). Nên câu hỏi không còn là "client_id mới có hợp lệ
     * không" mà là "bỏ qua bất kỳ client_id nào $data mang, luôn ghi đè lại đúng giá trị đang có
     * trên bản ghi" — không đọc `$data['client_id']` vào đâu cả, kể cả để so sánh.
     *
     * ClientUserForm::configure() đã `disabled()` ô này khi sửa, nhưng đó chỉ là lớp UI: Filament
     * tự cảnh báo ngay trong `CanBeDisabled::disabled()` rằng một request bị chỉnh sửa tay vẫn
     * gửi được `client_id` khác. Hàm này là lớp chặn THẬT, không phụ thuộc trạng thái `disabled()`
     * của field.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['client_id'] = $this->record->client_id;

        return $data;
    }
}
