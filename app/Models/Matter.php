<?php

namespace App\Models;

use App\Enums\Confidentiality;
use App\Enums\MatterAiAccess;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role as StaffRole;
use App\Exceptions\MatterHasOutstandingBalance;
use App\Exceptions\MatterNotDestroyable;
use App\Exceptions\MatterRecordDestroyed;
use App\Exceptions\StageNotConfigured;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HidesInternalAttributesFromPortal;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Support\Billing\BillingSummary;
use App\Support\CodeSequence;
use App\Support\Scopes\ClientPortalScope;
use Carbon\CarbonInterface;
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
            'ai_access' => MatterAiAccess::class,
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
            // M11 R9: chỗ DUY NHẤT một vụ mới nhận cờ AI — `MCP_MATTER_DEFAULT`, mặc định `denied`.
            // `ai_access` không nằm trong `$fillable`, nên một giá trị đã có ở đây chỉ đến từ một
            // lần gán tường minh (factory, Action), không từ mảng thuộc tính của một form.
            $matter->ai_access ??= MatterAiAccess::defaultForNewMatter();
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
     * làm điều kiện lọc** (cùng {@see self::scopeClosed()} ngay dưới). Mọi nơi khác gọi `->open()`
     * hoặc `->closed()` — xem các widget trang chủ, `ClientPolicy::delete()`, `App\Support\OpenWork`,
     * `App\Support\MatterStaleness`, và các màn hình tiền của M9. Hai chỗ còn lại được PHÉP nhắc
     * tên cột này: chính `TransitionMatterStage` (nơi ghi), và các cast/nhãn hiển thị đơn thuần
     * (`MatterInfolist`, `getActivitylogOptions()`). Luật này có test cấu trúc
     * (`MatterTest`, "uses closed_at as a condition nowhere in app/…").
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
     * Nửa kia của {@see self::scopeOpen()} (gộp M9, xung đột 2): "vụ đã kết thúc" — `closed_at` có
     * giá trị VÀ chưa xoá mềm. Một vụ đã huỷ không "đang mở" mà cũng không "đã kết thúc": nó đã
     * huỷ, và `withTrashed()` phía trên không được kéo nó vào đây. Dùng ở widget "Hồ sơ đã kết thúc
     * còn công nợ", bộ lọc cùng tên của trang Công nợ; trước khi gộp, M9 tự viết
     * `whereNotNull('closed_at')` ở những chỗ đó. `tests/Feature/Models/MatterTest.php` cấm dùng cột
     * này làm điều kiện ở bất kỳ đâu khác trong app/.
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query
            ->whereNotNull($this->qualifyColumn('closed_at'))
            ->whereNull($this->qualifyColumn('deleted_at'));
    }

    /** Bản trong bộ nhớ của {@see self::scopeClosed()} — cùng hai điều kiện. */
    public function isClosed(): bool
    {
        return $this->closed_at !== null && ! $this->trashed();
    }

    /**
     * Làn fm B2: hồ sơ đã ghi quyết định tiêu huỷ (`matter_archives.destroyed_at`) — trạng thái khoá
     * ({@see MatterRecordDestroyed}). Luôn đọc CSDL (không dùng quan hệ đã nạp), để một Action gọi
     * dưới khoá `matters` thấy đúng dòng lưu trữ hiện tại.
     */
    public function isRecordDestroyed(): bool
    {
        return MatterArchive::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('matter_id', $this->getKey())
            ->whereNotNull('destroyed_at')
            ->exists();
    }

    /**
     * Vụ đã kết thúc TRƯỚC ngày `$day` (so theo ngày) — làn fm A3: "ngày mở hồ sơ" sửa lại không được
     * đứng sau ngày kết thúc. Viết ở đây vì `closed_at` chỉ được dùng làm điều kiện trong model này
     * (`MatterTest`, "uses closed_at as a condition nowhere in app/…"). Vụ chưa đóng: `false`.
     */
    public function closedBefore(CarbonInterface $day): bool
    {
        return $this->closed_at !== null
            && $this->closed_at->toDateString() < $day->toDateString();
    }

    /**
     * Vụ đã kết thúc TRONG một khoảng ngày (M13, cột P5 "Vụ kết thúc trong kỳ"):
     * {@see self::scopeClosed()} cộng `closed_at` nằm giữa 00:00:00 của `$from` và 23:59:59 của `$to`.
     *
     * **Cận đủ giờ, không ngày trần** — bài học `RevenueFilters::bounds()` (M9 Task 13). `closed_at`
     * là cột `date`, nhưng cast `date` ghi theo định dạng ngày-giờ của kết nối: trên SQLite dòng do
     * `TransitionMatterStage` ghi (`now()`) mang cả giờ (`2026-09-30 15:42:10`), lớn hơn cận trần
     * `2026-09-30` khi so chuỗi, nên vụ kết thúc đúng ngày cuối kỳ rơi khỏi kỳ. MariaDB nâng cột
     * `DATE` lên nửa đêm khi so với `DATETIME`, nên cùng hai cận đúng trên cả hai. Giờ trên `$from`/
     * `$to` của người gọi bị bỏ: cận luôn là đầu và cuối NGÀY.
     *
     * Đi qua `closed()`, nên vụ đã huỷ không bao giờ "kết thúc trong kỳ", kể cả dưới `withTrashed()`.
     * Ngoài tệp này (và `TransitionMatterStage`, nơi ghi cột), không chỗ nào trong `app/` được viết
     * điều kiện trên `closed_at` — `MatterTest` ("uses closed_at as a condition nowhere…") canh, và từ
     * M13 bắt cả `whereBetween`/`whereDate`/`whereColumn` lẫn so sánh `<`, `<=`, `>`, `>=`.
     */
    public function scopeClosedWithin(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query
            ->closed()
            ->whereBetween($this->qualifyColumn('closed_at'), [
                $from->copy()->startOfDay()->toDateTimeString(),
                $to->copy()->endOfDay()->toDateTimeString(),
            ]);
    }

    /**
     * Vụ đã kết thúc vào hoặc trước NGÀY `$day`: {@see self::isClosed()} và `closed_at` không muộn
     * hơn 23:59:59 của `$day` — kể cả vụ kết thúc ĐÚNG ngày đó. Dùng ở `Deadline::outcomeAt()` (ca 6
     * của bảng ca biên P1): vụ đã kết thúc thì mốc đến hạn từ hôm đó trở đi hết hiệu lực.
     *
     * So với cuối ngày, không với ngày trần: cùng lý do với {@see self::scopeClosedWithin()}. Trong bộ
     * nhớ cast `date` đọc `closed_at` về 00:00 của ngày đóng, nên phép so thực chất là so NGÀY.
     */
    public function closedOnOrBefore(CarbonInterface $day): bool
    {
        return $this->isClosed() && $this->closed_at->lte($day->copy()->endOfDay());
    }

    /**
     * Mỗi chỗ ngồi "giữ việc phụ" của đội ngũ là một dòng (M13, cột N2 "Vụ đang tham gia"): nối
     * `matter_user` (bí danh `supporting_seat`) với vai thuộc {@see MatterRole::supporting()} — luật sư
     * cộng sự, trợ lý; không `lead` (đã quy qua `lead_lawyer_id`), không `observer` (không giữ việc).
     * Chọn thêm `supporting_seat.user_id as member_id` (cộng `matters.*` nếu truy vấn chưa chọn cột
     * nào), nên một vụ có hai người giữ việc phụ ra hai dòng, mỗi dòng mang `member_id` của một người.
     *
     * Đếm theo người: `->select('supporting_seat.user_id as member_id')->selectRaw('COUNT(*) …')
     * ->groupBy('supporting_seat.user_id')` — đọc bí danh của scope này, không viết lại điều kiện vai.
     * Bí danh bảng để câu không có hai `matter_user` (lần nối này và EXISTS của `listableBy()` ở nhánh
     * người không có `matter.viewAny`). Cùng tập với quan hệ {@see self::team()} lọc theo vai, và
     * hình dạng đếm ở trên chạy trên MariaDB strict — `SingleSourceParityTest`.
     */
    public function scopeWithSupportingMember(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($this->qualifyColumn('*'));
        }

        return $query
            ->join('matter_user as supporting_seat', 'supporting_seat.matter_id', '=', $this->qualifyColumn('id'))
            ->whereIn('supporting_seat.role_in_matter', self::supportingRoleValues())
            ->addSelect('supporting_seat.user_id as member_id');
    }

    /**
     * Vụ `$subject` đang phụ trách — `lead_lawyer_id` HIỆN TẠI (M13, danh sách giấy tờ chờ duyệt trên
     * trang của một người). Không phải "người phụ trách lúc …": câu đó là `LeadAt` (R18).
     */
    public function scopeLedBy(Builder $query, User $subject): Builder
    {
        return $query->where($this->qualifyColumn('lead_lawyer_id'), $subject->getKey());
    }

    /**
     * Vụ `$subject` giữ một ghế "việc phụ" trong đội ngũ — vai thuộc {@see MatterRole::supporting()}
     * (luật sư cộng sự, trợ lý); không `lead`, không `observer` (M13 Task 5: bộ lọc "Tham gia" của bảng
     * vụ và bảng cơ cấu lĩnh vực của một trợ lý trên trang của một người). Đúng tập vụ mà
     * {@see self::scopeWithSupportingMember()} cho một dòng mang `member_id` của người đó — cùng danh
     * sách vai; `matter_user` có khoá duy nhất `(matter_id, user_id)`, nên mỗi vụ là đúng một ghế và
     * phép đếm vụ ở đây bằng phép đếm ghế của N2 (`TeamMemberPageTest` ghim hai hình dạng). Chỉ thu
     * hẹp: người gọi tự ghép `listableBy($viewer)` (R4).
     */
    public function scopeSupportedBy(Builder $query, User $subject): Builder
    {
        return $query->whereHas('team', fn (Builder $team): Builder => $team
            ->whereKey($subject->getKey())
            ->whereIn('matter_user.role_in_matter', self::supportingRoleValues()));
    }

    /**
     * Vụ `$subject` đang làm (M13, bảng vụ trên trang của một người): {@see self::scopeLedBy()} HOẶC
     * {@see self::scopeSupportedBy()}. Cùng hai vế với N1 + N2 — vai `observer` không tính. Chỉ thu
     * hẹp: người gọi tự ghép `listableBy($viewer)` (R4).
     */
    public function scopeWorkedOnBy(Builder $query, User $subject): Builder
    {
        return $query->where(fn (Builder $matters): Builder => $matters
            ->ledBy($subject)
            ->orWhere(fn (Builder $seated): Builder => $seated->supportedBy($subject)));
    }

    /**
     * Vụ ở một mức bảo mật (M13 Task 7: tác vụ chụp tách mỗi người thành dòng `normal` và
     * `restricted`). **Không phải luật xem** — ai thấy vụ nào vẫn chỉ là {@see self::scopeListableBy()};
     * scope này chỉ phân loại cho một tác vụ chạy không người đăng nhập, để tác vụ đó không tự viết
     * điều kiện trên `confidentiality`.
     */
    public function scopeOfConfidentiality(Builder $query, Confidentiality $level): Builder
    {
        return $query->where($this->qualifyColumn('confidentiality'), $level->value);
    }

    /** @return list<string> */
    private static function supportingRoleValues(): array
    {
        return array_map(static fn (MatterRole $role): string => $role->value, MatterRole::supporting());
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
     *
     * Năm điều kiện, theo thứ tự dưới đây: đúng khách hàng; đã bật công tắc công bố; vụ chưa xoá
     * mềm; khách hàng chưa xoá mềm (M6.5 Task 2); và — M7 Task 5 — khách chưa hết hạn tra cứu.
     * `MatterPolicy::releasedToPortal()` nói lại ĐÚNG năm điều kiện này bằng thuộc tính, không gọi
     * lại hàm này.
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
            ->whereHas('client')
            // M7 Task 5 (R4, SPEC §11 "Bàn giao và lưu trữ"): quá `client_access_until` thì vụ việc
            // rời cổng — định nghĩa ở `MatterArchive::scopeClientAccessExpired()`. Không sửa dữ
            // liệu nào của vụ việc: điều kiện này là toàn bộ việc "ẩn", và nó đúng từ 00:00 ngày SAU
            // `client_access_until` dù job `ExpireClientAccess` đã chạy hay chưa.
            //
            // `withoutGlobalScope(ClientPortalScope::class)` trong truy vấn con là BẮT BUỘC, không
            // phải trang trí: `MatterArchive` mang `ClientPortalScope` chặn sạch (`1 = 0`), và
            // truy vấn con này chạy đúng lúc scope đó đang hoạt động (ta đang ở bên trong
            // `ClientPortalScope::apply()` của chính `Matter`). Không gỡ nó thì truy vấn con không
            // bao giờ tìm thấy dòng lưu trữ nào, `whereDoesntHave` luôn đúng, và điều kiện thành vô
            // hiệu ở đúng nơi nó tồn tại để chặn. `SoftDeletingScope` của `MatterArchive` thì giữ
            // nguyên: một dòng lưu trữ đã xoá mềm không tính (cùng luật với tầng policy, có test
            // ghim hai tầng đồng ý — `ClientAccessExpiryTest`).
            ->whereDoesntHave('archive', fn (Builder $archive) => $archive
                ->withoutGlobalScope(ClientPortalScope::class)
                ->clientAccessExpired());
    }

    /**
     * M7 Task 5: dòng lưu trữ, đọc cho RIÊNG câu hỏi "khách còn được tra cứu vụ này không"
     * (`MatterPolicy::releasedToPortal()`, điều kiện thứ năm) — không bao giờ bị `ClientPortalScope`
     * cắt.
     *
     * Vì sao không dùng {@see self::archive()}: `MatterArchive` mang `ClientPortalScope` chặn sạch
     * (`1 = 0`), nên `with('archive')` dưới phiên khách (đúng chỗ `MyMatters::buildCards()` và
     * `SubmitDocument::resolveMatter()` nạp sẵn) trả `null` cho MỌI vụ — và đường trong bộ nhớ của
     * policy sẽ đọc `null` thành "không có dòng lưu trữ, không hết hạn": thủng tầng policy đúng lúc
     * nó được dùng. Quan hệ này gỡ scope ngay trong định nghĩa, nên mọi lần nạp sẵn nó đều đúng bất
     * kể phiên nào đang mở. `archive()` giữ nguyên scope của nó cho mọi nơi khác.
     *
     * `SoftDeletingScope` được giữ: một dòng lưu trữ đã xoá mềm không tính, cùng luật với tầng truy
     * vấn ở {@see self::applyClientPortalConstraints()}.
     *
     * Không bao giờ in ra cổng: thứ duy nhất đọc nó là policy.
     */
    public function clientAccessArchive(): HasOne
    {
        return $this->hasOne(MatterArchive::class)->withoutGlobalScope(ClientPortalScope::class);
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

    /**
     * Nháp cập nhật tiến độ do AI soạn (M11 R5) — mọi nháp, kể cả đã dùng hay đã bỏ; nháp đang chờ
     * là `->pending()` ({@see StageLogDraft}, scope của `IsMcpDraft`).
     */
    public function stageLogDrafts(): HasMany
    {
        return $this->hasMany(StageLogDraft::class);
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
                'court_name', 'case_number', 'confidentiality', 'ai_access',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
