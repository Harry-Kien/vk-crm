<?php

namespace App\Models;

use App\Enums\MatterAssignmentStatus;
use App\Enums\MatterRole;
use App\Models\Concerns\RestrictedToClientPortal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Một ghế trong đội ngũ vụ việc (`matter_user`).
 *
 * # Nhận hồ sơ (làn tk, Task 2)
 *
 * Ghế được giao — thêm vào đội ngũ với vai khác "người theo dõi", hay trở thành luật sư phụ trách qua
 * "Bàn giao" — chờ người nhận xác nhận: {@see self::assignmentStatus()} đọc hai mốc `accepted_at` /
 * `declined_at`. Hai scope {@see self::scopeAwaitingAcceptance()} và {@see self::scopeReturned()} nói CÙNG
 * luật đó bằng SQL, cho danh sách "Chờ tôi nhận", lịch nhắc và "Theo dõi đội ngũ". Mọi lần ghi các mốc đi
 * qua `App\Actions\Matter\AcceptMatterAssignment` / `DeclineMatterAssignment` (người nhận) và
 * `Matter::addTeamMember()` / `ReassignMatter` (người giao).
 */
class MatterUser extends Pivot
{
    use RestrictedToClientPortal;

    /** Cột `decline_reason` VARCHAR(1000) — cùng độ dài lý do trả lại của một việc. */
    public const DECLINE_REASON_MAX_LENGTH = 1000;

    protected $table = 'matter_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'role_in_matter' => MatterRole::class,
            // Ép số: so `===` với `$user->getKey()`.
            'matter_id' => 'integer',
            'user_id' => 'integer',
            'assigned_by' => 'integer',
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    /** Xem docblock lớp, mục "Nhận hồ sơ". */
    public function assignmentStatus(): MatterAssignmentStatus
    {
        return match (true) {
            $this->accepted_at !== null => MatterAssignmentStatus::Accepted,
            $this->declined_at !== null => MatterAssignmentStatus::Declined,
            default => MatterAssignmentStatus::Pending,
        };
    }

    /** Ghế chưa ai bấm "Đã nhận hồ sơ" hay "Trả lại". Cùng luật {@see self::assignmentStatus()}. */
    public function scopeAwaitingAcceptance(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('accepted_at'))
            ->whereNull($this->qualifyColumn('declined_at'));
    }

    /** Ghế người nhận đã "Trả lại" — chờ quản lý hay luật sư phụ trách phân công lại. */
    public function scopeReturned(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('accepted_at'))
            ->whereNotNull($this->qualifyColumn('declined_at'));
    }

    /**
     * Ghế của một vụ ĐANG MỞ mà `$viewer` xem được (`Matter::open()` + `listableBy()`, vụ chưa huỷ) — một
     * ghế chờ nhận trên vụ đã kết thúc không còn gì để nhận.
     */
    public function scopeOnOpenMattersListableBy(Builder $query, User $viewer): Builder
    {
        return $query->whereHas('matter', fn (Builder $matter): Builder => $matter->open()->listableBy($viewer));
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Portal không bao giờ đọc bảng phân công nhân sự. Lưu ý: quan hệ belongsToMany của
     * Matter::team() đọc thẳng bảng matter_user qua join chứ không dựng truy vấn của model
     * Pivot này, nên chặn ở đây không ảnh hưởng đội ngũ vụ việc.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }
}
