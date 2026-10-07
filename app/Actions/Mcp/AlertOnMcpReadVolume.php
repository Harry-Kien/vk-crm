<?php

namespace App\Actions\Mcp;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Models\User;
use App\Notifications\Staff\McpReadVolumeAlert;
use Illuminate\Cache\RateLimiter;

/**
 * Cảnh báo admin khi một người ĐỌC quá {@see self::THRESHOLD} bản ghi qua MCP trong một giờ (M11 R8,
 * Task 8, [DC:156]). Gọi bởi {@see RecordMcpToolCall} sau mỗi lần gọi tool đọc thành công, với số bản
 * ghi lần đó trả về (số id khác nhau trong kết quả, `App\Support\Mcp\ToolAuditFields`).
 *
 * - **Đếm theo người**, trong một cửa sổ {@see self::WINDOW_SECONDS} giây mở từ lần đọc đầu
 *   (`RateLimiter::increment()` trên cache mặc định của app — `database` ở máy thật, cùng store với
 *   rate limit; đừng đổi sang `array`, xem `App\Support\Mcp\McpRateLimits`).
 * - **Cảnh báo khi bộ đếm của cửa sổ đang > 200** (lần gọi đầu tiên đưa nó qua ngưỡng, hoặc lần gọi
 *   đầu tiên sau khi khoá dưới đây hết hạn mà người đó vẫn đang ở trên ngưỡng).
 * - **Tối đa một cảnh báo mỗi giờ cho mỗi người**, kể cả khi cửa sổ đếm đã sang lượt mới mà cảnh báo
 *   trước chưa đủ một giờ: một khoá riêng giữ chỗ một giờ kể từ lần cảnh báo. Các lần đọc sau trong
 *   giờ đó không cảnh báo lại.
 * - Người nhận: {@see ResolveStaffRecipients::forAiOversight()} (người bật/tắt được truy cập AI, đang
 *   hoạt động), thông báo trong hệ thống ({@see McpReadVolumeAlert}), không thư. Gọi ngoài mọi
 *   transaction (sau khi đã có phản hồi của tool).
 */
final class AlertOnMcpReadVolume
{
    public const THRESHOLD = 200;

    public const WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly ResolveStaffRecipients $recipients,
    ) {}

    public function handle(User $reader, int $records): void
    {
        if ($records < 1) {
            return;
        }

        $read = $this->limiter->increment('mcp:read-volume:'.$reader->getKey(), self::WINDOW_SECONDS, $records);

        if ($read <= self::THRESHOLD) {
            return;
        }

        $slot = 'mcp:read-volume-alert:'.$reader->getKey();

        if ($this->limiter->tooManyAttempts($slot, 1)) {
            return;
        }

        $this->limiter->hit($slot, self::WINDOW_SECONDS);

        foreach ($this->recipients->forAiOversight() as $recipient) {
            $recipient->notify(new McpReadVolumeAlert($reader));
        }
    }
}
