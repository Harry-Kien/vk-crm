<?php

namespace App\Support\Mcp\Presenters;

use App\Actions\Mcp\Read\MatterListPage;
use App\Models\Matter;

/**
 * Đầu ra của tool `search_matters` (kế hoạch M11, bảng tool 4): mỗi dòng là
 * {@see MatterPresenter::row()} cộng `next_deadline` — mốc chưa hoàn thành gần nhất qua
 * {@see DeadlinePresenter} (`null` khi vụ không còn mốc nào chưa xong) —, và `next_cursor` (`null` ở
 * trang cuối). Không có tổng số: R3 cấm số đếm trên tập người gọi không thấy, và việc đọc tiếp không
 * cần nó.
 */
final class MatterListPresenter
{
    public const FIELDS = ['matters', 'next_cursor'];

    public const ROW_FIELDS = [...MatterPresenter::ROW_FIELDS, 'next_deadline'];

    /**
     * Cần nạp sẵn: như {@see MatterPresenter::row()}; mỗi mốc trong `nextDeadlines` như
     * {@see DeadlinePresenter::present()}.
     *
     * @return array{matters: list<array<string, mixed>>, next_cursor: ?string}
     */
    public static function present(MatterListPage $page, ?string $nextCursor): array
    {
        return [
            'matters' => array_map(function (Matter $matter) use ($page): array {
                $deadline = $page->nextDeadlines[(int) $matter->getKey()] ?? null;

                return [
                    ...MatterPresenter::row($matter),
                    'next_deadline' => $deadline === null ? null : DeadlinePresenter::present($deadline),
                ];
            }, $page->matters),
            'next_cursor' => $nextCursor,
        ];
    }
}
