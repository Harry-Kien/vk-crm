<?php

namespace App\Models;

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Models\Concerns\HasBlameable;
use App\Support\CodeSequence;
use Database\Factories\MatterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Matter extends Model
{
    use HasBlameable;

    /** @use HasFactory<MatterFactory> */
    use HasFactory;
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
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Matter $matter): void {
            $type = $matter->matterType ?? MatterType::query()->findOrFail($matter->matter_type_id);

            $matter->code ??= static::nextCode($type);
            $matter->stage ??= $type->firstStage()?->key;
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

    public function currentStage(): ?MatterTypeStage
    {
        return $this->matterType->stage($this->stage);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
}
