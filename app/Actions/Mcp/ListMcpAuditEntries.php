<?php

namespace App\Actions\Mcp;

use App\Enums\McpPlatform;
use App\Enums\McpToolOutcome;
use App\Models\User;
use App\Support\ActivityPeople;
use App\Support\SensitivePropertyFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * Khối "Nhật ký MCP" của trang "Kết nối AI" (M11 R8, Task 15): {@see self::LIMIT} dòng gần nhất của
 * kênh AI, lọc theo người và theo tool. Chỉ ĐỌC, trả DTO {@see McpAuditEntry}; trang không truy vấn
 * `activity_log` trực tiếp.
 *
 * # Dòng nào
 *
 * Đúng các sự kiện của {@see self::EVENTS}: mỗi lần gọi tool (`mcp_tool_called`, Task 8), đồng ý / từ
 * chối một kết nối (Task 4), làm mới một kết nối (`mcp_token_refreshed`, Task 17), thu hồi kết nối, đổi chế độ, cam kết chính sách (Task 6), đổi cấu hình
 * toàn hệ thống ({@see UpdateAiSettings}), và (làn fb, SPEC §16.7) cờ AI của vụ, dùng hay bỏ nháp AI,
 * xác nhận mốc hạn do AI tạo. Không dòng nhật ký nào khác của hệ thống.
 *
 *  - Lọc theo người (`$userId`): dòng mà người đó là causer (người sở hữu token, người bấm) HOẶC chủ
 *    thể (người bị đổi chế độ, bị thu hồi kết nối).
 *  - Lọc theo tool (`$tool`): chỉ dòng `mcp_tool_called` mang đúng tên tool đó.
 *
 * # Quyền và dữ liệu
 *
 * `settings.manage` (chỉ admin), hỏi `Gate::forUser($actor)` ngay đầu. Trang đòi cùng quyền, nên
 * không lọc vụ `restricted` ở đây: dòng nhật ký MCP không mang mã, tiêu đề hay khách hàng của vụ nào,
 * chỉ id dạng `matter_N` (Task 8). Phần `properties` còn lại vẫn đi qua
 * {@see SensitivePropertyFilter} trước khi tới màn hình — lớp phòng thủ ở tầng hiển thị, độc lập với
 * allowlist ở tầng ghi.
 */
final class ListMcpAuditEntries
{
    public const LIMIT = 100;

    /** Số dòng `mcp_tool_called` gần nhất được quét để dựng danh sách tên tool của bộ lọc. */
    public const TOOL_SCAN = 1000;

    /** @var list<string> */
    public const EVENTS = [
        'mcp_tool_called',
        'mcp_connection_authorized',
        'mcp_connection_denied',
        'mcp_token_refreshed',
        'ai_connections_revoked',
        'ai_access_changed',
        'ai_policy_acknowledged',
        'ai_settings_updated',
        // Làn fb (mục B, SPEC §16.7 "cũng ghi"): cờ AI của vụ (ai xác nhận khách đồng ý), dùng hay bỏ
        // nháp AI, xác nhận mốc hạn AI tạo — văn phòng cần thấy chúng ngay trên trang này khi lập
        // hồ sơ đánh giá tác động.
        'matter_ai_access_changed',
        'mcp_draft_used',
        'mcp_draft_discarded',
        'deadline_ai_confirmed',
    ];

    /** Khoá `properties` đã có cột riêng trên màn hình, không lặp lại trong "chi tiết". */
    private const COLUMN_KEYS = ['channel', 'tool', 'outcome', 'platform', 'ip'];

    /** @return list<McpAuditEntry> mới nhất trước */
    public function handle(User $actor, ?int $userId = null, ?string $tool = null): array
    {
        Gate::forUser($actor)->authorize('viewAny', User::class);

        $userMorph = (new User)->getMorphClass();

        return Activity::query()
            // Làn fb, mục A6: nạp cả nhân sự đã nghỉ việc (xoá mềm) — xem {@see ActivityPeople}.
            ->with(['causer' => ActivityPeople::withTrashed(), 'subject' => ActivityPeople::withTrashed()])
            ->whereIn('event', self::EVENTS)
            ->when($userId !== null, fn (Builder $query) => $query->where(fn (Builder $person) => $person
                ->where(fn (Builder $causer) => $causer->where('causer_type', $userMorph)->where('causer_id', $userId))
                ->orWhere(fn (Builder $subject) => $subject->where('subject_type', $userMorph)->where('subject_id', $userId))))
            ->when(filled($tool), fn (Builder $query) => $query
                ->where('event', 'mcp_tool_called')
                ->where('properties->tool', $tool))
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Activity $activity): McpAuditEntry => $this->entry($activity))
            ->all();
    }

    /**
     * Tên tool cho bộ lọc: các tên đã xuất hiện trong {@see self::TOOL_SCAN} dòng `mcp_tool_called` gần
     * nhất, theo thứ tự chữ cái. Nhật ký chỉ ghi tên của tool ĐÃ KHAI trên máy chủ (tên lạ chỉ còn độ
     * dài, Task 8), nên không tên nào ở đây do client tự đặt.
     *
     * @return list<string>
     */
    public function toolNames(User $actor): array
    {
        Gate::forUser($actor)->authorize('viewAny', User::class);

        return Activity::query()
            ->where('event', 'mcp_tool_called')
            ->orderByDesc('id')
            ->limit(self::TOOL_SCAN)
            ->get(['id', 'properties'])
            ->map(fn (Activity $activity): mixed => $activity->properties['tool'] ?? null)
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function entry(Activity $activity): McpAuditEntry
    {
        /** @var array<string, mixed> $properties */
        $properties = $activity->properties?->toArray() ?? [];

        $tool = $properties['tool'] ?? null;
        $outcome = $properties['outcome'] ?? null;
        $platform = $properties['platform'] ?? null;
        $ip = $properties['ip'] ?? null;

        return new McpAuditEntry(
            id: (int) $activity->getKey(),
            at: CarbonImmutable::parse($activity->created_at),
            event: (string) $activity->event,
            person: ActivityPeople::name($activity->causer) ?? ActivityPeople::name($activity->subject),
            tool: is_string($tool) ? $tool : null,
            outcome: is_string($outcome) ? McpToolOutcome::tryFrom($outcome) : null,
            platform: is_string($platform) ? McpPlatform::tryFrom($platform) : null,
            ip: is_string($ip) ? $ip : null,
            details: SensitivePropertyFilter::filter(array_diff_key($properties, array_flip(self::COLUMN_KEYS))),
        );
    }
}
