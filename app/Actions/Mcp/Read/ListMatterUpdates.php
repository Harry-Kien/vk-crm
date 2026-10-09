<?php

namespace App\Actions\Mcp\Read;

use App\Actions\Mcp\McpMatterScope;
use App\Actions\Mcp\Read\Concerns\FindsVisibleMatter;
use App\Actions\Mcp\Read\Concerns\LoadsWithoutPortalScope;
use App\Models\Matter;
use App\Models\MatterTypeStage;
use App\Models\StageLog;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Đọc cho tool `list_matter_updates` (kế hoạch M11, bảng tool 6 [DC:52]): dòng tiến độ của MỘT vụ.
 *
 * - **Vụ**: {@see FindsVisibleMatter} (`McpMatterScope` rồi Gate `view`); không thấy thì `null`.
 * - **Quyền từng dòng**: đúng cột kiểm quyền của bảng tool — `StageLogPolicy::viewAny` (từ chối thì
 *   `null`, như vụ không thấy), rồi `StageLogPolicy::view` trên từng dòng sau truy vấn. Với nhân sự
 *   `view` là `canSeeMatter`, giống nhau cho mọi dòng của một vụ, nên hôm nay lọc từng dòng không bỏ
 *   dòng nào; nó giữ "kế thừa policy web" đúng nếu policy thêm điều kiện. Lọc chạy SAU khi cắt trang,
 *   nên một trang có thể ít hơn `limit` dòng; vị trí trang kế vẫn là dòng cuối truy vấn đã đọc
 *   ({@see KeysetPage::filter()}).
 * - **Mọi dòng, kể cả chưa công bố**: tab "Tiến độ" của nhân sự hiện cả hai; cờ `is_published` trong
 *   kết quả nói dòng nào khách đã thấy. Dòng tiến độ không xoá được (SPEC §4.8).
 * - **Thứ tự**: ngày xảy ra giảm dần, rồi `id` giảm dần — đúng `Matter::stageLogs()` mà tab dùng
 *   ({@see KeysetOrder}).
 * - **Nạp**: `views` bỏ scope cổng (presenter lấy lượt xem SỚM NHẤT); mỗi dòng được gắn chính vụ đã
 *   nạp `team`, nên `StageLogPolicy::view` không lazy-load vụ cha dưới `ClientPortalScope`.
 * - **Nhãn giai đoạn**: bảng khoá → nhãn NỘI BỘ của loại vụ, gồm cả giai đoạn đã xoá mềm mà lịch sử
 *   còn nhắc — giai đoạn còn sống thắng, rồi tới dòng xoá gần nhất, cùng luật với
 *   `MatterType::stageIncludingTrashed()` mà tab dùng. Một truy vấn cho cả trang (presenter không
 *   truy vấn).
 *
 * Không bao giờ đọc `internal_note` ra ngoài: presenter chỉ trả cờ `has_internal_note` (R4).
 */
final class ListMatterUpdates
{
    use FindsVisibleMatter;
    use LoadsWithoutPortalScope;

    public function __construct(private readonly McpMatterScope $scope) {}

    public function handle(User $actor, int $matterId, int $limit, ?KeysetPosition $after = null): ?MatterUpdatesPage
    {
        $matter = $this->visibleMatter($this->scope, $actor, $matterId);

        if ($matter === null || ! Gate::forUser($actor)->allows('viewAny', StageLog::class)) {
            return null;
        }

        $page = (new KeysetOrder('stage_logs', 'occurred_at', descending: true))->page(
            $this->scope->constrain(StageLog::query(), $actor)
                ->where('stage_logs.matter_id', $matter->getKey())
                ->with($this->withoutPortalScope('views')),
            $limit,
            $after,
        );

        foreach ($page->rows as $log) {
            $log->setRelation('matter', $matter);
        }

        return new MatterUpdatesPage(
            $matter,
            $page->filter(fn (StageLog $log): bool => Gate::forUser($actor)->allows('view', $log)),
            $this->stageLabels($matter),
        );
    }

    /**
     * Khoá → nhãn nội bộ của mọi giai đoạn từng có của loại vụ: sắp sao cho dòng thắng đứng SAU (đã
     * xoá trước, theo thời điểm xoá; còn sống sau cùng), rồi ghi đè theo khoá.
     *
     * @return array<string, string>
     */
    private function stageLabels(Matter $matter): array
    {
        return MatterTypeStage::withTrashed()
            ->where('matter_type_id', $matter->matter_type_id)
            ->get()
            ->sortBy(fn (MatterTypeStage $stage): array => [
                $stage->deleted_at === null ? 1 : 0,
                $stage->deleted_at?->getTimestamp() ?? 0,
                (int) $stage->getKey(),
            ])
            ->mapWithKeys(fn (MatterTypeStage $stage): array => [(string) $stage->key => (string) $stage->label])
            ->all();
    }
}
