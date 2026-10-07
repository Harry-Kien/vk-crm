<?php

namespace App\Actions\Mcp;

use App\Enums\McpPlatform;
use App\Models\AiAcknowledgement;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Mcp\ConsentRequest;
use App\Support\Mcp\McpAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;

/**
 * Đọc kết nối AI cho hai trang của Task 15 (M11 R8): "Kết nối AI" của quản trị (bảng nhân sự,
 * chi tiết một người) và "Kết nối AI của tôi". Chỉ ĐỌC, trả DTO ({@see AiConnection},
 * {@see StaffAiSummary}), không trả token hay model.
 *
 * # Một "kết nối"
 *
 * Một client OAuth (`oauth_clients`) mà người này còn ít nhất một token SỐNG cho nó — access token
 * chưa thu hồi và chưa hết hạn, HOẶC access token có refresh token chưa thu hồi và chưa hết hạn (access
 * token 1 giờ hết hạn thì client làm mới bằng refresh token, kết nối vẫn còn). Client đã bị thu hồi
 * (`oauth_clients.revoked`) hay đã bị xoá thì không còn là kết nối: không token nào của nó dùng được.
 * Client DCR là của một lần kết nối; client CIMD dùng chung cho mọi nhân sự của một nền tảng (Task 5),
 * nên kết nối luôn là cặp (người, client), không bao giờ chỉ client.
 *
 * Mã uỷ quyền chưa đổi (sống 10 phút) không phải kết nối; "Thu hồi" vẫn thu hồi chúng
 * ({@see RevokeAiConnections}).
 *
 * # Quyền
 *
 *  - {@see self::forUser()}: `Gate::forUser($actor)->authorize('viewAiConnections', $target)` —
 *    quản trị xem của mọi người, nhân sự chỉ của chính mình ({@see UserPolicy::viewAiConnections()});
 *  - {@see self::overview()}: `viewAny` trên `User` (`settings.manage`).
 *
 * Không nhận người dùng từ `auth()`: `$actor` luôn tường minh.
 */
final class ListAiConnections
{
    /** @return list<AiConnection> mới kết nối nhất trước */
    public function forUser(User $actor, User $target): array
    {
        Gate::forUser($actor)->authorize('viewAiConnections', $target);

        $clientIds = $this->liveClientIds([$target->getKey()])[$target->getKey()] ?? [];
        $clients = $this->usableClients($clientIds);

        return $clients
            ->map(function (Client $client) use ($target): AiConnection {
                $redirectUri = $client->redirect_uris[0] ?? '';
                $redirectUri = is_string($redirectUri) ? $redirectUri : '';

                return new AiConnection(
                    clientId: (string) $client->getKey(),
                    platform: McpPlatform::fromRedirectUri($redirectUri),
                    host: ConsentRequest::displayHost($redirectUri),
                    connectedAt: $this->connectedAt($target, (string) $client->getKey()),
                    lastUsedAt: $this->lastToolCallAt($target, (string) $client->getKey()),
                );
            })
            ->sortByDesc(fn (AiConnection $connection): int => $connection->connectedAt->getTimestamp())
            ->values()
            ->all();
    }

    /**
     * Một dòng tóm tắt cho mỗi người trong `$users` (bảng nhân sự của trang "Kết nối AI"), theo
     * khoá `users.id`. Bốn truy vấn cho cả bảng, không một truy vấn mỗi dòng.
     *
     * @param  iterable<User>  $users
     * @return array<int, StaffAiSummary>
     */
    public function overview(User $actor, iterable $users): array
    {
        Gate::forUser($actor)->authorize('viewAny', User::class);

        $ids = collect($users)->map(fn (User $user): int => (int) $user->getKey())->values()->all();

        if ($ids === []) {
            return [];
        }

        $liveClientIds = $this->liveClientIds($ids);
        $usableClientIds = $this->usableClients(array_merge([], ...array_values($liveClientIds)))
            ->map(fn (Client $client): string => (string) $client->getKey())
            ->all();

        $lastUsed = Activity::query()
            ->where('event', 'mcp_tool_called')
            ->where('causer_type', (new User)->getMorphClass())
            ->whereIn('causer_id', $ids)
            ->groupBy('causer_id')
            ->selectRaw('causer_id, MAX(created_at) AS last_used_at')
            ->pluck('last_used_at', 'causer_id');

        $currentVersion = McpAccess::policyVersion();

        /** @var Collection<int, Collection<int, AiAcknowledgement>> $acknowledgements */
        $acknowledgements = AiAcknowledgement::query()
            ->whereIn('user_id', $ids)
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('user_id');

        $summaries = [];

        foreach ($ids as $id) {
            $rows = $acknowledgements->get($id, collect());
            $current = $currentVersion === null ? null : $rows->firstWhere('policy_version', $currentVersion);
            $shown = $current ?? $rows->first();
            $last = $lastUsed->get($id);

            $summaries[$id] = new StaffAiSummary(
                acknowledgedAt: $shown?->accepted_at?->toImmutable(),
                acknowledgedVersion: $shown?->policy_version,
                acknowledgedCurrent: $current !== null,
                connections: count(array_intersect($liveClientIds[$id] ?? [], $usableClientIds)),
                lastUsedAt: is_string($last) ? CarbonImmutable::parse($last) : null,
            );
        }

        return $summaries;
    }

    /**
     * `users.id` → danh sách `client_id` (không trùng) mà người đó còn token sống — định nghĩa ở
     * docblock lớp, mục "Một kết nối".
     *
     * @param  list<int|string>  $userIds
     * @return array<int, list<string>>
     */
    private function liveClientIds(array $userIds): array
    {
        $now = now();
        $tokens = Passport::token()->newQuery();
        $tokenTable = $tokens->getModel()->getTable();
        $refreshTable = Passport::refreshToken()->newQuery()->getModel()->getTable();

        return $tokens
            ->whereIn('user_id', $userIds)
            ->where(function (Builder $query) use ($now, $tokenTable, $refreshTable): void {
                $query
                    ->where(fn (Builder $live) => $live->where('revoked', false)->where('expires_at', '>', $now))
                    ->orWhereExists(fn (QueryBuilder $refresh) => $refresh
                        ->selectRaw('1')
                        ->from($refreshTable)
                        ->whereColumn("{$refreshTable}.access_token_id", "{$tokenTable}.id")
                        ->where("{$refreshTable}.revoked", false)
                        ->where("{$refreshTable}.expires_at", '>', $now));
            })
            ->distinct()
            ->get(['user_id', 'client_id'])
            ->groupBy('user_id')
            ->map(fn (Collection $rows): array => $rows->pluck('client_id')->map(fn ($id): string => (string) $id)->unique()->values()->all())
            ->all();
    }

    /**
     * Client còn dùng được (chưa thu hồi) trong số `$clientIds`; client đã xoá thì vắng mặt.
     *
     * @param  list<string>  $clientIds
     * @return Collection<int, Client>
     */
    private function usableClients(array $clientIds): Collection
    {
        if ($clientIds === []) {
            return collect();
        }

        return Passport::client()->newQuery()
            ->whereIn('id', array_values(array_unique($clientIds)))
            ->where('revoked', false)
            ->get()
            ->values();
    }

    /** Lần đồng ý gần nhất (Task 4), hoặc token cũ nhất còn trong bảng khi không có dòng nhật ký. */
    private function connectedAt(User $user, string $clientId): CarbonImmutable
    {
        $authorized = $this->latestActivityAt('mcp_connection_authorized', $user, $clientId);

        if ($authorized !== null) {
            return $authorized;
        }

        $first = Passport::token()->newQuery()
            ->where('user_id', $user->getKey())
            ->where('client_id', $clientId)
            ->min('created_at');

        return CarbonImmutable::parse($first ?? now());
    }

    private function lastToolCallAt(User $user, string $clientId): ?CarbonImmutable
    {
        return $this->latestActivityAt('mcp_tool_called', $user, $clientId);
    }

    private function latestActivityAt(string $event, User $user, string $clientId): ?CarbonImmutable
    {
        $at = Activity::query()
            ->where('event', $event)
            ->where('causer_type', $user->getMorphClass())
            ->where('causer_id', $user->getKey())
            ->where('properties->oauth_client_id', $clientId)
            ->max('created_at');

        return $at === null ? null : CarbonImmutable::parse($at);
    }
}
