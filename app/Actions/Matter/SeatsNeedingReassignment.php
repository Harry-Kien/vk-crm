<?php

namespace App\Actions\Matter;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindPendingWork;
use App\Enums\MatterAssignmentStatus;
use App\Filament\Admin\Pages\TeamOverview;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ghế CHỜ NHẬN mà người được giao không còn nhận được hồ sơ nữa (làn tk, vòng sửa polish 1 — phán quyết
 * controller (3)): tài khoản đã bị vô hiệu hoá hay xoá, hoặc không còn mở được vụ
 * (`ResolveStaffRecipients::qualifies()` — cùng luật "ai nhận được lời báo về vụ này"). Lời nhắc chờ nhận
 * và lời báo "X chưa nhận" của lịch không còn đi cho ghế đó ({@see RemindPendingWork::seatReminderHolds()}
 * hỏi {@see self::memberCanAccept()}): ghế hiện "Cần phân công lại" thay vào đó — ở tab "Đội ngũ" của vụ
 * ({@see TeamRelationManager}) và trên Theo dõi đội ngũ ({@see TeamOverview}, {@see self::handle()}).
 * MỘT định nghĩa cho cả ba chỗ.
 */
final class SeatsNeedingReassignment
{
    /** Người ngồi ghế còn bấm "Đã nhận hồ sơ" được không — cùng luật người nhận lời báo của vụ. */
    public static function memberCanAccept(?User $member, ?Matter $matter): bool
    {
        return $member instanceof User && $matter instanceof Matter
            && app(ResolveStaffRecipients::class)->qualifies($member, $matter);
    }

    /** Ghế đang chờ nhận mà người ngồi không còn nhận được — "Cần phân công lại". */
    public static function stuck(MatterUser $seat, ?User $member, ?Matter $matter): bool
    {
        return $seat->assignmentStatus() === MatterAssignmentStatus::Pending
            && ! self::memberCanAccept($member, $matter);
    }

    /**
     * Các ghế "Cần phân công lại" trên những vụ còn mở `$viewer` được liệt kê (`MatterUser::
     * onOpenMattersListableBy()` — vụ hạn chế họ không mở được không bao giờ vào), theo mã hồ sơ. `null` khi
     * `$viewer` không phân công lại được ở phạm vi văn phòng (chỉ quản lý/quản trị — `Task::isSupervisor()`,
     * cùng người xem của Theo dõi đội ngũ).
     *
     * Lọc trong bộ nhớ: tập ghế chờ nhận của một văn phòng nhỏ, và luật "còn nhận được" là `qualifies()`
     * (một cổng Gate), không có bản SQL thứ hai.
     *
     * Đội ngũ của mỗi vụ nạp sẵn (`matter.team`), để `MatterPolicy::view()` trả lời trong bộ nhớ
     * (`Matter::isListableBy()`, cùng đáp án với EXISTS của nó) thay vì một truy vấn cho MỖI ghế: ghế chờ nhận
     * của một văn phòng thật là hàng trăm (rà soát làn ops 2026-10-22 — dữ liệu đo M13, 3.000 vụ: trang
     * "Theo dõi đội ngũ" khoảng 20 giây, 5.935 truy vấn; `StuckSeatsQueryBudgetTest`).
     *
     * @return Collection<int, MatterUser>|null
     */
    public function handle(User $viewer): ?Collection
    {
        if (! Task::isSupervisor($viewer)) {
            return null;
        }

        return MatterUser::query()
            ->onOpenMattersListableBy($viewer)
            ->awaitingAcceptance()
            ->with(['matter.team', 'user' => fn ($user) => $user->withTrashed()])
            ->get()
            ->filter(fn (MatterUser $seat): bool => self::stuck($seat, $seat->user, $seat->matter))
            ->sortBy(fn (MatterUser $seat): string => (string) $seat->matter?->code)
            ->values();
    }
}
