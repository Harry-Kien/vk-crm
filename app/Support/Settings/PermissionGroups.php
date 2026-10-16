<?php

namespace App\Support\Settings;

use App\Enums\Permission;

/**
 * Nhóm quyền của bảng "Vai trò & quyền" (làn st, trang `App\Filament\Admin\Pages\Settings\RolePermissionsPage`)
 * — chỉ để TRÌNH BÀY: quyền là nguồn sự thật ở {@see Permission}, bộ quyền của từng vai ở
 * `App\Enums\Role::permissions()`. Lớp này chỉ xếp các quyền đã có vào sáu nhóm đọc được.
 *
 * Một quyền MỚI chưa được xếp nhóm không biến mất khỏi bảng: {@see self::all()} đặt nó vào nhóm
 * `other` ("Quyền khác") ở cuối. `RolePermissionsPageTest` vẫn đỏ khi điều đó xảy ra
 * ({@see self::unplaced()} phải rỗng), để lần thêm quyền nhớ xếp nhóm cho nó.
 */
final class PermissionGroups
{
    /** @var array<string, list<Permission>> */
    private const GROUPS = [
        'matters' => [
            Permission::MatterViewAny,
            Permission::MatterView,
            Permission::MatterCreate,
            Permission::MatterUpdate,
            Permission::MatterTransitionStage,
            Permission::StageLogPublish,
        ],
        'documents' => [
            Permission::DocumentViewInternal,
            Permission::DocumentPublish,
            Permission::ChecklistReview,
        ],
        'clients' => [
            Permission::ClientManage,
            Permission::ClientUserManage,
        ],
        'money' => [
            Permission::BillingView,
            Permission::ContractManage,
            Permission::PaymentRecord,
            Permission::RevenueViewAny,
        ],
        'intake' => [
            Permission::IntakeCreate,
            Permission::IntakeViewAny,
            Permission::IntakeConvert,
        ],
        'system' => [
            Permission::SettingsManage,
            Permission::AuditLogView,
            Permission::PerformanceViewAny,
        ],
    ];

    /**
     * Nhóm → quyền, theo thứ tự trình bày; nhóm `other` chỉ có khi còn quyền chưa xếp.
     *
     * @return array<string, list<Permission>>
     */
    public static function all(): array
    {
        $unplaced = self::unplaced();

        return $unplaced === [] ? self::GROUPS : [...self::GROUPS, 'other' => $unplaced];
    }

    /** @return list<Permission> quyền của {@see Permission} chưa nằm trong nhóm nào */
    public static function unplaced(): array
    {
        $placed = array_merge(...array_values(self::GROUPS));

        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => ! in_array($permission, $placed, true),
        ));
    }
}
