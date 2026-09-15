<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\OutboundMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OutboundMessage extends Model
{
    /** @use HasFactory<OutboundMessageFactory> */
    use HasFactory;

    use RestrictedToClientPortal;

    protected $fillable = [
        'channel', 'recipient', 'template', 'payload', 'related_type', 'related_id', 'status', 'sent_at', 'error',
    ];

    protected $attributes = ['status' => 'queued', 'channel' => 'email'];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'status' => MessageStatus::class,
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Nhật ký thông báo gửi đi phục vụ tra cứu nội bộ (SPEC §4.15). Chặn sạch ở tầng truy vấn
     * thay vì trông vào việc không ai viết resource cho nó.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
