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
 * Nguồn gốc: sao lại nguyên văn logic của
 * App\Filament\Admin\Resources\Matters\RelationManagers\PartiesRelationManager::visibleClientOptions()
 * (không sửa file đó — ngoài phạm vi task 10, một implementer khác đang làm việc trên thư mục
 * Matters) vào một nơi cả hai phía có thể dùng chung về sau.
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
