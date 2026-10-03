<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
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
 *     lời của một yêu cầu đi qua yêu cầu đó) → vụ ghi ở cột `matter_id` của nó;
 *  3. còn lại → `properties.matter_id` nếu dòng có ghi;
 *  4. không có gì ở trên → dòng không thuộc vụ nào (đăng nhập, người dùng, khách hàng, cấu hình).
 *
 * {@see self::owningMatterId()} nói luật đó bằng PHP cho MỘT dòng (modal); {@see self::scopeVisibleTo()}
 * nói lại bằng SQL cho cả bảng (Filament phân trang trên truy vấn, không lọc được sau khi nạp).
 * {@see self::scopeOwnedBy()} (M7 Task 8, tab "Nhật ký" của một vụ) dùng chung đúng câu SQL đó
 * qua {@see self::whereOwnedByAny()}, chỉ với một vụ thay cho tập vụ người xem thấy được.
 * Hai cách nói đọc thẳng các bảng con qua `DB::table()` — không qua model — để `SoftDeletes` và
 * `ClientPortalScope` không làm một dòng con đã xoá mềm "mất chủ".
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
        // M7 Task 8: dòng `communication_logged` / `communication_log_deleted`.
        'communication_log' => 'communication_logs',
    ];

    private const MATTER = 'matter';

    private const CLIENT_REQUEST_REPLY = 'client_request_reply';

    public static function canView(?User $viewer, Activity $activity): bool
    {
        if ($viewer === null) {
            return false;
        }

        if (self::isAdmin($viewer)) {
            return true;
        }

        $matterId = self::owningMatterId($activity);

        if ($matterId === null) {
            // Không quy được về vụ nào: chỉ thả khi dòng THẬT SỰ không thuộc vụ nào.
            return ! self::claimsAMatter($activity);
        }

        $matter = Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->find($matterId);

        return $matter !== null && Gate::forUser($viewer)->allows('view', $matter);
    }

    /**
     * Vụ việc sở hữu dòng này, hoặc `null` khi không quy được (xem docblock lớp, bốn bước).
     */
    public static function owningMatterId(Activity $activity): ?int
    {
        $type = $activity->subject_type;
        $id = $activity->subject_id;

        if ($type === self::MATTER && $id !== null) {
            return (int) $id;
        }

        if (isset(self::MATTER_OWNED[$type]) && $id !== null) {
            $matterId = DB::table(self::MATTER_OWNED[$type])->where('id', $id)->value('matter_id');

            return $matterId === null ? null : (int) $matterId;
        }

        if ($type === self::CLIENT_REQUEST_REPLY && $id !== null) {
            $matterId = DB::table('client_request_replies')
                ->join('client_requests', 'client_requests.id', '=', 'client_request_replies.request_id')
                ->where('client_request_replies.id', $id)
                ->value('client_requests.matter_id');

            return $matterId === null ? null : (int) $matterId;
        }

        $fromProperties = $activity->properties?->get('matter_id');

        return is_numeric($fromProperties) ? (int) $fromProperties : null;
    }

    /**
     * Lọc truy vấn bảng nhật ký cho `$viewer` — cùng luật với {@see self::canView()}.
     *
     * Hai phần: các dòng quy được về một vụ `$viewer` xem được ({@see self::whereOwnedByAny()},
     * cùng câu mà tab "Nhật ký" của một vụ dùng), cộng các dòng không thuộc vụ nào (bước 4).
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

        $query->where(function (Builder $rows) use ($visibleMatters): void {
            self::whereOwnedByAny($rows, $visibleMatters);

            // Bước 4: dòng không thuộc vụ nào — chủ thể không phải model của vụ việc VÀ không có
            // `properties.matter_id`.
            $rows->orWhere(fn (Builder $q) => $q
                ->where(fn (Builder $type) => $type
                    ->whereNull('subject_type')
                    ->orWhereNotIn('subject_type', self::matterOwnedTypes()))
                ->whereNull('properties->matter_id'));
        });
    }

    /**
     * Chỉ các dòng mà {@see self::owningMatterId()} quy về đúng `$matter` (M7 Task 8, tab "Nhật
     * ký" của riêng vụ việc). Cùng MỘT câu SQL với nửa "thuộc vụ xem được" của
     * {@see self::scopeVisibleTo()}, chỉ khác tập vụ việc: ở đây là một vụ. Quyền ĐỌC tab không hỏi
     * ở đây — đó là việc của `MatterPolicy::viewActivityLog`.
     *
     * @param  Builder<Activity>  $query
     */
    public static function scopeOwnedBy(Builder $query, Matter $matter): void
    {
        $matterId = (int) $matter->getKey();

        $query->where(fn (Builder $rows) => self::whereOwnedByAny(
            $rows,
            fn () => Matter::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->withTrashed()
                ->whereKey($matterId)
                ->select('matters.id'),
        ));
    }

    /**
     * Ba bước đầu của luật (docblock lớp) bằng SQL, nối bằng `OR`, cho một TẬP vụ việc cho trước
     * (`$matterIds` trả về một truy vấn `select matters.id`): chủ thể là vụ đó; chủ thể là model
     * con của vụ đó; hoặc chủ thể không phải model của vụ việc và `properties.matter_id` là vụ đó.
     *
     * @param  Builder<Activity>  $rows
     * @param  Closure(): Builder<Matter>  $matterIds
     */
    private static function whereOwnedByAny(Builder $rows, Closure $matterIds): void
    {
        $rows->where(fn (Builder $q) => $q
            ->where('subject_type', self::MATTER)
            ->whereIn('subject_id', $matterIds()));

        foreach (self::MATTER_OWNED as $type => $table) {
            $rows->orWhere(fn (Builder $q) => $q
                ->where('subject_type', $type)
                ->whereIn('subject_id', DB::table($table)->select('id')->whereIn('matter_id', $matterIds())));
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
        return [self::MATTER, self::CLIENT_REQUEST_REPLY, ...array_keys(self::MATTER_OWNED)];
    }

    private static function isAdmin(User $viewer): bool
    {
        return $viewer->hasRole(Role::Admin->value);
    }
}
