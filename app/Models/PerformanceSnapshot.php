<?php

namespace App\Models;

use App\Actions\Performance\BuildPerformanceTrend;
use App\Actions\Schedule\CapturePerformanceSnapshots;
use App\Enums\Confidentiality;
use App\Enums\Permission;
use App\Models\Concerns\RestrictedToClientPortal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Ảnh chụp cuối ngày của số "bây giờ" của MỘT người (M13 R10): N1, N4, N5 và X/Y của N10, như các luật
 * đó trả lời lúc 23:50 (`CapturePerformanceSnapshots`). Đây là bản ghi lịch sử của ĐẦU RA một luật, không
 * phải một luật: số "hôm nay" trên mọi trang luôn tính trực tiếp; ảnh chụp chỉ dùng cho những ngày đã qua
 * ({@see BuildPerformanceTrend}). Không dựng ảnh chụp cho quá khứ: xu hướng bắt đầu từ ngày triển khai.
 *
 * # Hai dòng mỗi người mỗi ngày, và ai đọc được dòng nào (R4) — đóng khi không chắc
 *
 * Tác vụ chụp chạy không người đăng nhập, nên CẢ HAI dòng được tính NGOÀI `Matter::listableBy()`: dòng
 * `normal` là tổng toàn văn phòng của người đó trên vụ thường, dòng `restricted` trên vụ `restricted`.
 * Một dòng chỉ được TRUY VẤN (không phải nạp rồi lọc) khi chứng minh được nó bằng phần giao
 * `listableBy(người xem) ∩ việc của người đó`. {@see self::visibleLevels()} là hàm quyết định DUY NHẤT;
 * {@see self::scopeVisibleTo()} (một người) và {@see self::scopeVisibleToMany()} (cả trang, một truy vấn)
 * chỉ dịch kết quả của nó sang SQL:
 *
 *  - `normal`: người xem có `matter.viewAny` (khi đó nửa `normal` của `listableBy` là MỌI vụ thường),
 *    hoặc người xem là chính người đó và có `matter.view` (người phụ trách luôn có tên trong đội ngũ vụ của
 *    mình). KHÔNG nới thành "người xem có `performance.viewAny`": một người được cấp thẳng quyền đó mà
 *    không có `matter.viewAny` sẽ đọc tổng toàn văn phòng tính ngoài `listableBy`.
 *  - `restricted`: một vụ `restricted` GIẢ do người đó phụ trách qua được `Matter::isListableBy($viewer)`.
 *    Không viết lại "admin, hoặc người phụ trách có `matter.view`": đổi nhánh `restricted` của
 *    `listableBy` thì ảnh chụp đổi theo. Vụ giả không có khoá `deleted_at` nên luôn đi nhánh restricted;
 *    không truy vấn.
 *  - không vế nào: `1 = 0`.
 *
 * Kế toán có `matter.viewAny` nên theo đúng chữ trên được dòng `normal`. Không phải rò rỉ: kế toán không
 * bao giờ tới chỗ đọc ảnh chụp — `UserPolicy::viewPerformance()` chặn ở cả ba trang và hai widget xu hướng.
 *
 * # Cổng khách, policy, nhật ký
 *
 * Khách không bao giờ đọc được ({@see self::applyClientPortalConstraints()} là `1 = 0`);
 * `PerformanceSnapshotPolicy` từ chối mọi khách và mọi thao tác ghi. Alias morph `performance_snapshot`
 * (map nghiêm ngặt), nên `Audit::record(…, $snapshot)` không ném lỗi.
 */
class PerformanceSnapshot extends Model
{
    use RestrictedToClientPortal;

    /** Giữ bao lâu (R10, câu hỏi mở 5 — mặc định của kế hoạch): đủ để so cùng tháng năm trước. */
    public const KEEP_MONTHS = 25;

    protected $fillable = [
        'captured_on',
        'user_id',
        'confidentiality',
        'open_lead_matters',
        'stale_matters',
        'overdue_deadlines',
        'checklist_settled',
        'checklist_total',
    ];

    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
            'confidentiality' => Confidentiality::class,
            'open_lead_matters' => 'integer',
            'stale_matters' => 'integer',
            'overdue_deadlines' => 'integer',
            'checklist_settled' => 'integer',
            'checklist_total' => 'integer',
        ];
    }

    /** Số liệu hiệu suất là dữ liệu nhân sự nội bộ (R14): cổng khách không bao giờ đọc. */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Loại dòng `$viewer` được đọc về `$subject` — xem docblock lớp. `[]` = không gì. Không truy vấn khi
     * vai trò và quyền của `$viewer` đã nạp sẵn (spatie giữ chúng trên model sau lần hỏi đầu).
     *
     * @return list<Confidentiality>
     */
    public static function visibleLevels(User $viewer, User $subject): array
    {
        $gate = Gate::forUser($viewer);
        $levels = [];

        if ($gate->allows(Permission::MatterViewAny->value)
            || ($viewer->is($subject) && $gate->allows(Permission::MatterView->value))) {
            $levels[] = Confidentiality::Normal;
        }

        $restrictedMatterOfSubject = (new Matter)->forceFill([
            'confidentiality' => Confidentiality::Restricted,
            'lead_lawyer_id' => $subject->getKey(),
        ]);

        if ($restrictedMatterOfSubject->isListableBy($viewer)) {
            $levels[] = Confidentiality::Restricted;
        }

        return $levels;
    }

    /**
     * Dòng của `$subject` mà `$viewer` được đọc: đúng {@see self::visibleLevels()}, dịch sang SQL.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $viewer, User $subject): Builder
    {
        return $this->scopeVisibleToMany($query, $viewer, collect([$subject]));
    }

    /**
     * Dòng của mọi người trong `$subjects` mà `$viewer` được đọc, MỘT truy vấn cho cả trang (R11): hợp
     * của `(user_id thuộc nhóm, confidentiality thuộc loại của nhóm)`, người có cùng câu trả lời của
     * {@see self::visibleLevels()} gộp một nhóm. Không ai được đọc gì: `1 = 0`.
     *
     * @param  Builder<self>  $query
     * @param  Collection<int, User>  $subjects
     * @return Builder<self>
     */
    public function scopeVisibleToMany(Builder $query, User $viewer, Collection $subjects): Builder
    {
        $groups = [];

        foreach ($subjects as $subject) {
            $levels = array_map(fn (Confidentiality $level): string => $level->value, self::visibleLevels($viewer, $subject));

            if ($levels !== []) {
                $groups[implode(',', $levels)]['levels'] = $levels;
                $groups[implode(',', $levels)]['users'][] = (int) $subject->getKey();
            }
        }

        if ($groups === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $visible) use ($groups): void {
            foreach ($groups as $group) {
                $visible->orWhere(fn (Builder $one): Builder => $one
                    ->whereIntegerInRaw($this->qualifyColumn('user_id'), $group['users'])
                    ->whereIn($this->qualifyColumn('confidentiality'), $group['levels']));
            }
        });
    }

    /**
     * Dòng chụp từ ngày `$from` tới hết ngày `$to` (hai cận đủ giờ: cột `date` trên SQLite lưu
     * `Y-m-d 00:00:00`, cận trên là ngày trần làm rơi ngày cuối — bài học `RevenueFilters::bounds()`).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCapturedBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereBetween($this->qualifyColumn('captured_on'), [
            $from->copy()->startOfDay()->toDateTimeString(),
            $to->copy()->endOfDay()->toDateTimeString(),
        ]);
    }

    /**
     * Dòng chụp đúng một trong các ngày `$days` (mỗi ngày hai cận đủ giờ, như {@see self::scopeCapturedBetween()}).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCapturedOn(Builder $query, CarbonInterface ...$days): Builder
    {
        return $query->where(function (Builder $any) use ($days): void {
            foreach ($days as $day) {
                $any->orWhere(fn (Builder $one): Builder => $this->scopeCapturedBetween($one, $day, $day));
            }
        });
    }

    /**
     * Một dòng mỗi (người, ngày): tổng các dòng đọc được — `normal` cộng `restricted` khi người xem được
     * đọc cả hai — và `normal_rows`, số dòng `normal` trong đó. `normal_rows = 0` là ngày bị lỡ (tác vụ
     * không chạy): xu hướng để TRỐNG, không vẽ 0 ({@see BuildPerformanceTrend}). Cột ra: `user_id`,
     * `captured_on`, `open_lead_matters`, `stale_matters`, `overdue_deadlines`, `checklist_settled`,
     * `checklist_total`, `normal_rows`.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDailyTotals(Builder $query): Builder
    {
        return $query
            ->select($this->qualifyColumn('user_id'), $this->qualifyColumn('captured_on'))
            ->selectRaw('SUM(open_lead_matters) as open_lead_matters')
            ->selectRaw('SUM(stale_matters) as stale_matters')
            ->selectRaw('SUM(overdue_deadlines) as overdue_deadlines')
            ->selectRaw('SUM(checklist_settled) as checklist_settled')
            ->selectRaw('SUM(checklist_total) as checklist_total')
            ->selectRaw('SUM(CASE WHEN confidentiality = ? THEN 1 ELSE 0 END) as normal_rows', [Confidentiality::Normal->value])
            ->groupBy($this->qualifyColumn('user_id'), $this->qualifyColumn('captured_on'));
    }

    /**
     * Dòng `restricted` của ngày `$day` của những người trong `$people` — để lần chụp lại trong ngày xoá
     * dòng của người nay đã về 0 ở mọi số (dòng `restricted` chỉ có khi có số > 0).
     *
     * @param  Builder<self>  $query
     * @param  list<int>  $people
     * @return Builder<self>
     */
    public function scopeRestrictedOf(Builder $query, CarbonInterface $day, array $people): Builder
    {
        return $this->scopeCapturedBetween($query, $day, $day)
            ->where($this->qualifyColumn('confidentiality'), Confidentiality::Restricted->value)
            ->whereIntegerInRaw($this->qualifyColumn('user_id'), $people);
    }

    /**
     * Dòng cũ hơn {@see self::KEEP_MONTHS} tháng tính tới hôm nay: ngày chụp TRƯỚC ngày {@see self::keptFrom()}.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('captured_on'), '<', self::keptFrom()->toDateTimeString());
    }

    /** Ngày chụp cũ nhất còn giữ: hôm nay lùi {@see self::KEEP_MONTHS} tháng (ngày 31 không lật sang tháng sau). */
    public static function keptFrom(): CarbonImmutable
    {
        return today()->toImmutable()->subMonthsNoOverflow(self::KEEP_MONTHS);
    }

    /** Giá trị lưu của một ngày chụp — đúng dạng cast `date` ghi (`Y-m-d 00:00:00`), cho `upsert()` (không qua cast). */
    public static function storedDay(CarbonInterface $day): string
    {
        return (new self)->fromDateTime($day->copy()->startOfDay());
    }
}
