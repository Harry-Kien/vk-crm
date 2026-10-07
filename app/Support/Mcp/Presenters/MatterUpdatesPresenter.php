<?php

namespace App\Support\Mcp\Presenters;

use App\Actions\Mcp\Read\MatterUpdatesPage;
use App\Models\StageLog;

/**
 * Đầu ra của tool `list_matter_updates` (kế hoạch M11, bảng tool 6): tham chiếu vụ, các dòng tiến độ
 * ({@see StageLogPresenter} — chỉ cờ `has_internal_note`, không bao giờ nội dung ghi chú, R4), và
 * `next_cursor`.
 */
final class MatterUpdatesPresenter
{
    public const FIELDS = ['matter', 'updates', 'next_cursor'];

    /**
     * Cần nạp sẵn: mỗi dòng như {@see StageLogPresenter::present()}.
     *
     * @return array{matter: array<string, string>, updates: list<array<string, mixed>>, next_cursor: ?string}
     */
    public static function present(MatterUpdatesPage $result, ?string $nextCursor): array
    {
        return [
            'matter' => MatterPresenter::reference($result->matter),
            'updates' => array_map(
                fn (StageLog $log): array => StageLogPresenter::present($log, $result->stageLabels),
                $result->page->rows,
            ),
            'next_cursor' => $nextCursor,
        ];
    }
}
