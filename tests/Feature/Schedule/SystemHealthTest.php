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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Livewire\Livewire;

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

// Trang chủ tải trễ widget này qua Livewire, và Livewire ném RootTagMissingFromViewException khi không
// tìm thấy phần tử gốc. Test ở trên chỉ đọc hai cờ dữ liệu nên không thấy điều đó: view từng rỗng hẳn
// đúng lúc hệ thống KHOẺ, tức mọi nhân sự nhận lỗi 500 trên trang chủ của một máy chủ có cron chạy
// đúng. Máy dev không lộ ra vì ở đó lịch chưa từng chạy, dải "chưa từng chạy" luôn hiện.
it('renders through Livewire while the schedule is running normally: one hidden root and no banner', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    SystemHealth::current()->forceFill(['last_schedule_run_at' => now()->subMinute()])->save();

    Livewire::test(SystemHealthWidget::class)
        ->assertOk()
        ->assertSeeHtml('data-state="idle"')
        ->assertDontSeeHtml('data-widget="system-health"')
        ->assertDontSeeHtml('data-widget="document-store-health"');

    $markup = trim(view('filament.admin.widgets.system-health', systemHealthWidgetData())->render());

    // Gốc lúc im lặng không được chiếm ô nào trên lưới trang chủ, và không mang chữ nào.
    expect($markup)->toStartWith('<div')
        ->and($markup)->toContain('display: none;')
        ->and(trim(strip_tags($markup)))->toBe('')
        ->and($markup)->not->toContain('class=');
});

// Gốc phải đứng NGOÀI mọi `@if`: trong một component, Livewire chèn dấu `<!--[if BLOCK]>` trước mỗi `@if`,
// nên một gốc nằm trong `@if` không còn đứng đầu dòng. Bản trước chạy được lúc có dải chỉ vì Livewire
// vớ nhầm một thẻ CON đứng đầu dòng và gắn `wire:id` vào đó. Test này đo đúng điều ấy ở cả hai trạng
// thái: thẻ mang `wire:id` chính là gốc `system-health-root`.
it('puts the Livewire attributes on the one real root in both states', function (bool $healthy) {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    SystemHealth::current()->forceFill(['last_schedule_run_at' => $healthy ? now()->subMinute() : null])->save();

    $html = trim(Livewire::test(SystemHealthWidget::class)->assertOk()->html());

    expect($html)->toMatch('/^<div\s+wire:[^>]*data-widget="system-health-root"[^>]*data-state="'.($healthy ? 'idle' : 'alert').'"/s')
        ->and(substr_count($html, 'wire:id='))->toBe(1)
        ->and(str_contains($html, 'data-widget="system-health"'))->toBe(! $healthy);
})->with(['lịch chạy bình thường' => true, 'lịch chưa từng chạy' => false]);

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

/**
 * Final review X6 (B-I2): `deadlines.check` chạy lần đầu lúc 07:00 giờ Việt Nam, rồi mỗi 30 phút
 * tới 19:30 (trong khung 07:00–20:00) — lần chạy lặp lại vô hại (khoá dòng + `reminders_sent` +
 * sổ thư bậc@ngày), và một phút cron bị bỏ lỡ trên shared hosting không còn làm mất cả một ngày
 * nhắc hạn. Cron thuần `*\/30 7-19`, không `->between()`: `between()` chụp `now()` lúc lịch được
 * dựng, nên `isDue()` sau `travelTo()` sẽ trả lời theo giờ khởi động của bộ test.
 *
 * Giờ Việt Nam, không phải UTC: 07:00 Việt Nam là 00:00 UTC (ngoài khung nếu sự kiện rơi về
 * UTC), còn 21:00 Việt Nam là 14:00 UTC (trong khung nếu rơi về UTC) — hai phép `isDue()` đó bắt
 * đúng lỗi múi giờ mà test cũ ở vị trí này bắt.
 */
it('says the deadline check is due at 07:00 Vietnam time and every 30 minutes until 19:30, and only then', function () {
    $event = collect(Schedule::events())->first(fn ($e) => $e->description === 'deadlines.check');

    expect($event)->not->toBeNull();

    $today = today()->startOfDay();

    foreach (['07:00', '07:30', '12:00', '19:30'] as $due) {
        [$h, $m] = explode(':', $due);
        $this->travelTo($today->copy()->setTime((int) $h, (int) $m));
        expect($event->isDue(app()))->toBeTrue("phải tới hạn lúc {$due}");
    }

    foreach (['00:00', '06:30', '07:10', '20:00', '20:30', '21:00'] as $notDue) {
        [$h, $m] = explode(':', $notDue);
        $this->travelTo($today->copy()->setTime((int) $h, (int) $m));
        expect($event->isDue(app()))->toBeFalse("không được tới hạn lúc {$notDue}");
    }
});

/**
 * Final review X6 (B-I2): `withoutOverlapping()` trần giữ khoá 1440 phút — một lần 07:00 bị giết
 * giữa chừng (giới hạn CPU của shared hosting) khoá luôn lần chạy của ngày hôm sau. Cùng lý lẽ đã
 * áp cho `queue.drain`: khoá phải hết hạn trong thời gian một lần chạy thật còn có thể kéo dài.
 */
it('lets a killed deadline check hold its overlap lock for an hour at most, and the heartbeat for ten minutes', function () {
    $events = collect(Schedule::events());

    $check = $events->first(fn ($e) => $e->description === 'deadlines.check');
    $heartbeat = $events->first(fn ($e) => $e->description === 'system-health.heartbeat');

    expect($check->withoutOverlapping)->toBeTrue()
        ->and($check->expiresAt)->toBe(60)
        ->and($heartbeat->withoutOverlapping)->toBeTrue()
        ->and($heartbeat->expiresAt)->toBeLessThanOrEqual(10);
});

// ---------------------------------------------------------------------------------------------
// M6 Task 7 — stale-matters.check
// ---------------------------------------------------------------------------------------------

/** SPEC §6.4: mốc hồ sơ quá hạn cập nhật cũng phải tra được theo đúng định danh ổn định của nó. */
it('registers the stale matters check under its stable id', function () {
    $names = collect(Schedule::events())
        ->map(fn ($event) => $event->description)
        ->filter()
        ->values()
        ->all();

    expect($names)->toContain('stale-matters.check');
});

/**
 * SPEC §6.4: "lịch 07:30 hằng ngày" — MỘT lần một ngày, giờ Việt Nam (cùng lý lẽ
 * `deadlines.check`: 07:30 giờ Việt Nam là 00:30 UTC, ngoài khung nếu sự kiện rơi về UTC).
 */
it('says the stale matters check is due at 07:30 Vietnam time, and only then', function () {
    $event = collect(Schedule::events())->first(fn ($e) => $e->description === 'stale-matters.check');

    expect($event)->not->toBeNull();

    $today = today()->startOfDay();

    $this->travelTo($today->copy()->setTime(7, 30));
    expect($event->isDue(app()))->toBeTrue('phải tới hạn lúc 07:30');

    foreach (['00:00', '07:00', '07:29', '07:31', '12:00', '23:59'] as $notDue) {
        [$h, $m] = explode(':', $notDue);
        $this->travelTo($today->copy()->setTime((int) $h, (int) $m));
        expect($event->isDue(app()))->toBeFalse("không được tới hạn lúc {$notDue}");
    }
});

/**
 * Nó gửi thư và ghi thông báo, nên hai tiến trình chồng nhau là hai lần nhắc cho cùng một người —
 * cùng lý lẽ `deadlines.check`. Khoá hết hạn sau 60 phút, không phải mặc định 1440: một lần chạy
 * bị giết giữa chừng (giới hạn CPU của shared hosting) không được khoá luôn lần chạy NGÀY HÔM SAU.
 */
it('lets a killed stale matters check hold its overlap lock for an hour at most', function () {
    $event = collect(Schedule::events())->first(fn ($e) => $e->description === 'stale-matters.check');

    expect($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});

// ---------------------------------------------------------------------------------------------
// M6 Task 8 — missing-documents.remind
// ---------------------------------------------------------------------------------------------

/** SPEC §6.9: tác vụ nhắc thiếu giấy tờ phải tra được theo đúng định danh ổn định của nó. */
it('registers the missing documents reminder under its stable id', function () {
    $names = collect(Schedule::events())
        ->map(fn ($event) => $event->description)
        ->filter()
        ->values()
        ->all();

    expect($names)->toContain('missing-documents.remind');
});

/**
 * SPEC §6.9: "thứ Hai/Tư/Sáu 08:00" — giờ Việt Nam (08:00 Việt Nam là 01:00 UTC; nếu sự kiện rơi về
 * UTC thì `isDue()` lúc 08:00 giờ máy Việt Nam sai). Ngày cố định trong tuần, không phụ thuộc hôm nay
 * là thứ mấy: 2026-10-05 là thứ Hai.
 *
 * Mutation probe: đổi cron thành `0 8 * * 1,3` (bỏ thứ Sáu) — hàng thứ Sáu ĐỎ; thành `0 8 * * *`
 * — hàng thứ Ba ĐỎ; thành `0 9 * * 1,3,5` — mọi hàng 08:00 ĐỎ.
 */
it('says the missing documents reminder is due Monday, Wednesday and Friday at 08:00 Vietnam time, and only then', function () {
    $event = collect(Schedule::events())->first(fn ($e) => $e->description === 'missing-documents.remind');

    expect($event)->not->toBeNull();

    foreach (['2026-10-05' => 'thứ Hai', '2026-10-07' => 'thứ Tư', '2026-10-09' => 'thứ Sáu'] as $day => $label) {
        $this->travelTo(Carbon::parse($day.' 08:00:00'));
        expect($event->isDue(app()))->toBeTrue("phải tới hạn lúc 08:00 {$label}");

        foreach (['00:00', '07:59', '08:01', '09:00', '20:00'] as $notDue) {
            $this->travelTo(Carbon::parse($day.' '.$notDue.':00'));
            expect($event->isDue(app()))->toBeFalse("không được tới hạn lúc {$notDue} {$label}");
        }
    }

    foreach (['2026-10-06' => 'thứ Ba', '2026-10-08' => 'thứ Năm', '2026-10-10' => 'thứ Bảy', '2026-10-11' => 'Chủ nhật'] as $day => $label) {
        $this->travelTo(Carbon::parse($day.' 08:00:00'));
        expect($event->isDue(app()))->toBeFalse("không được tới hạn lúc 08:00 {$label}");
    }
});

/** Nó gửi thư và ghi thông báo — cùng lý lẽ `deadlines.check`: khoá hết hạn sau 60 phút, không phải 1440. */
it('lets a killed missing documents reminder hold its overlap lock for an hour at most', function () {
    $event = collect(Schedule::events())->first(fn ($e) => $e->description === 'missing-documents.remind');

    expect($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});

// ---------------------------------------------------------------------------------------------
// M12 R12 — hàng đợi `push` rút riêng
// ---------------------------------------------------------------------------------------------

/**
 * `PushAlert` nằm trên hàng đợi `push`; `queue.drain` (không `--queue`) chỉ rút hàng `default`, nên
 * không mục lịch này thì không thông báo đẩy nào rời máy chủ. Mỗi phút, `--stop-when-empty`,
 * `--max-time=50` như `queue.drain`: máy chủ push chậm (hạn 10 giây mỗi máy) giữ lượt rút của CHÍNH
 * nó, không giữ thư nhắc hạn.
 *
 * Mutation probe: xoá mục lịch `queue.push` ở `routes/console.php` → ĐỎ.
 */
it('drains the push queue every minute with its own worker, stopping when empty within 50 seconds', function () {
    $push = collect(Schedule::events())->first(fn ($event) => $event->description === 'queue.push');

    expect($push)->not->toBeNull()
        ->and($push->command)->toEndWith("'artisan' queue:work --queue=push --stop-when-empty --max-time=50")
        ->and($push->expression)->toBe('* * * * *');
});

/**
 * `withoutOverlapping()` có hạn: hai tiến trình rút cùng hàng `push` là hai lần gửi cùng một job khi
 * một lượt chạy quá `retry_after`; khoá mặc định 1440 phút thì một tiến trình bị giết tắt push cả ngày.
 * Năm phút — mỗi lượt tự dừng sau 50 giây.
 *
 * Việc sau gộp M12 (làn fu4, mục 3): và chạy NỀN. `schedule:run` chạy các mục của một phút lần lượt
 * trong cùng tiến trình; `queue.push` đứng trước `queue.handover` và các tác vụ hằng ngày của
 * M7/M9/M10, nên chạy tiền cảnh thì một phút bận (máy chủ push chậm, tới 50 giây) bắt mọi mục sau nó
 * chờ. Khoá `withoutOverlapping` vẫn giữ tới `schedule:finish` của lệnh nền.
 *
 * Mutation probe (báo cáo fu4): bỏ `->runInBackground()` ở mục `queue.push` → ĐỎ.
 */
it('never lets the push queue drain overlap itself, frees a killed drain lock within five minutes, and runs it in the background', function () {
    $push = collect(Schedule::events())->first(fn ($event) => $event->description === 'queue.push');

    expect($push->withoutOverlapping)->toBeTrue()
        ->and($push->expiresAt)->toBe(5)
        ->and($push->runInBackground)->toBeTrue();
});
