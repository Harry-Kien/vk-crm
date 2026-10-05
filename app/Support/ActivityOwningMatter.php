<?php

namespace App\Support;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * Một dòng nhật ký thuộc về VỤ VIỆC nào (final review M6.5, X1 = A-C1/C-C1), và người đang xem
 * trang Nhật ký hệ thống có được thấy dòng đó hay không.
 *
 * Trước bản sửa này `ActivityLogPage` liệt kê MỌI dòng cho mọi người có `auditLog.view`, và modal
 * "Xem chi tiết" không hỏi gì — một trưởng phòng đọc được tiêu đề, tóm tắt, tên/địa chỉ đương
 * sự, lý do ghi đè xung đột… của một vụ `restricted` mà chính `MatterPolicy::view` từ chối họ.
 *
 * # Vụ việc sở hữu một dòng — MỘT luật, hai cách nói
 *
 *  1. chủ thể là một `Matter` → vụ đó;
 *  2. chủ thể là một model sống bên trong một vụ việc (`MATTER_OWNED` — bên đương sự, tài liệu,
 *     mốc thời hạn, nhật ký giai đoạn, yêu cầu của khách, đầu mục danh mục, nhật ký liên lạc; trả
 *     lời của một yêu cầu đi qua yêu cầu đó) → vụ ghi ở cột `matter_id` của nó; chủ thể là một
 *     model TIỀN (`MONEY_OWNED`, gộp M9 — hợp đồng, đợt, khoản thu, phụ lục) → vụ của hợp đồng, và
 *     dòng đó còn đòi thêm `billing.view`;
 *  3. còn lại → `properties.matter_id` nếu dòng có ghi;
 *  4. không có gì ở trên → dòng không thuộc vụ nào (đăng nhập, người dùng, khách hàng, cấu hình).
 *
 * {@see self::owningMatterId()} nói luật đó bằng PHP cho MỘT dòng (modal); {@see self::scopeVisibleTo()}
 * nói lại bằng SQL cho cả bảng (Filament phân trang trên truy vấn, không lọc được sau khi nạp).
 * {@see self::scopeOwnedBy()} (M7 Task 8, tab "Nhật ký" của một vụ) dùng chung đúng câu SQL đó
 * qua {@see self::whereOwnedByAny()}, chỉ với một vụ thay cho tập vụ người xem thấy được — và
 * cùng cổng `billing.view` cho dòng TIỀN (gộp M7 vào `main`).
 * Hai cách nói đọc thẳng các bảng con qua `DB::table()` — không qua model — để `SoftDeletes` và
 * `ClientPortalScope` không làm một dòng con đã xoá mềm "mất chủ".
 *
 * Nửa PHP chạy THEO LÔ (`owningMatterIds()`/`canViewMany()`, final review wave 2 M-2): một trang
 * nhật ký hỏi luật này cho mọi dòng để vẽ nút, và hỏi riêng từng dòng là vài truy vấn mỗi dòng.
 *
 * # Ai thấy dòng nào
 *
 * Admin: mọi dòng, kể cả dòng không quy được về vụ nào. Người khác:
 *  - dòng quy được về một vụ → chỉ khi `Gate::forUser($viewer)->allows('view', $matter)` (bảng
 *    dùng `Matter::scopeListableBy`, đúng truy vấn mà nhánh EXISTS của `MatterPolicy::view` chạy);
 *  - chủ thể là một model thuộc vụ việc nhưng KHÔNG quy được về vụ nào (dòng con đã bị xoá cứng),
 *    hoặc `properties.matter_id` trỏ tới một vụ không còn → ẩn, vì không ai chứng minh được vụ đó
 *    không phải `restricted`;
 *  - dòng không thuộc vụ nào → giữ nguyên như trước.
 *
 * # Dòng của một bản ghi tiếp nhận (rà soát cuối M10, vòng sửa 1 — FI1)
 *
 * Chủ thể `intake_request` không phải model của vụ việc — một lần liên hệ chưa chuyển đổi không thuộc
 * vụ nào, và các dòng đó vẫn rơi vào bước 3/4 ở trên. Nhưng các dòng ấy mang tên người liên hệ và tên
 * các bên (`conflict_check_run`, kể cả lượt chuyển đổi bị chặn mà Task 7 gắn vào bản ghi), lý do ghi đè
 * Đỏ… Thêm MỘT cổng, chồng lên luật trên như cổng `billing.view` của dòng TIỀN: dòng chủ thể
 * `intake_request` chỉ hiện cho người xem được CHÍNH bản ghi đó — đúng định nghĩa
 * `IntakeRequest::scopeVisibleTo()`/`isVisibleTo()` (SPEC §5: mọi màn hình đọc bản ghi phải đi qua định
 * nghĩa này). Với người có `auditLog.view` (trưởng phòng, admin — cả hai có `intake.viewAny`), cổng đó
 * chỉ còn vế `restricted`: bản đã chuyển thành vụ `restricted` mà họ không xem được, và các bản đã gộp
 * vào nó (`merge_chain_matter_id`), biến mất khỏi trang Nhật ký hệ thống. Cổng KHÔNG kéo các dòng đó
 * vào tab "Nhật ký" của vụ ({@see self::scopeOwnedBy()}): lý do ghi đè Đỏ của bản ghi là của
 * `intake.viewAny` (R8), không của mọi người trong đội vụ.
 */
final class ActivityOwningMatter
{
    /**
     * Tên morph (xem `Relation::enforceMorphMap` ở `AppServiceProvider`) => bảng mang `matter_id`.
     *
     * @var array<string, string>
     */
    private const MATTER_OWNED = [
        'matter_party' => 'matter_parties',
        'document' => 'documents',
        'deadline' => 'deadlines',
        'stage_log' => 'stage_logs',
        'client_request' => 'client_requests',
        'matter_checklist_item' => 'matter_checklist_items',
        // M7 Task 8: dòng `communication_logged` / `communication_log_deleted`.
        'communication_log' => 'communication_logs',
    ];

    /**
     * Gộp M9: tên morph của bốn model TIỀN => bảng của chúng. Không mang `matter_id` — vụ việc đọc
     * qua hợp đồng ({@see self::moneyRowsWithMatter()}). Một dòng tiền chỉ hiện cho người vừa thấy
     * vụ vừa có `billing.view` (cùng hai điều kiện `ChecksBillingAccess::canSeeBilling()`), vì SPEC
     * §5 (bổ sung M9) tách "thấy vụ" khỏi "thấy tiền của vụ" — trưởng phòng không thấy tiền của vụ
     * `restricted`, kể cả ở nhật ký. Trước khi gộp, các dòng này rơi vào nhánh "không thuộc vụ nào"
     * (đa số không ghi `properties.matter_id`) và hiện cho mọi người có `auditLog.view`.
     *
     * @var array<string, string>
     */
    private const MONEY_OWNED = [
        'contract' => 'contracts',
        'instalment' => 'instalments',
        'payment' => 'payments',
        'contract_amendment' => 'contract_amendments',
    ];

    private const MATTER = 'matter';

    private const CLIENT_REQUEST_REPLY = 'client_request_reply';

    /** Rà soát cuối M10, FI1 — xem docblock lớp, mục "Dòng của một bản ghi tiếp nhận". */
    private const INTAKE_REQUEST = 'intake_request';

    public static function canView(?User $viewer, Activity $activity): bool
    {
        return self::canViewMany($viewer, [$activity])[$activity->getKey()] ?? false;
    }

    /**
     * {@see self::canView()} cho NHIỀU dòng một lúc — final review wave 2, M-2. Trang nhật ký hỏi
     * luật này cho từng dòng để vẽ nút "Xem chi tiết"; hỏi riêng từng dòng là vài truy vấn mỗi
     * dòng (dòng con → vụ việc → Gate). Ở đây: một truy vấn cho mỗi LOẠI dòng con, một truy vấn
     * nạp mọi vụ việc (kèm `team`, để `MatterPolicy::view` đi đường trong bộ nhớ
     * `Matter::isListableBy()` — cùng câu trả lời với đường EXISTS, xem docblock hàm đó), rồi Gate
     * một lần cho mỗi VỤ VIỆC, không phải mỗi dòng; cộng MỘT truy vấn cho mọi bản ghi tiếp nhận có mặt
     * (cổng FI1, docblock lớp).
     *
     * @param  iterable<Activity>  $activities
     * @return array<int|string, bool> Khoá theo id của dòng nhật ký.
     */
    public static function canViewMany(?User $viewer, iterable $activities): array
    {
        $activities = collect($activities);

        if ($viewer === null || self::isAdmin($viewer)) {
            $verdict = $viewer !== null;

            return $activities->mapWithKeys(fn (Activity $activity): array => [$activity->getKey() => $verdict])->all();
        }

        $owning = self::owningMatterIds($activities);

        $matterIds = array_values(array_unique(array_filter($owning, fn (?int $id): bool => $id !== null)));

        $gate = Gate::forUser($viewer);

        $visibleByMatter = $matterIds === []
            ? collect()
            : Matter::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->withTrashed()
                ->with('team')
                ->whereIn('id', $matterIds)
                ->get()
                ->mapWithKeys(fn (Matter $matter): array => [$matter->getKey() => $gate->allows('view', $matter)]);

        $seesMoney = self::seesMoney($viewer);

        $intakeIds = $activities->where('subject_type', self::INTAKE_REQUEST)->pluck('subject_id')->filter()->unique()->values();

        $visibleIntakeIds = $intakeIds->isEmpty()
            ? []
            : array_flip(self::visibleIntakes($viewer)->whereKey($intakeIds->all())->pluck('intake_requests.id')->map(fn (mixed $id): int => (int) $id)->all());

        return $activities->mapWithKeys(function (Activity $activity) use ($owning, $visibleByMatter, $seesMoney, $visibleIntakeIds): array {
            $matterId = $owning[$activity->getKey()] ?? null;

            // Không quy được về vụ nào: chỉ thả khi dòng THẬT SỰ không thuộc vụ nào. Quy được
            // nhưng vụ không còn (xoá cứng) → không có `view` nào để cho.
            $allowed = $matterId === null
                ? ! self::claimsAMatter($activity)
                : (bool) ($visibleByMatter[$matterId] ?? false);

            // Dòng TIỀN (gộp M9): thấy vụ chưa đủ, còn phải có `billing.view`.
            if (isset(self::MONEY_OWNED[$activity->subject_type]) && ! $seesMoney) {
                $allowed = false;
            }

            // Dòng của một bản ghi tiếp nhận (FI1): phải xem được chính bản ghi đó.
            if ($activity->subject_type === self::INTAKE_REQUEST && ! isset($visibleIntakeIds[(int) $activity->subject_id])) {
                $allowed = false;
            }

            return [$activity->getKey() => $allowed];
        })->all();
    }

    /**
     * Vụ việc sở hữu dòng này, hoặc `null` khi không quy được (xem docblock lớp, bốn bước).
     */
    public static function owningMatterId(Activity $activity): ?int
    {
        return self::owningMatterIds([$activity])[$activity->getKey()] ?? null;
    }

    /**
     * {@see self::owningMatterId()} theo lô: một truy vấn cho mỗi loại dòng con có mặt.
     *
     * @param  iterable<Activity>  $activities
     * @return array<int|string, int|null> Khoá theo id của dòng nhật ký.
     */
    public static function owningMatterIds(iterable $activities): array
    {
        $activities = collect($activities);
        $result = [];

        $childMatterIds = [];

        foreach (self::MATTER_OWNED as $type => $table) {
            $ids = $activities->where('subject_type', $type)->pluck('subject_id')->filter()->unique()->values();

            if ($ids->isNotEmpty()) {
                $childMatterIds[$type] = DB::table($table)->whereIn('id', $ids->all())->pluck('matter_id', 'id')->all();
            }
        }

        foreach (self::MONEY_OWNED as $type => $table) {
            $ids = $activities->where('subject_type', $type)->pluck('subject_id')->filter()->unique()->values();

            if ($ids->isNotEmpty()) {
                $childMatterIds[$type] = self::moneyRowsWithMatter($table)
                    ->whereIn("{$table}.id", $ids->all())
                    ->pluck('contracts.matter_id', "{$table}.id")
                    ->all();
            }
        }

        $replyIds = $activities->where('subject_type', self::CLIENT_REQUEST_REPLY)->pluck('subject_id')->filter()->unique()->values();

        if ($replyIds->isNotEmpty()) {
            $childMatterIds[self::CLIENT_REQUEST_REPLY] = DB::table('client_request_replies')
                ->join('client_requests', 'client_requests.id', '=', 'client_request_replies.request_id')
                ->whereIn('client_request_replies.id', $replyIds->all())
                ->pluck('client_requests.matter_id', 'client_request_replies.id')
                ->all();
        }

        foreach ($activities as $activity) {
            $type = $activity->subject_type;
            $id = $activity->subject_id;

            if ($type === self::MATTER && $id !== null) {
                $result[$activity->getKey()] = (int) $id;

                continue;
            }

            if ((isset(self::MATTER_OWNED[$type]) || isset(self::MONEY_OWNED[$type]) || $type === self::CLIENT_REQUEST_REPLY) && $id !== null) {
                $matterId = $childMatterIds[$type][$id] ?? null;
                $result[$activity->getKey()] = $matterId === null ? null : (int) $matterId;

                continue;
            }

            $fromProperties = $activity->properties?->get('matter_id');

            $result[$activity->getKey()] = is_numeric($fromProperties) ? (int) $fromProperties : null;
        }

        return $result;
    }

    /**
     * Lọc truy vấn bảng nhật ký cho `$viewer` — cùng luật với {@see self::canView()}.
     *
     * Hai phần: các dòng quy được về một vụ `$viewer` xem được ({@see self::whereOwnedByAny()},
     * cùng câu mà tab "Nhật ký" của một vụ dùng; dòng TIỀN chỉ khi có `billing.view`), cộng các dòng
     * không thuộc vụ nào (bước 4). Chồng lên cả hai: dòng chủ thể `intake_request` chỉ khi `$viewer` xem
     * được bản ghi đó (FI1, docblock lớp).
     *
     * @param  Builder<Activity>  $query
     */
    public static function scopeVisibleTo(Builder $query, User $viewer): void
    {
        if (self::isAdmin($viewer)) {
            return;
        }

        $visibleMatters = fn () => Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->listableBy($viewer)
            ->select('matters.id');

        $seesMoney = self::seesMoney($viewer);

        $query->where(function (Builder $rows) use ($visibleMatters, $seesMoney): void {
            self::whereOwnedByAny($rows, $visibleMatters, $seesMoney);

            // Bước 4: dòng không thuộc vụ nào — chủ thể không phải model của vụ việc VÀ không có
            // `properties.matter_id`.
            $rows->orWhere(fn (Builder $q) => $q
                ->where(fn (Builder $type) => $type
                    ->whereNull('subject_type')
                    ->orWhereNotIn('subject_type', self::matterOwnedTypes()))
                ->whereNull('properties->matter_id'));
        });

        // Dòng của một bản ghi tiếp nhận (FI1, docblock lớp): chồng lên MỌI nhánh ở trên — phải xem được
        // chính bản ghi đó.
        self::whereIntakeRowVisibleTo($query, $viewer);
    }

    /**
     * Cổng FI1 (docblock lớp) dạng SQL: dòng chủ thể `intake_request` chỉ khi `$viewer` xem được chính bản
     * ghi đó; dòng khác giữ nguyên. Một hàm cho cả {@see self::scopeVisibleTo()} (trang Nhật ký hệ thống) và
     * {@see self::scopeOwnedByVisibleMatters()} (M13: N11, P6) — gộp `main` vào làn M13. Bản trong bộ nhớ
     * là nhánh `INTAKE_REQUEST` của {@see self::canViewMany()}.
     *
     * @param  Builder<Activity>  $query
     */
    private static function whereIntakeRowVisibleTo(Builder $query, User $viewer): void
    {
        $query->where(fn (Builder $rows) => $rows
            ->whereNull('subject_type')
            ->orWhere('subject_type', '!=', self::INTAKE_REQUEST)
            ->orWhereIn('subject_id', self::visibleIntakes($viewer)->select('intake_requests.id')));
    }

    /**
     * Các bản ghi tiếp nhận `$viewer` xem được — đúng `IntakeRequest::scopeVisibleTo()`, kể cả bản đã xoá
     * mềm (một dòng nhật ký sống lâu hơn trạng thái của bản ghi), bỏ `ClientPortalScope` như mọi truy
     * vấn của lớp này.
     *
     * @return Builder<IntakeRequest>
     */
    private static function visibleIntakes(User $viewer): Builder
    {
        return IntakeRequest::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->visibleTo($viewer);
    }

    /**
     * Chỉ các dòng mà {@see self::owningMatterId()} quy về đúng `$matter` (M7 Task 8, tab "Nhật
     * ký" của riêng vụ việc). Cùng MỘT câu SQL với nửa "thuộc vụ xem được" của
     * {@see self::scopeVisibleTo()}, chỉ khác tập vụ việc: ở đây là một vụ. Quyền ĐỌC tab không hỏi
     * ở đây — đó là việc của `MatterPolicy::viewActivityLog` (nền là `view()`, nên người xem tab
     * luôn thấy được vụ).
     *
     * **`$viewer` (gộp M7 vào `main`).** Dòng TIỀN (`MONEY_OWNED`, M9) thuộc về vụ của hợp đồng,
     * nhưng SPEC §5 (bổ sung M9) tách "thấy vụ" khỏi "thấy tiền của vụ": tab chỉ thả chúng ra khi
     * người xem có `billing.view` — cùng cổng mà {@see self::canViewMany()} và
     * {@see self::scopeVisibleTo()} áp ở trang Nhật ký hệ thống. Không có tham số này, một luật sư
     * phụ trách không có `billing.view` đọc được dòng hợp đồng/khoản thu của vụ mình ở tab, dù tab
     * "Hợp đồng và thanh toán" đóng với họ.
     *
     * @param  Builder<Activity>  $query
     */
    public static function scopeOwnedBy(Builder $query, Matter $matter, User $viewer): void
    {
        $matterId = (int) $matter->getKey();

        $seesMoney = self::isAdmin($viewer) || self::seesMoney($viewer);

        $query->where(fn (Builder $rows) => self::whereOwnedByAny(
            $rows,
            fn () => Matter::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->withTrashed()
                ->whereKey($matterId)
                ->select('matters.id'),
            $seesMoney,
        ));
    }

    /**
     * Chỉ các dòng QUY ĐƯỢC về một vụ trong `Matter::listableBy($viewer)` (M13: cột N11 "Thao tác hồ sơ
     * gần nhất" và P6 "Giấy tờ đã duyệt" đếm trên đúng tập này) — ba bước đầu của luật (docblock lớp)
     * qua CÙNG câu SQL {@see self::whereOwnedByAny()} mà {@see self::scopeVisibleTo()} và
     * {@see self::scopeOwnedBy()} dùng, chỉ khác tập vụ.
     *
     * Ba chỗ khác `scopeVisibleTo()`, có chủ đích:
     *  - **không có bước 4**: dòng không thuộc vụ nào (đăng nhập, người dùng, cấu hình) không bao giờ
     *    tính — "thao tác hồ sơ" là thao tác trên một vụ việc;
     *  - **không có lối tắt admin**: admin cũng chỉ nhận dòng quy được về một vụ (admin thấy mọi vụ
     *    chưa huỷ qua `listableBy()`, nên chỉ mất những dòng không thuộc vụ nào, như dòng đăng nhập);
     *  - **không `withTrashed()`**: dòng của một vụ ĐÃ HUỶ không tính, khác trang Nhật ký hệ thống (nơi
     *    dòng đó vẫn hiện để admin đọc lại lịch sử). Mọi con số khác của M13 lấy tập gốc là
     *    `Matter::query()->listableBy($viewer)` — `SoftDeletes` của `Matter` tự bỏ vụ đã huỷ (P1: "vụ đã
     *    huỷ tự rơi") — và N11/P6 đi cùng luật đó, để "lần thao tác gần nhất" hay "số lần duyệt" không
     *    đếm việc trên một vụ mà mọi cột khác của cùng dòng đã bỏ. `SingleSourceParityTest` ghim lựa chọn
     *    này cạnh dòng tương ứng của trang nhật ký.
     *
     * Dòng TIỀN chỉ khi người xem là admin hoặc có `billing.view` — đúng như `scopeOwnedBy()`. Bỏ
     * `ClientPortalScope` của `Matter` như `scopeVisibleTo()`.
     *
     * Cổng bản ghi tiếp nhận của M10 (FI1, docblock lớp) chồng lên như ở `scopeVisibleTo()`, qua cùng
     * hàm {@see self::whereIntakeRowVisibleTo()}: dòng chủ thể `intake_request` mang `properties.matter_id`
     * của một vụ xem được chỉ tính khi người xem xem được CHÍNH bản ghi đó (`SingleSourceParityTest`,
     * "drops an intake_request row … (M10 gate)"). Cổng này áp cho cả admin (admin thấy mọi bản ghi).
     *
     * @param  Builder<Activity>  $query
     */
    public static function scopeOwnedByVisibleMatters(Builder $query, User $viewer): void
    {
        $seesMoney = self::isAdmin($viewer) || self::seesMoney($viewer);

        $query->where(fn (Builder $rows) => self::whereOwnedByAny(
            $rows,
            fn () => Matter::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->listableBy($viewer)
                ->select('matters.id'),
            $seesMoney,
        ));

        self::whereIntakeRowVisibleTo($query, $viewer);
    }

    /**
     * Dòng nhật ký của sự kiện `$event` ghi trong `$bounds` — hai cận đủ giờ của
     * `PerformancePeriod::bounds()` (M13, cột P6: `ReviewChecklistItem::AUDIT_EVENT`). Ghép với
     * {@see self::scopeOwnedByVisibleMatters()} để chỉ đếm dòng của vụ người xem thấy được; hàm này
     * không tự quyết tầm nhìn.
     *
     * @param  Builder<Activity>  $query
     * @param  array{0: string, 1: string}  $bounds
     */
    public static function scopeEventsWithin(Builder $query, string $event, array $bounds): void
    {
        $query
            ->where($query->qualifyColumn('event'), $event)
            ->whereBetween($query->qualifyColumn('created_at'), $bounds);
    }

    /**
     * Ba bước đầu của luật (docblock lớp) bằng SQL, nối bằng `OR`, cho một TẬP vụ việc cho trước
     * (`$matterIds` trả về một truy vấn `select matters.id`): chủ thể là vụ đó; chủ thể là model
     * con của vụ đó (dòng TIỀN chỉ khi `$includeMoney`); hoặc chủ thể không phải model của vụ việc
     * và `properties.matter_id` là vụ đó.
     *
     * @param  Builder<Activity>  $rows
     * @param  Closure(): Builder<Matter>  $matterIds
     */
    private static function whereOwnedByAny(Builder $rows, Closure $matterIds, bool $includeMoney): void
    {
        $rows->where(fn (Builder $q) => $q
            ->where('subject_type', self::MATTER)
            ->whereIn('subject_id', $matterIds()));

        foreach (self::MATTER_OWNED as $type => $table) {
            $rows->orWhere(fn (Builder $q) => $q
                ->where('subject_type', $type)
                ->whereIn('subject_id', DB::table($table)->select('id')->whereIn('matter_id', $matterIds())));
        }

        // Dòng TIỀN (gộp M9): chỉ khi người xem có `billing.view` — không có thì không nhánh nào
        // ở đây thả chúng ra (chúng nằm trong `matterOwnedTypes()`, nên nhánh `properties.matter_id`
        // dưới đây và bước 4 của `scopeVisibleTo()` cũng bỏ).
        if ($includeMoney) {
            foreach (self::MONEY_OWNED as $type => $table) {
                $rows->orWhere(fn (Builder $q) => $q
                    ->where('subject_type', $type)
                    ->whereIn('subject_id', self::moneyRowsWithMatter($table)
                        ->select("{$table}.id")
                        ->whereIn('contracts.matter_id', $matterIds())));
            }
        }

        $rows->orWhere(fn (Builder $q) => $q
            ->where('subject_type', self::CLIENT_REQUEST_REPLY)
            ->whereIn('subject_id', DB::table('client_request_replies')
                ->join('client_requests', 'client_requests.id', '=', 'client_request_replies.request_id')
                ->select('client_request_replies.id')
                ->whereIn('client_requests.matter_id', $matterIds())));

        $rows->orWhere(fn (Builder $q) => $q
            ->where(fn (Builder $type) => $type
                ->whereNull('subject_type')
                ->orWhereNotIn('subject_type', self::matterOwnedTypes()))
            ->whereIn('properties->matter_id', $matterIds()));
    }

    /**
     * Dòng này có KHẲNG ĐỊNH thuộc về một vụ việc không — chủ thể là một model của vụ việc, hoặc
     * `properties.matter_id` có ghi. Dùng khi {@see self::owningMatterId()} trả `null`.
     */
    private static function claimsAMatter(Activity $activity): bool
    {
        return in_array($activity->subject_type, self::matterOwnedTypes(), true)
            || $activity->properties?->get('matter_id') !== null;
    }

    /** @return list<string> */
    private static function matterOwnedTypes(): array
    {
        return [self::MATTER, self::CLIENT_REQUEST_REPLY, ...array_keys(self::MATTER_OWNED), ...array_keys(self::MONEY_OWNED)];
    }

    /**
     * Bảng tiền `$table` nối tới `contracts` — nơi DUY NHẤT một hàng tiền mang `matter_id`. Đọc
     * thẳng `DB::table()`, cùng lý do với các bảng con khác (docblock lớp).
     */
    private static function moneyRowsWithMatter(string $table): QueryBuilder
    {
        $query = DB::table($table);

        if ($table === 'payments') {
            $query->join('instalments', 'instalments.id', '=', 'payments.instalment_id');
        }

        if ($table !== 'contracts') {
            $query->join('contracts', 'contracts.id', '=', $table === 'payments' ? 'instalments.contract_id' : "{$table}.contract_id");
        }

        return $query;
    }

    /** Cùng quyền mà `ChecksBillingAccess::canSeeBilling()` đòi trước khi hỏi tầm nhìn vụ việc. */
    private static function seesMoney(User $viewer): bool
    {
        return $viewer->can(Permission::BillingView->value);
    }

    private static function isAdmin(User $viewer): bool
    {
        return $viewer->hasRole(Role::Admin->value);
    }
}
