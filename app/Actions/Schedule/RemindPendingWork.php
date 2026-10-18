<?php

namespace App\Actions\Schedule;

use App\Actions\Matter\SeatsNeedingReassignment;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Task\TaskAssignees;
use App\Enums\MatterAssignmentStatus;
use App\Enums\TaskStatus;
use App\Jobs\SendSeatReminderDigest;
use App\Jobs\SendWorkReminder;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\Task;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use App\Support\Tasks\AcceptanceClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Lịch nhắc của luồng giao – nhận nội bộ (làn tk, Task 2; tác vụ `tasks.remind` của `routes/console.php`,
 * mỗi 30 phút, chỉ trong giờ làm việc). Ba loại nhắc (sáu lời nhắc — lời thứ sáu, "sắp đến hạn" cho người giao, từ
 * làn fa1 vòng sửa 1), mỗi lời MỘT lần cho mỗi (việc hay hồ sơ, người nhận, khoá nhắc):
 *
 *  1. **Chờ nhận quá lâu** — một việc "Chờ nhận", hay một hồ sơ (ghế trong đội ngũ vụ) chưa bấm "Đã nhận
 *     hồ sơ": sau {@see AcceptanceClock::REMIND_AFTER_HOURS} giờ làm việc nhắc người nhận
 *     (`reminder.awaiting`); sau {@see AcceptanceClock::ESCALATE_AFTER_HOURS} giờ làm việc vẫn chưa nhận
 *     thì báo người giao (`reminder.unaccepted`). Khoá: `awaiting@<assigned_at>` — giao lại là một lần
 *     giao mới, đồng hồ chạy lại.
 *  2. **Sắp đến hạn** — việc chờ nhận hay đang làm có hạn là hôm nay hoặc ngày mai: nhắc người nhận MỘT lần
 *     cho mỗi hạn (`reminder.due_soon`, khoá `due-soon@<hạn>`). Cửa sổ hai ngày, không đúng "ngày mai":
 *     tác vụ không chạy ngoài giờ làm việc, nên một hạn Thứ Hai vẫn được nhắc sáng Thứ Hai khi Chủ nhật
 *     không có lượt nào. Dời hạn là một hạn mới — nhắc lại cho hạn mới. Người nhận không còn giữ được việc: người
 *     giao nhận `reminder.due_soon_assigner` thay (xem đoạn "vòng sửa 1" dưới).
 *  3. **Quá hạn** — việc chờ nhận hay đang làm đã qua hạn: mỗi ngày làm việc một lần, nhắc người nhận
 *     (`reminder.overdue`) và báo người giao (`reminder.overdue_assigner`), khoá `overdue@<hôm nay>`. Việc riêng (người
 *     giao = người giữ) chỉ có lời nhắc người nhận — gộp integ-fa, rà soát chéo mục 3 ({@see self::isOwnTask()}).
 *
 * Việc "Bị trả lại" không nhắc (người giao đã được báo, nó nằm ở "Tôi đã giao"); việc của vụ đã kết thúc
 * hay đã huỷ không nhắc (không còn việc gì để làm trên vụ — người giao huỷ hay hoàn tất nó).
 *
 * Làn fa1 (phán quyết 5, kiểm tra luồng nội bộ 2026-10-16): ba lời nhắc NGƯỜI NHẬN (chờ nhận, sắp đến hạn, quá
 * hạn) chỉ tới người còn GIỮ ĐƯỢC việc ({@see self::assigneeHoldsSeat()} — `Task::scopeHeldBySeatedAssignee()`,
 * cùng định nghĩa với số "quá hạn"): người đã sang "người theo dõi", đã trả lại hồ sơ hay không còn mở được hồ sơ
 * không được bảo bấm một nút họ không còn có. Việc đó hiện "Cần giao lại"; hai lời báo NGƯỜI GIAO (chưa được nhận,
 * quá hạn) vẫn đi như cũ — người giao được nhắc thay — kèm một câu nói rõ việc cần giao lại (`SendWorkReminder`).
 *
 * Làn fa1, vòng sửa 1 (rà soát Task 1, I1): người giao được nhắc thay ĐÚNG LÚC người nhận lẽ ra được nhắc, không đợi
 * tới khi việc đã quá hạn hay đã chờ 48 giờ. "Không còn làm được" = {@see self::assigneeCanStillWork()} sai: không còn
 * giữ được việc ({@see self::assigneeHoldsSeat()}), hay tài khoản đã bị vô hiệu / xoá (khi đó `forTask()` cũng bỏ họ
 * khỏi lời nhắc người nhận):
 *
 *  - "sắp đến hạn": người nhận không còn làm được việc thì người giao (hay người nhận thay — {@see self::
 *    taskAssignerAudience()}) nhận lời báo riêng {@see self::DUE_SOON_ASSIGNER} (`reminder.due_soon_assigner`, cùng
 *    khoá `due-soon@<hạn>`, kèm câu "cần giao lại") — một hồ sơ toà án hạn ngày mai không được im lặng tới hôm sau;
 *  - "chờ nhận": người nhận không còn làm được việc thì lời báo người giao `reminder.unaccepted` đi ngay ở mốc
 *    {@see AcceptanceClock::REMIND_AFTER_HOURS} giờ làm việc (mốc của lời nhắc người nhận), không đợi
 *    {@see AcceptanceClock::ESCALATE_AFTER_HOURS} — cùng khoá `awaiting@<assigned_at>`, nên mốc 48 giờ không gửi lần
 *    hai ({@see self::taskThresholdFor()}).
 *
 * Cả hai được hỏi lại lúc gửi ({@see self::taskReminderHolds()}): người nhận đã làm lại được việc (xếp lại ghế, mở
 * lại tài khoản) thì lời báo sớm cho người giao không đi.
 *
 * # Người nhận — không bao giờ lộ vụ hạn chế
 *
 *  - việc: người nhận qua `ResolveStaffRecipients::forTask()` (đang hoạt động, chưa xoá, còn XEM ĐƯỢC
 *    việc — tức xem được vụ); lời báo cho người giao qua `ResolveStaffRecipients::forTaskAssigner()` (người
 *    giao đã nghỉ, rời vụ thì luật sư phụ trách hiện tại của vụ, với câu chữ và đường dẫn của người nhận
 *    thay — {@see self::isAssignerKind()}; bàn giao vụ thì lead mới đã là người giao, `MoveTasksOnHandover`);
 *  - hồ sơ: người nhận qua `ResolveStaffRecipients::qualifies()`; lời báo người giao qua
 *    {@see self::seatAssignerAudience()} — người giao, hay chuỗi dự phòng của `ResolveStaffRecipients`,
 *    không bao giờ chính người được giao.
 *
 * # Chống trùng, chống chạy chồng
 *
 * Lượt này chỉ CHỌN và XẾP job ({@see SendWorkReminder}, `afterCommit`); job hỏi lại MỌI điều kiện lúc gửi
 * ({@see self::taskReminderHolds()}, {@see self::seatReminderHolds()} — MỘT định nghĩa cho cả hai) và chống
 * trùng bằng chuông đã có cùng khoá và sổ thư (`outbound_messages`, `payload.tier` = khoá, M6 R3). Lượt này
 * bỏ qua người đã có thư `sent` cùng khoá ({@see SendWorkReminder::alreadyMailed()}), nên chạy lại cả ngày
 * không xếp lại; và bỏ qua (người, việc hay hồ sơ) mà thư nhắc đã hỏng HẲN hôm nay
 * ({@see SendWorkReminder::failedForGoodToday()} — vòng sửa polish 1, rà soát Task 2 m7: cùng cơ chế
 * `SendUnansweredIntakeReminderMail`), nên một hộp thư hỏng chỉ có một chuỗi thử mỗi ngày, lượt đầu hôm sau
 * thử lại. `withoutOverlapping(30)` ở lịch. Mỗi việc/hồ sơ một `try`: một lỗi không dừng lượt nhắc.
 *
 * # Lời nhắc hồ sơ gộp theo người (vòng sửa polish 1 — phán quyết controller (3))
 *
 * Một lô bàn giao hàng loạt N hồ sơ chạm ngưỡng cùng một lượt. Lời nhắc HỒ SƠ không xếp từng ghế: lượt gom
 * theo (loại nhắc, người nhận) ({@see self::queueSeat()}, {@see self::dispatchSeatReminders()}). Một hồ sơ
 * thì một {@see SendWorkReminder} như cũ; nhiều hồ sơ thì MỘT {@see SendSeatReminderDigest} — một chuông
 * và một thư liệt kê các hồ sơ, cho người được giao ("chờ anh/chị nhận") và cho từng người giao hay quản
 * lý ("chưa được nhận"). Lời nhắc việc không gộp (mỗi việc một tiêu đề, một hạn riêng).
 *
 * Ghế mà người được giao không còn nhận được hồ sơ (đã nghỉ việc, không còn mở được vụ) không nhắc ai:
 * không "hãy nhận" cho người đã nghỉ, không "X chưa nhận" cho người giao —
 * {@see SeatsNeedingReassignment} hiện ghế đó là "Cần phân công lại" thay vào đó.
 */
class RemindPendingWork
{
    public const SUBJECT_TASK = 'task';

    public const SUBJECT_SEAT = 'seat';

    public const AWAITING = 'reminder.awaiting';

    public const UNACCEPTED = 'reminder.unaccepted';

    public const DUE_SOON = 'reminder.due_soon';

    public const OVERDUE = 'reminder.overdue';

    public const OVERDUE_ASSIGNER = 'reminder.overdue_assigner';

    /**
     * Làn fa1, vòng sửa 1 (I1): việc sắp đến hạn mà người nhận không còn giữ được — báo NGƯỜI GIAO (xem docblock lớp).
     */
    public const DUE_SOON_ASSIGNER = 'reminder.due_soon_assigner';

    /**
     * Lời nhắc hồ sơ của lượt này, gom trước khi xếp: `[loại][id người nhận][id ghế] = khoá nhắc` — xem
     * docblock lớp, mục "Lời nhắc hồ sơ gộp theo người".
     *
     * @var array<string, array<int, array<int, string>>>
     */
    private array $seatReminders = [];

    public function __invoke(): array
    {
        return $this->handle();
    }

    /** @return array{queued: int} số lời nhắc (người × việc/hồ sơ) lượt này đã xếp */
    public function handle(): array
    {
        $clock = AcceptanceClock::fromConfig();

        if (! $clock->hours->isOpen(now())) {
            return ['queued' => 0];
        }

        $queued = 0;

        foreach ($this->awaitingTasks($clock) as $task) {
            $queued += $this->guarded(fn (): int => $this->remindAwaitingTask($task, $clock));
        }

        $this->seatReminders = [];

        foreach ($this->awaitingSeats($clock) as $seat) {
            $queued += $this->guarded(fn (): int => $this->remindAwaitingSeat($seat));
        }

        $this->dispatchSeatReminders();

        $dueSoon = self::activeTasks()
            ->whereDate('tasks.due_date', '>=', today()->toDateString())
            ->whereDate('tasks.due_date', '<=', today()->addDay()->toDateString());

        // Làn fa1, vòng sửa 1 (I1): người nhận không còn làm được việc thì người giao được báo thay — một lời trong hai.
        foreach ($dueSoon->get() as $task) {
            $queued += $this->guarded(fn (): int => self::assigneeCanStillWork($task)
                ? $this->queueForTask($task, self::DUE_SOON, self::dueSoonKey($task), [$task->assignee])
                : $this->queueForTask($task, self::DUE_SOON_ASSIGNER, self::dueSoonKey($task), $this->taskAssignerAudience($task)));
        }

        // Việc còn giữ, đã qua hạn, trên vụ còn mở. Lời nhắc NGƯỜI NHẬN thêm vế "còn giữ được việc"
        // (`queueForTask()` → `Task::scopeHeldBySeatedAssignee()`), nên tới đúng tập `Task::scopeOverdueToHandle()` —
        // CÙNG định nghĩa "quá hạn" với ô của bảng điều khiển và Theo dõi đội ngũ; lời báo NGƯỜI GIAO không có vế đó
        // (làn fa1, phán quyết 5: người nhận không còn giữ được việc thì người giao được nhắc thay).
        // Gộp integ-fa (rà soát chéo, mục 3): việc riêng (người giao = người giữ) không có lời báo người giao — người giữ
        // đã nhận lời nhắc quá hạn của chính họ ({@see self::isOwnTask()}).
        foreach (self::liveTasks()->held()->overdue()->get() as $task) {
            $queued += $this->guarded(fn (): int => $this->queueForTask($task, self::OVERDUE, self::overdueKey(), [$task->assignee])
                + (self::isOwnTask($task) ? 0 : $this->queueForTask($task, self::OVERDUE_ASSIGNER, self::overdueKey(), $this->taskAssignerAudience($task))));
        }

        return ['queued' => $queued];
    }

    /**
     * Lời nhắc `$kind` với khoá `$key` còn đúng cho việc `$task` lúc `$now` không — MỘT định nghĩa cho lượt
     * chọn và cho lần hỏi lại lúc gửi ({@see SendWorkReminder}).
     */
    public static function taskReminderHolds(Task $task, string $kind, string $key, ?CarbonInterface $now = null): bool
    {
        $now = CarbonImmutable::instance($now ?? now());

        if ($task->trashed() || ! $task->isOnLiveMatter()) {
            return false;
        }

        return match ($kind) {
            self::AWAITING, self::UNACCEPTED => $task->status === TaskStatus::Pending
                && $task->assigned_at !== null
                && $key === self::awaitingKey($task->assigned_at)
                && AcceptanceClock::fromConfig()->reached($task->assigned_at, self::taskThresholdFor($task, $kind), $now),
            self::DUE_SOON, self::DUE_SOON_ASSIGNER => self::isActive($task)
                && $task->due_date !== null
                && $key === self::dueSoonKey($task)
                && $task->due_date->toDateString() >= $now->toDateString()
                // Làn fa1, vòng sửa 1 (I1): lời báo người giao chỉ khi người nhận VẪN không làm được việc.
                && ($kind === self::DUE_SOON || ! self::assigneeCanStillWork($task)),
            self::OVERDUE, self::OVERDUE_ASSIGNER => self::isActive($task)
                && $task->isOverdue()
                && $key === self::overdueKey($now)
                // Gộp integ-fa (rà soát chéo, mục 3): việc đã thành việc riêng giữa lúc xếp và lúc gửi — không báo người giao.
                && ($kind === self::OVERDUE || ! self::isOwnTask($task)),
            default => false,
        };
    }

    /**
     * `$recipient` còn đúng là người lời nhắc `$kind` của `$task` nói tới (vòng sửa polish 1 — rà soát Task 2,
     * m6 + n2): ba loại nhắc người nhận (chờ nhận, sắp đến hạn, quá hạn) chỉ tới người ĐANG giữ việc; các loại
     * báo người giao ({@see self::isAssignerKind()}) chỉ tới người đang ở trong {@see self::taskAssignerAudience()}
     * (người giao, hay người nhận thay). Lượt chọn xếp đúng những người này; job hỏi lại lúc gửi — giữa hai lúc, việc có
     * thể đã được giao lại, hay bàn giao vụ đã đổi người giao.
     */
    public static function stillAddressedTo(Task $task, string $kind, User $recipient): bool
    {
        if (self::isAssignerKind($kind)) {
            return app(ResolveStaffRecipients::class)->forTaskAssigner($task)
                ->contains(fn (User $user): bool => $user->is($recipient));
        }

        return $task->assignee_id === $recipient->getKey() && self::assigneeHoldsSeat($task);
    }

    /**
     * Người nhận của `$task` còn GIỮ ĐƯỢC việc (làn fa1, phán quyết 5) — `Task::scopeHeldBySeatedAssignee()`, MỘT
     * định nghĩa với số "quá hạn": không "người theo dõi", không ghế đã trả lại, còn mở được hồ sơ. Không còn thì
     * ba lời nhắc người nhận (chờ nhận, sắp đến hạn, quá hạn) không đi — họ không bấm "Đã nhận"/"Hoàn thành" được
     * nữa (`TaskPolicy::stillHolds()`), việc hiện "Cần giao lại" — và lời báo người giao (chưa được nhận — từ mốc
     * 24 giờ, sắp đến hạn, quá hạn; docblock lớp) nói rõ việc cần giao lại (`SendWorkReminder`).
     */
    public static function assigneeHoldsSeat(Task $task): bool
    {
        return Task::query()->withoutGlobalScope(ClientPortalScope::class)
            ->whereKey($task->getKey())
            ->heldBySeatedAssignee()
            ->exists();
    }

    /**
     * Người nhận của `$task` còn LÀM ĐƯỢC việc — {@see self::assigneeHoldsSeat()} VÀ tài khoản còn hoạt động, chưa xoá
     * (vế `inactive` của `Task::scopeNeedsReassignment()`). Không thì lời nhắc người nhận không tới ai
     * (`ResolveStaffRecipients::forTask()` bỏ người đã nghỉ), nên người giao được báo sớm thay (làn fa1, vòng sửa 1 —
     * docblock lớp): "sắp đến hạn" (`DUE_SOON_ASSIGNER`) và "chưa được nhận" từ mốc 24 giờ ({@see self::taskThresholdFor()}).
     *
     * BẢN SQL của {@see TaskAssignees::qualifies()} (làn fa1, vòng sửa 2): cùng các vế — tài khoản còn hoạt động, chưa
     * xoá mềm, và (việc của vụ) còn giữ ghế ({@see TaskAssignees::holdsSeat()} ↔ `Task::scopeHeldBySeatedAssignee()`).
     * `ReopenTask`, `RestoreTask` và `UpdateTask` hỏi `qualifies()` dưới khoá; lịch nhắc hỏi hàm này. Đổi một vế ở một
     * bên thì PHẢI đổi cùng lúc ở bên kia (và ở `Task::scopeNeedsReassignment()`, phủ định của cả hai), không thì lời
     * nhắc và màn hình nói hai điều khác nhau về cùng một người.
     */
    public static function assigneeCanStillWork(Task $task): bool
    {
        $assignee = User::query()->withTrashed()->find($task->assignee_id);

        return $assignee !== null && $assignee->is_active && ! $assignee->trashed() && self::assigneeHoldsSeat($task);
    }

    /**
     * Như {@see self::taskReminderHolds()}, cho một ghế trong đội ngũ vụ (chỉ hai loại chờ nhận). Người ngồi
     * ghế phải còn NHẬN ĐƯỢC hồ sơ — đang hoạt động, chưa xoá, còn mở được vụ
     * ({@see SeatsNeedingReassignment::memberCanAccept()}; vòng sửa polish 1): một người đã nghỉ không được
     * nhắc "hãy nhận", và người giao không bị báo "X chưa nhận" về một người không còn bấm nhận được — ghế đó
     * hiện "Cần phân công lại" (gỡ ghế, hay bàn giao vụ).
     */
    public static function seatReminderHolds(MatterUser $seat, string $kind, string $key, ?CarbonInterface $now = null): bool
    {
        return in_array($kind, [self::AWAITING, self::UNACCEPTED], true)
            && $seat->assignmentStatus() === MatterAssignmentStatus::Pending
            && $seat->assigned_at !== null
            && $key === self::awaitingKey($seat->assigned_at)
            && AcceptanceClock::fromConfig()->reached($seat->assigned_at, self::thresholdFor($kind), $now)
            && SeatsNeedingReassignment::memberCanAccept($seat->user, $seat->matter);
    }

    public static function awaitingKey(CarbonInterface $assignedAt): string
    {
        return 'awaiting@'.$assignedAt->format('Y-m-d\TH:i:s');
    }

    public static function dueSoonKey(Task $task): string
    {
        return 'due-soon@'.$task->due_date?->toDateString();
    }

    public static function overdueKey(?CarbonInterface $now = null): string
    {
        return 'overdue@'.CarbonImmutable::instance($now ?? now())->toDateString();
    }

    /**
     * Loại nhắc báo NGƯỜI GIAO (chưa được nhận, quá hạn; từ làn fa1 vòng sửa 1 cả "sắp đến hạn" khi người nhận không
     * còn giữ được việc) — người nhận qua {@see self::taskAssignerAudience()}, có thể là luật sư phụ trách nhận thay
     * (vòng sửa 1 của làn tk, I1: câu chữ, đường dẫn của người nhận thay — `TaskReminderAlert::onBehalf()`).
     */
    public static function isAssignerKind(string $kind): bool
    {
        return in_array($kind, [self::UNACCEPTED, self::OVERDUE_ASSIGNER, self::DUE_SOON_ASSIGNER], true);
    }

    /** Ngưỡng giờ làm việc của một loại nhắc chờ nhận (hồ sơ; việc qua {@see self::taskThresholdFor()}). */
    public static function thresholdFor(string $kind): int
    {
        return $kind === self::UNACCEPTED ? AcceptanceClock::ESCALATE_AFTER_HOURS : AcceptanceClock::REMIND_AFTER_HOURS;
    }

    /**
     * Ngưỡng của một loại nhắc chờ nhận cho VIỆC `$task`: như {@see self::thresholdFor()}, trừ lời báo người giao
     * `UNACCEPTED` về một việc mà người nhận không còn làm được ({@see self::assigneeCanStillWork()}; làn fa1, vòng
     * sửa 1 — docblock lớp): mốc của lời nhắc người nhận, vì người nhận sẽ không bao giờ bấm "Đã nhận" được.
     */
    public static function taskThresholdFor(Task $task, string $kind): int
    {
        return $kind === self::UNACCEPTED && ! self::assigneeCanStillWork($task)
            ? AcceptanceClock::REMIND_AFTER_HOURS
            : self::thresholdFor($kind);
    }

    /**
     * Người nhận lời báo "cho người giao" của một việc — `ResolveStaffRecipients::forTaskAssigner()`: người
     * giao nếu còn hợp lệ, không thì luật sư phụ trách hiện tại của vụ, không nữa thì người quản lý của vụ (mọi lời
     * báo người giao của lịch là loại CẦN người quyết định).
     *
     * @return list<User>
     */
    private function taskAssignerAudience(Task $task): array
    {
        return app(ResolveStaffRecipients::class)->forTaskAssigner($task)->all();
    }

    /** @return Collection<int, Task> */
    private function awaitingTasks(AcceptanceClock $clock): Collection
    {
        return self::liveTasks()
            ->where('tasks.status', TaskStatus::Pending->value)
            ->where('tasks.assigned_at', '<=', $clock->coarseCutoff(AcceptanceClock::REMIND_AFTER_HOURS))
            ->get()
            ->filter(fn (Task $task): bool => $clock->reached($task->assigned_at, AcceptanceClock::REMIND_AFTER_HOURS));
    }

    private function remindAwaitingTask(Task $task, AcceptanceClock $clock): int
    {
        $key = self::awaitingKey($task->assigned_at);
        $queued = $this->queueForTask($task, self::AWAITING, $key, [$task->assignee]);

        // Làn fa1, vòng sửa 1 (I1): người nhận không còn làm được việc — người giao được báo ngay ở mốc 24 giờ.
        if ($clock->reached($task->assigned_at, self::taskThresholdFor($task, self::UNACCEPTED))) {
            $queued += $this->queueForTask($task, self::UNACCEPTED, $key, $this->taskAssignerAudience($task));
        }

        return $queued;
    }

    /** @return Collection<int, MatterUser> */
    private function awaitingSeats(AcceptanceClock $clock): Collection
    {
        return MatterUser::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->awaitingAcceptance()
            ->whereNotNull('matter_user.assigned_at')
            ->where('matter_user.assigned_at', '<=', $clock->coarseCutoff(AcceptanceClock::REMIND_AFTER_HOURS))
            ->whereHas('matter', fn (Builder $matter): Builder => $matter->withoutGlobalScope(ClientPortalScope::class)->open())
            ->with(['matter.leadLawyer', 'user', 'assigner'])
            ->orderBy('matter_user.id')
            ->get()
            ->filter(fn (MatterUser $seat): bool => $clock->reached($seat->assigned_at, AcceptanceClock::REMIND_AFTER_HOURS));
    }

    private function remindAwaitingSeat(MatterUser $seat): int
    {
        $matter = $seat->matter;
        $key = self::awaitingKey($seat->assigned_at);
        $queued = 0;

        if (self::seatReminderHolds($seat, self::AWAITING, $key)) {
            $queued += $this->queueSeat($seat, self::AWAITING, $key, $seat->user, $matter);
        }

        if (self::seatReminderHolds($seat, self::UNACCEPTED, $key)) {
            foreach ($this->seatAssignerAudience($seat) as $user) {
                $queued += $this->queueSeat($seat, self::UNACCEPTED, $key, $user, $matter);
            }
        }

        return $queued;
    }

    /**
     * Ghi một lời nhắc hồ sơ vào {@see self::$seatReminders} (chưa xếp job) — cùng hai lần bỏ qua của
     * {@see self::queue()}: thư đã gửi (kể cả trong một thư gộp — `SendWorkReminder::alreadyMailed()` nhận id
     * ghế), thư đã hỏng hẳn hôm nay.
     */
    private function queueSeat(MatterUser $seat, string $kind, string $key, User $recipient, Matter $matter): int
    {
        $seatId = (int) $seat->getKey();

        if (SendWorkReminder::alreadyMailed($matter, $recipient, self::SUBJECT_SEAT, $kind, $key, $seatId)
            || SendWorkReminder::failedForGoodToday(self::SUBJECT_SEAT, $seatId, $recipient->getKey())) {
            return 0;
        }

        $this->seatReminders[$kind][$recipient->getKey()][$seatId] = $key;

        return 1;
    }

    /**
     * Xếp các lời nhắc hồ sơ đã gom — một hồ sơ: {@see SendWorkReminder} như mọi lời nhắc; nhiều hồ sơ cho
     * cùng (loại, người nhận): MỘT {@see SendSeatReminderDigest}. Xem docblock lớp, mục "Lời nhắc hồ sơ gộp
     * theo người".
     */
    private function dispatchSeatReminders(): void
    {
        foreach ($this->seatReminders as $kind => $byRecipient) {
            foreach ($byRecipient as $recipientId => $seats) {
                $this->guarded(function () use ($kind, $recipientId, $seats): int {
                    if (count($seats) === 1) {
                        SendWorkReminder::dispatch(self::SUBJECT_SEAT, (int) array_key_first($seats), $kind, $recipientId, (string) reset($seats))->afterCommit();
                    } else {
                        SendSeatReminderDigest::dispatch($kind, $recipientId, $seats)->afterCommit();
                    }

                    return 0;
                });
            }
        }

        $this->seatReminders = [];
    }

    /**
     * Người nhận lời báo "hồ sơ chưa được nhận" — không bao giờ chính người chưa nhận:
     * `ResolveStaffRecipients::handle()` với người giao làm người ưu tiên (đã nghỉ, mất quyền xem vụ thì
     * chuỗi dự phòng: luật sư phụ trách → quản lý → admin được xem vụ). Chuỗi dự phòng dừng ở người hợp lệ
     * ĐẦU TIÊN, và đó có thể là chính người đang chờ nhận (ghế luật sư phụ trách vừa bàn giao): khi đó người
     * giám sát vụ (`supervisorsFor()` — quản lý, hay admin với vụ hạn chế) nhận thay.
     *
     * @return Collection<int, User>
     */
    private function seatAssignerAudience(MatterUser $seat): Collection
    {
        $resolver = app(ResolveStaffRecipients::class);
        $notMember = fn (User $user): bool => $user->getKey() !== $seat->user_id;

        $audience = $resolver->handle($seat->matter, [$seat->assigner])->filter($notMember)->values();

        return $audience->isNotEmpty()
            ? $audience
            : $resolver->supervisorsFor($seat->matter)->filter($notMember)->values();
    }

    /**
     * @param  list<User|null>  $preferred
     */
    private function queueForTask(Task $task, string $kind, string $key, array $preferred): int
    {
        // Xem {@see self::assigneeHoldsSeat()}: lời nhắc người nhận chỉ tới người còn giữ được việc.
        if (! self::isAssignerKind($kind) && ! self::assigneeHoldsSeat($task)) {
            return 0;
        }

        $queued = 0;

        foreach (app(ResolveStaffRecipients::class)->forTask($task, $preferred) as $user) {
            $queued += $this->queue(self::SUBJECT_TASK, (int) $task->getKey(), $kind, $key, $user, $task);
        }

        return $queued;
    }

    private function queue(string $subject, int $subjectId, string $kind, string $key, User $recipient, Task|Matter $related): int
    {
        if (SendWorkReminder::alreadyMailed($related, $recipient, $subject, $kind, $key)
            || SendWorkReminder::failedForGoodToday($subject, $subjectId, $recipient->getKey())) {
            return 0;
        }

        SendWorkReminder::dispatch($subject, $subjectId, $kind, $recipient->getKey(), $key)->afterCommit();

        return 1;
    }

    /** @param  callable(): int  $step */
    private function guarded(callable $step): int
    {
        try {
            return $step();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /** Việc chưa xoá của vụ còn mở (hay việc chung), kèm quan hệ lời nhắc cần đọc. */
    private static function liveTasks(): Builder
    {
        return Task::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->onLiveMatter()
            ->with(['matter.leadLawyer', 'assignee', 'assigner'])
            ->orderBy('tasks.id');
    }

    /** Việc "Chờ nhận" hay "Đang làm" — hai trạng thái người nhận còn phải làm. */
    private static function activeTasks(): Builder
    {
        return self::liveTasks()->whereIn('tasks.status', [TaskStatus::Pending->value, TaskStatus::Accepted->value]);
    }

    /**
     * Việc RIÊNG — người giao chính là người đang giữ (nhận thay, "Giao lại" về người đã giao, bàn giao vụ — gộp
     * integ-fa). Lời nhắc quá hạn tới người giữ là đủ: không có lời báo "quá hạn" cho người giao, và (qua
     * `ResolveStaffRecipients::forTaskAssigner()`) không lời báo nào của việc riêng rơi tới người quản lý của vụ.
     */
    public static function isOwnTask(Task $task): bool
    {
        return $task->assigned_by !== null && $task->assigned_by === $task->assignee_id;
    }

    private static function isActive(Task $task): bool
    {
        return in_array($task->status, [TaskStatus::Pending, TaskStatus::Accepted], true);
    }
}
