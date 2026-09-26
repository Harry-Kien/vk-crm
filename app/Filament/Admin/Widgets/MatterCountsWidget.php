<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Permission;
use App\Models\Matter;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Bốn ô số tóm tắt ở đầu trang chủ: tổng hồ sơ, đang xử lý, đã kết thúc, mở trong tháng này.
 *
 * ĐÍNH CHÍNH CÓ NGÀY cho SPEC §7.1 (2026-09-22, theo yêu cầu của chủ văn phòng). §7.1 nói widget
 * "Hồ sơ quá hạn cập nhật" là quan trọng nhất và "đặt trên cùng". Widget này đứng CAO HƠN nó, và
 * đó không phải một mâu thuẫn: luật của §7.1 nói về việc DANH SÁCH nào dẫn đầu, vì danh sách là
 * thứ người ta phải hành động theo. Một hàng ô số cao một dòng là phần tóm tắt, không phải một
 * danh sách cạnh tranh sự chú ý — nó không đẩy danh sách quá hạn xuống dưới màn hình đầu tiên.
 * Nếu sau này hàng này dài thêm thành nhiều dòng thì đính chính này hết đúng và phải xem lại.
 *
 * VÌ SAO MỌI Ô SỐ ĐỀU ĐI QUA Matter::listableBy(): đây là cái bẫy chính của mọi ô số trên trang
 * chủ. Nếu ô số đếm toàn văn phòng trong khi mọi danh sách bên dưới chỉ đếm phần người đang xem
 * được phép thấy, thì hai con số trên cùng một màn hình nói hai chuyện khác nhau — và người dùng
 * sẽ thôi tin cả màn hình. Một luật sư thấy con số của chính họ; quản lý và kế toán thấy toàn bộ.
 * Cùng một định nghĩa "ai thấy vụ việc nào" mà MatterResource và mọi widget khác dùng, từ M2.
 *
 * "Đang xử lý" và "Đã kết thúc" đọc theo `Matter::scopeOpen()` (R8, M6.5 Task 5) — tức theo
 * `closed_at`, không theo giai đoạn cuối. Một vụ việc có thể đang ở giai đoạn cuối mà chưa ai
 * đóng hồ sơ (còn chờ bàn giao, còn công nợ), và ngược lại. `closed_at` là hành động dứt khoát
 * của văn phòng, ghi bởi `TransitionMatterStage` khi vào/rời một giai đoạn `is_terminal`; giai
 * đoạn là nơi hồ sơ đang nằm. Biểu đồ "Thống kê nhanh" bên dưới trả lời câu "đang nằm ở đâu", nên
 * hai widget không nói chồng nhau.
 *
 * `$closed` tính bằng `$total - $open`, không phải một câu truy vấn `closed_at` riêng: `scopeOpen()`
 * là chỗ DUY NHẤT trong `app/` được lọc theo cột đó (`grep -rn closed_at app/`), và "không mở"
 * đã đúng nghĩa "đã đóng" trên đúng tập `$visible()` (hồ sơ đã xoá mềm không có mặt ở cả hai vế,
 * vì `SoftDeletingScope` loại chúng khỏi `$visible()` từ đầu).
 *
 * Hồ sơ đã rút (soft delete) không nằm trong bất kỳ ô nào: `Matter::query()` mang sẵn
 * SoftDeletingScope. Có test, vì nếu tổng ở đây lệch với danh sách vụ việc thì người dùng sẽ tin
 * danh sách chứ không tin ô số, và ô số thành vô dụng.
 */
class MatterCountsWidget extends StatsOverviewWidget
{
    // Widget đứng đầu hiện tại là StaleMattersWidget ở -4 (phải thấp hơn -3 của AccountWidget do
    // Filament đăng ký sẵn). Hàng ô số đứng trên nữa: -5. DashboardWidgetOrderTest và test của
    // chính widget này giữ thứ tự tương đối đó.
    protected static ?int $sort = -5;

    protected int|string|array $columnSpan = 'full';

    /**
     * Cùng ranh giới với MattersByStageWidget: đây chỉ là những con số ĐẾM, không lộ tiêu đề hay
     * nội dung vụ việc nào, nên kế toán — vốn chỉ có matter.viewAny — vẫn xem được.
     */
    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && ($user->can(Permission::MatterView->value) || $user->can(Permission::MatterViewAny->value));
    }

    protected function getStats(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $visible = fn (): Builder => Matter::query()->listableBy($user);

        $total = $visible()->count();
        $open = $visible()->open()->count();
        $closed = $total - $open;
        $openedThisMonth = $visible()->where('opened_at', '>=', now()->startOfMonth())->count();

        return [
            Stat::make(__('widgets.matter_counts.total'), (string) $total)
                ->description(__('widgets.matter_counts.total_hint')),

            Stat::make(__('widgets.matter_counts.open'), (string) $open)
                ->description(__('widgets.matter_counts.open_hint'))
                ->color('primary'),

            Stat::make(__('widgets.matter_counts.closed'), (string) $closed)
                ->description(__('widgets.matter_counts.closed_hint'))
                ->color('success'),

            Stat::make(__('widgets.matter_counts.opened_this_month'), (string) $openedThisMonth)
                ->description(__('widgets.matter_counts.opened_this_month_hint')),
        ];
    }
}
