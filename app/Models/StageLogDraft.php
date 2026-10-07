<?php

namespace App\Models;

use App\Models\Concerns\IsMcpDraft;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\StageLogDraftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nháp một dòng cập nhật tiến độ KHÔNG đổi giai đoạn, do tool MCP `draft_progress_update` soạn
 * (M11 R5; Task 13 ghi, Task 12 dùng hoặc bỏ). Không phải {@see StageLog}: về cấu trúc nó không thể
 * tới cổng khách, không công bố được, không sinh thư. Một người trong `/admin` mở nháp, sửa, rồi bấm
 * "Thêm cập nhật" — dòng tiến độ thật sinh ra qua `TransitionMatterStage` dưới tên NGƯỜI BẤM, và
 * `used_stage_log_id` trỏ tới nó.
 *
 * `internal_note` ở đây là thứ AI được GHI (R4: AI không bao giờ đọc ghi chú nội bộ, nhưng vẫn soạn
 * được vào nháp). Luật không xoá và luật bỏ nháp: {@see IsMcpDraft}.
 */
class StageLogDraft extends Model
{
    /** @use HasFactory<StageLogDraftFactory> */
    use HasFactory;

    use IsMcpDraft;
    use RestrictedToClientPortal;

    /**
     * `created_by` và bốn cột dùng/bỏ (`used_stage_log_id`, `discarded_at`, `discarded_by`,
     * `discard_reason`) không nằm ở đây: Action ghi chúng tường minh (`forceFill`), không mảng thuộc
     * tính nào từ một form hay một tham số tool tự đặt được người soạn hay trạng thái.
     */
    protected $fillable = [
        'matter_id', 'public_content', 'next_step', 'client_action', 'expected_next_update_at',
        'internal_note', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'expected_next_update_at' => 'date',
            'discarded_at' => 'datetime',
        ];
    }

    public static function usedColumn(): string
    {
        return 'used_stage_log_id';
    }

    public static function draftType(): string
    {
        return 'stage_log_draft';
    }

    /**
     * Nháp là thứ văn phòng CHƯA quyết định nói ra, và có thể mang `internal_note`. Không màn hình
     * cổng khách nào đọc bảng này; chặn sạch ở tầng truy vấn thay vì trông vào điều đó.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function usedStageLog(): BelongsTo
    {
        return $this->belongsTo(StageLog::class, 'used_stage_log_id');
    }
}
