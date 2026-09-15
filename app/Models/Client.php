<?php

namespace App\Models;

use App\Enums\ClientType;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HidesInternalAttributesFromPortal;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Support\CodeSequence;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Client extends Model
{
    use HasBlameable;

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use HidesInternalAttributesFromPortal;
    use LogsActivity;
    use RestrictedToClientPortal;
    use SoftDeletes;

    protected $fillable = [
        'type',
        'name',
        'id_number',
        'phone',
        'email',
        'address',
        'representative_name',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'type' => ClientType::class,
            'id_number' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Client $client): void {
            $client->code ??= static::nextCode();
        });
    }

    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereKey($clientUser->client_id);
    }

    public function clientUsers(): HasMany
    {
        return $this->hasMany(ClientUser::class);
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }

    /**
     * KH-{YYYY}-{0001}; số thứ tự chạy lại từ đầu mỗi năm.
     */
    public static function nextCode(): string
    {
        $year = now()->format('Y');

        return CodeSequence::format("KH-{$year}-", CodeSequence::next("client:{$year}"));
    }

    /** SPEC §4.2: note là ghi chú nội bộ, không bao giờ ra portal. */
    protected function internalAttributes(): array
    {
        return ['note'];
    }

    /** SPEC §10.5: không bao giờ log id_number. note (ghi chú nội bộ) cũng loại khỏi nhật ký. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'name', 'phone', 'email', 'address', 'representative_name'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
