<?php

namespace App\Models;

use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\MatterArchiveFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MatterArchive extends Model
{
    /** @use HasFactory<MatterArchiveFactory> */
    use HasFactory;

    use RestrictedToClientPortal;
    use SoftDeletes;

    /**
     * M7 Task 3 (R1, đính chính SPEC §4.19): `handover_package_path` KHÔNG còn trong danh sách
     * này — gói bàn giao là một bản ghi `Document` (xem {@see self::handoverDocument()}), không
     * phải một chuỗi đường dẫn. Cột vẫn còn trên bảng (migration Task 3 không xoá nó) vì xoá một
     * cột đã NULL ở mọi dòng hiện có là một thao tác phá huỷ không cần thiết; bỏ nó khỏi đây là
     * đủ để không còn đường ghi nào chạm tới nó nữa.
     */
    protected $fillable = [
        'matter_id', 'archived_at', 'archived_by', 'handover_document_id', 'handover_generated_at',
        'client_access_until', 'retention_until', 'destroyed_at',
        'destruction_reason', 'destruction_record_no', 'destroyed_by',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'handover_generated_at' => 'datetime',
            'client_access_until' => 'date',
            'retention_until' => 'date',
            'destroyed_at' => 'datetime',
        ];
    }

    /**
     * Hồ sơ lưu trữ và đường dẫn gói bàn giao là dữ liệu nội bộ (SPEC §4.19). Chặn sạch ở tầng
     * truy vấn thay vì trông vào việc không ai viết resource cho nó.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /** M7 Task 3 (R1): gói bàn giao là một `Document` nhóm B, không phải một đường dẫn. */
    public function handoverDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'handover_document_id');
    }

    /** M7 Task 3 (chuẩn bị cho Task 6): người ra quyết định tiêu huỷ hồ sơ. */
    public function destroyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destroyed_by');
    }
}
