<?php

namespace App\Support;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
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
 *     mốc thời hạn, nhật ký giai đoạn, yêu cầu của khách, đầu mục danh mục; trả lời của một yêu
 *     cầu đi qua yêu cầu đó) → vụ ghi ở cột `matter_id` của nó; chủ thể là một model TIỀN
 *     (`MONEY_OWNED`, gộp M9 — hợp đồng, đợt, khoản thu, phụ lục) → vụ của hợp đồng, và dòng đó
 *     còn đòi thêm `billing.view`;
 *  3. còn lại → `properties.matter_id` nếu dòng có ghi;
 *  4. không có gì ở trên → dòng không thuộc vụ nào (đăng nhập, người dùng, khách hàng, cấu hình).
 *
 * {@see self::owningMatterId()} nói luật đó bằng PHP cho MỘT dòng (modal); {@see self::scopeVisibleTo()}
 * nói lại bằng SQL cho cả bảng (Filament phân trang trên truy vấn, không lọc được sau khi nạp).
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
     * một lần cho mỗi VỤ VIỆC, không phải mỗi dòng.
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

        return $activities->mapWithKeys(function (Activity $activity) use ($owning, $visibleByMatter, $seesMoney): array {
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
            $rows->where(fn (Builder $q) => $q
                ->where('subject_type', self::MATTER)
                ->whereIn('subject_id', $visibleMatters()));

            foreach (self::MATTER_OWNED as $type => $table) {
                $rows->orWhere(fn (Builder $q) => $q
                    ->where('subject_type', $type)
                    ->whereIn('subject_id', DB::table($table)->select('id')->whereIn('matter_id', $visibleMatters())));
            }

            // Dòng TIỀN (gộp M9): chỉ khi người xem có `billing.view` — không có thì không nhánh
            // nào ở đây thả chúng ra (chúng nằm trong `matterOwnedTypes()`, nên nhánh cuối cũng bỏ).
            if ($seesMoney) {
                foreach (self::MONEY_OWNED as $type => $table) {
                    $rows->orWhere(fn (Builder $q) => $q
                        ->where('subject_type', $type)
                        ->whereIn('subject_id', self::moneyRowsWithMatter($table)
                            ->select("{$table}.id")
                            ->whereIn('contracts.matter_id', $visibleMatters())));
                }
            }

            $rows->orWhere(fn (Builder $q) => $q
                ->where('subject_type', self::CLIENT_REQUEST_REPLY)
                ->whereIn('subject_id', DB::table('client_request_replies')
                    ->join('client_requests', 'client_requests.id', '=', 'client_request_replies.request_id')
                    ->select('client_request_replies.id')
                    ->whereIn('client_requests.matter_id', $visibleMatters())));

            $rows->orWhere(fn (Builder $q) => $q
                ->where(fn (Builder $type) => $type
                    ->whereNull('subject_type')
                    ->orWhereNotIn('subject_type', self::matterOwnedTypes()))
                ->where(fn (Builder $property) => $property
                    ->whereNull('properties->matter_id')
                    ->orWhereIn('properties->matter_id', $visibleMatters())));
        });
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
