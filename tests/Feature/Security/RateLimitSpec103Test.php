<?php

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
 * "API 60 request/phút": hôm nay KHÔNG có API. Test khẳng định điều đó, để ngày có route `api/*`
 * (M11 — máy chủ MCP, đã nhận giới hạn 60/phút ở R8 của kế hoạch M11) test này đỏ và buộc người
 * thêm route phải tới đây, gắn giới hạn, và sửa test có chủ đích — thay vì một API ra đời không
 * có giới hạn nào.
 */
it('§10.3 has no api/* route yet, so the 60-requests-a-minute rule has nothing to guard until M11 adds one', function () {
    $api = collect(Route::getRoutes())
        ->filter(fn ($route): bool => $route->uri() === 'api' || str_starts_with($route->uri(), 'api/'))
        ->map(fn ($route): string => $route->uri())
        ->values()
        ->all();

    expect($api)->toBe([]);
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
