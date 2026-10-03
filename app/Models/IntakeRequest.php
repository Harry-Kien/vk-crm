<?php

namespace App\Models;

use App\Enums\Confidentiality;
use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Policies\IntakeRequestPolicy;
use App\Support\Audit;
use App\Support\CodeSequence;
use App\Support\Normalizer;
use App\Support\Scopes\ClientPortalScope;
use Database\Factories\IntakeRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
     * Trần số bên đối lập của MỘT bản ghi (M10 Task 3) — một con số cho lần ghi đầu và lần sửa
     * (`ValidatesIntakeIdentity`), cho form (`IntakeRequestForm`, `maxItems`) và cho gộp
     * (`MergeIntake` từ chối một lần gộp làm bản đích vượt trần). Không có nó, gộp tạo ra một bản
     * ghi mà form không lưu lại được nữa trừ khi gỡ bớt bên đối lập — tức bỏ dữ kiện của kiểm tra
     * xung đột chỉ để qua được một luật nhập liệu.
     */
    public const MAX_OPPOSING_PARTIES = 10;

    /**
     * Độ dài cột của mọi SĐT CHUẨN HOÁ của tiếp nhận: `intake_requests.contact_phone_normalized` và
     * `intake_parties.phone_normalized` (cả hai `string(20)`).
     */
    public const NORMALIZED_PHONE_LENGTH = 20;

    /**
     * Dạng chuẩn hoá của một SĐT gõ vào có vừa cột không (M10 Task 3; rà soát Task 2, m2). Dạng chuẩn
     * hoá có thể DÀI hơn dạng gõ: số 0 đầu thành `84` (`09123456780987654321`, 20 ký tự → 21), nên luật
     * `max:20` của dạng gõ không giữ được cột — vượt là lỗi 1406 trên MariaDB strict (SQLite không
     * thấy). Số mà `Normalizer::phone()` không đọc được (ra null) thì "vừa": không có gì vào cột. Cổng
     * thật ở `ValidatesIntakeIdentity` (lần ghi đầu và lần sửa); form hỏi cùng hàm này để báo lỗi ở đúng ô.
     */
    public static function normalizedPhoneFits(?string $phone): bool
    {
        return strlen(Normalizer::phone($phone) ?? '') <= self::NORMALIZED_PHONE_LENGTH;
    }

    /**
     * KHÔNG có `code` (sinh khi tạo), `contact_phone_normalized` và `contact_id_number_hash` (chỉ
     * `identify()` ghi được, xem {@see self::fill()}), `created_by`/`updated_by` (`HasBlameable`), và
     * `conflict_red_pending_since` (Đỏ đang chờ quản lý/admin — chỉ `CheckIntakeConflict` đặt, chỉ
     * `ResolveIntakeRedConflict` xoá; một form gán hàng loạt không được xoá khoá đó).
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
            'conflict_red_pending_since' => 'datetime',
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
     *
     * **Cộng thêm một vế cho bản ghi ĐÃ CHUYỂN ĐỔI (fix vòng 1 của Task 1):** bản ghi mang tên khách,
     * câu chuyện và liên kết `client_id`/`matter_id` của vụ nó đã thành. Nếu vụ đó `restricted` mà
     * người hỏi không xem được vụ (`MatterPolicy::view` false: không phải admin, không phải luật sư
     * phụ trách còn `matter.view`) thì bản ghi biến mất với họ — kể cả khi họ có `intake.viewAny` hay
     * chính là người ghi/được giao. Vụ thường KHÔNG đòi thêm gì (R9 giữ nguyên: trợ lý đã ghi bản ghi
     * vẫn thấy dù không nằm trong nhóm vụ). Bản ghi chưa chuyển đổi (`matter_id` null) không đổi.
     * Vụ đã xoá mềm vẫn tính (cùng `MatterPolicy::view`), và truy vấn vụ bỏ `ClientPortalScope`.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->can(Permission::IntakeViewAny->value)) {
            if (! $user->can(Permission::IntakeCreate->value)) {
                return $query->whereRaw('1 = 0');
            }

            $model = $query->getModel();

            $query->where(fn (Builder $q) => $q
                ->where($model->qualifyColumn('created_by'), $user->getKey())
                ->orWhere($model->qualifyColumn('assigned_to'), $user->getKey()));
        }

        $model = $query->getModel();

        return $query->where(fn (Builder $q) => $q
            ->whereNull($model->qualifyColumn('matter_id'))
            ->orWhereIn($model->qualifyColumn('matter_id'), Matter::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->withTrashed()
                ->where(fn (Builder $m) => $m
                    ->where('confidentiality', '!=', Confidentiality::Restricted->value)
                    ->orWhere(fn (Builder $restricted) => $restricted->listableBy($user)))
                ->select('matters.id')));
    }

    /** Bản trong bộ nhớ của {@see self::scopeVisibleTo()} — test khẳng định hai bản trả lời giống nhau. */
    public function isVisibleTo(User $user): bool
    {
        if (! $user->can(Permission::IntakeViewAny->value)
            && ! ($user->can(Permission::IntakeCreate->value)
                && ($this->created_by === $user->getKey() || $this->assigned_to === $user->getKey()))) {
            return false;
        }

        return $this->matter_id === null || $this->canSeeConvertedMatter($user);
    }

    /**
     * Vế "vụ đã chuyển đổi" của {@see self::isVisibleTo()}: vụ không `restricted` thì qua; vụ
     * `restricted` thì phải là vụ người này xem được ({@see Matter::isListableBy()}, cùng luật
     * `MatterPolicy::view`). Không tìm thấy vụ (khoá ngoại đã đặt null mà `matter_id` còn giữ
     * trong bộ nhớ) → từ chối, đúng như truy vấn SQL.
     */
    private function canSeeConvertedMatter(User $user): bool
    {
        $matter = Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->find($this->matter_id, ['id', 'confidentiality', 'lead_lawyer_id', 'deleted_at']);

        return $matter !== null
            && ($matter->confidentiality !== Confidentiality::Restricted || $matter->isListableBy($user));
    }

    /**
     * Bản ghi còn là một "người văn phòng đã nghe chuyện mà chưa nhận việc" — tức đủ điều kiện làm
     * NGUỒN DÒ THỨ HAI của `RunConflictCheck` (M10 R1): chưa chuyển đổi (không `matter_id`, không
     * `won`), chưa gộp vào bản khác (không `merged_into_id`, không `merged`), chưa ẩn danh
     * (`anonymised_at` null). Bản đã xoá mềm bị loại bởi `SoftDeletes`. Cũng là định nghĩa của cờ
     * "còn bản khác bạn không xem được" ở gợi ý trùng (`FindIntakeDuplicates`), để hai nơi không lệch
     * nhau (Task 7 đặt `anonymised_at`).
     */
    public function scopeOpenForConflictCheck(Builder $query): Builder
    {
        $model = $query->getModel();

        return $query
            ->whereNull($model->qualifyColumn('matter_id'))
            ->whereNull($model->qualifyColumn('merged_into_id'))
            ->whereNull($model->qualifyColumn('anonymised_at'))
            ->whereNotIn($model->qualifyColumn('status'), [IntakeStatus::Won->value, IntakeStatus::Merged->value]);
    }

    /**
     * Bản ghi đã ẩn danh hoặc đã gộp vào bản khác: không còn nhận thêm nội dung hay kiểm tra nào
     * (M10, các Action `app/Actions/Intake` từ chối). Ẩn danh xoá dữ liệu cá nhân; bản đã gộp đã
     * chuyển phần việc sang bản đích.
     */
    public function isClosedToWrites(): bool
    {
        return $this->anonymised_at !== null || $this->status === IntakeStatus::Merged;
    }

    /**
     * Bản ghi đã xong việc với màn hình tiếp nhận (M10 Task 3): đã ẩn danh hoặc đã gộp
     * ({@see self::isClosedToWrites()}), HOẶC đã chuyển thành vụ việc (`won`, hay đã có `matter_id`
     * — R3: "khoá bản ghi tiếp nhận"). Không sửa danh tính, không đổi trạng thái, không từ chối, không
     * gộp vào hay gộp đi; màn hình hiện nó ở dạng chỉ đọc. Hẹp hơn có chủ đích, `isClosedToWrites()`
     * vẫn là cổng của các Action Task 2 (câu chuyện, thông báo, kiểm tra lại).
     */
    public function isClosedToChanges(): bool
    {
        return $this->isClosedToWrites() || $this->status === IntakeStatus::Won || $this->matter_id !== null;
    }

    /**
     * PHẦN DANH TÍNH không sửa được nữa (M10 Task 3, fix vòng 1 — rà soát Task 3, C1): bản ghi đã xong
     * việc ({@see self::isClosedToChanges()}) HOẶC đã bị từ chối, vì bất kỳ lý do nào, với bất kỳ ai.
     * Từ chối là một quyết định trên đúng danh tính đó; với từ chối vì xung đột, SĐT/CCCD + vai của nó
     * là thứ khoá các lần gọi lại của cùng người ({@see self::locksRepeatCalls()}) — đổi chúng là rửa
     * khoá. "Mọi lý do" để một câu từ chối không cho người không có `intake.viewAny` biết đó là xung đột
     * (R8). Bản đã từ chối vẫn gộp đi được theo luật của `MergeIntake`, và vẫn nhận gộp vào (chỉ THÊM
     * bên đối lập và dấu Đỏ); `UpdateIntakeIdentity` và form trang sửa đọc hàm này.
     */
    public function isClosedToIdentityEdits(): bool
    {
        return $this->isClosedToChanges() || $this->status === IntakeStatus::Declined;
    }

    /**
     * Một ghi đè Đỏ còn hiệu lực: có người ghi đè VÀ có lý do (R1 — lý do bắt buộc). Thiếu một trong
     * hai thì không phải ghi đè.
     */
    public function hasConflictOverride(): bool
    {
        return $this->conflict_overridden_by !== null && filled($this->conflict_override_reason);
    }

    /**
     * Bản ghi đang có một Đỏ CHƯA được quản lý/admin xử lý (M10 R1; fix vòng 1 của Task 2, I2 — Đỏ
     * DÍNH). Đúng khi:
     *  - `conflict_red_pending_since` đã đặt: một lần kiểm tra từng ra Đỏ (hoặc khoá của người gọi lại,
     *    {@see self::locksRepeatCalls()}) và chưa ai ghi đè. Một lần chạy lại ra Xanh — sau khi sửa
     *    hay gỡ bên đối lập, kể cả do quản lý tự chạy — KHÔNG xoá nó: R1 chỉ có hai cách xử lý Đỏ, từ
     *    chối (R8) hoặc ghi đè kèm lý do; hoặc
     *  - mức đã lưu là Đỏ mà không có ghi đè còn hiệu lực (lưới an toàn cho một dòng Đỏ thiếu dấu "đang
     *    chờ" — ghi trước khi có cột, hoặc sửa tay).
     * `IntakeSummaryGate` khoá ô câu chuyện theo đúng hàm này; `ResolveIntakeRedConflict` chỉ ghi đè
     * khi hàm này đúng.
     */
    public function hasUnresolvedRed(): bool
    {
        return $this->conflict_red_pending_since !== null
            || ($this->conflict_level === ConflictLevel::Red && ! $this->hasConflictOverride());
    }

    /**
     * Một cuộc gọi LẠI của cùng người (xem {@see self::sameCallerIntakes()}) phải chờ quản lý/admin
     * như Đỏ (fix vòng 1 của Task 2, C1): bản này còn một Đỏ chưa xử lý, hoặc văn phòng đã từ chối vì
     * xung đột (R8, `decline_reason_is_conflict`). Không thì một trợ lý khác ghi lần gọi lại sẽ nghe
     * hết câu chuyện mà không quản lý nào biết — đúng điều R1 tồn tại để chặn.
     */
    public function locksRepeatCalls(): bool
    {
        return $this->hasUnresolvedRed() || $this->decline_reason_is_conflict;
    }

    /**
     * Các lần tiếp nhận KHÁC còn mở ({@see self::scopeOpenForConflictCheck()}) mà người liên hệ là
     * CÙNG người gọi lại với bản này, về cùng một việc: khớp dấu băm CCCD hoặc SĐT chuẩn hoá (không bao
     * giờ chỉ tên — tên người Việt trùng nhau rất phổ biến) VÀ đã khai đúng vai `$role`. `$role` là vai
     * mà lần kiểm tra của bản này dùng cho người liên hệ (đã khai, hoặc suy từ bên đối lập khi chưa
     * khai — `App\Actions\Intake\CheckIntakeConflict`); lần gọi kia phải ĐÃ KHAI vai (cột `contact_role`). Khác vai
     * thì không phải cùng một người gọi lại: vợ và chồng chung một số máy bàn.
     *
     * MỘT định nghĩa cho cả hai nơi dùng: `RunConflictCheck` (bỏ khớp với lần gọi lành, mang bên đối
     * lập của lần gọi trước vào lần kiểm tra) và `CheckIntakeConflict` (khoá lần gọi lại khi một lần
     * gọi trước {@see self::locksRepeatCalls()}). Không có định danh mạnh nào thì rỗng. Bỏ
     * `ClientPortalScope` như mọi truy vấn của kiểm tra xung đột.
     *
     * @return EloquentCollection<int, IntakeRequest>
     */
    public function sameCallerIntakes(PartyRole $role): EloquentCollection
    {
        $hash = $this->contact_id_number_hash;
        $phone = $this->contact_phone_normalized;

        if ($hash === null && $phone === null) {
            return new EloquentCollection;
        }

        return static::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->openForConflictCheck()
            ->whereKeyNot($this->getKey())
            ->where('contact_role', $role->value)
            ->where(fn (Builder $q) => $q
                ->when($hash, fn (Builder $w) => $w->orWhere('contact_id_number_hash', $hash))
                ->when($phone, fn (Builder $w) => $w->orWhere('contact_phone_normalized', $phone)))
            ->get();
    }

    /**
     * Mọi cuộc gọi lại mà {@see self::sameCallerIntakes()} ghép với `$earlier` thì cũng ghép với bản
     * này (M10 Task 3, fix vòng 1 — rà soát Task 3, C1: gộp không được là đường rửa Đỏ thứ hai). Phép
     * ghép đọc ở lần gọi trước: vai ĐÃ KHAI, và SĐT chuẩn hoá HOẶC dấu băm CCCD. Nên đúng khi:
     *  - `$earlier` chưa khai vai, hoặc không có SĐT lẫn CCCD — không cuộc gọi lại nào ghép được với nó,
     *    nên không có gì để mất; hoặc
     *  - bản này cùng vai đã khai, VÀ mang đúng từng định danh `$earlier` có: SĐT chuẩn hoá nếu
     *    `$earlier` có SĐT, dấu băm CCCD nếu `$earlier` có CCCD (bản này có thêm định danh khác thì
     *    không sao — nó chỉ bắt được NHIỀU cuộc gọi lại hơn).
     * Không xét việc bản này còn mở hay không: `MergeIntake` đã từ chối một bản đích đã xong việc.
     */
    public function catchesRepeatCallsOf(IntakeRequest $earlier): bool
    {
        if ($earlier->contact_role === null
            || ($earlier->contact_phone_normalized === null && $earlier->contact_id_number_hash === null)) {
            return true;
        }

        return $this->contact_role === $earlier->contact_role
            && ($earlier->contact_phone_normalized === null || $earlier->contact_phone_normalized === $this->contact_phone_normalized)
            && ($earlier->contact_id_number_hash === null || $earlier->contact_id_number_hash === $this->contact_id_number_hash);
    }

    /**
     * Dấu vân tay của phần danh tính mà một lần kiểm tra xung đột đã chạy trên đó (vai dự kiến, tên
     * chuẩn hoá, SĐT chuẩn hoá, dấu băm CCCD của người liên hệ, cùng vai + tên + SĐT + dấu băm của
     * từng bên đối lập). Lưu kèm `conflict_result`; cổng ô câu chuyện so nó với danh tính HIỆN TẠI:
     * ai sửa danh tính mà chưa chạy lại kiểm tra thì kết quả cũ không còn là bằng chứng. HMAC với
     * `APP_KEY` chứ không phải sha256 trần — số điện thoại và CCCD có không gian nhỏ, dò ngược được.
     */
    public function identityFingerprint(): string
    {
        $parties = $this->parties()->get()
            ->map(fn (IntakeParty $party): string => implode('|', [
                $party->role?->value, $party->name_normalized, $party->phone_normalized, $party->id_number_hash,
            ]))
            ->sort()
            ->values()
            ->all();

        return Audit::identifierHash((string) json_encode([
            $this->contact_role?->value,
            $this->contact_name_normalized,
            $this->contact_phone_normalized,
            $this->contact_id_number_hash,
            $parties,
        ]));
    }

    /**
     * Phí đã báo lúc tiếp nhận của bản ghi đã CHUYỂN THÀNH `$matter` (M10 Task 4, R3) — giá trị GỢI Ý
     * cho ô tổng giá trị của form soạn hợp đồng M9; `DraftContract` không đọc nó. `matter_id` là cột
     * unique, nên có nhiều nhất một bản ghi. Không có bản ghi nào, hay bản ghi không có phí: null. Bỏ
     * `ClientPortalScope` như mọi truy vấn nội bộ của bảng này; người hỏi đã qua cổng của tab tiền.
     */
    public static function quotedAmountFor(Matter $matter): ?int
    {
        $amount = static::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('matter_id', $matter->getKey())
            ->value('quoted_amount');

        return $amount === null ? null : (int) $amount;
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
     * `conflict_level`/`conflict_result` (đã có dòng `conflict_check_run` riêng). Lý do ghi đè Đỏ
     * KHÔNG đi qua đây mà vào dòng `intake_conflict_overridden` một cách tường minh (SPEC §6.10 "ghi
     * vào activity log", như `OpenMatter`; fix vòng 1 của Task 2) — một bản sao tự động nữa ở diff của
     * model chỉ thêm một chỗ Task 7 phải dọn. Bẫy đã biết: dòng
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
