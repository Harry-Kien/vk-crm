<?php

namespace App\Actions\Mcp;

use App\Enums\CreatedVia;
use App\Enums\McpToolOutcome;
use App\Models\ClientRequestReplyDraft;
use App\Models\Deadline;
use App\Models\StageLogDraft;
use App\Models\User;
use App\Support\ActivityPeople;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * Khối "Hoạt động của trợ lý AI" của trang "Kết nối AI" (làn ai, 2026-10-15): trợ lý AI đã được dùng
 * bao nhiêu trong 7 hay 30 ngày qua — theo ngày, theo chức năng (tool), theo người — bao nhiêu lần bị
 * từ chối, và bao nhiêu lần ghi của AI đang chờ người duyệt. Chỉ ĐỌC, trả DTO
 * ({@see McpActivityReport}); trang không truy vấn `activity_log` trực tiếp.
 *
 * # Nguồn
 *
 * Đúng các dòng `mcp_tool_called` mà M11 đã ghi (R8, {@see RecordMcpToolCall}) — mỗi `tools/call`
 * mang token hợp lệ một dòng, kể cả lần bị `EnsureMcpAccess` từ chối (công tắc tắt, chưa cam kết, chế
 * độ "Tắt": outcome `denied`) hay bị chặn vì giới hạn tần suất (`rate_limited`). Không ghi thêm gì vào
 * nhật ký. "Bị từ chối" trên màn hình = `denied` + `rate_limited`; nhật ký không tách được "công tắc
 * tắt" khỏi "thiếu quyền" (cả hai là `denied`), nên màn hình cũng không tách.
 *
 * Kỳ = hôm nay và `days − 1` ngày trước, từ nửa đêm theo giờ của ứng dụng ({@see self::windowStart()}).
 *
 * # Truy vấn
 *
 * Ba câu `GROUP BY` trên `activity_log` (ngày × kết cục, tool × kết cục, người × kết cục), đều lọc
 * `event` + `created_at` — đúng index `activity_log(event, created_at)` của M13 Task 8; một câu nạp tên
 * người (kể cả nhân sự đã nghỉ việc, {@see ActivityPeople}); ba câu đếm lần ghi chờ duyệt
 * ({@see self::awaitingReview()}). Số câu không đổi theo số người hay số tool — không N+1, không nạp
 * dòng nhật ký nào lên PHP.
 *
 * # Quyền và dữ liệu
 *
 * `viewAny` trên `User` (`settings.manage`, chỉ admin), hỏi `Gate::forUser($actor)` ngay đầu — cùng
 * cổng với trang. Kết quả chỉ có SỐ, tên tool (tên ĐÃ KHAI trên máy chủ — tên lạ chỉ còn độ dài, Task
 * 8) và tên nhân sự; không có mã, tiêu đề hay khách hàng của vụ nào, kể cả vụ `restricted`: số lần
 * ghi chờ duyệt chỉ là một con số.
 */
final class SummarizeMcpActivity
{
    /** Hai kỳ màn hình cho chọn. */
    public const PERIODS = [7, 30];

    public const DEFAULT_PERIOD = 7;

    /** Nửa đêm của ngày đầu kỳ `$days` ngày, tính cả hôm nay. */
    public static function windowStart(int $days): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfDay()->subDays(max(1, $days) - 1);
    }

    /** Kỳ ngoài {@see self::PERIODS} rơi về {@see self::DEFAULT_PERIOD}. */
    public function handle(User $actor, int $days = self::DEFAULT_PERIOD): McpActivityReport
    {
        Gate::forUser($actor)->authorize('viewAny', User::class);

        $days = in_array($days, self::PERIODS, true) ? $days : self::DEFAULT_PERIOD;
        $from = self::windowStart($days);

        $daily = $this->calls($from)
            ->selectRaw('DATE(created_at) AS day')
            ->addSelect('properties->outcome as outcome')
            ->selectRaw('COUNT(*) AS calls')
            ->groupByRaw('DATE(created_at)')
            ->groupBy('properties->outcome')
            ->get();

        $tools = $this->calls($from)
            ->select(['properties->tool as tool', 'properties->outcome as outcome'])
            ->selectRaw('COUNT(*) AS calls')
            ->groupBy('properties->tool', 'properties->outcome')
            ->get();

        $people = $this->calls($from)
            ->where('causer_type', (new User)->getMorphClass())
            ->whereNotNull('causer_id')
            ->select(['causer_id', 'properties->outcome as outcome'])
            ->selectRaw('COUNT(*) AS calls, MAX(created_at) AS last_at')
            ->groupBy('causer_id', 'properties->outcome')
            ->get();

        $outcomes = collect(McpToolOutcome::cases())
            ->mapWithKeys(fn (McpToolOutcome $outcome): array => [$outcome->value => 0])
            ->all();

        foreach ($daily as $row) {
            $outcomes[self::outcome($row)->value] += (int) $row->calls;
        }

        return new McpActivityReport(
            days: $days,
            from: $from,
            calls: array_sum($outcomes),
            outcomes: $outcomes,
            byDay: $this->byDay($daily, $from, $days),
            byTool: $this->byTool($tools),
            byPerson: $this->byPerson($people),
            awaitingReview: $this->awaitingReview(),
        );
    }

    /**
     * "Lần ghi của AI chờ người duyệt" (brief mục 6; task polish vòng sửa 1, n5): mọi thứ một tool ghi
     * của M11 để lại mà còn chờ một người trong `/admin` quyết —
     *
     *  - nháp dòng tiến độ ({@see StageLogDraft}) và nháp trả lời khách ({@see ClientRequestReplyDraft})
     *    còn đang chờ (`pending()`: chưa dùng, chưa bỏ);
     *  - mốc tạo qua AI chưa xác nhận: `created_via = mcp` ∧ `confirmed_at` rỗng — đúng điều kiện
     *    hiện nút "Xác nhận" và nhãn "Tạo qua AI, chưa xác nhận" ở tab mốc của vụ; mốc đã xoá mềm thì
     *    không (scope mặc định của {@see Deadline}).
     *
     * Chỉ trên vụ việc chưa xoá mềm (và với nháp trả lời, yêu cầu của khách chưa xoá mềm): `whereHas`
     * mang scope mặc định của `Matter` / `ClientRequest`, nên thứ nằm trên một trang không còn ai mở
     * được để duyệt không đếm. Nhật ký liên lạc AI ghi (`log_communication`) là bản ghi nội bộ xong
     * ngay, không chờ duyệt, nên không ở đây.
     */
    private function awaitingReview(): int
    {
        return StageLogDraft::query()->pending()->whereHas('matter')->count()
            + ClientRequestReplyDraft::query()->pending()->whereHas('request.matter')->count()
            + Deadline::query()
                ->where('created_via', CreatedVia::Mcp->value)
                ->whereNull('confirmed_at')
                ->whereHas('matter')
                ->count();
    }

    /** Các dòng `mcp_tool_called` từ `$from`, ở tầng query builder (không model, không cast). */
    private function calls(CarbonImmutable $from): QueryBuilder
    {
        return Activity::query()
            ->toBase()
            ->where('event', 'mcp_tool_called')
            ->where('created_at', '>=', $from);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<McpActivityRow>
     */
    private function byDay(Collection $rows, CarbonImmutable $from, int $days): array
    {
        $grouped = $rows->groupBy(fn (object $row): string => substr((string) $row->day, 0, 10));

        return collect(range(0, $days - 1))
            ->map(function (int $offset) use ($from, $grouped): McpActivityRow {
                $day = $from->addDays($offset);
                [$calls, $refused] = self::tally($grouped->get($day->format('Y-m-d'), collect()));

                return new McpActivityRow($day->format('Y-m-d'), $day->format('d/m'), $calls, $refused);
            })
            ->all();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<McpActivityRow>
     */
    private function byTool(Collection $rows): array
    {
        return $rows
            ->groupBy(fn (object $row): string => is_string($row->tool) ? $row->tool : '')
            ->map(function (Collection $group, string $tool): McpActivityRow {
                [$calls, $refused] = self::tally($group);

                return new McpActivityRow($tool, $tool, $calls, $refused);
            })
            ->sortBy([['calls', 'desc'], ['key', 'asc']])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<McpActivityRow>
     */
    private function byPerson(Collection $rows): array
    {
        $grouped = $rows->groupBy(fn (object $row): int => (int) $row->causer_id);

        if ($grouped->isEmpty()) {
            return [];
        }

        $people = User::query()
            ->withTrashed()
            ->whereIn('id', $grouped->keys()->all())
            ->get(['id', 'name', 'deleted_at'])
            ->keyBy(fn (User $user): int => (int) $user->getKey());

        return $grouped
            ->map(function (Collection $group, int $id) use ($people): McpActivityRow {
                [$calls, $refused] = self::tally($group);
                $last = $group->max('last_at');

                return new McpActivityRow(
                    (string) $id,
                    ActivityPeople::name($people->get($id)) ?? __('ai_connections.admin.audit.system'),
                    $calls,
                    $refused,
                    is_string($last) ? CarbonImmutable::parse($last) : null,
                );
            })
            ->sortBy([['calls', 'desc'], ['label', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Tổng số lần gọi và số lần bị từ chối (`denied`, `rate_limited`) của một nhóm dòng `GROUP BY`.
     *
     * @param  Collection<int, object>  $rows
     * @return array{int, int}
     */
    private static function tally(Collection $rows): array
    {
        $calls = 0;
        $refused = 0;

        foreach ($rows as $row) {
            $calls += (int) $row->calls;

            if (in_array(self::outcome($row), [McpToolOutcome::Denied, McpToolOutcome::RateLimited], true)) {
                $refused += (int) $row->calls;
            }
        }

        return [$calls, $refused];
    }

    /** Kết cục của một dòng nhóm; vắng hay lạ là `error`, như `ToolCallContext` ghi khi chưa đặt. */
    private static function outcome(object $row): McpToolOutcome
    {
        $value = $row->outcome ?? null;

        return is_string($value) ? (McpToolOutcome::tryFrom($value) ?? McpToolOutcome::Error) : McpToolOutcome::Error;
    }
}
