<?php

namespace App\Models;

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
 * tầng kia (`TaskAccessTest` ghim hai tầng khớp nhau trên ma trận năm vai):
 *
 *  - việc chung: người nhận, người giao, quản lý/quản trị ({@see self::isSupervisor()});
 *  - việc của một vụ: người xem phải XEM ĐƯỢC VỤ (`Matter::listableBy()` / `MatterPolicy::view`, vụ chưa
 *    huỷ), VÀ là người nhận, người giao, luật sư phụ trách hiện tại của vụ, hoặc quản lý/quản trị. Vụ
 *    `restricted` vì vậy chỉ lộ ra với luật sư phụ trách và admin — luật của vụ, không viết lại ở đây.
 *    Kế toán không có `matter.view`: không bao giờ thấy việc của vụ nào, chỉ việc chung giao cho mình.
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
     * chủ văn phòng 2026-10-10): người đó đã bị vô hiệu hoá hay xoá mềm, hoặc — việc của một vụ — không
     * còn ngồi ghế giữ việc nào trong đội ngũ của vụ ({@see self::workingSeats()}: đã rời đội,
     * hay chuyển sang "người theo dõi"). Cùng luật, đọc trong bộ nhớ: {@see self::reassignmentReason()}.
     */
    public function scopeNeedsReassignment(Builder $query): Builder
    {
        return $query->open()->where(fn (Builder $stuck): Builder => $stuck
            ->whereHas('assignee', fn (Builder $assignee): Builder => $assignee->withTrashed()->where(fn (Builder $gone): Builder => $gone
                ->where('users.is_active', false)
                ->orWhereNotNull('users.deleted_at')))
            ->orWhere(fn (Builder $offTeam): Builder => $offTeam
                ->whereNotNull($this->qualifyColumn('matter_id'))
                ->whereNotExists(fn (QueryBuilder $seat): QueryBuilder => $seat
                    ->selectRaw('1')
                    ->from('matter_user')
                    ->whereColumn('matter_user.matter_id', $this->qualifyColumn('matter_id'))
                    ->whereColumn('matter_user.user_id', $this->qualifyColumn('assignee_id'))
                    ->whereIn('matter_user.role_in_matter', self::workingSeats()))));
    }

    /**
     * Vì sao việc này cần giao lại — `inactive` (người nhận đã nghỉ), `off_team` (không còn trong đội ngũ
     * giữ việc của vụ), hay `null` (không cần). Cùng luật {@see self::scopeNeedsReassignment()}, đọc trên
     * quan hệ đã nạp (`assignee` kể cả đã xoá mềm, `matter.team` — `TaskColumns::eagerLoad()`). Chỉ để
     * hiển thị; không Action nào đọc nó (Action hỏi lại dưới khoá).
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

        if ($this->matter_id === null || $this->matter === null) {
            return null;
        }

        $seat = $this->matter->team->first(fn (User $member): bool => $member->is($assignee))?->pivot?->role_in_matter;
        $seat = $seat instanceof MatterRole ? $seat->value : $seat;

        return in_array($seat, self::workingSeats(), true) ? null : 'off_team';
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
