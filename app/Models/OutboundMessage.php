<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use Database\Factories\OutboundMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OutboundMessage extends Model
{
    /** @use HasFactory<OutboundMessageFactory> */
    use HasFactory;

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

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
