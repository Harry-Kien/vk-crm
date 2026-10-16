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
    // Làn kd (2026-10-14, chủ văn phòng: "nhân viên kinh doanh cập nhật lead").
    case Sales = 'sales';

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
                // M9: quản lý thấy tiền và doanh thu, soạn hợp đồng, nhưng CHỈ XEM khoản thu —
                // không `payment.record` (SPEC §5, bổ sung 2026-09-19, sửa 2026-09-24).
                Permission::BillingView,
                Permission::ContractManage,
                Permission::RevenueViewAny,
                // M10: quản lý thấy mọi bản ghi tiếp nhận, xử lý Đỏ, chuyển đổi.
                Permission::IntakeCreate,
                Permission::IntakeViewAny,
                Permission::IntakeConvert,
                // M13: số liệu theo dõi và hiệu suất của mọi nhân sự được theo dõi (SPEC §5, bổ
                // sung 2026-10-04). Luật sư, trợ lý chỉ xem số của chính mình, không cần quyền này.
                Permission::PerformanceViewAny,
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
                // M9: tiền của vụ mình ("của mình" = `Matter::listableBy`), không doanh thu toàn
                // văn phòng, không ghi khoản thu — trừ vụ `restricted` mình phụ trách, xem
                // `PaymentPolicy::canRecordPaymentOn()`.
                Permission::BillingView,
                Permission::ContractManage,
                // M10: luật sư ghi và chuyển đổi bản ghi mình thấy (mình ghi hoặc được giao); không
                // `intake.viewAny`.
                Permission::IntakeCreate,
                Permission::IntakeConvert,
            ],
            self::Assistant => [
                Permission::MatterView,
                Permission::MatterUpdate,
                Permission::ChecklistReview,
                Permission::ClientManage,
                Permission::ClientUserManage,
                // M10 (SPEC §5, bổ sung 2026-09-24): trợ lý ghi được lần liên hệ (người trực điện
                // thoại thường là trợ lý), thấy bản ghi mình ghi hoặc được giao; không chuyển đổi
                // (không `matter.create`), không xem mọi bản ghi.
                Permission::IntakeCreate,
            ],
            // Kế toán chỉ thấy danh sách rút gọn, không mở được nội dung hồ sơ (SPEC §5) — vẫn
            // đúng sau M9: `billing.view` là một trục riêng, không kèm `matter.view`. Kế toán thấy
            // và ghi TIỀN (qua `AccountantBillingRow`).
            // M10: không có quyền `intake.*` nào — người liên hệ không có tiền nào để thu (SPEC §5).
            // Làn kt (2026-10-15, SPEC §5 bổ sung cùng ngày): kế toán NHẬP hợp đồng giấy đã ký cho vụ có
            // sẵn (`contract.enter`, trang "Hợp đồng"), vẫn không `contract.manage` (phụ lục, hoàn tất,
            // huỷ, miễn, dời hạn, thư nhắc khách) và vẫn không `matter.view`.
            self::Accountant => [
                Permission::MatterViewAny,
                Permission::BillingView,
                Permission::PaymentRecord,
                Permission::RevenueViewAny,
                Permission::ContractEnter,
            ],
            // Làn kd (2026-10-14, SPEC §5 bổ sung cùng ngày): nhân viên kinh doanh ghi một lần liên hệ
            // và cập nhật lead MÌNH ghi, được giao — đúng tầm nhìn `intake.create` của
            // `IntakeRequest::scopeVisibleTo()`. Không `matter.*` (vụ việc, tìm kiếm, kết quả kiểm tra xung
            // đột chi tiết — `IntakeRequestPolicy::viewConflictMatches`), không `client.manage`, không
            // `document.*`, không `billing.*`/`revenue.*`, không `intake.viewAny` (báo cáo toàn văn
            // phòng), không `intake.convert` (mở vụ từ lead là việc của luật sư: họ "Chuyển cho luật sư").
            // Không quyền AI: `McpAccess::canHold()` đòi `matter.view`.
            self::Sales => [
                Permission::IntakeCreate,
            ],
        };
    }

    public static function fromPosition(UserPosition $position): self
    {
        return self::from($position->value);
    }
}
