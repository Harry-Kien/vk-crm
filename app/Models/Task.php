<?php

namespace App\Models;

use App\Enums\Confidentiality;
use App\Enums\MatterAssignmentStatus;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Policies\TaskPolicy;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Một việc giao NỘI BỘ (làn tk, Task 1 — chủ văn phòng, 2026-10-10: "luật sư giao việc chuyên viên";
 * phạm vi (a): chỉ giao nhận nội bộ, không sổ giấy tờ gốc của khách). Việc gắn với một vụ
 * (`matter_id`) hoặc là việc chung (`matter_id` rỗng). Người nhận bấm "Đã nhận" hoặc "Trả lại" kèm lý
 * do; người giao theo dõi, giao lại hoặc huỷ. Mọi lần ghi đi qua `app/Actions/Task/`.
 *
 * # Ai thấy một việc — một luật, hai tầng
 *
 * {@see self::scopeVisibleTo()} (mọi danh sách) và {@see TaskPolicy::view()} (mọi nút, mọi Action)
 * phát biểu CÙNG một luật, tầng này bằng `where`, tầng kia bằng thuộc tính; không tầng nào gọi lại
 * tầng kia (`TaskAccessTest` ghim hai tầng khớp nhau trên ma trận sáu vai — thêm Nhân viên kinh doanh
 * từ khi gộp làn kd):
 *
 *  - việc chung: người nhận, người giao, quản lý/quản trị ({@see self::isSupervisor()});
 *  - việc của một vụ: người xem phải XEM ĐƯỢC VỤ (`Matter::listableBy()` / `MatterPolicy::view`, vụ chưa
 *    huỷ), VÀ là người nhận, người giao, luật sư phụ trách hiện tại của vụ, hoặc quản lý/quản trị. Vụ
 *    `restricted` vì vậy chỉ lộ ra với luật sư phụ trách và admin — luật của vụ, không viết lại ở đây.
 *    Kế toán và nhân viên kinh doanh không có `matter.view`: không bao giờ thấy việc của vụ nào, chỉ việc
 *    chung giao cho mình.
 *
 * Cổng khách: không bao giờ ({@see self::applyClientPortalConstraints()} chặn sạch; policy trả `false`
 * cho mọi tài khoản khách).
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use RestrictedToClientPortal;
    use SoftDeletes;

    /** Cột `title` VARCHAR(255). */
    public const TITLE_MAX_LENGTH = 255;

    /** Cột `description` TEXT: 65.535 byte / 4 byte mỗi ký tự utf8mb4. */
    public const DESCRIPTION_MAX_LENGTH = 16383;

    /** Cột `decline_reason` và `cancel_reason` VARCHAR(1000). */
    public const REASON_MAX_LENGTH = 1000;

    /** Cột `completion_note` VARCHAR(2000). */
    public const NOTE_MAX_LENGTH = 2000;

    /** Việc hoàn thành trong ngần này ngày qua còn hiện ở "Tôi đã giao" ({@see self::scopeAssignedBy()}). */
    public const RECENTLY_COMPLETED_DAYS = 7;

    protected $fillable = [
        'matter_id', 'title', 'description', 'assignee_id', 'assigned_by', 'due_date', 'priority', 'status',
        'assigned_at', 'accepted_at', 'declined_at', 'decline_reason', 'completed_at', 'completion_note',
        'cancelled_at', 'cancel_reason',
    ];

    protected $attributes = [
        'priority' => 'normal',
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            // Ép số ở ba khoá ngoại: policy so `===` với `$user->getKey()`.
            'matter_id' => 'integer',
            'assignee_id' => 'integer',
            'assigned_by' => 'integer',
            'due_date' => 'date',
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * "Quản lý/quản trị" của luồng giao việc — MỘT định nghĩa, đọc ở cả scope lẫn policy: vai `manager`
     * hoặc `admin`. Họ giao việc chung, thấy và xử lý mọi việc (trong phạm vi vụ họ xem được).
     */
    public static function isSupervisor(User $user): bool
    {
        return $user->hasRole(Role::Admin->value) || $user->hasRole(Role::Manager->value);
    }

    /**
     * Ghế GIỮ VIỆC trong đội ngũ một vụ — luật sư phụ trách, luật sư cộng sự, trợ lý; không "người theo
     * dõi". Chỉ người ngồi một trong các ghế này nhận được việc của vụ (`TaskAssignees`), và người giữ việc
     * rời mọi ghế này thì việc cần giao lại ({@see self::scopeNeedsReassignment()}).
     *
     * @return list<string>
     */
    public static function workingSeats(): array
    {
        return [MatterRole::Lead->value, MatterRole::Associate->value, MatterRole::Assistant->value];
    }

    /** Xem docblock lớp, mục "Ai thấy một việc". */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $id = $user->getKey();
        $supervisor = self::isSupervisor($user);
        $seesMatters = $user->can(Permission::MatterView->value);

        $involved = fn (Builder $rows): Builder => $rows->where(fn (Builder $who): Builder => $who
            ->where($this->qualifyColumn('assignee_id'), $id)
            ->orWhere($this->qualifyColumn('assigned_by'), $id));

        return $query->where(function (Builder $outer) use ($user, $supervisor, $seesMatters, $involved): void {
            $outer->where(function (Builder $general) use ($supervisor, $involved): void {
                $general->whereNull($this->qualifyColumn('matter_id'));

                if (! $supervisor) {
                    $involved($general);
                }
            })->orWhere(function (Builder $onMatter) use ($user, $supervisor, $seesMatters, $involved): void {
                $onMatter->whereNotNull($this->qualifyColumn('matter_id'));

                if (! $seesMatters) {
                    $onMatter->whereRaw('1 = 0');

                    return;
                }

                $onMatter->whereHas('matter', fn (Builder $matter): Builder => $matter->listableBy($user));

                if (! $supervisor) {
                    $onMatter->where(fn (Builder $who): Builder => $involved($who)
                        ->orWhereHas('matter', fn (Builder $matter): Builder => $matter->ledBy($user)));
                }
            });
        });
    }

    /** Việc còn mở: chờ nhận, đang làm, hoặc bị trả lại chưa xử lý. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), TaskStatus::openValues());
    }

    /**
     * Việc người nhận còn GIỮ (làn tk, Task 2): chờ nhận hoặc đang làm — chưa trả lại, chưa xong, chưa huỷ.
     * Một việc "Bị trả lại" vẫn còn mở ({@see self::scopeOpen()}) nhưng đã về tay người giao.
     */
    public function scopeHeld(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), [TaskStatus::Pending->value, TaskStatus::Accepted->value]);
    }

    /** Việc chưa ai bấm "Đã nhận" (mọi người nhận) — làn tk, Task 2. */
    public function scopePendingAcceptance(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), TaskStatus::Pending->value);
    }

    /** Việc người nhận đã nhận, chưa xong (mọi người nhận) — làn tk, Task 2. */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), TaskStatus::Accepted->value);
    }

    /** Việc bị trả lại, chờ người giao giao lại hay huỷ — làn tk, Task 2. */
    public function scopeReturned(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), TaskStatus::Declined->value);
    }

    /**
     * Việc CÓ HẠN đã hoàn thành trong `$bounds` (hai cận đủ giờ của `PerformancePeriod::bounds()`) — tập của
     * chỉ số "Việc hoàn thành đúng hạn" (làn tk, Task 2). Đúng hạn hay trễ: {@see self::completedOnTime()}.
     *
     * @param  array{0: string, 1: string}  $bounds
     */
    public function scopeCompletedWithDueDateBetween(Builder $query, array $bounds): Builder
    {
        return $query->where($this->qualifyColumn('status'), TaskStatus::Completed->value)
            ->whereNotNull($this->qualifyColumn('due_date'))
            ->whereBetween($this->qualifyColumn('completed_at'), $bounds);
    }

    /**
     * Việc đã hoàn thành KHÔNG muộn hơn hết ngày hạn (theo `APP_TIMEZONE`) — cùng nghĩa "đúng hạn" của mốc
     * thời hạn (P1: xong không muộn hơn hết ngày đến hạn). Việc chưa xong hay không có hạn: `false`.
     */
    public function completedOnTime(): bool
    {
        return $this->status === TaskStatus::Completed
            && $this->completed_at !== null
            && $this->due_date !== null
            && $this->completed_at->copy()->setTimezone((string) config('app.timezone'))->toDateString() <= $this->due_date->toDateString();
    }

    /** "Chờ tôi nhận": giao cho `$user`, chưa ai bấm "Đã nhận". */
    public function scopeAwaitingAcceptanceBy(Builder $query, User $user): Builder
    {
        return $query->where($this->qualifyColumn('assignee_id'), $user->getKey())
            ->where($this->qualifyColumn('status'), TaskStatus::Pending->value);
    }

    /** "Đang làm": `$user` đã nhận, chưa xong. */
    public function scopeInProgressFor(Builder $query, User $user): Builder
    {
        return $query->where($this->qualifyColumn('assignee_id'), $user->getKey())
            ->where($this->qualifyColumn('status'), TaskStatus::Accepted->value);
    }

    /**
     * "Tôi đã giao": việc `$user` giao còn mở (kể cả việc bị trả lại chờ giao lại), cộng việc đã HOÀN
     * THÀNH trong {@see self::RECENTLY_COMPLETED_DAYS} ngày qua — lời báo "đã hoàn thành" (chuông, thư)
     * trỏ về đây, và người giao đọc ghi chú kết quả ở đúng chỗ họ theo dõi việc. Việc đã huỷ thì không.
     */
    public function scopeAssignedBy(Builder $query, User $user): Builder
    {
        return $query->where($this->qualifyColumn('assigned_by'), $user->getKey())
            ->where(fn (Builder $tracked): Builder => $tracked
                ->whereIn($this->qualifyColumn('status'), TaskStatus::openValues())
                ->orWhere(fn (Builder $done): Builder => $done
                    ->where($this->qualifyColumn('status'), TaskStatus::Completed->value)
                    ->where($this->qualifyColumn('completed_at'), '>=', now()->subDays(self::RECENTLY_COMPLETED_DAYS))));
    }

    /** Việc còn mở đã qua hạn (hạn là hôm qua hoặc trước nữa). */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull($this->qualifyColumn('due_date'))
            ->whereDate($this->qualifyColumn('due_date'), '<', today()->toDateString());
    }

    /**
     * Việc còn mở mà người đang giữ không làm được nữa — cần người quản lý GIAO LẠI (không tự chuyển,
     * chủ văn phòng 2026-10-10). Năm vế, mỗi vế một mã của {@see self::reassignmentReason()} (cùng luật,
     * đọc trong bộ nhớ):
     *
     *  1. `inactive` — người nhận đã bị vô hiệu hoá hay xoá mềm.
     *  2. `off_team` — việc của một vụ mà người nhận không còn ngồi ghế giữ việc nào trong đội ngũ của vụ
     *     ({@see self::workingSeats()}: đã rời đội, hay chuyển sang "người theo dõi").
     *  3. `seat_declined` (vòng sửa cuối 1, F3) — người nhận đã "Trả lại" chính ghế của họ trong vụ (quá
     *     tải, xung đột lợi ích…; cùng luật `MatterUser::scopeReturned()`): họ còn ngồi ghế, nhưng đã nói
     *     không nhận hồ sơ, nên việc của hồ sơ đó — kể cả việc bàn giao vụ chuyển sang họ — cần người khác.
     *  4. `off_access` (vòng sửa cuối 1, F4) — việc của một vụ mà người nhận không còn MỞ ĐƯỢC vụ
     *     ({@see self::scopeWhereCannotOpenMatter()}: mất quyền `matter.view` vì đổi chức danh sang Kế toán
     *     hay Nhân viên kinh doanh, hoặc vụ bị đổi sang "Hạn chế truy cập" mà họ không phải luật sư phụ
     *     trách hay quản trị viên). Việc biến khỏi mọi danh sách của họ và lịch nhắc bỏ họ; không có vế này
     *     thì một việc đang làm, không hạn, không tới được ai.
     *  5. `assigner_gone` / `assigner_off_access` — việc "Bị trả lại" mà NGƯỜI GIAO đã bị vô hiệu hoá hay
     *     xoá mềm (làn tk, Task 2, vòng sửa 1 — I1), hay — việc của một vụ — không còn mở được vụ (vòng sửa
     *     cuối 1, F4: rời đội ngũ mà không có quyền xem mọi vụ, vụ đổi sang hạn chế, đổi chức danh). Việc
     *     bị trả lại chờ người giao giao lại hay huỷ; người đó không còn thấy việc thì nó không nằm ở "Tôi
     *     đã giao" của ai — nó cần luật sư phụ trách hay quản lý nhận lấy. Việc người nhận còn giữ (chờ
     *     nhận, đang làm) thì không: người nhận vẫn làm được, và lời báo/nhắc cho người giao có người nhận
     *     thay (`ResolveStaffRecipients::forTaskAssigner()`). Bàn giao vụ đổi người giao sang lead mới
     *     (`MoveTasksOnHandover::transferAssigner()`).
     */
    public function scopeNeedsReassignment(Builder $query): Builder
    {
        $gone = fn (Builder $user): Builder => $user->withTrashed()->where(fn (Builder $left): Builder => $left
            ->where('users.is_active', false)
            ->orWhereNotNull('users.deleted_at'));

        return $query->open()->where(fn (Builder $stuck): Builder => $stuck
            ->whereHas('assignee', $gone)
            ->orWhere(fn (Builder $offTeam): Builder => $offTeam
                ->whereNotNull($this->qualifyColumn('matter_id'))
                ->whereNotExists(fn (QueryBuilder $seat): QueryBuilder => $seat
                    ->selectRaw('1')
                    ->from('matter_user')
                    ->whereColumn('matter_user.matter_id', $this->qualifyColumn('matter_id'))
                    ->whereColumn('matter_user.user_id', $this->qualifyColumn('assignee_id'))
                    ->whereIn('matter_user.role_in_matter', self::workingSeats())))
            ->orWhere(fn (Builder $seatDeclined): Builder => $seatDeclined
                ->whereNotNull($this->qualifyColumn('matter_id'))
                ->whereExists(fn (QueryBuilder $seat): QueryBuilder => $seat
                    ->selectRaw('1')
                    ->from('matter_user')
                    ->whereColumn('matter_user.matter_id', $this->qualifyColumn('matter_id'))
                    ->whereColumn('matter_user.user_id', $this->qualifyColumn('assignee_id'))
                    ->whereNull('matter_user.accepted_at')
                    ->whereNotNull('matter_user.declined_at')))
            ->orWhere(fn (Builder $offAccess): Builder => $offAccess->whereCannotOpenMatter('assignee_id'))
            ->orWhere(fn (Builder $orphaned): Builder => $orphaned
                ->where($this->qualifyColumn('status'), TaskStatus::Declined->value)
                ->where(fn (Builder $assigner): Builder => $assigner
                    ->whereHas('assigner', $gone)
                    ->orWhere(fn (Builder $offAccess): Builder => $offAccess->whereCannotOpenMatter('assigned_by')))));
    }

    /**
     * Việc của một vụ (chưa huỷ) mà người ở cột `$column` (`assignee_id` hay `assigned_by`) không MỞ ĐƯỢC
     * vụ — bản SQL của `MatterPolicy::view` (có `matter.view`, rồi `Matter::scopeListableBy()`) cho một người
     * đọc từ cột, không phải người đăng nhập: thiếu `matter.view`; hoặc vụ hạn chế mà họ không phải luật sư
     * phụ trách hay quản trị viên; hoặc vụ thường mà họ không có `matter.viewAny` và không có tên trong đội
     * ngũ. Bản trong bộ nhớ: {@see self::canOpenMatter()}. Việc chung không bao giờ thuộc đây.
     */
    public function scopeWhereCannotOpenMatter(Builder $query, string $column): Builder
    {
        $person = $this->qualifyColumn($column);

        return $query->whereNotNull($this->qualifyColumn('matter_id'))
            ->whereHas('matter', fn (Builder $matter): Builder => $matter->where(fn (Builder $blocked): Builder => $blocked
                ->whereNotIn($person, self::holdersOf(Permission::MatterView))
                ->orWhere(fn (Builder $restricted): Builder => $restricted
                    ->where('matters.confidentiality', Confidentiality::Restricted->value)
                    ->where(fn (Builder $notLead): Builder => $notLead
                        ->whereNull('matters.lead_lawyer_id')
                        ->orWhereColumn('matters.lead_lawyer_id', '!=', $person))
                    ->whereNotIn($person, self::admins()))
                ->orWhere(fn (Builder $normal): Builder => $normal
                    ->where('matters.confidentiality', '!=', Confidentiality::Restricted->value)
                    ->whereNotIn($person, self::holdersOf(Permission::MatterViewAny))
                    ->whereNotExists(fn (QueryBuilder $seat): QueryBuilder => $seat
                        ->selectRaw('1')
                        ->from('matter_user')
                        ->whereColumn('matter_user.matter_id', 'matters.id')
                        ->whereColumn('matter_user.user_id', $person)))));
    }

    /**
     * `$user` mở được `$matter` — phủ định của {@see self::scopeWhereCannotOpenMatter()}, đọc trong bộ nhớ:
     * `matter.view` rồi `Matter::isListableBy()` (dùng `team` đã nạp — `TaskColumns::eagerLoad()`), đúng hai
     * bước của `MatterPolicy::view`.
     */
    public static function canOpenMatter(User $user, Matter $matter): bool
    {
        return $user->can(Permission::MatterView->value) && $matter->isListableBy($user);
    }

    /**
     * Id mọi nhân sự (kể cả đã xoá mềm) có `$permission` qua vai hay gán thẳng — truy vấn con của
     * {@see self::scopeWhereCannotOpenMatter()}.
     */
    private static function holdersOf(Permission $permission): QueryBuilder
    {
        $named = fn (Builder $grant): Builder => $grant->where('name', $permission->value);

        return User::query()->withTrashed()
            ->where(fn (Builder $who): Builder => $who->whereHas('roles.permissions', $named)->orWhereHas('permissions', $named))
            ->select('users.id')
            ->toBase();
    }

    /** Id mọi nhân sự (kể cả đã xoá mềm) mang vai quản trị viên — vụ hạn chế mở cho họ. */
    private static function admins(): QueryBuilder
    {
        return User::query()->withTrashed()
            ->whereHas('roles', fn (Builder $role): Builder => $role->where('name', Role::Admin->value))
            ->select('users.id')
            ->toBase();
    }

    /**
     * Vì sao việc này cần giao lại — một trong các mã của {@see self::scopeNeedsReassignment()} (`inactive`,
     * `off_team`, `seat_declined`, `off_access`, `assigner_gone`, `assigner_off_access`), hay `null` (không
     * cần). Cùng luật, đọc trên quan hệ đã nạp (`assignee` và `assigner` kể cả đã xoá mềm, `matter.team` —
     * `TaskColumns::eagerLoad()`). Thứ tự kiểm theo thứ tự vế: một việc vướng hai vế mang mã của vế đầu.
     * Chỉ để hiển thị; không Action nào đọc nó (Action hỏi lại dưới khoá).
     */
    public function reassignmentReason(): ?string
    {
        $assignee = $this->assignee;

        if (! $this->isOpen() || ! $assignee instanceof User) {
            return null;
        }

        if (! $assignee->is_active || $assignee->trashed()) {
            return 'inactive';
        }

        $matter = null;

        if ($this->matter_id !== null) {
            $matter = $this->matter;

            if ($matter === null) {
                return null;
            }

            $seat = $matter->team->first(fn (User $member): bool => $member->is($assignee))?->pivot;
            $role = $seat?->role_in_matter;
            $role = $role instanceof MatterRole ? $role->value : $role;

            if (! in_array($role, self::workingSeats(), true)) {
                return 'off_team';
            }

            if ($seat instanceof MatterUser && $seat->assignmentStatus() === MatterAssignmentStatus::Declined) {
                return 'seat_declined';
            }

            if (! self::canOpenMatter($assignee, $matter)) {
                return 'off_access';
            }
        }

        $assigner = $this->assigner;

        if ($this->status !== TaskStatus::Declined || ! $assigner instanceof User) {
            return null;
        }

        if (! $assigner->is_active || $assigner->trashed()) {
            return 'assigner_gone';
        }

        return $matter !== null && ! self::canOpenMatter($assigner, $matter) ? 'assigner_off_access' : null;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->due_date !== null
            && $this->due_date->toDateString() < today()->toDateString();
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** Việc nội bộ không bao giờ ra cổng khách. */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }
}
