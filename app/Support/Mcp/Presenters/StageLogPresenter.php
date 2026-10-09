<?php

namespace App\Support\Mcp\Presenters;

use App\Models\StageLog;
use App\Models\StageLogView;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\Concerns\ReadsLoadedRelations;
use Illuminate\Support\Collection;

/**
 * Một dòng tiến độ trong kết quả MCP (kế hoạch M11, tool `list_matter_updates`): ngày, giai đoạn từ →
 * tới, ba trường viết cho khách (`public_content`, `next_step`, `client_action`), hẹn cập nhật kế tiếp,
 * đã công bố chưa, khách xem LẦN ĐẦU lúc nào, và cờ `has_internal_note`.
 *
 * Không bao giờ `internal_note` — chỉ cờ (R4 [DC:52]); AI vẫn GHI được ghi chú nội bộ vào nháp (R5).
 * Không ai xem, IP hay `client_user_id` của lượt xem: chỉ thời điểm sớm nhất. Ba trường cho khách do
 * văn phòng viết, nên không bọc `untrusted_client_content`.
 *
 * Nhãn giai đoạn đến từ `$stageLabels` (khoá → nhãn nội bộ) do Action đọc dựng — kể cả giai đoạn đã
 * xoá mềm mà lịch sử còn nhắc (`MatterType::stageIncludingTrashed()` là một truy vấn, và presenter
 * không truy vấn). Khoá không có trong bảng thì nhãn là `null`, khoá vẫn trả.
 */
final class StageLogPresenter
{
    use ReadsLoadedRelations;

    public const FIELDS = [
        'id', 'matter_id', 'occurred_at', 'from_stage', 'from_stage_label', 'to_stage', 'to_stage_label',
        'public_content', 'next_step', 'client_action', 'expected_next_update_at', 'is_published',
        'published_at', 'client_viewed_at', 'has_internal_note', 'url',
    ];

    /**
     * Cần nạp sẵn: `views`.
     *
     * @param  array<string, string>  $stageLabels
     * @return array<string, mixed>
     */
    public static function present(StageLog $log, array $stageLabels = []): array
    {
        /** @var Collection<int, StageLogView> $views */
        $views = self::loaded($log, 'views');
        $firstViewedAt = $views->map(fn (StageLogView $view) => $view->viewed_at)->min();

        return [
            'id' => McpIds::encode(McpIds::UPDATE, (int) $log->getKey()),
            'matter_id' => McpIds::encode(McpIds::MATTER, (int) $log->matter_id),
            'occurred_at' => $log->occurred_at?->toIso8601String(),
            'from_stage' => $log->from_stage,
            'from_stage_label' => $stageLabels[(string) $log->from_stage] ?? null,
            'to_stage' => $log->to_stage,
            'to_stage_label' => $stageLabels[(string) $log->to_stage] ?? null,
            'public_content' => $log->public_content,
            'next_step' => $log->next_step,
            'client_action' => $log->client_action,
            'expected_next_update_at' => $log->expected_next_update_at?->toDateString(),
            'is_published' => (bool) $log->is_published,
            'published_at' => $log->published_at?->toIso8601String(),
            'client_viewed_at' => $firstViewedAt?->toIso8601String(),
            // Chỉ cờ: nội dung `internal_note` không bao giờ rời hệ thống qua MCP (R4).
            'has_internal_note' => filled($log->internal_note),
            'url' => AdminUrls::stageLog($log),
        ];
    }
}
