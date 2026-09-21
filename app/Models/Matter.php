<?php

namespace App\Models;

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role as StaffRole;
use App\Exceptions\MatterNotDestroyable;
use App\Exceptions\StageNotConfigured;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HidesInternalAttributesFromPortal;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Support\CodeSequence;
use Database\Factories\MatterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Matter extends Model
{
    use HasBlameable;

    /** @use HasFactory<MatterFactory> */
    use HasFactory;

    use HidesInternalAttributesFromPortal;
    use LogsActivity;
    use RestrictedToClientPortal;
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'matter_type_id', 'title', 'description_internal', 'summary_for_client',
        'stage', 'stage_entered_at', 'lead_lawyer_id', 'opened_at', 'closed_at',
        'is_published_to_portal', 'court_name', 'case_number', 'last_client_update_at', 'confidentiality',
    ];

    protected function casts(): array
    {
        return [
            'stage_entered_at' => 'datetime',
            'opened_at' => 'date',
            'closed_at' => 'date',
            'is_published_to_portal' => 'boolean',
            'last_client_update_at' => 'datetime',
            'confidentiality' => Confidentiality::class,
            // isListableBy() so sánh chặt (===) lead_lawyer_id với $user->getKey(); không ép
            // kiểu ở đây thì một model bẩn giữ giá trị string từ request (chưa qua DB) sẽ lệch
            // với so sánh lỏng của scopeListableBy — đúng cái bất đối xứng mà isListableBy()
            // sinh ra để triệt tiêu.
            'lead_lawyer_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Matter $matter): void {
            $type = $matter->matterType ?? MatterType::query()->findOrFail($matter->matter_type_id);

            // Kiểm tra giai đoạn trước khi sinh mã: nextCode() commit số thứ tự ngay trong
            // transaction riêng của nó (CodeSequence::next), nên nếu để sau, StageNotConfigured
            // vẫn ném ra nhưng số thứ tự đã bị tiêu mất dù vụ việc không được tạo.
            $matter->stage ??= $type->firstStage()?->key ?? throw StageNotConfigured::make($type);
            $matter->code ??= static::nextCode($type);
            $matter->stage_entered_at ??= now();
            $matter->opened_at ??= today();
            $matter->confidentiality ??= Confidentiality::Normal;
        });

        // Luật sư phụ trách luôn có mặt trong đội ngũ với vai lead.
        static::created(function (Matter $matter): void {
            $matter->team()->syncWithoutDetaching([
                $matter->lead_lawyer_id => ['role_in_matter' => MatterRole::Lead->value],
            ]);
        });

        static::forceDeleting(function (): void {
            throw MatterNotDestroyable::make();
        });
    }

    /**
     * {MATTER_CODE_PREFIX}-{YYYY}-{mã loại}-{0001}; số thứ tự theo năm và theo loại (SPEC §6.1).
     */
    public static function nextCode(MatterType $type): string
    {
        $prefix = config('vkcrm.matter_code_prefix', 'VK');
        $year = now()->format('Y');

        return CodeSequence::format("{$prefix}-{$year}-{$type->code}-", CodeSequence::next("matter:{$year}:{$type->code}"));
    }

    public function addTeamMember(User $user, MatterRole $role): void
    {
        $this->team()->attach($user->id, ['role_in_matter' => $role->value]);
    }

    /**
     * Định nghĩa duy nhất của "nhân sự này được thấy vụ việc nào" (SPEC §5).
     * Dùng cho danh sách ở panel admin và cho MatterPolicy::view, để hai nơi không lệch nhau.
     *
     * - Vụ thường: ai có matter.viewAny thấy tất cả; còn lại phải có tên trong matter_user.
     * - Vụ `restricted`: chỉ luật sư phụ trách và vai trò admin, kể cả trưởng phòng cũng không.
     * - Ai không có cả matter.viewAny lẫn matter.view (kế toán chỉ có viewAny) xử lý ở nhánh tương ứng.
     */
    public function scopeListableBy(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $outer) use ($user): void {
            $outer->where(function (Builder $normal) use ($user): void {
                $normal->where($this->qualifyColumn('confidentiality'), '!=', Confidentiality::Restricted->value);

                if ($user->can(Permission::MatterViewAny->value)) {
                    return;
                }

                $user->can(Permission::MatterView->value)
                    ? $normal->whereHas('team', fn (Builder $team) => $team->whereKey($user->getKey()))
                    : $normal->whereRaw('1 = 0');
            })->orWhere(function (Builder $restricted) use ($user): void {
                $restricted->where($this->qualifyColumn('confidentiality'), Confidentiality::Restricted->value);

                if ($user->hasRole(StaffRole::Admin->value)) {
                    return;
                }

                // Luật sư phụ trách vẫn phải có quyền matter.view: một người bị đổi chức danh
                // sang kế toán vẫn còn lead_lawyer_id trên các vụ cũ.
                $user->can(Permission::MatterView->value)
                    ? $restricted->where($this->qualifyColumn('lead_lawyer_id'), $user->getKey())
                    : $restricted->whereRaw('1 = 0');
            });
        });
    }

    /**
     * Bản kiểm tra trong bộ nhớ của scopeListableBy(), dùng quan hệ `team` đã nạp thay vì chạy
     * EXISTS. Cùng ba điều kiện, để MatterPolicy::view và danh sách Filament không lệch nhau.
     *
     * Chỉ tin quan hệ `team` khi nó được nạp KHÔNG ràng buộc (`with('team')`,
     * `load('team')`, `$matter->team`) — hàm này coi "đã nạp" nghĩa là "đủ mặt". Một nơi nạp có
     * điều kiện sau này, ví dụ `load(['team' => fn ($q) => $q->where('role_in_matter', 'lead')])`,
     * sẽ khiến `relationLoaded('team')` vẫn trả true nhưng tập hợp thiếu người — im lặng đổi kết
     * quả `MatterPolicy::view` sang từ chối, không có test nào bắt được. Nạp `team` có điều kiện
     * ở bất cứ đâu thì phải `unsetRelation('team')` trước khi gọi hàm này.
     */
    public function isListableBy(User $user): bool
    {
        if ($this->confidentiality === Confidentiality::Restricted) {
            return $user->hasRole(StaffRole::Admin->value)
                || ($user->can(Permission::MatterView->value) && $this->lead_lawyer_id === $user->getKey());
        }

        if ($user->can(Permission::MatterViewAny->value)) {
            return true;
        }

        return $user->can(Permission::MatterView->value)
            && ($this->relationLoaded('team')
                ? $this->team->contains('id', $user->getKey())
                : $this->team()->whereKey($user->getKey())->exists());
    }

    public function currentStage(): ?MatterTypeStage
    {
        return $this->matterType->stage($this->stage);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Khách chỉ thấy vụ việc của chính mình và chỉ khi đã bật công tắc công bố (SPEC §5).
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->where($this->qualifyColumn('client_id'), $clientUser->client_id)
            ->where($this->qualifyColumn('is_published_to_portal'), true)
            // Đã xoá mềm thì không bao giờ ra tới portal, KỂ CẢ khi ai đó gọi `withTrashed()`.
            // `SoftDeletingScope` đã loại chúng ở truy vấn thường, nhưng nó là một scope KHÁC và
            // `withTrashed()` gỡ đúng nó ra mà không đụng gì tới `ClientPortalScope`. Ở phía nội
            // bộ `withTrashed()` là một công cụ đúng đắn (quản trị viên còn phải khôi phục được
            // hồ sơ); ở phía khách nó là một cái nút mở lại thứ văn phòng vừa rút đi. Một điều
            // kiện chỉ do một scope khác giữ là một điều kiện người khác tắt được.
            ->whereNull($this->qualifyColumn('deleted_at'));

        // M7 bổ sung điều kiện client_access_until ở đây (SPEC §11 "Bàn giao và lưu trữ").
    }

    public function matterType(): BelongsTo
    {
        return $this->belongsTo(MatterType::class);
    }

    public function leadLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_lawyer_id');
    }

    public function team(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'matter_user')
            ->using(MatterUser::class)
            ->withPivot('role_in_matter')
            ->withTimestamps();
    }

    public function stageLogs(): HasMany
    {
        return $this->hasMany(StageLog::class)->orderByDesc('occurred_at');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(MatterChecklistItem::class)->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function deadlines(): HasMany
    {
        return $this->hasMany(Deadline::class)->orderBy('due_date');
    }

    public function clientRequests(): HasMany
    {
        return $this->hasMany(ClientRequest::class);
    }

    public function parties(): HasMany
    {
        return $this->hasMany(MatterParty::class);
    }

    public function communicationLogs(): HasMany
    {
        return $this->hasMany(CommunicationLog::class)->orderByDesc('occurred_at');
    }

    public function archive(): HasOne
    {
        return $this->hasOne(MatterArchive::class);
    }

    /** SPEC §4.6: description_internal không bao giờ ra portal. */
    protected function internalAttributes(): array
    {
        return ['description_internal'];
    }

    /** SPEC §10.6: ghi nhật ký nghiệp vụ, trừ nội dung nội bộ dài (description_internal). */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'client_id', 'matter_type_id', 'title', 'summary_for_client', 'stage',
                'lead_lawyer_id', 'opened_at', 'closed_at', 'is_published_to_portal',
                'court_name', 'case_number', 'confidentiality',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
