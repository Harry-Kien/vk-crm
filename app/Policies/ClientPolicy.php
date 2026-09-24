<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Policies\Concerns\ChecksPortalVisibility;
use Illuminate\Auth\Access\Response;

class ClientPolicy
{
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::ClientManage->value);
    }

    public function view(User|ClientUser $user, Client $client): bool
    {
        // Khách xem được hồ sơ của chính mình; scope đã giới hạn, policy xác nhận lại.
        if ($user instanceof ClientUser) {
            return $this->visibleToPortal($user, $client);
        }

        // Không có quyền client.manage riêng vẫn đọc được hồ sơ khách của một vụ việc mà
        // họ xem được (M3 review): tách quyền xem khỏi quyền quản lý, không thêm quyền mới.
        return $user->can(Permission::ClientManage->value)
            || Matter::query()->listableBy($user)->where('client_id', $client->getKey())->exists();
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::ClientManage->value);
    }

    public function update(User|ClientUser $user, Client $client): bool
    {
        return $this->create($user);
    }

    /**
     * Task 2 (rà soát cuối, "Xoá khách hàng còn vụ việc đang mở"): trước bản sửa này, admin xoá
     * mềm được một khách hàng đang có vụ việc mở, không cảnh báo, không chốt chặn — khác hẳn
     * `MatterTypePolicy::delete()` là có chặn cho một quan hệ tương tự. Hậu quả: `Matter::client()`
     * trả `null` cho hồ sơ đó ở mọi màn hình đọc qua quan hệ này (danh sách vụ việc, widget,
     * infolist), và không mở được vụ mới cho khách đó nữa — hồ sơ vẫn chạy nhưng "mồ côi" khách
     * hàng cho tới khi có người nhớ ra và khôi phục (`RestoreBulkAction` vẫn xoá mềm chứ không
     * mất dữ liệu, nhưng không ai được nhắc phải làm vậy).
     *
     * "Vụ đang mở" CHƯA có định nghĩa dùng chung — `Matter::scopeOpen()` là việc của Task 5. Dùng
     * thẳng `whereNull('closed_at')` trên các vụ CHƯA xoá mềm (quan hệ `matters()` đã tự loại vụ
     * xoá mềm qua `SoftDeletingScope` của chính `Matter`); thay bằng `Matter::open()` khi Task 5
     * merge.
     *
     * Trả `Response::deny()` kèm số vụ thay vì `bool`: `EditClient::getHeaderActions()` bật
     * `authorizationNotification()` cho đúng `DeleteAction` này, nên thông điệp ở đây là thứ admin
     * đọc được ngay khi bấm — "vì sao không xoá được" — thay vì một nút biến mất không lời giải
     * thích.
     */
    public function delete(User|ClientUser $user, Client $client): bool|Response
    {
        if (! ($user instanceof User && $user->hasRole(Role::Admin->value))) {
            return false;
        }

        $openMattersCount = $client->matters()->whereNull('closed_at')->count();

        if ($openMattersCount > 0) {
            return Response::deny(__('clients.delete_blocked_open_matters', ['count' => $openMattersCount]));
        }

        return true;
    }
}
