<?php

namespace App\Support\Mcp\Presenters;

use App\Actions\Mcp\Read\DeadlineListPage;
use App\Models\Deadline;

/**
 * Đầu ra của tool `list_deadlines` (kế hoạch M11, bảng tool 7): các mốc ({@see DeadlinePresenter}),
 * khoảng hạn THẬT đã áp (`due_from`/`due_to`, `Y-m-d`; `null` là bỏ ngỏ phía đó — để AI nói đúng "mốc
 * tới ngày …" khi người dùng không nêu khoảng nào), và `next_cursor`.
 */
final class DeadlineListPresenter
{
    public const FIELDS = ['deadlines', 'due_from', 'due_to', 'next_cursor'];

    /**
     * Cần nạp sẵn: mỗi mốc như {@see DeadlinePresenter::present()}.
     *
     * @return array{deadlines: list<array<string, mixed>>, due_from: ?string, due_to: ?string, next_cursor: ?string}
     */
    public static function present(DeadlineListPage $result, ?string $nextCursor): array
    {
        return [
            'deadlines' => array_map(fn (Deadline $deadline): array => DeadlinePresenter::present($deadline), $result->page->rows),
            'due_from' => $result->dueFrom,
            'due_to' => $result->dueTo,
            'next_cursor' => $nextCursor,
        ];
    }
}
