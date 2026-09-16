<?php

namespace App\Filament\Admin\Support;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\Matter;
use App\Models\User;
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
