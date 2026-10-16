<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Nhóm của menu trái panel nội bộ (làn rv, 2026-10-15 — chủ văn phòng: "nhiều chữ nhìn rối quá, làm
 * như các CRM chuyên nghiệp"). Mỗi Resource/Page khai MỘT dòng:
 *
 *     protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;
 *
 * **Thứ tự nhóm = thứ tự khai báo case.** Filament tự xếp nhóm theo `cases()` khi nhóm là một enum
 * (`Filament\Navigation\NavigationManager::get()`), nên không cần đăng ký thêm ở panel; thứ tự MỤC
 * trong một nhóm đi theo `$navigationSort` của từng trang (bước 10 để chen được trang mới). Nhóm nào
 * người xem không có mục nào thì Filament không vẽ.
 *
 * **Khu không nhóm trên cùng** — Bảng điều khiển (`Filament\Pages\Dashboard` của vendor, không gán nhóm
 * được mà không viết lớp con, thứ tự `-2`), rồi việc hằng ngày: Công việc (`TaskResource`, `-1`) và Việc
 * của tôi (`TeamMember`, `0`, chỉ người không có "Theo dõi đội ngũ"), rồi Tìm kiếm (`10`) — đóng vai
 * "Tổng quan", đúng chỗ của trang chủ và "My tasks" trong các CRM (gộp làn rv với làn tk, phán quyết
 * controller).
 *
 * Nhóm KHÔNG đổi quyền: ai thấy mục nào vẫn do `canAccess()`/`shouldRegisterNavigation()` của từng
 * trang quyết (`tests/Feature/Filament/AdminNavigationGroupsTest.php` ghim tập mục của từng vai).
 */
enum NavigationGroup: string implements HasLabel
{
    /** Tiếp nhận, Khách hàng, Vụ việc, Tài khoản portal. */
    case Clients = 'clients';

    /** Doanh thu, Công nợ, Nhắc thanh toán cho khách. */
    case Finance = 'finance';

    /** Bàn giao hàng loạt, Nhân sự, Theo dõi đội ngũ, Hiệu suất. */
    case Team = 'team';

    /** Báo cáo tiếp nhận và các trang báo cáo khác. */
    case Reports = 'reports';

    /** Cấu hình và vận hành: loại vụ việc, văn phòng, kho tài liệu, kết nối AI, thư đã gửi, nhật ký. */
    case System = 'system';

    public function label(): string
    {
        return __('navigation.groups.'.$this->value);
    }

    /** Filament đọc nhãn nhóm qua `HasLabel` — cùng một chuỗi với `label()`. */
    public function getLabel(): string
    {
        return $this->label();
    }
}
