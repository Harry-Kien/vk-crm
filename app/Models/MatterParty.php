<?php

namespace App\Models;

use App\Enums\PartyRole;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Support\Normalizer;
use Database\Factories\MatterPartyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MatterParty extends Model
{
    use HasBlameable;

    /** @use HasFactory<MatterPartyFactory> */
    use HasFactory;

    use LogsActivity;
    use RestrictedToClientPortal;
    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'role', 'is_our_client', 'client_id', 'name',
        'address', 'note',
    ];

    protected function casts(): array
    {
        return [
            'role' => PartyRole::class,
            'is_our_client' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MatterParty $party): void {
            $party->name_normalized = Normalizer::name($party->name);
        });
    }

    /**
     * identify() là đường ghi duy nhất cho id_number_hash và phone_normalized:
     * chặn cả khi factory gọi qua Model::unguarded() (vd. ->make(['id_number_hash' => ...])).
     */
    public function fill(array $attributes): static
    {
        unset($attributes['id_number_hash'], $attributes['phone_normalized']);

        return parent::fill($attributes);
    }

    /** Điền định danh đã chuẩn hoá từ dữ liệu gốc; dữ liệu gốc không được lưu. */
    public function identify(?string $idNumber, ?string $phone): static
    {
        $this->id_number_hash = Normalizer::idNumberHash($idNumber);
        $this->phone_normalized = Normalizer::phone($phone);

        return $this;
    }

    /**
     * Sửa một bên (M6.5 Task 9, `conflict-05`): giữ NGUYÊN `id_number_hash`/`phone_normalized` cũ
     * ở ô nào bị bỏ trống, thay vì xoá mất như `identify()`.
     *
     * **Vì sao `identify()` không dùng được nguyên vẹn cho màn hình sửa.** Số căn cước/điện thoại
     * GỐC không bao giờ được lưu (SPEC §10.5) — chỉ hash/số đã chuẩn hoá còn lại. Form "sửa một
     * bên" vì vậy không có gì để điền sẵn vào hai ô đó: chúng LUÔN bắt đầu trống, kể cả khi bên này
     * đã có định danh từ trước. Nếu form đi qua `identify()` như lúc TẠO, một lượt sửa chỉ đổi
     * `address` (không đụng gì tới định danh) sẽ vô tình gửi `id_number = null, phone = null` và
     * xoá SẠCH định danh đã có — đúng hạng lỗi mà `conflict-05` mô tả cho chiều "gõ sai không sửa
     * được", nay đảo ngược thành "sửa cái khác cũng làm mất luôn cái đúng".
     *
     * Chỉ áp cho bên KHÔNG `is_our_client`: bên `is_our_client` không bao giờ gọi hàm này —
     * `BuildsMatterParties` luôn lấy định danh của bên đó từ hồ sơ `Client` (qua `identify()`
     * thẳng), không đọc `id_number`/`phone` của form.
     */
    public function identifyKeepingWhenBlank(?string $idNumber, ?string $phone): static
    {
        if (filled($idNumber)) {
            $this->id_number_hash = Normalizer::idNumberHash($idNumber);
        }

        if (filled($phone)) {
            $this->phone_normalized = Normalizer::phone($phone);
        }

        return $this;
    }

    /** Tìm bản ghi trùng hash căn cước hoặc trùng số điện thoại đã chuẩn hoá (SPEC §6.10 bước 2). */
    public function scopeMatchingIdentity(Builder $query, ?string $idNumberHash, ?string $phoneNormalized): Builder
    {
        if ($idNumberHash === null && $phoneNormalized === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($idNumberHash, $phoneNormalized): void {
            if ($idNumberHash !== null) {
                $q->orWhere('id_number_hash', $idNumberHash);
            }
            if ($phoneNormalized !== null) {
                $q->orWhere('phone_normalized', $phoneNormalized);
            }
        });
    }

    /**
     * Portal không bao giờ đọc bảng này (các bên trong vụ việc chỉ phục vụ kiểm tra xung đột
     * lợi ích ở SPEC §4.16). Chặn sạch ở tầng truy vấn thay vì trông vào việc không ai viết
     * resource cho nó.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Không log id_number_hash/phone_normalized (không fillable, chỉ ghi qua identify()) và
     * không log note (ghi chú nội bộ dài, cùng nguyên tắc với Matter::description_internal).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['matter_id', 'role', 'is_our_client', 'client_id', 'name', 'address'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
