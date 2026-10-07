<?php

namespace App\Actions\Mcp\Read;

use App\Actions\Mcp\McpMatterScope;
use App\Actions\Mcp\Read\Concerns\FindsVisibleMatter;
use App\Actions\Mcp\Read\Concerns\LoadsWithoutPortalScope;
use App\Models\Deadline;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;

/**
 * Đọc cho tool `list_deadlines` (kế hoạch M11, bảng tool 7 [DC:53]). Mặc định (tool đặt người phụ
 * trách là người gọi) là "mốc tuần này": mốc chưa xong, hạn tới hết {@see self::DEFAULT_WINDOW_DAYS}
 * ngày tới kể cả quá hạn, quá hạn lên đầu.
 *
 * - **Phạm vi**: mốc của vụ trong tập `McpMatterScope` (`constrain()`), chưa xoá mềm. Vụ hạn chế mà
 *   chính người gọi phụ trách, vụ `denied`, vụ đội khác: vắng mặt dù mốc giao cho người gọi (R3) — tập
 *   R3 là định nghĩa duy nhất, không lọc lại theo từng tool. Kế toán (không `matter.view`) có tập rỗng,
 *   nên không nhận mốc nào.
 * - **Vụ**: không lọc theo vụ thì chỉ vụ ĐANG MỞ (`Matter::scopeOpen()`), cùng luật với widget "Mốc
 *   thời hạn 7 ngày tới" và với `CheckDeadlines` (mốc của vụ đã kết thúc không còn là việc phải làm,
 *   và đã thôi được nhắc). Lọc theo một vụ ({@see FindsVisibleMatter}; không thấy thì `null`) thì mọi
 *   mốc của vụ đó, vụ đã kết thúc cũng vậy.
 * - **Khoảng ngày**: `from`/`to` đều rỗng là cửa sổ mặc định — hạn ≤ hôm nay + 7, không cận dưới (cùng
 *   cửa sổ với `Deadline::scopeUpcoming(7)` của widget). Có một trong hai thì đúng các cận đã cho, cận
 *   kia bỏ ngỏ. So bằng `whereDate()` ở cả hai cận: gồm trọn ngày cận, trên SQLite lẫn MariaDB.
 * - **Mức độ**, **người phụ trách**, **kèm mốc đã xong**: theo {@see DeadlineListFilters}.
 * - **Quyền từng dòng**: `DeadlinePolicy::view` sau khi cắt trang (bảng tool: "view từng dòng + R3");
 *   với nhân sự nó là `canSeeMatter`, hôm nay không bỏ dòng nào ({@see KeysetPage::filter()}).
 * - **Thứ tự**: hạn tăng dần rồi `id` — quá hạn tự đứng đầu ({@see KeysetOrder}).
 * - **Nạp**: `matter` bỏ scope cổng kèm `team` (presenter dựng tham chiếu vụ; Gate trả lời từ bộ nhớ);
 *   `responsible` kể cả tài khoản đã xoá mềm — người đã nghỉ việc vẫn hiện tên, như tab "Mốc thời hạn"
 *   và widget.
 */
final class ListDeadlines
{
    use FindsVisibleMatter;
    use LoadsWithoutPortalScope;

    /** Cửa sổ mặc định — cùng con số với widget "Mốc thời hạn 7 ngày tới" (SPEC §7.1 mục 2). */
    public const DEFAULT_WINDOW_DAYS = 7;

    public function __construct(private readonly McpMatterScope $scope) {}

    public function handle(User $actor, DeadlineListFilters $filters, int $limit, ?KeysetPosition $after = null): ?DeadlineListPage
    {
        $query = $this->scope->constrain(Deadline::query(), $actor);

        if ($filters->matterId !== null) {
            $matter = $this->visibleMatter($this->scope, $actor, $filters->matterId);

            if ($matter === null) {
                return null;
            }

            $query->where('deadlines.matter_id', $matter->getKey());
        } else {
            $query->whereIn('deadlines.matter_id', $this->scope->query($actor)->open()->select('matters.id'));
        }

        [$from, $to] = $filters->usesDefaultWindow()
            ? [null, today()->addDays(self::DEFAULT_WINDOW_DAYS)->toDateString()]
            : [$filters->from, $filters->to];

        $query
            ->when($from !== null, fn (Builder $q) => $q->whereDate('deadlines.due_date', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('deadlines.due_date', '<=', $to))
            ->when(! $filters->includeCompleted, fn (Builder $q) => $q->where('deadlines.is_completed', false))
            ->when($filters->severity !== null, fn (Builder $q) => $q->where('deadlines.severity', $filters->severity?->value))
            ->when($filters->responsibleId !== null, fn (Builder $q) => $q->where('deadlines.responsible_user_id', $filters->responsibleId))
            ->with([
                ...$this->withoutPortalScope('matter'),
                'matter.team',
                'responsible' => fn (BelongsTo $responsible) => $responsible->withTrashed(),
            ]);

        $page = (new KeysetOrder('deadlines', 'due_date', descending: false))->page($query, $limit, $after);

        return new DeadlineListPage(
            $page->filter(fn (Deadline $deadline): bool => Gate::forUser($actor)->allows('view', $deadline)),
            $from,
            $to,
        );
    }
}
