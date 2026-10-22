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
use Illuminate\Database\Eloquent\Builder;
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
     * (một cổng Gate), không có bản SQL thứ hai — lối tắt SQL bên dưới là chính câu EXISTS của
     * `MatterPolicy::view()`, chỉ dùng theo chiều "chắc chắn xem được".
     *
     * Không hỏi Gate cho MỖI ghế: ghế chờ nhận của một văn phòng thật là hàng trăm (rà soát làn ops
     * 2026-10-22 — dữ liệu đo M13, 3.000 vụ: trang "Theo dõi đội ngũ" khoảng 20 giây, 5.935 truy vấn;
     * `StuckSeatsQueryBudgetTest`). Làn perf2: sau khi nạp sẵn `matter.team`, mỗi ghế vẫn là khoảng bốn lần hỏi
     * quyền Spatie (6–9 giây trên dữ liệu đo). Nay mỗi NGƯỜI ngồi ghế một truy vấn: ghế trên vụ thuộc
     * `ResolveStaffRecipients::surelyQualifyingMatters()` (đúng câu EXISTS của `MatterPolicy::view()`, người
     * còn hoạt động) chắc chắn không "kẹt" và bị bỏ qua; mọi ghế còn lại — thường chỉ những ghế thật sự kẹt —
     * đi qua {@see self::stuck()} như cũ. `StuckSeatsBatchParityTest` ghim cùng danh sách với lọc từng ghế.
     *
     * @return Collection<int, MatterUser>|null
     */
    public function handle(User $viewer): ?Collection
    {
        if (! Task::isSupervisor($viewer)) {
            return null;
        }

        $pending = fn (): Builder => MatterUser::query()
            ->onOpenMattersListableBy($viewer)
            ->awaitingAcceptance();
        $recipients = app(ResolveStaffRecipients::class);

        // Ghế mà người ngồi CHẮC CHẮN còn nhận được: một truy vấn cho mỗi người ngồi ghế (xem docblock).
        $accepting = [];

        foreach (User::withTrashed()->whereIn('id', $pending()->select('matter_user.user_id'))->get() as $member) {
            $matters = $recipients->surelyQualifyingMatters($member);

            if ($matters !== null) {
                foreach ($pending()->where('matter_user.user_id', $member->getKey())->whereIn('matter_user.matter_id', $matters->select('matters.id'))->toBase()->pluck('matter_user.id') as $id) {
                    $accepting[(int) $id] = true;
                }
            }
        }

        $candidates = $pending()->toBase()->pluck('matter_user.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->reject(fn (int $id): bool => isset($accepting[$id]))
            ->values()
            ->all();

        // Phần còn lại hỏi đúng định nghĩa từng ghế; đội ngũ nạp sẵn để `MatterPolicy::view()` trả lời trong bộ nhớ.
        return MatterUser::query()
            ->whereIntegerInRaw('matter_user.id', $candidates)
            ->orderBy('matter_user.id')
            ->with(['matter.team', 'user' => fn ($user) => $user->withTrashed()])
            ->get()
            ->filter(fn (MatterUser $seat): bool => self::stuck($seat, $seat->user, $seat->matter))
            ->sortBy(fn (MatterUser $seat): string => (string) $seat->matter?->code)
            ->values();
    }
}
