<?php

namespace App\Models;

use App\Enums\ClientType;
use App\Models\Concerns\HasBlameable;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Client extends Model
{
    use HasBlameable;

    /** @use HasFactory<ClientFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code',
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

    /**
     * KH-{YYYY}-{0001}; số thứ tự chạy lại từ đầu mỗi năm. Khoá dòng mới nhất của năm để tránh trùng.
     */
    public static function nextCode(): string
    {
        $prefix = 'KH-'.now()->format('Y').'-';

        return DB::transaction(function () use ($prefix): string {
            $last = static::withTrashed()
                ->where('code', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('code')
                ->value('code');

            $next = $last ? ((int) substr($last, -4)) + 1 : 1;

            return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        });
    }
}
