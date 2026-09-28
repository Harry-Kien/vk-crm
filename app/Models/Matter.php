<?php

namespace App\Models;

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role as StaffRole;
use App\Exceptions\MatterHasOutstandingBalance;
use App\Exceptions\MatterNotDestroyable;
use App\Exceptions\StageNotConfigured;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HidesInternalAttributesFromPortal;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Support\Billing\BillingSummary;
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

        // M9 Task 5, "Tiền trên một vụ việc… đã xoá mềm → CHẶN": xoá mềm một vụ việc còn dư nợ
        // làm khoản nợ đó BỐC HƠI khỏi mọi báo cáo doanh thu (chúng bỏ qua vụ đã xoá mềm), nên
        // đây là chốt chặn khác với "đã ĐÓNG" (closed_at) — một vụ đã đóng còn nợ KHÔNG bị chặn ở
        // đây (widget "Hồ sơ đã kết thúc còn công nợ" là câu trả lời cho trường hợp đó, không
        // phải hook này), chỉ xoá MỀM mới bị. Chỉ hỏi hợp đồng active
        // (BillingSummary::outstandingForMatter(), constraint (a) mang từ Task 4).
        //
        // Ném CÙNG một lớp với App\Actions\Matter\CancelMatter (M6.5 Task 5, chưa merge lúc task
        // này viết — xem báo cáo Task 5): khi nó merge, nó phải gọi lại đúng
        // BillingSummary::outstandingForMatter(), không viết một phép tính dư nợ thứ hai.
        //
        // Sau forceDeleting ở trên: xoá CỨNG luôn bị chặn vô điều kiện trước khi chạm tới đây
        // (SoftDeletes::forceDelete() bắn forceDeleting rồi mới gọi delete() nội bộ), nên hook
        // deleting này trong thực tế chỉ chạy trên đường xoá MỀM.
        static::deleting(function (Matter $matter): void {
            $balance = BillingSummary::outstandingForMatter($matter->getKey());

            if ($balance['amount'] > 0) {
                throw MatterHasOutstandingBalance::make($matter, $balance['amount'], $balance['count']);
            }
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
     * Định nghĩa DUY NHẤT của "vụ việc đang mở" trong toàn hệ thống (SPEC §6.4 "vụ việc chưa
     * đóng", §6.9 "matter đang mở"; M6.5 Task 5, R8).
     *
     * Hai điều kiện, cả hai cùng cần dù `SoftDeletingScope` thường đã lo vế thứ hai:
     *
     *  - `closed_at` null — `TransitionMatterStage` ghi cột này khi vụ việc VÀO một giai đoạn
     *    `is_terminal` và xoá nó khi RỜI giai đoạn đó (đường bỏ qua của admin). Trước Task 5
     *    không có nơi nào ghi cột này (`stage/stage-03`, `spec-gap/spec-gap-03`) nên scope này
     *    không có tác dụng gì cho tới khi Action đó tồn tại.
     *  - `deleted_at` null — nói ra TƯỜNG MINH thay vì chỉ tin `SoftDeletingScope` mặc định: một
     *    lời gọi `withTrashed()`/`withoutGlobalScope(SoftDeletingScope::class)` ở TRÊN scope này
     *    (ví dụ `MatterPolicy::view()` cho nhánh nhân sự, đọc §7.2) không được để "đang mở" âm
     *    thầm bao gồm cả những vụ đã huỷ.
     *
     * **Chỗ DUY NHẤT trong `app/` được viết `whereNull('closed_at')`/`whereNotNull('closed_at')`
     * làm điều kiện lọc.** Mọi nơi khác gọi `->open()` — xem các widget trang chủ,
     * `ClientPolicy::delete()`, `App\Support\OpenWork`, `App\Support\MatterStaleness`. Hai chỗ
     * còn lại được PHÉP nhắc tên cột này: chính `TransitionMatterStage` (nơi ghi), và các cast/
     * nhãn hiển thị đơn thuần (`MatterInfolist`, `getActivitylogOptions()`).
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereNull($this->qualifyColumn('closed_at'))
            ->whereNull($this->qualifyColumn('deleted_at'));
    }

    /**
     * Bản kiểm tra TRONG BỘ NHỚ của {@see self::scopeOpen()}, cho một bản ghi ĐÃ tải sẵn — dùng
     * ở `App\Support\MatterStaleness::color()`, nơi tô màu từng dòng của `MattersTable` chứ
     * không lọc một truy vấn. Cùng hai điều kiện, một chỗ định nghĩa duy nhất.
     */
    public function isOpen(): bool
    {
        return $this->closed_at === null && ! $this->trashed();
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
     *
     * **Rà soát mang sang từ một lane khác (M6.5 Task 5) — fail-open tiềm ẩn.** Bản trước so
     * `$this->confidentiality === Confidentiality::Restricted`. Trên một bản ghi nạp qua một
     * select rút gọn (ví dụ `with('matter:id,code,lead_lawyer_id')`), cột này không nằm trong
     * SELECT, và Eloquent trả về `null` cho một thuộc tính có cast mà không được chọn — `null !==
     * Restricted` đi thẳng vào nhánh THƯỜNG, tức mở một vụ việc hạn chế cho MỌI người có
     * `matter.viewAny` (kế toán, trưởng phòng). "Không rõ" giờ đi vào ĐÚNG nhánh restricted —
     * `!== Confidentiality::Normal`, không phải `=== Restricted` — nên `null` và bất kỳ giá trị lạ
     * nào khác đều bị coi là "chưa chắc thường", không phải "chưa chắc hạn chế". Không cần một
     * điều kiện `array_key_exists('confidentiality', ...)` riêng: một cột không được SELECT luôn
     * đọc ra `null` qua cast, và `null !== Normal` đã tự bắt đúng ca đó — một điều kiện riêng ở
     * đây sẽ không có mutation probe nào chứng minh được nó đang chặn gì (đã thử: xoá thì không
     * test nào đỏ).
     *
     * `deleted_at` được canh RIÊNG, và phải riêng: không đi qua được đường trên vì cột này
     * KHÔNG có cast (không nằm trong `casts()`), nên `array_key_exists('deleted_at', ...)` trên
     * `getAttributes()` là cách DUY NHẤT phân biệt "cột không được SELECT" (mất khỏi mảng) với
     * "cột được chọn và mang giá trị `null`" — chính thuộc tính `$this->deleted_at` không đưa ra
     * được phân biệt đó, vì cả hai trường hợp nó đều trả về `null`. Một select mang `confidentiality`
     * (nên nhánh trên không tự bắt được) nhưng cố ý bỏ `deleted_at` ra là ca riêng điều kiện này
     * chặn — có test ghim (`MatterResourceTest`, "fails closed when confidentiality is
     * known-normal but deleted_at was left out of the select") và mutation probe xoá điều kiện
     * này làm đúng test đó đỏ, không test nào khác.
     *
     * Một Matter ĐÃ QUA MỘT LẦN TRUY VẤN THẬT (tức "đủ mặt" theo đúng nghĩa của docblock ở trên —
     * mọi nơi gọi hợp lệ của hàm này đều là một bản ghi đã tồn tại trong CSDL, được nạp qua
     * `Eloquent`, không phải một instance vừa dựng trong bộ nhớ chưa từng chạm CSDL) luôn có khoá
     * `deleted_at` trong mảng thuộc tính, dù giá trị có là `null` hay không — SELECT mặc định
     * (`*`) hay bất kỳ SELECT tường minh nào bao gồm nó đều để lại khoá. Chỉ một SELECT rút gọn
     * CỐ Ý bỏ nó ra mới làm khoá biến mất, và đó đúng là trường hợp cần chặn.
     *
     * **Fix round 1, finding minor: `wasRecentlyCreated` CŨNG đếm là "biết `deleted_at`, và biết
     * nó là `null`".** Câu khẳng định ngay trên ("mọi nơi gọi hợp lệ đều đã qua một lần truy vấn
     * thật") sai ở đúng MỘT trường hợp: `Matter::factory()->create()` rồi `->load('team')` NGAY
     * trên instance đó (không `->fresh()`/reload) — INSERT không refetch các cột nullable chưa
     * từng được set, nên `deleted_at` vắng mặt khỏi `getAttributes()` y hệt một select rút gọn.
     * Trước bản sửa này, một Matter như vậy — VỪA mở, chắc chắn chưa xoá mềm — vẫn rơi vào nhánh
     * restricted một cách SAI. `wasRecentlyCreated` (cờ chuẩn của Eloquent, bật ngay sau
     * `save()`/`create()` thành công, tắt lại ở lần `save()` kế tiếp) phân biệt được đúng ca này
     * với một select rút gọn thật sự: một bản ghi vừa tạo trong CHÍNH request này chắc chắn chưa
     * ai xoá mềm được, dù `getAttributes()` chưa có khoá đó.
     */
    public function isListableBy(User $user): bool
    {
        $deletedAtKnown = array_key_exists('deleted_at', $this->getAttributes()) || $this->wasRecentlyCreated;

        if (! $deletedAtKnown || $this->confidentiality !== Confidentiality::Normal) {
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

    /**
     * Giai đoạn hiện tại, hoặc `null` khi không tra được.
     *
     * **`?->`, không `->`.** `matterType` trỏ vào một model có `SoftDeletes`, nên khi quản trị
     * viên xoá mềm một LOẠI vụ việc thì quan hệ này trả `null` cho mọi hồ sơ đang đứng trong loại
     * ấy — và một lần gọi `stage()` trên `null` là một trang 500 cho từng khách hàng của loại đó
     * (đo được ở `MatterProgressTest`, "still serves the page when the matter type behind it has
     * been soft deleted"). Trường hợp hẹp hơn — xoá mềm một GIAI ĐOẠN — đã được `stage()` trả
     * `null` từ trước; đây là trường hợp rộng hơn nằm một tầng trên.
     *
     * Đây là nửa ĐỌC của lần vá. Nửa GHI nằm ở `MatterTypePolicy::delete()`, thứ không cho xoá
     * một loại còn hồ sơ dùng ngay từ đầu; hai nửa đều cần, xem docblock của policy đó.
     */
    public function currentStage(): ?MatterTypeStage
    {
        return $this->matterType?->stage($this->stage);
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
            ->whereNull($this->qualifyColumn('deleted_at'))
            // Task 2 (`portal/portal-3`): khách hàng (Client) đã xoá mềm không được để vụ việc
            // của họ ra portal, ĐỘC LẬP với điều kiện tương tự ở ClientUser::canAccessPanel() —
            // xem docblock ở đó cho lý do hai tầng tách rời. Trước bản sửa này, các điều kiện ở
            // trên chỉ hỏi bảng `matters`; `clients.deleted_at` không được hỏi ở đâu cả, nên xoá
            // mềm một khách hàng không rút được vụ việc của họ khỏi cổng. `whereHas` kéo theo
            // đúng `SoftDeletingScope` (global scope thường trực của `Client`) vào truy vấn con,
            // nên "còn một dòng `clients` chưa xoá mềm" là toàn bộ ý nghĩa của điều kiện này —
            // không cần lặp lại `whereNull('clients.deleted_at')` bằng tay.
            ->whereHas('client');

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

    /**
     * Dòng tiến độ, mới nhất trước.
     *
     * **`id` giảm dần là TIÊU CHÍ PHỤ, và nó không phải trang trí.** `occurred_at` được nhập qua
     * một ô chọn NGÀY, nên mọi giá trị đều là nửa đêm: hai cập nhật trong cùng một ngày bằng nhau
     * tuyệt đối ở cột sắp xếp, và đó là trường hợp thường ngày chứ không phải một ca biên. Không
     * có tiêu chí phụ thì thứ tự hai dòng ấy do bộ tối ưu truy vấn quyết định — SQLite và MariaDB
     * hôm nay đều tình cờ trả về `id` giảm dần, nên KHÔNG test hành vi nào bắt được lần đổi ý của
     * chúng. Hậu quả khi nó đổi không nằm ở thứ tự hiển thị mà ở khối 2 của SPEC §8.3: trang đọc
     * `client_action` của dòng ĐẦU TIÊN, nên một thứ tự lật đưa một chỉ dẫn đã bị thay thế ra làm
     * việc khách đang phải làm.
     *
     * `id` là tiêu chí phụ đúng nghĩa ở chính bảng này: `StageLog` chặn XOÁ hoàn toàn và chỉ cho
     * sửa đúng năm cột công bố/thông báo (`StageLog::MUTABLE`) — `occurred_at` không nằm trong
     * số đó — nên `id` tăng đúng theo thứ tự các cập nhật được ghi vào, và không một dòng nào
     * đổi ngày hay biến mất về sau.
     *
     * Ghim bằng một test CẤU TRÚC (`MatterProgressTest`, "breaks the tie on the stage log relation
     * with a descending id") chứ không chỉ bằng test hành vi, đúng vì lý do trên.
     */
    public function stageLogs(): HasMany
    {
        return $this->hasMany(StageLog::class)->orderByDesc('occurred_at')->orderByDesc('id');
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

    /** Một hợp đồng cho một vụ việc — `contracts.matter_id` unique thật (M9 quyết định 1). */
    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    /**
     * Khung cho tính phí theo giờ giai đoạn 2 (SPEC §15, M9 Task 12 — chỉ khung, không nghiệp vụ
     * nào đọc quan hệ này ở M9). Xem docblock {@see TimeEntry}.
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
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
