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

    /**
     * Task 2, vòng sửa 1 (Critical #1): cổng THÔ của `DeleteBulkAction` trên `ListClients`.
     * Filament tự hỏi `deleteAny` cho toàn bộ nút xoá hàng loạt (`Page.php::getDefaultActionAuthorizationResponse()`),
     * và hàm `get_authorization_response()` của Filament coi một ability KHÔNG có phương thức
     * tương ứng trên policy là CHO PHÉP khi không ở chế độ nghiêm ngặt (`isAuthorizationStrict()`
     * = false, mặc định của dự án — không cấu hình ở đâu). Thiếu phương thức này, MỌI người vào
     * được trang danh sách (kể cả Lawyer/Assistant, không chỉ Admin) bấm xoá hàng loạt trót lọt,
     * bỏ qua cả luật admin-only lẫn luật "còn vụ đang mở" của {@see self::delete()}.
     *
     * Đây chỉ là cổng THÔ — quyết định nút có bấm được không. Luật thật cho TỪNG bản ghi (còn vụ
     * mở hay không) vẫn nằm nguyên một chỗ ở {@see self::delete()}; `ClientsTable` gọi
     * `->authorizeIndividualRecords('delete')` để mỗi dòng được lọc qua đúng phương thức đó
     * trước khi bị xoá — không lặp lại luật ở đây.
     */
    public function deleteAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }

    /** Cùng luật với {@see self::delete()}, không có điều kiện "vụ đang mở" (chiều ngược lại). */
    public function restore(User|ClientUser $user, Client $client): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }

    /** Cổng thô của `RestoreBulkAction` — cùng lý do {@see self::deleteAny()}. */
    public function restoreAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }

    /** Không ai xoá vĩnh viễn một khách hàng được — cùng luật `MatterPolicy::forceDelete()`. */
    public function forceDelete(User|ClientUser $user, Client $client): bool
    {
        return false;
    }

    /** Cổng thô của `ForceDeleteBulkAction` — cùng lý do {@see self::deleteAny()}, luôn từ chối. */
    public function forceDeleteAny(User|ClientUser $user): bool
    {
        return false;
    }
}
