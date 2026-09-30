<?php

namespace App\Models;

use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Policies\IntakeRequestPolicy;
use App\Support\CodeSequence;
use App\Support\Normalizer;
use Database\Factories\IntakeRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * MỘT lần có người liên hệ văn phòng (M10, kế hoạch 2026-09-22). Người liên hệ CHƯA phải khách hàng
 * (R2): không có hàng `clients` nào cho tới khi `ConvertIntakeToMatter` (Task 4) tra khách bằng
 * `FindClientByIdentifier` rồi mở vụ bằng `OpenMatter`.
 *
 * Bản ghi có hai phần: PHẦN DANH TÍNH (`contact_*`, `contact_role`, các `IntakeParty`) và PHẦN CÂU
 * CHUYỆN (`summary`). Ai được ghi phần câu chuyện, và khi nào, là luật của Action (R1, R7a) chứ
 * không của model — model này không tự khoá cột nào.
 *
 * **Không bao giờ xoá dòng.** Xoá là ẩn danh (R7): các cột cá nhân về null, dòng ở lại. `SoftDeletes`
 * có mặt như mọi bảng khác, nhưng policy không cho ai xoá ({@see IntakeRequestPolicy}).
 *
 * **Cổng khách đóng kín MÃI** — người liên hệ chưa có tài khoản và không được thấy gì (M10,
 * "Architecture"): `applyClientPortalConstraints()` chặn `1 = 0`, cùng thiết bị với `MatterParty`.
 *
 * **Nhật ký tự động chỉ mang cột KHÔNG cá nhân và KHÔNG nhạy cảm** ({@see self::getActivitylogOptions()}):
 * R7 đòi ẩn danh phủ cả `activity_log`, R8 giới hạn lý do xung đột, và `ActivityOwningMatter` cho mọi
 * người có `auditLog.view` đọc các dòng chủ thể `intake_request`.
 */
class IntakeRequest extends Model
{
    use HasBlameable;

    /** @use HasFactory<IntakeRequestFactory> */
    use HasFactory;

    use LogsActivity;
    use RestrictedToClientPortal;
    use SoftDeletes;

    /**
     * KHÔNG có `code` (sinh khi tạo), `contact_phone_normalized` và `contact_id_number_hash` (chỉ
     * `identify()` ghi được, xem {@see self::fill()}), `created_by`/`updated_by` (`HasBlameable`).
     */
    protected $fillable = [
        'contact_name', 'contact_phone', 'contact_email', 'contact_role',
        'source', 'referred_by', 'matter_type_id', 'summary', 'quoted_amount',
        'conflict_level', 'conflict_checked_at', 'conflict_result',
        'conflict_acknowledged_by', 'conflict_acknowledged_at',
        'conflict_overridden_by', 'conflict_override_reason',
        'privacy_notice_version', 'privacy_notice_acknowledged_at', 'privacy_notice_recorded_by',
        'status', 'assigned_to', 'received_at', 'first_response_at',
        'decline_reason', 'decline_reason_is_conflict',
        'client_id', 'matter_id', 'merged_into_id',
        'retention_until', 'anonymised_at', 'anonymised_by', 'anonymised_reason',
    ];

    protected function casts(): array
    {
        return [
            'contact_role' => PartyRole::class,
            'source' => IntakeSource::class,
            'status' => IntakeStatus::class,
            'conflict_level' => ConflictLevel::class,
            'conflict_result' => 'array',
            'conflict_checked_at' => 'datetime',
            'conflict_acknowledged_at' => 'datetime',
            'privacy_notice_acknowledged_at' => 'datetime',
            'received_at' => 'datetime',
            'first_response_at' => 'datetime',
            'anonymised_at' => 'datetime',
            'retention_until' => 'date',
            'quoted_amount' => 'integer',
            'decline_reason_is_conflict' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (IntakeRequest $intake): void {
            $intake->code ??= static::nextCode();
        });

        // Cùng `Normalizer::name()` với `MatterParty` — hai định nghĩa "cùng tên" sẽ lệch nhau, và
        // cái lệch đó im lặng (M10, "Những chỗ đã biết trước là sẽ cắn"). Null (đã ẩn danh) → null.
        static::saving(function (IntakeRequest $intake): void {
            $intake->contact_name_normalized = Normalizer::name($intake->contact_name);
        });
    }

    /**
     * `identify()` là đường ghi duy nhất cho `contact_id_number_hash` và `contact_phone_normalized`
     * (cùng khuôn `MatterParty::fill()`): chặn cả khi factory gọi qua `Model::unguarded()`.
     */
    public function fill(array $attributes): static
    {
        unset($attributes['contact_id_number_hash'], $attributes['contact_phone_normalized']);

        return parent::fill($attributes);
    }

    /**
     * Điền định danh đã chuẩn hoá từ dữ liệu gốc. Số CCCD thô KHÔNG được lưu ở đâu cả (R7): chỉ dấu
     * băm. `$phone` là số người gọi gõ; `contact_phone` (dạng gõ) do form đặt riêng.
     */
    public function identify(?string $idNumber, ?string $phone): static
    {
        $this->contact_id_number_hash = Normalizer::idNumberHash($idNumber);
        $this->contact_phone_normalized = Normalizer::phone($phone);

        return $this;
    }

    /** TN-{YYYY}-{0001}; số thứ tự chạy lại từ đầu mỗi năm (SPEC §6.1). */
    public static function nextCode(): string
    {
        $year = now()->format('Y');

        return CodeSequence::format("TN-{$year}-", CodeSequence::next("intake:{$year}"));
    }

    /**
     * MỘT định nghĩa "bản ghi này thấy được với người này" cho policy, resource, widget và báo cáo
     * (R9): quyền `intake.viewAny` thấy mọi bản ghi; người chỉ có `intake.create` thấy bản ghi MÌNH
     * ghi hoặc được giao cho mình; người không có cả hai (kế toán) không thấy gì. Đọc QUYỀN, không
     * đọc vai. {@see self::isVisibleTo()} là bản trong bộ nhớ của đúng luật này.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can(Permission::IntakeViewAny->value)) {
            return $query;
        }

        if (! $user->can(Permission::IntakeCreate->value)) {
            return $query->whereRaw('1 = 0');
        }

        $model = $query->getModel();

        return $query->where(fn (Builder $q) => $q
            ->where($model->qualifyColumn('created_by'), $user->getKey())
            ->orWhere($model->qualifyColumn('assigned_to'), $user->getKey()));
    }

    /** Bản trong bộ nhớ của {@see self::scopeVisibleTo()} — test khẳng định hai bản trả lời giống nhau. */
    public function isVisibleTo(User $user): bool
    {
        if ($user->can(Permission::IntakeViewAny->value)) {
            return true;
        }

        return $user->can(Permission::IntakeCreate->value)
            && ($this->created_by === $user->getKey() || $this->assigned_to === $user->getKey());
    }

    /** Portal không bao giờ đọc bảng này: người liên hệ chưa là khách hàng, chưa có tài khoản. */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(IntakeParty::class);
    }

    public function matterType(): BelongsTo
    {
        return $this->belongsTo(MatterType::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Bản ghi mà bản này đã được gộp vào (R4), khi `status = merged`. */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** Các bản ghi trùng đã được gộp vào bản này. */
    public function mergedFrom(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    /**
     * CHỈ cột không cá nhân, không nhạy cảm: nguồn, trạng thái, người được giao, lĩnh vực, và các
     * liên kết sau chuyển đổi/gộp. Tuyệt đối không tên, SĐT, email, người giới thiệu, `summary`,
     * `decline_reason`, `conflict_override_reason`, `decline_reason_is_conflict` (R7, R8) — và
     * `conflict_level`/`conflict_result` (đã có dòng `conflict_check_run` riêng). Bẫy đã biết: dòng
     * `conflict_check_run` của tiếp nhận mang `matches` (mã hồ sơ, tên bên của vụ khác, kể cả vụ
     * `restricted`) — đúng lỗ mang sang M8 Task 6; M10 không làm rộng thêm.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['source', 'status', 'assigned_to', 'matter_type_id', 'client_id', 'matter_id', 'merged_into_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
