<?php

namespace App\Actions\Mcp\Read;

use App\Actions\Document\ChecklistProgress;
use App\Actions\Mcp\McpMatterScope;
use App\Actions\Mcp\Read\Concerns\LoadsWithoutPortalScope;
use App\Enums\ClientRequestStatus;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Đọc cho `get_matter` và nhánh vụ việc của `fetch` (kế hoạch M11, bảng tool 5 [DC:51]).
 *
 * Vụ việc lấy từ `McpMatterScope` (R3), RỒI hỏi lại `Gate::forUser($actor)->allows('view', $matter)`
 * — đúng ability mà trang vụ việc trên web hỏi. Tập R3 đã là con của Gate `view` (ghim ở
 * `MatterScopeTest`); lần hỏi thứ hai giữ câu "MCP kế thừa policy của web" đúng cả khi
 * `MatterPolicy::view()` được thêm một điều kiện mà bản SQL của `McpMatterScope` chưa có. Mọi nhánh
 * không thấy — id không tồn tại, đội khác, hạn chế, `denied`, đã xoá, Gate từ chối — trả `null`.
 *
 * Nạp cho `MatterPresenter::detail()`: `client` và `parties` (bỏ scope cổng, {@see LoadsWithoutPortalScope}),
 * `leadLawyer`, `matterType.stages`, `team` (kèm pivot). Các bên đã xoá mềm không ra (scope xoá mềm
 * của `MatterParty` vẫn chạy).
 *
 * Ghép thêm:
 *  - {@see self::NEXT_DEADLINES} mốc gần nhất ({@see OpenDeadlines});
 *  - "Đã nộp X/Y" của CHÍNH `ChecklistProgress` (SPEC §4.10) — không đếm lại lần thứ hai;
 *  - số yêu cầu từ khách đang mở: chưa `closed` (cùng nghĩa "còn mở" với `OpenWork`,
 *    `ClientRequestNotOpen`), chưa rút (xoá mềm).
 */
final class ReadMatter
{
    use LoadsWithoutPortalScope;

    /** "5 mốc sắp tới" của bảng tool. */
    public const NEXT_DEADLINES = 5;

    public function __construct(
        private readonly McpMatterScope $scope,
        private readonly OpenDeadlines $deadlines,
        private readonly ChecklistProgress $checklist,
    ) {}

    public function handle(User $actor, int $matterId): ?MatterOverview
    {
        /** @var Matter|null $matter */
        $matter = $this->scope->query($actor)
            ->whereKey($matterId)
            ->with([
                ...$this->withoutPortalScope('client', 'parties'),
                'leadLawyer',
                'matterType.stages',
                'team',
            ])
            ->first();

        if ($matter === null || ! Gate::forUser($actor)->allows('view', $matter)) {
            return null;
        }

        $nextDeadlines = $this->deadlines->forMatters($actor, [(int) $matter->getKey()], self::NEXT_DEADLINES)
            ->each(fn (Deadline $deadline) => $deadline->setRelation('matter', $matter))
            ->values()
            ->all();

        ['submitted' => $submitted, 'total' => $total] = $this->checklist->handle($matter);

        $openRequests = $this->scope->constrain(ClientRequest::query(), $actor)
            ->where('client_requests.matter_id', $matter->getKey())
            ->where('client_requests.status', '!=', ClientRequestStatus::Closed->value)
            ->count();

        return new MatterOverview($matter, $nextDeadlines, $submitted, $total, $openRequests);
    }
}
