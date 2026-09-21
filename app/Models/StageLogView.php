<?php

namespace App\Models;

use App\Models\Concerns\RestrictedToClientPortal;
use App\Policies\StageLogViewPolicy;
use Database\Factories\StageLogViewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageLogView extends Model
{
    /** @use HasFactory<StageLogViewFactory> */
    use HasFactory;

    use RestrictedToClientPortal;

    protected $fillable = ['stage_log_id', 'client_user_id', 'viewed_at', 'ip'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }

    /**
     * `whereHas('stageLog')` kế thừa nguyên điều kiện của `StageLog` — đã công bố, thuộc vụ việc
     * khách thấy được — nên không lặp lại `client_id` ở đây.
     *
     * KHÔNG lọc theo `client_user_id`, và đó là một câu đã chốt: phạm vi của portal là **theo
     * `Client`** (phán quyết 19/09/2026, xem {@see ClientRequest::applyClientPortalConstraints()}),
     * nên hai tài khoản portal của cùng một khách hàng đọc được biên bản của nhau. Lý lẽ đầy đủ
     * ở docblock {@see StageLogViewPolicy}; nói ngắn: nhãn "Khách đã xem" ở panel
     * nội bộ (SPEC §7.2) cũng nói về khách hàng chứ không về một tài khoản.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereHas('stageLog');
    }

    public function stageLog(): BelongsTo
    {
        return $this->belongsTo(StageLog::class);
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }
}
