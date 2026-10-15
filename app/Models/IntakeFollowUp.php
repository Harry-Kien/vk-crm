<?php

namespace App\Models;

use App\Actions\Intake\AnonymiseProspect;
use App\Actions\Intake\LogIntakeFollowUp;
use App\Enums\FollowUpChannel;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\IntakeFollowUpFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MỘT lần chăm sóc một lead (làn kd, 2026-10-14): thời gian, kênh, nội dung ngắn, người ghi. Bảng con của
 * {@see IntakeRequest}; chỉ {@see LogIntakeFollowUp} ghi.
 *
 * Nội dung là dữ liệu của người CHƯA thành khách: không vào `activity_log` (không `LogsActivity`; dòng audit
 * của Action chỉ mang kênh), và ẩn danh ({@see AnonymiseProspect}, R7) đặt `note` về null — dòng ở lại.
 * Không `SoftDeletes`: không màn hình nào xoá một lần chăm sóc.
 *
 * **Cổng khách đóng kín mãi**, như bản ghi cha: `applyClientPortalConstraints()` chặn `1 = 0`.
 */
class IntakeFollowUp extends Model
{
    use HasBlameable;

    /** @use HasFactory<IntakeFollowUpFactory> */
    use HasFactory;

    use RestrictedToClientPortal;

    /** Độ dài cột `note` (`string(1000)`) — trần của form và của Action (MariaDB strict). */
    public const NOTE_MAX_LENGTH = 1000;

    protected $fillable = ['intake_request_id', 'channel', 'occurred_at', 'note'];

    protected function casts(): array
    {
        return [
            'channel' => FollowUpChannel::class,
            'occurred_at' => 'datetime',
        ];
    }

    /** Portal không bao giờ đọc bảng này. */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    public function intakeRequest(): BelongsTo
    {
        return $this->belongsTo(IntakeRequest::class);
    }

    /** Người ghi — kể cả nhân sự đã nghỉ việc (xoá mềm): lịch sử chăm sóc giữ tên người đã làm. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
