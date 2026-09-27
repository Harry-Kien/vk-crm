<?php

use App\Actions\Schedule\RecordScheduleRun;
use App\Actions\Schedule\SendHeartbeat;
use App\Enums\Role;
use App\Filament\Admin\Widgets\SystemHealthWidget;
use App\Models\ClientUser;
use App\Models\SystemHealth;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

// ---------------------------------------------------------------------------------------------
// Mạch đập của scheduler
// ---------------------------------------------------------------------------------------------

it('records that the schedule ran, creating the single row the first time', function () {
    expect(SystemHealth::query()->count())->toBe(0);

    (new RecordScheduleRun)->handle();

    $health = SystemHealth::query()->sole();

    expect($health->last_schedule_run_at)->not->toBeNull()
        ->and($health->singleton)->toBe(1);
});

it('keeps exactly one row however many times the schedule runs', function () {
    (new RecordScheduleRun)->handle();
    (new RecordScheduleRun)->handle();
    (new RecordScheduleRun)->handle();

    // Bảng này tồn tại để trả lời MỘT câu hỏi. Hai dòng nghĩa là hai câu trả lời, và chúng sẽ
    // lệch nhau vào đúng lúc cron chết — tức đúng lúc con số này là thứ duy nhất đáng tin.
    expect(SystemHealth::query()->count())->toBe(1);
});

it('moves the timestamp forward on a later run', function () {
    $this->travelTo(now()->subHour());
    (new RecordScheduleRun)->handle();
    $first = SystemHealth::query()->sole()->last_schedule_run_at;

    $this->travelBack();
    (new RecordScheduleRun)->handle();

    expect(SystemHealth::query()->sole()->last_schedule_run_at->gt($first))->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Heartbeat — luật quan trọng nhất là nó không bao giờ được ném
// ---------------------------------------------------------------------------------------------

it('pings the monitoring service and records the moment', function () {
    config(['vkcrm.heartbeat_url' => 'https://giam-sat.test/ping']);
    Http::fake(['giam-sat.test/*' => Http::response('OK', 200)]);

    (new SendHeartbeat)->handle();

    $health = SystemHealth::query()->sole();

    expect($health->last_heartbeat_at)->not->toBeNull()
        ->and($health->last_heartbeat_error)->toBeNull();
});

it('survives a monitoring service that has stopped answering', function () {
    config(['vkcrm.heartbeat_url' => 'https://giam-sat.test/ping']);
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    // Cả tác vụ này tồn tại để phát hiện hệ thống chết. Nếu chính nó làm chết lịch thì cái đồng
    // hồ báo cháy trở thành đám cháy.
    (new SendHeartbeat)->handle();

    $health = SystemHealth::query()->sole();

    expect($health->last_heartbeat_at)->toBeNull()
        ->and($health->last_heartbeat_error)->toContain('Connection timed out')
        // Tên lớp đi kèm, vì "Connection timed out" đọc giống hệt nhau cho một tên miền sai,
        // một cổng bị chặn và một dịch vụ quá tải.
        ->and($health->last_heartbeat_error)->toContain('ConnectionException');
});

it('survives a monitoring service that answers with an error code', function () {
    config(['vkcrm.heartbeat_url' => 'https://giam-sat.test/ping']);
    Http::fake(['giam-sat.test/*' => Http::response('boom', 500)]);

    (new SendHeartbeat)->handle();

    $health = SystemHealth::query()->sole();

    expect($health->last_heartbeat_at)->toBeNull()
        ->and($health->last_heartbeat_error)->toBe('HTTP 500');
});

it('stays silent when no monitoring url is configured, because that is the developer machine', function () {
    config(['vkcrm.heartbeat_url' => null]);
    Http::fake();

    $result = (new SendHeartbeat)->handle();

    // Không gọi, không ghi lỗi, không tạo dòng. Một cảnh báo mỗi năm phút trên máy dev sẽ dạy
    // người ta bỏ qua cảnh báo.
    expect($result)->toBeNull()
        ->and(SystemHealth::query()->count())->toBe(0);

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------------------------
// Dải cảnh báo trên trang chủ — ba trạng thái
// ---------------------------------------------------------------------------------------------

function systemHealthWidgetData(): array
{
    return (new SystemHealthWidget)->getViewData();
}

it('warns that the schedule has never run when there is no row at all', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    $data = systemHealthWidgetData();

    // Trạng thái một lần triển khai mới thật sự gặp, và là trạng thái dễ quên nhất.
    expect($data['neverRan'])->toBeTrue()
        ->and($data['stale'])->toBeFalse();
});

/**
 * **Nhân chứng riêng cho `scheduleHasNeverRun()`.**
 *
 * Đo được: đổi hàm đó thành `return false` thì cả 15 test vẫn xanh, vì test phía trên không có
 * DÒNG nào cả, nên câu trả lời đến từ vế `$health === null` và hàm không bao giờ được gọi tới.
 * Cùng hình dạng đã bắt được sáu lần ở milestone trước: một test đi tới đúng kết luận vì một lý
 * do khác với lý do nó mang tên.
 *
 * Tình huống này có thật chứ không phải dựng ra: heartbeat chạy trước và TẠO dòng, trong khi
 * tác vụ chạm mốc thời gian chưa bao giờ chạy thành công. Khi đó dòng tồn tại nhưng cột rỗng —
 * và đó đúng là lúc văn phòng cần thấy cảnh báo nhất.
 */
it('warns that the schedule has never run when the row exists but the column is empty', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    // Tiền đề: dòng CÓ tồn tại, nên vế `$health === null` không thể trả lời thay.
    $health = SystemHealth::current();
    expect($health->exists)->toBeTrue()
        ->and($health->last_schedule_run_at)->toBeNull();

    $data = systemHealthWidgetData();

    expect($data['neverRan'])->toBeTrue()
        ->and($data['stale'])->toBeFalse();
});

it('warns that the schedule has stopped when the last run is older than the threshold', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    SystemHealth::current()->forceFill([
        'last_schedule_run_at' => now()->subMinutes(SystemHealth::STALE_AFTER_MINUTES + 1),
    ])->save();

    $data = systemHealthWidgetData();

    expect($data['stale'])->toBeTrue()
        ->and($data['neverRan'])->toBeFalse();
});

it('says nothing at all while the schedule is running normally', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    SystemHealth::current()->forceFill(['last_schedule_run_at' => now()->subMinute()])->save();

    $data = systemHealthWidgetData();

    expect($data['stale'])->toBeFalse()
        ->and($data['neverRan'])->toBeFalse();
});

it('paints the warning with a registered Filament colour and emits no css class', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    $markup = view('filament.admin.widgets.system-health', systemHealthWidgetData())->render();

    // Dự án không có bước build CSS: một class viết tay render ra KHÔNG CÓ GÌ, và hai tính năng
    // đã từng xuất xưởng vô hình vì đúng lỗi đó.
    expect($markup)->toContain('var(--danger-600)')
        ->and($markup)->toContain('var(--danger-50)')
        ->and($markup)->not->toContain('class=');
});

it('hides the banner from a client portal account', function () {
    $this->actingAs(ClientUser::factory()->create(), 'client');

    expect(SystemHealthWidget::canView())->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Lịch: múi giờ và ba tác vụ
// ---------------------------------------------------------------------------------------------

it('runs the schedule on Vietnam time, so an 0700 job is 0700 at the office', function () {
    // Một tác vụ "07:00" chạy theo giờ UTC sẽ gửi thư nhắc hạn lúc 2 giờ sáng.
    expect(config('app.timezone'))->toBe('Asia/Ho_Chi_Minh');
});

/**
 * Vòng sửa 1, I1: `->name()` chỉ là bí danh của `->description()` trong Laravel 13 (cả hai ghi
 * cùng một property `$description`) — một lời gọi cả hai chỉ còn giữ giá trị của lời gọi SAU
 * CÙNG, và bản routes/console.php trước bản sửa này gọi `->name($id)` RỒI `->description($text)`,
 * nên định danh ổn định `$id` bị mất hẳn, chỉ còn lại chuỗi tiếng Việt. Bản sửa giữ ĐÚNG MỘT giá
 * trị ổn định cho mỗi tác vụ (chuỗi định danh, ví dụ `queue.drain`) bằng cách chỉ gọi `->name()`,
 * bỏ hẳn `->description()` — nên các test dưới đây tra theo ĐÚNG chuỗi định danh đó, không phải
 * câu tiếng Việt (câu tiếng Việt vẫn còn, nhưng nay chỉ nằm trong docblock phía trên mỗi khai báo).
 */
it('registers the heartbeat, the health touch and the queue drain', function () {
    $names = collect(Schedule::events())
        ->map(fn ($event) => $event->description)
        ->filter()
        ->values()
        ->all();

    expect($names)->toContain('system-health.touch')
        ->and($names)->toContain('system-health.heartbeat')
        ->and($names)->toContain('queue.drain');
});

it('never lets the queue drain overlap itself', function () {
    $drain = collect(Schedule::events())
        ->first(fn ($event) => $event->description === 'queue.drain');

    // Cron gọi mỗi phút. Không có khoá này, một hàng đợi bận sẽ chồng tiến trình lên nhau cho
    // tới khi máy chủ hết bộ nhớ.
    expect($drain->withoutOverlapping)->toBeTrue();
});

it('lets a killed queue drain hold its overlap lock for ten minutes at most, not a whole day', function () {
    $drain = collect(Schedule::events())
        ->first(fn ($event) => $event->description === 'queue.drain');

    // `withoutOverlapping()` trần giữ khoá 1440 phút. Trên shared hosting tiến trình rút hàng đợi
    // hay bị giết giữa chừng (giới hạn CPU/thời gian của nhà cung cấp) — khi đó khoá không được
    // nhả, và mọi lần cron sau bị bỏ qua suốt 24 giờ: thư, lần rà xung đột lợi ích, tất cả nằm im
    // trong bảng `jobs`, trong khi đồng hồ sức khoẻ (một tác vụ KHÁC) vẫn báo xanh. Mỗi lần rút
    // tự dừng sau `--max-time=50` giây, nên 10 phút vẫn rộng gấp mười hai lần một lần chạy thật.
    expect($drain->expiresAt)->toBe(10);
});

/** Vòng sửa 1, I1: mốc nhắc hạn cũng phải tra được theo đúng định danh ổn định của nó. */
it('registers the deadline check under its stable id', function () {
    $names = collect(Schedule::events())
        ->map(fn ($event) => $event->description)
        ->filter()
        ->values()
        ->all();

    expect($names)->toContain('deadlines.check');
});
