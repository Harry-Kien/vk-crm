<?php

namespace App\Support\Tasks;

use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Filament\Admin\Widgets\Tasks\MatterAssignmentsAwaitingMeWidget;
use App\Models\Matter;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;

/**
 * Người nhận bấm "Đã nhận hồ sơ" / "Trả lại" Ở ĐÂU (làn tk, vòng sửa cuối 1 — F2). Hai chỗ có hai nút đó:
 *
 *  - khối "Hồ sơ chờ tôi nhận" ({@see MatterAssignmentsAwaitingMeWidget}) trên **Việc của tôi** — trang của
 *    chính người đó ({@see TeamMember}), chỉ mở cho người `UserPolicy::viewPerformance()` cho xem chính mình:
 *    luật sư, trợ lý, trưởng phòng. Quản trị viên (không nằm trong danh sách theo dõi), kế toán và nhân viên
 *    kinh doanh (không `matter.view`) mở trang đó là 404;
 *  - dòng của chính họ ở tab **"Đội ngũ"** của vụ ({@see TeamRelationManager}) — mở cho mọi người xem được vụ.
 *
 * Quản trị viên chỉ ngồi được ghế "người theo dõi" (không chờ nhận), nên ca thật là một người được đổi chức
 * danh sang Quản trị viên khi còn ghế chờ nhận: người đó đi tab "Đội ngũ". Chuông, thư nhắc, thư tổng hợp bàn
 * giao và ô số trên bảng điều khiển hỏi lớp này, không tự trỏ về "Việc của tôi".
 */
final class SeatInbox
{
    /** `$user` mở được "Việc của tôi" của chính mình — cùng cổng người của {@see TeamMember} ({@see UserPolicy::viewPerformance()}). */
    public static function onMyWork(User $user): bool
    {
        return Gate::forUser($user)->allows('viewPerformance', $user);
    }

    /** Đường dẫn tới chỗ `$user` bấm nhận ghế của mình trong `$matter` — xem docblock lớp. */
    public static function urlFor(User $user, Matter $matter): string
    {
        return self::onMyWork($user)
            ? TeamMember::getUrl(['user' => $user->getKey()], panel: 'admin')
            : self::teamTabUrl((int) $matter->getKey());
    }

    /** Tab "Đội ngũ" của một vụ. */
    public static function teamTabUrl(int $matterId): string
    {
        $parameters = ['record' => $matterId];
        $tab = array_search(TeamRelationManager::class, MatterResource::getRelations(), true);

        if ($tab !== false) {
            $parameters['relation'] = $tab;
        }

        return MatterResource::getUrl('view', $parameters, panel: 'admin');
    }

    /** Khoá chữ "ở đâu" (`tasks.seat.where.*`) của `$user`: "mục Việc của tôi" hay "tab Đội ngũ của hồ sơ". */
    public static function whereKey(User $user): string
    {
        return 'tasks.seat.where.'.(self::onMyWork($user) ? 'my_work' : 'team_tab');
    }
}
