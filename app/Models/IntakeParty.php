<?php

namespace App\Models;

use App\Enums\PartyRole;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Support\Normalizer;
use Database\Factories\IntakePartyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một bên đối lập người gọi khai lúc tiếp nhận (M10, R1). Bảng con của {@see IntakeRequest}, cùng
 * khuôn cột với `MatterParty` (SPEC §4.16) nhưng CHỈ giữ thứ kiểm tra xung đột cần: tên, SĐT chuẩn
 * hoá, dấu băm CCCD. Không SĐT thô, không CCCD thô — bên thứ ba không thể đồng ý (R7).
 *
 * Không `SoftDeletes`, không `HasBlameable`, không `LogsActivity`: nhật ký của bản ghi cha đã đủ để
 * biết ai sửa gì, và tên/định danh của BÊN THỨ BA không có lý do gì nằm trong `activity_log`. Ẩn
 * danh (R7b) là cập nhật (`name`, `name_normalized`, `phone_normalized`, `id_number_hash` về null),
 * không phải xoá dòng.
 */
class IntakeParty extends Model
{
    /** @use HasFactory<IntakePartyFactory> */
    use HasFactory;

    use RestrictedToClientPortal;

    protected $fillable = ['intake_request_id', 'role', 'name'];

    protected function casts(): array
    {
        return [
            'role' => PartyRole::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (IntakeParty $party): void {
            $party->name_normalized = Normalizer::name($party->name);
        });
    }

    /** {@see IntakeRequest::fill()} — cùng lý do, cùng khuôn `MatterParty::fill()`. */
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
     * Bên này dưới dạng một `MatterParty` CHƯA LƯU, `is_our_client = false`, mang đúng dấu băm và SĐT
     * chuẩn hoá đã lưu — đầu vào của `RunConflictCheck`. MỘT cách dựng cho cả hai nơi: các bên của
     * chính lần tiếp nhận (`CheckIntakeConflict`) và các bên mang sang từ lần gọi trước của cùng người
     * (`RunConflictCheck`, người gọi lại) — hai cách dựng là hai định nghĩa "cùng một bên" sẽ lệch nhau.
     * `MatterParty::fill()` chặn hai cột định danh, nên gán thẳng.
     */
    public function toConflictParty(): MatterParty
    {
        $party = new MatterParty(['role' => $this->role, 'name' => $this->name, 'is_our_client' => false]);
        $party->id_number_hash = $this->id_number_hash;
        $party->phone_normalized = $this->phone_normalized;

        return $party;
    }

    /** Portal không bao giờ đọc bảng này. */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    public function intakeRequest(): BelongsTo
    {
        return $this->belongsTo(IntakeRequest::class);
    }
}
