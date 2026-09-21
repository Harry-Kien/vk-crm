<?php

namespace App\Models;

use App\Enums\CommunicationType;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\CommunicationLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommunicationLog extends Model
{
    use HasBlameable;

    /** @use HasFactory<CommunicationLogFactory> */
    use HasFactory;

    use RestrictedToClientPortal;
    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'type', 'occurred_at', 'duration_minutes', 'counterpart', 'summary', 'is_visible_to_client',
    ];

    protected function casts(): array
    {
        return [
            'type' => CommunicationType::class,
            'occurred_at' => 'datetime',
            'duration_minutes' => 'integer',
            'is_visible_to_client' => 'boolean',
        ];
    }

    /**
     * Nhật ký liên lạc mặc định là nội bộ; chỉ dòng được đánh dấu mới ra portal (SPEC §4.17).
     * SPEC §5 không liệt kê nhật ký liên lạc trong danh sách portal; cột is_visible_to_client ở
     * §4.17 tồn tại cho mục đích này nên mặc định đóng, chỉ mở từng dòng.
     *
     * `whereNull('deleted_at')`: một dòng đã bị xoá mềm không quay lại bằng một lần
     * `withTrashed()` — thứ chỉ gỡ `SoftDeletingScope` chứ không đụng tới `ClientPortalScope`.
     * Cùng lý lẽ với {@see Matter::applyClientPortalConstraints()}. Bảng này chưa có màn hình
     * portal nào (phán quyết 3 của M5), nhưng scope của nó vẫn trả lời, nên nó vẫn phải trả lời
     * đúng: một bảng không có màn hình hôm nay là một bảng có màn hình vào ngày ai đó viết nó.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->where($this->qualifyColumn('is_visible_to_client'), true)
            ->whereNull($this->qualifyColumn('deleted_at'))
            ->whereHas('matter');
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
