<?php

namespace App\Enums;

/**
 * Đúng 21 quyền ở SPEC §5: 13 quyền của bảng gốc, cộng 4 quyền tiền của M9 (`billing.view`,
 * `contract.manage`, `payment.record`, `revenue.viewAny`) thêm bằng đính chính có ngày ngay dưới
 * bảng đó ("Bổ sung 2026-09-19, sửa 2026-09-24"), cộng 3 quyền tiếp nhận của M10 (`intake.create`,
 * `intake.viewAny`, `intake.convert`) thêm bằng đính chính "Bổ sung 2026-09-24 (M10)", cộng 1 quyền
 * của M13 (`performance.viewAny`, "Bổ sung 2026-10-04") — không thêm lặng lẽ. Đếm cộng dồn theo
 * milestone: lần gộp một làn khác thêm quyền chỉ phải sửa con số tổng. Tên quyền là nguồn sự thật,
 * không sinh tự động từ resource.
 */
enum Permission: string
{
    case MatterViewAny = 'matter.viewAny';
    case MatterView = 'matter.view';
    case MatterCreate = 'matter.create';
    case MatterUpdate = 'matter.update';
    case MatterTransitionStage = 'matter.transitionStage';
    case StageLogPublish = 'stageLog.publish';
    case DocumentViewInternal = 'document.viewInternal';
    case DocumentPublish = 'document.publish';
    case ChecklistReview = 'checklist.review';
    case ClientManage = 'client.manage';
    case ClientUserManage = 'clientUser.manage';
    case SettingsManage = 'settings.manage';
    case AuditLogView = 'auditLog.view';

    /**
     * Hợp đồng, đợt thanh toán, khoản thu và phụ lục của MỘT vụ việc — một trục riêng với
     * `matter.view` (nội dung hồ sơ). Một mình quyền này không mở tiền của vụ nào: cùng đi với
     * `Matter::listableBy()`, xem `ChecksBillingAccess::canSeeBilling()`.
     */
    case BillingView = 'billing.view';

    /** Soạn, kích hoạt, ký phụ lục (kể cả đổi số tiền từng đợt), huỷ hợp đồng; miễn một đợt. */
    case ContractManage = 'contract.manage';

    /** Ghi nhận và huỷ một khoản thu. Trên vụ `restricted` luật sư phụ trách ghi được dù thiếu quyền này. */
    case PaymentRecord = 'payment.record';

    /** Số liệu doanh thu toàn văn phòng và trang "Công nợ". Cặp với `billing.view` như `matter.viewAny` với `matter.view`. */
    case RevenueViewAny = 'revenue.viewAny';

    /**
     * Ghi một lần có người liên hệ văn phòng và đổi trạng thái bản ghi MÌNH ghi hoặc được giao (M10,
     * SPEC §5 đính chính 2026-09-24). Người chỉ có quyền này thấy đúng những bản ghi đó — xem
     * `IntakeRequest::scopeVisibleTo()`. Kế toán không có.
     */
    case IntakeCreate = 'intake.create';

    /**
     * Mọi bản ghi tiếp nhận, kể cả câu chuyện và lý do từ chối vì xung đột; báo cáo đầu vào. Cặp với
     * `intake.create` như `matter.viewAny` với `matter.view`. Chỉ admin và quản lý.
     */
    case IntakeViewAny = 'intake.viewAny';

    /** Chuyển một bản ghi tiếp nhận thành vụ việc — luôn đi cùng `matter.create` (trợ lý không có cả hai). */
    case IntakeConvert = 'intake.convert';

    /** M13: số liệu theo dõi và hiệu suất của MỌI nhân sự được theo dõi; số của chính mình chỉ cần `matter.view` (`UserPolicy::viewPerformance()`). */
    case PerformanceViewAny = 'performance.viewAny';

    public function label(): string
    {
        return __('permissions.'.$this->value);
    }
}
