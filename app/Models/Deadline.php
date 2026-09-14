<?php

namespace App\Models;

use App\Enums\DeadlineSeverity;
use App\Models\Concerns\HasBlameable;
use Database\Factories\DeadlineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deadline extends Model
{
    use HasBlameable;

    /** @use HasFactory<DeadlineFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'name', 'due_date', 'severity', 'responsible_user_id',
        'is_completed', 'completed_at', 'is_published', 'reminders_sent',
    ];

    protected $attributes = ['reminders_sent' => '[]', 'severity' => 'normal'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'severity' => DeadlineSeverity::class,
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
            'is_published' => 'boolean',
            'reminders_sent' => 'array',
        ];
    }

    /** Hạn chưa xong, đến hạn trong N ngày tới (kể cả đã quá hạn). */
    public function scopeUpcoming(Builder $query, int $days): Builder
    {
        return $query
            ->where('is_completed', false)
            ->whereDate('due_date', '<=', today()->addDays($days));
    }

    /** Ghi mốc nhắc đã gửi (d7, d3, d1, overdue), không ghi trùng. */
    public function markReminderSent(string $key): void
    {
        $sent = $this->reminders_sent ?? [];

        if (! in_array($key, $sent, true)) {
            $sent[] = $key;
            $this->update(['reminders_sent' => $sent]);
        }
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
