<?php

namespace App\Actions\Mcp\Read;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Mcp\McpMatterScope;
use App\Actions\Mcp\Read\Concerns\LoadsWithoutPortalScope;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientRequestReplyDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;

/**
 * Đọc một luồng yêu cầu từ khách cho MCP: nhánh yêu cầu của `fetch` (Task 10) và tool
 * `get_client_request` (Task 11) — kế hoạch M11, bảng tool 3 và 11 [DC:57].
 *
 * Yêu cầu phải thuộc một vụ trong tập `McpMatterScope` của `$actor` (`constrain()`), chưa rút (xoá
 * mềm), và qua `Gate::forUser($actor)->allows('view', $request)` — đúng ability của tab "Yêu cầu từ
 * khách" trên web (`ClientRequestPolicy::view`, nhân sự → `canSeeMatter`). Mỗi trả lời qua thêm
 * `ClientRequestReplyPolicy::view` (bảng tool 11); với nhân sự nó trùng điều kiện của yêu cầu, nên
 * lọc từng dòng không bỏ dòng nào hôm nay — nó giữ đúng chữ "kế thừa policy của web" nếu policy đổi.
 * Mọi nhánh không thấy trả `null`, như {@see ReadMatter}.
 *
 * Nạp: `matter` (bỏ scope cổng) kèm `team` (Gate trả lời từ bộ nhớ, không một truy vấn mỗi trả lời),
 * `assignee` kể cả tài khoản đã xoá mềm (người đã nghỉ việc vẫn hiện tên, như tab "Yêu cầu từ khách"
 * và `list_client_requests` của Task 11), `replies` theo thứ tự thời gian (bỏ scope cổng) và `author`
 * của từng trả lời (presenter chỉ đọc nó cho trả lời của văn phòng). Số nháp trả lời đang chờ đọc
 * `ClientRequestReplyDraft::pending()` (bỏ scope cổng) — định nghĩa duy nhất của "nháp đang có" (Task 7).
 */
final class ReadClientRequest
{
    use LoadsWithoutPortalScope;
    use ReadsWithoutPortalScope;

    public function __construct(private readonly McpMatterScope $scope) {}

    public function handle(User $actor, int $requestId): ?ClientRequestThread
    {
        /** @var ClientRequest|null $request */
        $request = $this->scope->constrain(ClientRequest::query(), $actor)
            ->whereKey($requestId)
            ->with([
                ...$this->withoutPortalScope('matter', 'replies'),
                'matter.team',
                'assignee' => fn (BelongsTo $assignee) => $assignee->withTrashed(),
                'replies.author',
            ])
            ->first();

        if ($request === null || ! Gate::forUser($actor)->allows('view', $request)) {
            return null;
        }

        $request->replies->each(fn (ClientRequestReply $reply) => $reply->setRelation('request', $request));
        $request->setRelation('replies', $request->replies
            ->filter(fn (ClientRequestReply $reply): bool => Gate::forUser($actor)->allows('view', $reply))
            ->values());

        $pendingDrafts = $this->scopelessly(ClientRequestReplyDraft::query())
            ->where('request_id', $request->getKey())
            ->pending()
            ->count();

        return new ClientRequestThread($request, $pendingDrafts);
    }
}
