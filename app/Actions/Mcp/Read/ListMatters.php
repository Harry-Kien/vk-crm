<?php

namespace App\Actions\Mcp\Read;

use App\Actions\Mcp\McpMatterScope;
use App\Actions\Mcp\Read\Concerns\LoadsWithoutPortalScope;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Đọc cho tool `search_matters` (kế hoạch M11, bảng tool 4 [DC:50]): danh sách vụ trong tập
 * `McpMatterScope` của `$actor` (R3), lọc theo {@see MatterListFilters}, mới nhất trước (`id` giảm
 * dần), phân trang theo khoá.
 *
 * - **Chữ**: điều kiện `id IN (…)` dựng từ CHÍNH truy vấn tìm của `search` ({@see SearchRecords::matchingMatterIds()},
 *   tức `SearchMatters::matching()` của M7 Task 9 trên bốn nguồn R10 cho phép). Một định nghĩa tìm,
 *   không phải hai.
 * - **Loại vụ**: bằng mã (`DS`) hoặc bằng cả tên của loại vụ, không khớp một phần (bảng tool hiện
 *   tên loại vụ, không hiện mã). "Bằng" theo collation của cột: MariaDB không phân biệt hoa/thường.
 * - **Giai đoạn**: bằng mã `matters.stage`.
 * - **Vụ tôi phụ trách** (`mine`): `lead_lawyer_id` là người gọi — thành viên đội không tính.
 * - **Đang mở / đã kết thúc**: `Matter::scopeOpen()` / `scopeClosed()`, định nghĩa duy nhất.
 *
 * `limit` bị kẹp vào [1, {@see self::MAX_LIMIT}] — quá 25 thì lấy 25, không báo lỗi. `$afterId` là
 * id vụ cuối của trang trước (từ cursor đã giải mã ở tool); trang kế chỉ chứa id nhỏ hơn. Truy vấn
 * lấy `limit + 1` dòng để biết còn trang sau mà không đếm.
 *
 * Mỗi dòng kèm mốc gần nhất ({@see OpenDeadlines}, một truy vấn cho cả trang).
 */
final class ListMatters
{
    use LoadsWithoutPortalScope;

    public const DEFAULT_LIMIT = 10;

    public const MAX_LIMIT = 25;

    public function __construct(
        private readonly McpMatterScope $scope,
        private readonly SearchRecords $search,
        private readonly OpenDeadlines $deadlines,
    ) {}

    public function handle(User $actor, MatterListFilters $filters, int $limit = self::DEFAULT_LIMIT, ?int $afterId = null): MatterListPage
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $rows = $this->filtered($actor, $filters)
            ->when($afterId !== null, fn (Builder $query) => $query->where('matters.id', '<', $afterId))
            ->with([
                ...$this->withoutPortalScope('client'),
                'leadLawyer',
                'matterType.stages',
            ])
            ->orderByDesc('matters.id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        /** @var list<Matter> $matters */
        $matters = $rows->take($limit)->values()->all();

        $byId = collect($matters)->keyBy(fn (Matter $matter): int => (int) $matter->getKey());
        $nextDeadlines = [];

        foreach ($this->deadlines->forMatters($actor, $byId->keys()->all()) as $deadline) {
            $matterId = (int) $deadline->matter_id;

            if (! isset($nextDeadlines[$matterId])) {
                $deadline->setRelation('matter', $byId[$matterId]);
                $nextDeadlines[$matterId] = $deadline;
            }
        }

        $last = end($matters);

        return new MatterListPage(
            $matters,
            $nextDeadlines,
            $hasMore && $last instanceof Matter ? (int) $last->getKey() : null,
        );
    }

    /** @return Builder<Matter> */
    private function filtered(User $actor, MatterListFilters $filters): Builder
    {
        $query = $this->scope->query($actor);

        if ($filters->query !== null) {
            $query->whereIn('matters.id', $this->search->matchingMatterIds($actor, $filters->query));
        }

        if ($filters->matterType !== null) {
            $type = $filters->matterType;
            $query->whereHas('matterType', fn (Builder $types) => $types->where(
                fn (Builder $either) => $either->where('code', $type)->orWhere('name', $type),
            ));
        }

        if ($filters->stage !== null) {
            $query->where('matters.stage', $filters->stage);
        }

        if ($filters->mine) {
            $query->where('matters.lead_lawyer_id', $actor->getKey());
        }

        if ($filters->open !== null) {
            $filters->open ? $query->open() : $query->closed();
        }

        return $query;
    }
}
