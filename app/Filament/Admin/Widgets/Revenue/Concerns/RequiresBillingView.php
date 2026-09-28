<?php

namespace App\Filament\Admin\Widgets\Revenue\Concerns;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * `canView()` cho bốn widget doanh thu KHÔNG toàn văn phòng (Fix round 1, minor): trang đã gác
 * cổng bằng `billing.view` (`RevenueDashboard::canAccess()`), nhưng widget tự hỏi lại — phòng thủ
 * chiều sâu, cùng tinh thần `MattersByStageWidget::canView()` tự hỏi lại quyền dù trang chủ đã
 * gác. Hai widget toàn văn phòng (`MatterMixByPracticeAreaWidget`, `LoadPerLawyerWidget`) đòi
 * `revenue.viewAny` riêng, không dùng trait này.
 */
trait RequiresBillingView
{
    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && Gate::forUser($user)->allows(Permission::BillingView->value);
    }
}
