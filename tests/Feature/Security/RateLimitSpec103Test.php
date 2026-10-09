<?php

use App\Http\Middleware\Mcp\AuditToolCall;
use App\Http\Middleware\Mcp\ThrottleMcp;
use App\Mcp\Servers\CrmServer;
use App\Support\Mcp\McpRateLimits;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| SPEC §10.3 — phần không phải đăng nhập / tải tệp, và §10.6 giữ nhật ký (M8 Task 3)
|--------------------------------------------------------------------------
|
| Đăng nhập nhân sự: `tests/Feature/Filament/StaffLoginThrottleTest.php`. Đăng nhập khách:
| `tests/Feature/Portal/LoginTest.php`. Tải tệp: `tests/Feature/Http/UploadFileCountThrottleTest.php`.
| Ở đây là hai mục còn lại của task.
*/

/**
 * "API 60 request/phút": API duy nhất của app là máy chủ MCP `POST /mcp` (M11), KHÔNG nằm dưới
 * `api/*`. Giới hạn 60 lần một phút gắn ở đó (M11 R8, Task 8): test ngay dưới chỉ thẳng vào route
 * `/mcp`, và `tests/Feature/Mcp/RateLimitTest.php` đo lần gọi thứ 61 qua HTTP thật. Test này vẫn
 * khẳng định không có route `api/*`, để ngày có một API thứ hai test đỏ và buộc người thêm route
 * phải tới đây, gắn giới hạn, và sửa test có chủ đích — thay vì một API ra đời không có giới hạn nào.
 */
it('§10.3 has no api/* route, so the only API is /mcp and its limit is pinned below', function () {
    $api = collect(Route::getRoutes())
        ->filter(fn ($route): bool => $route->uri() === 'api' || str_starts_with($route->uri(), 'api/'))
        ->map(fn ($route): string => $route->uri())
        ->values()
        ->all();

    expect($api)->toBe([]);
});

/**
 * M11 R8 (Task 8): route `POST /mcp` mang hai middleware của giới hạn — `AuditToolCall` (trạng thái
 * của lần gọi) rồi `ThrottleMcp` (429 kèm `Retry-After`, `X-RateLimit-*`) — và con số chung của mọi
 * lần gọi tool đúng là 60 lần một phút. Hành vi đo qua HTTP ở `tests/Feature/Mcp/RateLimitTest.php`.
 */
it('§10.3 puts the 60-requests-a-minute limit on the /mcp API route', function () {
    $route = collect(Route::getRoutes())
        ->first(fn ($route): bool => $route->uri() === CrmServer::PATH && in_array('POST', $route->methods(), true));

    expect($route)->not->toBeNull();

    $middleware = $route->middleware();

    expect($middleware)->toContain(AuditToolCall::class)
        ->toContain(ThrottleMcp::class)
        ->and(array_search(ThrottleMcp::class, $middleware, true))->toBe(array_search(AuditToolCall::class, $middleware, true) + 1)
        ->and(McpRateLimits::PER_MINUTE)->toBe(60);
});

it('§10.3 does not register the api routing file either, so a route cannot appear there unnoticed', function () {
    expect(file_exists(base_path('routes/api.php')))->toBeFalse()
        ->and((string) file_get_contents(base_path('bootstrap/app.php')))->not->toMatch('/api\s*:\s*__DIR__/');
});

/**
 * Nhật ký là chứng cứ (SPEC §10.6) và `RegroupDocument` đọc lại dòng `document_regrouped` cũ để
 * quyết định; xoá dòng cũ đổi kết quả nghiệp vụ. Phán quyết (M8 Task 3, đóng minor M-8 của M6.5):
 * KHÔNG lên lịch `activitylog:clean`, và con số "xoá bản ghi cũ hơn N ngày" của gói không được
 * thấp hơn thời hạn lưu hồ sơ nếu ai đó chạy lệnh bằng tay.
 */
it('§10.6 schedules no task that runs activitylog:clean', function () {
    $offenders = collect(Schedule::events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'activitylog:clean'))
        ->map(fn (Event $event): string => (string) $event->command)
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('§10.6 keeps the activity log for at least the office retention period even if someone runs the clean command by hand', function () {
    expect((int) config('activitylog.delete_records_older_than_days'))
        ->toBeGreaterThanOrEqual(((int) config('vkcrm.retention_years')) * 365);
});
