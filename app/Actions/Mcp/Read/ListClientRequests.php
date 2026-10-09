<?php

namespace App\Actions\Mcp\Read;

use App\Actions\Mcp\McpMatterScope;
use App\Actions\Mcp\Read\Concerns\FindsVisibleMatter;
use App\Actions\Mcp\Read\Concerns\LoadsWithoutPortalScope;
use App\Enums\ClientRequestStatus;
use App\Models\ClientRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;

/**
 * Đọc cho tool `list_client_requests` (kế hoạch M11, bảng tool 10 [DC:56]).
 *
 * - **Phạm vi**: yêu cầu của vụ trong tập `McpMatterScope` (`constrain()`), chưa rút (xoá mềm). Lọc
 *   theo một vụ thì vụ đó qua {@see FindsVisibleMatter}; không thấy thì `null`.
 * - **Bộ lọc** ({@see ClientRequestListFilters}): "đang mở" là chưa `closed` — cùng nghĩa với số yêu
 *   cầu đang mở của `get_matter` (`ReadMatter`); "giao cho tôi" là `assigned_to` = người gọi.
 * - **Quyền từng dòng**: `ClientRequestPolicy::view` sau khi cắt trang (bảng tool), như `fetch` và
 *   `search` ({@see KeysetPage::filter()}).
 * - **Thứ tự**: hoạt động gần nhất giảm dần rồi `id` — đúng tab "Yêu cầu từ khách" (`last_activity_at`
 *   desc); luồng chưa có mốc hoạt động (cột rỗng) đứng cuối ({@see KeysetOrder}).
 * - **Nạp**: `matter` bỏ scope cổng kèm `team` (tham chiếu vụ; Gate trả lời từ bộ nhớ); `assignee` kể
 *   cả tài khoản đã xoá mềm — người đã nghỉ việc vẫn hiện tên, như tab. Không nạp người gửi
 *   (`clientUser`): tên và email của họ không ra MCP.
 */
final class ListClientRequests
{
    use FindsVisibleMatter;
    use LoadsWithoutPortalScope;

    public function __construct(private readonly McpMatterScope $scope) {}

    /** @return KeysetPage<ClientRequest>|null */
    public function handle(User $actor, ClientRequestListFilters $filters, int $limit, ?KeysetPosition $after = null): ?KeysetPage
    {
        $query = $this->scope->constrain(ClientRequest::query(), $actor);

        if ($filters->matterId !== null) {
            $matter = $this->visibleMatter($this->scope, $actor, $filters->matterId);

            if ($matter === null) {
                return null;
            }

            $query->where('client_requests.matter_id', $matter->getKey());
        }

        if ($filters->open !== null) {
            $query->where('client_requests.status', $filters->open ? '!=' : '=', ClientRequestStatus::Closed->value);
        }

        if ($filters->mine) {
            $query->where('client_requests.assigned_to', $actor->getKey());
        }

        $query->with([
            ...$this->withoutPortalScope('matter'),
            'matter.team',
            'assignee' => fn (BelongsTo $assignee) => $assignee->withTrashed(),
        ]);

        return (new KeysetOrder('client_requests', 'last_activity_at', descending: true))
            ->page($query, $limit, $after)
            ->filter(fn (ClientRequest $request): bool => Gate::forUser($actor)->allows('view', $request));
    }
}
