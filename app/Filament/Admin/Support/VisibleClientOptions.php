<?php

namespace App\Filament\Admin\Support;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\Matter;
use App\Models\User;
use App\Support\ClientVisibility;
use Illuminate\Support\Facades\Auth;

/**
 * Danh sách khách hàng cho các ô chọn/lọc "Khách hàng" trong panel admin — dùng chung để không
 * lặp lại luật này ở từng nơi (review fix round 1, Important #2): ai có client.manage thấy toàn
 * bộ, còn lại chỉ thấy khách hàng của những vụ việc họ liệt kê được (Matter::scopeListableBy),
 * đúng ranh giới ClientPolicy::view định nghĩa ở mọi nơi khác đọc danh sách khách hàng.
 *
 * Nguồn gốc: ban đầu sao lại nguyên văn logic của
 * App\Filament\Admin\Resources\Matters\RelationManagers\PartiesRelationManager::visibleClientOptions()
 * (không sửa file đó lúc mới tạo lớp này — ngoài phạm vi task 10, một implementer khác đang làm
 * việc trên thư mục Matters). Fix round 2 review (task 4): bản sao riêng đó đã bị xoá, cả
 * `PartiesRelationManager` lẫn `ClientUserForm` giờ gọi thẳng lớp này — không còn hai nơi có thể
 * lệch luật nhau, và cũng không còn thiếu guard `instanceof User` như bản sao cũ (Matter::
 * scopeListableBy() đòi một `User`, gọi với `null` sẽ là TypeError).
 */
final class VisibleClientOptions
{
    /**
     * Chặn THẬT một id khách hàng do form gửi lên (review fix round 3, finding I-5).
     * `forCurrentUser()` chỉ quyết định ô chọn HIỂN THỊ gì; payload thì phía client gửi gì cũng
     * được. Từ khi `App\Actions\Concerns\BuildsMatterParties` lấy TÊN và định danh của một bên
     * `is_our_client` thẳng từ hồ sơ `Client` đã khoá, một `client_id` giả mạo sẽ ghi TÊN THẬT của
     * một khách hàng ngoài tầm nhìn lên một dòng `matter_parties` mà chính người gửi đọc lại được.
     *
     * Đặt ở đây, cạnh chính danh sách mà nó đối chiếu, để hai màn hình dùng CHUNG một luật: ba id
     * khách hàng mà form gửi lên (khách hàng của vụ việc, khách hàng của từng bên ở trang tạo, và
     * khách hàng của bên mới ở tab "Các bên") đều đi qua đúng hàm này. Trước đó chỉ id thứ nhất
     * được kiểm tra, ở một dòng `abort_unless` viết tay trong `CreateMatter`.
     *
     * **404, không 403 (SPEC §10.10).** "Không có quyền" và "không tồn tại" phải trả về cùng một
     * mã: 403 ở đây tự nó tiết lộ rằng khách hàng mang id vừa gửi là có thật, đúng thứ §10.10 cấm.
     *
     * @param  mixed  $clientId  Giá trị thô từ form; `null`/rỗng cũng bị từ chối — một id bắt buộc
     *                           mà không gửi lên thì không có gì để cho phép.
     *
     * **Fix round 1 (C1, M6.5 Task 6): đi qua `App\Support\ClientVisibility::isVisibleTo()`**,
     * không còn tự tính bằng `array_key_exists(..., self::forCurrentUser())`. Cùng MỘT luật giờ
     * phục vụ hai nơi: ô CHỌN hiển thị gì (`forCurrentUser()`, dưới) và `App\Actions\Client\
     * CreateClient` quyết định có được DÙNG LẠI một hồ sơ trùng hay không — xem docblock
     * `ClientVisibility` cho lý do luật phải sống ở `App\Support`, không phải ở đây.
     */
    public static function assertVisibleToCurrentUser(mixed $clientId): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && ClientVisibility::isVisibleTo($user, (int) $clientId), 404);
    }

    /**
     * "Chọn từ danh sách" (Select có sẵn của `VisibleClientOptions::forCurrentUser()`) hay "tra
     * theo định danh / tạo mới" (M6.5 Task 6, R4)? MỘT nơi quyết định luật này, để `MatterForm`
     * (hiện khối nào) và `CreateMatter::mutateFormDataBeforeCreate()` (nhánh nào xử lý `client_id`)
     * không thể lệch nhau — đúng triết lý "dùng chung" của cả lớp này.
     */
    public static function currentUserCanChooseFromList(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can(Permission::ClientManage->value);
    }

    /** @return array<int, string> */
    public static function forCurrentUser(): array
    {
        $user = Auth::user();

        if ($user instanceof User && $user->can(Permission::ClientManage->value)) {
            return Client::query()->orderBy('name')->pluck('name', 'id')->all();
        }

        if (! $user instanceof User) {
            return [];
        }

        $visibleClientIds = Matter::query()->listableBy($user)->pluck('client_id')->unique();

        return Client::query()
            ->whereIn('id', $visibleClientIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
