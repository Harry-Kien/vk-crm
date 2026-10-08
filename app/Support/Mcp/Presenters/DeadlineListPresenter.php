<?php

namespace App\Support\Mcp\Presenters;

use App\Actions\Mcp\Read\DeadlineListPage;
use App\Models\Deadline;

/**
 * Đầu ra của tool `list_deadlines` (kế hoạch M11, bảng tool 7): các mốc ({@see DeadlinePresenter}),
 * khoảng hạn THẬT đã áp (`due_from`/`due_to`, `Y-m-d`; `null` là bỏ ngỏ phía đó — để AI nói đúng "mốc
 * tới ngày …" khi người dùng không nêu khoảng nào), bộ lọc người phụ trách đã áp (`responsible`: `me`,
 * `any` hoặc `user_…` — rà soát Task 11 r1: chỉ đưa `matter_id` thì mặc định "của tôi" vẫn áp, và AI
 * phải thấy điều đó thay vì báo "vụ không có mốc nào"), và `next_cursor`.
 */
final class DeadlineListPresenter
{
    public const FIELDS = ['deadlines', 'due_from', 'due_to', 'responsible', 'next_cursor'];

    /**
     * Cần nạp sẵn: mỗi mốc như {@see DeadlinePresenter::present()}. `$responsible` là bộ lọc người phụ
     * trách đúng như tool đã áp (`me` khi người gọi bỏ trống).
     *
     * @return array{deadlines: list<array<string, mixed>>, due_from: ?string, due_to: ?string, responsible: string, next_cursor: ?string}
     */
    public static function present(DeadlineListPage $result, string $responsible, ?string $nextCursor): array
    {
        return [
            'deadlines' => array_map(fn (Deadline $deadline): array => DeadlinePresenter::present($deadline), $result->page->rows),
            'due_from' => $result->dueFrom,
            'due_to' => $result->dueTo,
            'responsible' => $responsible,
            'next_cursor' => $nextCursor,
        ];
    }
}
