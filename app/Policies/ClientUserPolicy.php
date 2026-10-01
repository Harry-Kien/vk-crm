<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\ClientVisibility;

/** Quản lý tài khoản khách chỉ dành cho nhân sự — khách không bao giờ chạm tới. */
class ClientUserPolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::ClientUserManage->value);
    }

    /**
     * Task 2 (`roles/roles-02`): nhánh "thấy mọi tài khoản" từng đúng bằng `clientUser.manage`
     * — quyền mà Lawyer có (Role.php) nhưng KHÔNG kèm biên giới đội ngũ — nên một luật sư đọc
     * được tên, email, số điện thoại của tài khoản cổng thuộc bất kỳ khách hàng nào trong văn
     * phòng, kể cả khách của vụ hạn chế mình không được xem. Đổi mốc sang `client.manage`, đúng
     * ranh giới `ClientPolicy::view()` đã dùng cho chính hồ sơ `Client` — hai màn hình liền kề
     * (Khách hàng / Tài khoản cổng) giờ cùng một luật, không thể lệch nhau nữa. Ai không có
     * `client.manage` chỉ đọc được tài khoản của khách thuộc vụ việc mình liệt kê được.
     *
     * Final review X3 (A-I2): thêm một điều kiện cho MỌI vai trò trừ admin — `view` được mọi vụ
     * `restricted` chưa xoá của khách đó (một tài khoản cổng mở được các vụ ấy). Luật nằm DUY NHẤT ở
     * `ClientVisibility::canManagePortalAccountsOf()`; `create()`, `update()`, `unlockLogin()` và
     * `ClientUserResource::getEloquentQuery()` đều đi qua nó.
     */
    public function view(User|ClientUser $user, ClientUser $clientUser): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return ClientVisibility::canManagePortalAccountsOf($user, (int) $clientUser->client_id);
    }

    /**
     * $client là tham số ngữ cảnh TUỲ CHỌN (quy ước Laravel: ability nhận thêm model để kiểm
     * tra một trường hợp cụ thể — `Gate::authorize('create', [ClientUser::class, $client])`).
     * Filament tự gọi ability này KHÔNG kèm $client khi chỉ quyết định có hiện nút "Tạo" hay
     * không (lúc đó chưa biết người dùng sẽ chọn khách hàng nào), nên nhánh $client === null chỉ
     * xét quyền chung. Việc chặn thật — không cho gắn tài khoản portal vào một khách hàng ngoài
     * tầm nhìn — nằm ở CreateClientUser::mutateFormDataBeforeCreate(), nơi client_id đã có trong
     * dữ liệu gửi lên, gọi lại đúng ability này với $client đã biết (review fix round 1,
     * Important #2, nửa còn lại của cùng một lỗ hổng với VisibleClientOptions).
     *
     * `EditClientUser` KHÔNG còn gọi ability này (Task 2, `roles/roles-01`): client_id không đổi
     * được sau khi tạo với bất kỳ ai, nên "sửa" không còn câu hỏi "$client mới có hợp lệ không"
     * để hỏi — xem docblock của {@see self::update()}.
     */
    public function create(User|ClientUser $user, ?Client $client = null): bool
    {
        if (! $user instanceof User || ! $user->can(Permission::ClientUserManage->value)) {
            return false;
        }

        if ($client === null) {
            return true;
        }

        return ClientVisibility::canManagePortalAccountsOf($user, (int) $client->getKey());
    }

    /**
     * Task 2 (`roles/roles-01`, critical): trước bản sửa này hàm này là `return $this->create($user);`
     * — gọi `create()` KHÔNG kèm $client, nên nhánh `$client === null` của `create()` trả `true`
     * cho bất kỳ ai có `clientUser.manage` (mọi Lawyer), bất kể tài khoản đang sửa thuộc khách
     * hàng nào. Vì client_id giờ không đổi được (xem `EditClientUser::mutateFormDataBeforeSave()`),
     * "sửa được" chỉ còn cần hỏi một câu: người này có thấy tài khoản CỦA client_id HIỆN TẠI hay
     * không — đúng câu `view()` đã hỏi, nên hàm này giờ hỏi lại chính nó thay vì `create()`.
     */
    public function update(User|ClientUser $user, ClientUser $clientUser): bool
    {
        if (! $user instanceof User || ! $user->can(Permission::ClientUserManage->value)) {
            return false;
        }

        return $this->view($user, $clientUser);
    }

    public function delete(User|ClientUser $user, ClientUser $clientUser): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }

    /**
     * Task 7 (R12, phát hiện `portal/portal-4`): "Mở khoá đăng nhập"
     * (`App\Filament\Admin\Resources\ClientUsers\Pages\EditClientUser`, Action `unlockLogin`).
     * Một ability RIÊNG, không gọi lại `update()` bên trong Filament action — `HeaderActionsAreReachableTest`
     * đòi mọi thao tác trên thanh tiêu đề của một trang Edit/List phải mang đúng tên một phương
     * thức policy, để một cái nút không bao giờ là một lời nói dối về hệ thống.
     *
     * Ranh giới GIỐNG HỆT `update()` một cách có chủ ý (cùng gọi lại `view()`): ai sửa được thông
     * tin của tài khoản cổng thì cũng mở khoá đăng nhập được cho tài khoản đó, không mở rộng biên
     * giới nào so với chính trang đang đứng.
     */
    public function unlockLogin(User|ClientUser $user, ClientUser $clientUser): bool
    {
        return $this->update($user, $clientUser);
    }

    /**
     * Task 3: "Cấp lại mật khẩu" trên trang sửa (Action `reissueAccess`, gọi
     * `App\Actions\Client\IssuePortalAccess`) — cùng lý lẽ `unlockLogin()` (ability riêng vì
     * `HeaderActionsAreReachableTest`), cùng biên giới `update()`.
     */
    public function reissueAccess(User|ClientUser $user, ClientUser $clientUser): bool
    {
        return $this->update($user, $clientUser);
    }

    /**
     * Task 2, vòng sửa 1 (Important #3): cổng THÔ của `DeleteBulkAction`/`ForceDeleteBulkAction`/
     * `RestoreBulkAction` trên `ListClientUsers` — cùng lý do hệt `ClientPolicy::deleteAny()`
     * (đọc docblock ở đó): thiếu bốn phương thức này, Filament coi bốn ability tương ứng là CHO
     * PHÉP mặc định (không nghiêm ngặt), nên một luật sư — không chỉ admin — xoá hàng loạt được
     * tài khoản cổng của khách BẤT KỲ, và bộ lọc "đã xoá" + xoá vĩnh viễn hàng loạt xoá luôn cả
     * `stage_log_views` (sổ "đã xem") lẫn `client_requests` liên đới (cascade), không qua
     * {@see self::delete()} một dòng nào.
     */
    public function deleteAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }

    public function restore(User|ClientUser $user, ClientUser $clientUser): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }

    public function restoreAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }

    /** Không ai xoá vĩnh viễn một tài khoản cổng được: sẽ cuốn theo cả sổ "đã xem" liên đới. */
    public function forceDelete(User|ClientUser $user, ClientUser $clientUser): bool
    {
        return false;
    }

    public function forceDeleteAny(User|ClientUser $user): bool
    {
        return false;
    }
}
