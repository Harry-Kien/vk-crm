<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;

/** Quản lý tài khoản khách chỉ dành cho nhân sự — khách không bao giờ chạm tới. */
class ClientUserPolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::ClientUserManage->value);
    }

    public function view(User|ClientUser $user, ClientUser $clientUser): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        // Cùng luật với ClientPolicy::view: đọc được tài khoản khách của một vụ việc mà
        // họ xem được, dù không có quyền quản lý.
        return $user->can(Permission::ClientUserManage->value)
            || Matter::query()->listableBy($user)->where('client_id', $clientUser->client_id)->exists();
    }

    /**
     * $client là tham số ngữ cảnh TUỲ CHỌN (quy ước Laravel: ability nhận thêm model để kiểm
     * tra một trường hợp cụ thể — `Gate::authorize('create', [ClientUser::class, $client])`).
     * Filament tự gọi ability này KHÔNG kèm $client khi chỉ quyết định có hiện nút "Tạo" hay
     * không (lúc đó chưa biết người dùng sẽ chọn khách hàng nào), nên nhánh $client === null chỉ
     * xét quyền chung. Việc chặn thật — không cho gắn tài khoản portal vào một khách hàng ngoài
     * tầm nhìn — nằm ở CreateClientUser::mutateFormDataBeforeCreate() /
     * EditClientUser::mutateFormDataBeforeSave(), nơi client_id đã có trong dữ liệu gửi lên, gọi
     * lại đúng ability này với $client đã biết (review fix round 1, Important #2, nửa còn lại của
     * cùng một lỗ hổng với VisibleClientOptions).
     */
    public function create(User|ClientUser $user, ?Client $client = null): bool
    {
        if (! $user instanceof User || ! $user->can(Permission::ClientUserManage->value)) {
            return false;
        }

        if ($client === null) {
            return true;
        }

        return $user->can(Permission::ClientManage->value)
            || Matter::query()->listableBy($user)->where('client_id', $client->getKey())->exists();
    }

    public function update(User|ClientUser $user, ClientUser $clientUser): bool
    {
        return $this->create($user);
    }

    public function delete(User|ClientUser $user, ClientUser $clientUser): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }
}
