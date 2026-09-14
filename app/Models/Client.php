<?php

namespace App\Models;

use App\Enums\ClientType;
use App\Models\Concerns\HasBlameable;
use App\Support\CodeSequence;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasBlameable;

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

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
}
