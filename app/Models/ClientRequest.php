<?php

namespace App\Models;

use App\Enums\ClientRequestStatus;
use Database\Factories\ClientRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientRequest extends Model
{
    /** @use HasFactory<ClientRequestFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['matter_id', 'client_user_id', 'subject', 'content', 'status', 'assigned_to', 'answered_at'];

    protected $attributes = ['status' => 'new'];

    protected function casts(): array
    {
        return [
            'status' => ClientRequestStatus::class,
            'answered_at' => 'datetime',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ClientRequestReply::class, 'request_id')->orderBy('created_at');
    }
}
