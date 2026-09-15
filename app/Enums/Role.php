<?php

namespace App\Enums;

/**
 * Vai trò nội bộ. Giá trị trùng với UserPosition để một nhân sự luôn có vai trò
 * khớp chức danh (xem User::assignRoleFromPosition). Bộ quyền lấy nguyên từ bảng SPEC §5.
 */
enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Lawyer = 'lawyer';
    case Assistant = 'assistant';
    case Accountant = 'accountant';

    public function label(): string
    {
        return __('roles.'.$this->value);
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Manager => [
                Permission::MatterViewAny,
                Permission::MatterView,
                Permission::MatterCreate,
                Permission::MatterUpdate,
                Permission::MatterTransitionStage,
                Permission::StageLogPublish,
                Permission::DocumentViewInternal,
                Permission::DocumentPublish,
                Permission::ChecklistReview,
                Permission::ClientManage,
                Permission::ClientUserManage,
                Permission::AuditLogView,
            ],
            self::Lawyer => [
                Permission::MatterView,
                Permission::MatterCreate,
                Permission::MatterUpdate,
                Permission::MatterTransitionStage,
                Permission::StageLogPublish,
                Permission::DocumentViewInternal,
                Permission::DocumentPublish,
                Permission::ChecklistReview,
                Permission::ClientUserManage,
            ],
            self::Assistant => [
                Permission::MatterView,
                Permission::MatterUpdate,
                Permission::ChecklistReview,
                Permission::ClientManage,
                Permission::ClientUserManage,
            ],
            // Kế toán chỉ thấy danh sách rút gọn, không mở được nội dung hồ sơ (SPEC §5).
            self::Accountant => [
                Permission::MatterViewAny,
            ],
        };
    }

    public static function fromPosition(UserPosition $position): self
    {
        return self::from($position->value);
    }
}
