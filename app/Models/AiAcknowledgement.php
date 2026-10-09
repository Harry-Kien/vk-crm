<?php

namespace App\Models;

use App\Actions\Mcp\AcknowledgeAiPolicy;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\AiAcknowledgementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Một lời cam kết chính sách dùng AI của một nhân sự, cho đúng một phiên bản chính sách (M11 R12 mục
 * 1). Xem docblock của migration `2026_10_04_110100_create_ai_acknowledgements_table` cho ý nghĩa
 * từng cột. Ghi DUY NHẤT qua {@see AcknowledgeAiPolicy}; không đường nào sửa hay xoá một dòng.
 *
 * Không `$fillable`: mọi cột do Action đặt tường minh (`forceCreate`), không cột nào đến thẳng từ một
 * form.
 *
 * @property int $user_id
 * @property string $policy_version
 * @property Carbon $accepted_at
 * @property ?string $ip_address
 * @property ?string $user_agent
 */
class AiAcknowledgement extends Model
{
    /** @use HasFactory<AiAcknowledgementFactory> */
    use HasFactory;

    use RestrictedToClientPortal;

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    /** Cam kết của nhân sự về việc dùng AI; không màn hình cổng khách nào đọc bảng này. */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
