<?php

namespace App\Models;

use App\Actions\Portal\ReplyToClientRequest;
use App\Actions\Portal\TriageClientRequest;
use App\Enums\ClientRequestStatus;
use App\Models\Concerns\RestrictedToClientPortal;
use App\Policies\StageLogViewPolicy;
use Carbon\CarbonInterface;
use Database\Factories\ClientRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class ClientRequest extends Model
{
    /** @use HasFactory<ClientRequestFactory> */
    use HasFactory;

    use RestrictedToClientPortal;
    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'client_user_id', 'subject', 'content', 'status', 'assigned_to', 'answered_at',
        'last_activity_at',
    ];

    protected $attributes = ['status' => 'new'];

    protected function casts(): array
    {
        return [
            'status' => ClientRequestStatus::class,
            'answered_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Giới hạn theo vụ việc, tức theo `client_id`, **không theo `client_user_id`**: SPEC §4.3
     * nói rõ mọi truy vấn portal giới hạn theo khách hàng, để hai tài khoản cùng một khách đọc
     * chung.
     *
     * **Đây là CÂU ĐƯỢC CHỐT, không phải một mặc định còn treo.** SPEC §5 viết "Tạo và xem
     * `ClientRequest` của chính mình", và câu đó đọc được theo cả hai nghĩa — "của chính tài
     * khoản này" hay "của chính khách hàng này". Câu hỏi đã được nêu hai lần (rà soát M2 Task 6,
     * rà soát M4 Task 2) và chủ văn phòng chốt ngày **19/09/2026: theo `Client`**. Hệ quả cần
     * nói thẳng, vì nó là một quyết định về sự riêng tư giữa hai người trong cùng một gia đình:
     * **một vụ việc có hai tài khoản portal (SPEC §4.3 nêu ví dụ hai vợ chồng) thì người này đọc
     * được yêu cầu người kia gửi, và đọc được cả câu văn phòng trả lời.**
     *
     * Ghim bằng test ở `tests/Feature/Authorization/PortalIsolationSweepTest.php` (cả vế dương —
     * tài khoản anh em đọc được — lẫn vế âm — khách hàng khác thì không). Đổi cách đọc là đổi
     * đúng hàm này cộng dòng tương ứng ở `ClientRequestReply`, và bộ test sẽ đỏ, nên lần đổi đó
     * không lặng lẽ xảy ra được.
     *
     * Cùng cách đọc áp cho `StageLogView` (biên bản đã xem) — xem {@see StageLogViewPolicy}.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        // `whereNull('deleted_at')`: một yêu cầu đã xoá mềm không quay lại bằng `withTrashed()`,
        // thứ chỉ gỡ `SoftDeletingScope` chứ không đụng tới scope này. Xem
        // `Matter::applyClientPortalConstraints()`.
        $query->whereNull($this->qualifyColumn('deleted_at'))->whereHas('matter');
    }

    /**
     * Yêu cầu đang chờ văn phòng (M13, cột N9): trạng thái Mới hoặc Đang xử lý — hai trạng thái mà
     * quả bóng ở sân văn phòng (`answered` chờ khách, `closed` đã xong). Cột viết đủ tên bảng, như
     * mọi scope đi cùng {@see self::scopeWithHolder()}: phép nối đó thêm `users` và `matters`, và một
     * cột trần trùng tên với bảng nối (`created_at`, `deleted_at`, `id`) là lỗi cột mơ hồ trên MariaDB.
     */
    public function scopeAwaitingOffice(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), [
            ClientRequestStatus::New->value,
            ClientRequestStatus::InProgress->value,
        ]);
    }

    /**
     * Gắn `holder_id` — người ĐANG giữ luồng (M13, cột N9; R5) — vào mỗi dòng: người được giao còn
     * tài khoản (chưa xoá mềm), không có thì luật sư phụ trách HIỆN TẠI của vụ.
     *
     * **Cùng người mà đường thông báo báo, không phải một định nghĩa thứ hai.**
     * {@see ReplyToClientRequest} (`notifyHolderOfFollowUp()`) báo `$thread->assignee ??
     * $matter->leadLawyer`; quan hệ {@see self::assignee()} bỏ người đã xoá mềm (`SoftDeletes` của
     * `User`), nên người được giao đã xoá mềm nhường cho luật sư phụ trách. Scope này nói lại ĐÚNG câu
     * đó bằng SQL: nối trái `users` với `deleted_at is null` (bí danh `live_assignee`), nối trái
     * `matters` chưa huỷ (bí danh `holder_matter` — như quan hệ `matter`, vụ đã huỷ không có người phụ
     * trách nào để rơi về), `COALESCE` hai cột. {@see self::holderId()} là bản trong bộ nhớ;
     * `SingleSourceParityTest` ghim cả ba (scope, hàm, thông báo) trên bốn loại luồng.
     *
     * "Bây giờ", không phải "lúc trả lời": người giữ luồng TRONG KỲ là `RequestHolderAt` (R18), đọc
     * lịch sử và KHÔNG bỏ người đã xoá mềm. Hai cách đọc chỉ khác nhau khi một người bị xoá mềm lúc còn
     * giữ một luồng chưa đóng — điều `GuardsStaffOffboarding` chặn.
     *
     * Chọn `client_requests.*` (nếu truy vấn chưa chọn cột nào) cộng `holder_id`. Mọi scope đi cùng
     * phải viết đủ tên bảng (`client_requests.created_at`, `client_requests.status`). Gộp theo người:
     * thay phần chọn bằng {@see self::holderIdSql()} và `groupBy` cùng biểu thức đó.
     */
    public function scopeWithHolder(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($this->qualifyColumn('*'));
        }

        return $query
            ->leftJoin('users as live_assignee', fn (JoinClause $join): JoinClause => $join
                ->on('live_assignee.id', '=', $this->qualifyColumn('assigned_to'))
                ->whereNull('live_assignee.deleted_at'))
            ->leftJoin('matters as holder_matter', fn (JoinClause $join): JoinClause => $join
                ->on('holder_matter.id', '=', $this->qualifyColumn('matter_id'))
                ->whereNull('holder_matter.deleted_at'))
            ->addSelect(DB::raw(self::holderIdSql().' as holder_id'));
    }

    /**
     * Biểu thức SQL của người giữ luồng — CHỈ dùng sau {@see self::scopeWithHolder()} (nó đọc hai bí
     * danh của phép nối đó). Công khai để một truy vấn gộp theo người (`GROUP BY`) dùng lại đúng biểu
     * thức, không viết `COALESCE` lần thứ hai.
     */
    public static function holderIdSql(): string
    {
        return 'COALESCE(live_assignee.id, holder_matter.lead_lawyer_id)';
    }

    /**
     * Luồng `$subject` đang giữ (M13, danh sách yêu cầu trên trang của một người): {@see self::scopeWithHolder()}
     * cộng điều kiện trên ĐÚNG biểu thức người giữ của nó.
     */
    public function scopeHeldBy(Builder $query, User $subject): Builder
    {
        return $query->withHolder()->whereRaw(self::holderIdSql().' = ?', [$subject->getKey()]);
    }

    /**
     * Bản trong bộ nhớ của {@see self::scopeWithHolder()}: người được giao còn tài khoản, không có thì
     * luật sư phụ trách hiện tại — đúng `$thread->assignee ?? $matter->leadLawyer` của đường thông
     * báo, đọc ra id. `ReplyToClientRequest` KHÔNG gọi hàm này: đổi đường thông báo sang id không đem
     * lại gì mà còn phải nạp lại `User`; test đồng nhất ghim hai bên vào nhau.
     */
    public function holderId(): ?int
    {
        $holderId = $this->assignee?->getKey() ?? $this->matter?->lead_lawyer_id;

        return $holderId === null ? null : (int) $holderId;
    }

    /**
     * Yêu cầu khách gửi trong một kỳ (M13, cột P3): `client_requests.created_at` trong `$bounds` —
     * hai cận đủ giờ của `PerformancePeriod::bounds()`. Viết đủ tên bảng (xem `scopeWithHolder()`).
     *
     * @param  array{0: string, 1: string}  $bounds
     */
    public function scopeCreatedBetween(Builder $query, array $bounds): Builder
    {
        return $query->whereBetween($this->qualifyColumn('created_at'), $bounds);
    }

    /**
     * Luồng văn phòng đóng mà KHÔNG trả lời (M13, cột P10; R7): `closed` và `answered_at` rỗng —
     * trùng, khách rút, đã giải quyết ngoài hệ thống. {@see TriageClientRequest::setStatus()} cho đi
     * thẳng Mới → Đã đóng và chỉ ghi `answered_at` khi trạng thái tới `answered`; một luồng đã trả lời
     * (kể cả "đã trả lời qua điện thoại") rồi mới đóng thì mang `answered_at`, nên không vào đây.
     *
     * Bản trong bộ nhớ, có chủ đích (kế hoạch M13, R7): P3 và P10 đã nạp cả tập của kỳ để dựng người
     * giữ và tính trung vị bằng PHP, nên một scope SQL cùng luật sẽ là hình dạng thứ hai không ai gọi.
     * Cần lọc bằng SQL thì thêm scope cạnh hàm này, kèm test đồng nhất hai hình dạng.
     */
    public function isClosedWithoutAnswer(): bool
    {
        return $this->status === ClientRequestStatus::Closed && $this->answered_at === null;
    }

    /**
     * Văn phòng đã trả lời lần đầu không muộn hơn `$cutoff` (M13, cột P3; R19: mốc cắt của kỳ). Trả
     * lời sau mốc cắt không làm tăng số của một kỳ đã đóng. `answered_at` là lần trả lời ĐẦU —
     * `ReplyToClientRequest` ghi `??=`, `TriageClientRequest::setStatus(Answered)` cũng chỉ ghi khi rỗng.
     */
    public function answeredBy(CarbonInterface $cutoff): bool
    {
        return $this->answered_at !== null && $this->answered_at->lte($cutoff);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ClientRequestReply::class, 'request_id')->orderBy('created_at');
    }

    /**
     * Nháp trả lời do AI soạn (M11 R5) — mọi nháp, kể cả đã dùng hay đã bỏ; nháp đang chờ là
     * `->pending()` ({@see ClientRequestReplyDraft}, scope của `IsMcpDraft`).
     */
    public function replyDrafts(): HasMany
    {
        return $this->hasMany(ClientRequestReplyDraft::class, 'request_id');
    }
}
