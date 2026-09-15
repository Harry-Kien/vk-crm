<?php

namespace App\Models;

use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\ClientRequestReplyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ClientRequestReply extends Model
{
    /** @use HasFactory<ClientRequestReplyFactory> */
    use HasFactory;

    use RestrictedToClientPortal;

    protected $fillable = ['request_id', 'author_type', 'author_id', 'content'];

    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereHas('request');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ClientRequest::class, 'request_id');
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }
}
