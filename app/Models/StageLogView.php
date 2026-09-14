<?php

namespace App\Models;

use Database\Factories\StageLogViewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageLogView extends Model
{
    /** @use HasFactory<StageLogViewFactory> */
    use HasFactory;

    protected $fillable = ['stage_log_id', 'client_user_id', 'viewed_at', 'ip'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
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
