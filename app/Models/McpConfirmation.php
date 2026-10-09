<?php

namespace App\Models;

use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\McpConfirmationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Một mã xác nhận hai bước ĐÃ DÙNG của tool ghi nội bộ (`create_deadline`, `log_communication`;
 * M11 R6, Task 13). Mã ký HMAC không trạng thái; dòng này giữ `jti` của mã và bản ghi nó đã tạo
 * (`result`, qua alias của morph map), ghi trong CÙNG transaction với bản ghi đó. `jti` unique: gọi
 * lại cùng mã trả đúng bản ghi đã tạo, không tạo bản thứ hai.
 *
 * Chỉ `created_at` — một dòng không bao giờ đổi sau khi ghi.
 */
class McpConfirmation extends Model
{
    /** @use HasFactory<McpConfirmationFactory> */
    use HasFactory;

    use RestrictedToClientPortal;

    public const UPDATED_AT = null;

    protected $fillable = ['jti', 'user_id', 'tool', 'result_type', 'result_id'];

    /** Dấu vết nội bộ của đường ghi MCP; không màn hình cổng khách nào đọc bảng này. */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    /** Người sở hữu token OAuth đã gọi tool — mã chỉ dùng được dưới đúng người này. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function result(): MorphTo
    {
        return $this->morphTo();
    }
}
