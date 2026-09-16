<?php

namespace App\Models;

use App\Actions\SyncClientPartyIdentities;
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

        // Sự kiện model, không phải một lời gọi trong Filament EditClient: bất biến ở đây là
        // "ảnh chụp định danh trong matter_parties luôn khớp hồ sơ khách hàng", và nó phải đúng
        // với MỌI người ghi vào cột — form quản trị, seeder, lệnh console, job nhập liệu, một
        // resource Filament viết sau này, hay `Client::query()->update()` gọi từ đâu đó. Gắn vào
        // một đường ghi duy nhất là để lại đúng cái lỗ hổng đang phải vá: `RunConflictCheck` chỉ
        // đọc được định danh qua ảnh chụp đó (clients.id_number đã mã hoá), nên một đường ghi bị
        // bỏ quên = tầng "chắc chắn" của SPEC §6.10 mù với khách hàng đó, im lặng và vĩnh viễn.
        // Nghiệp vụ vẫn nằm trong app/Actions/ đúng quy ước: hook này không làm gì ngoài việc gọi
        // Action. Dùng `updated` (không phải `saving`) để chỉ đồng bộ sau khi dòng đã ghi thật.
        static::updated(function (Client $client): void {
            // `id_number` là cột `encrypted` — mã hoá không tất định, hai lần mã hoá cùng một giá
            // trị cho ra hai ciphertext khác nhau. Laravel giải mã cả hai phía trước khi so sánh
            // (cast `encrypted` nằm trong danh sách primitive của `originalIsEquivalent`), nên
            // `wasChanged()` ở đây so theo giá trị THẬT, không so ciphertext; ghi lại đúng số cũ
            // không kích hoạt đồng bộ. Hành vi này được ghim bằng test, không phải phỏng đoán.
            if ($client->wasChanged(['name', 'id_number', 'phone'])) {
                app(SyncClientPartyIdentities::class)->handle($client);
            }
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
