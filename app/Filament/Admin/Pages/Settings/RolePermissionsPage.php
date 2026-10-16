<?php

namespace App\Filament\Admin\Pages\Settings;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Concerns\IsSettingsPage;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Settings\PermissionGroups;
use Filament\Pages\Page;

/**
 * Trang "Vai trò & quyền" (làn st, mục 3 của brief) — ma trận sáu vai × quyền, ĐỌC-CHỈ. Mỗi ô dựng từ
 * `Role::permissions()` (nguồn sự thật, SPEC §5), không chép tay; nhãn quyền từ `Permission::label()`,
 * nhãn vai từ `Role::label()`, nhóm quyền chỉ để trình bày ({@see PermissionGroups}). Không có ô nào
 * sửa được: bộ quyền của từng vai do hệ thống quy định; đổi vai của MỘT người ở trang Nhân sự.
 *
 * Cổng: {@see IsSettingsPage} (chỉ `settings.manage`, 404 ở mount và mọi request cập nhật).
 */
class RolePermissionsPage extends Page
{
    use IsSettingsPage;

    protected string $view = 'filament.admin.pages.settings.roles';

    protected static ?string $slug = 'settings/roles';

    public function getTitle(): string
    {
        return __('settings.roles.title');
    }

    /** @return list<Role> */
    public function roles(): array
    {
        return Role::cases();
    }

    /** @return array<string, list<Permission>> */
    public function groups(): array
    {
        return PermissionGroups::all();
    }

    public function grants(Role $role, Permission $permission): bool
    {
        return in_array($permission, $role->permissions(), true);
    }

    /**
     * Số người ĐANG HOẠT ĐỘNG mang từng vai (vai Spatie của tài khoản). `whereHas` chứ không scope
     * `role()` của Spatie: scope đó ném lỗi khi vai chưa có dòng trong CSDL (máy chủ nâng cấp mà chưa
     * chạy `db:seed --force`), và một trang đọc-chỉ không được thành lỗi 500 vì thế.
     *
     * @return array<string, int> giá trị vai → số người
     */
    public function headcount(): array
    {
        $counts = [];

        foreach (Role::cases() as $role) {
            $counts[$role->value] = User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn ($query) => $query->where('name', $role->value))
                ->count();
        }

        return $counts;
    }

    public function staffUrl(): ?string
    {
        return UserResource::canAccess() ? UserResource::getUrl() : null;
    }
}
