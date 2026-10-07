<?php

use App\Actions\Push\SendPushAlert;
use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\PushTopic;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\User;
use App\Notifications\PushAlert;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Support\FakePushServer;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| M12 Task 7 — `SendPushAlert`, `PushAlert`, hàng đợi `push`, nhật ký `outbound_messages` (R10–R13)
|--------------------------------------------------------------------------
|
| `SendPushAlert` là ĐƯỜNG DUY NHẤT xếp một thông báo đẩy (Task 8/9 gọi nó từ đúng nơi gửi thư). Ở đây
| nó được gọi thẳng — Task 7 chưa nối sự kiện nào; đường sản phẩm (màn hình, HTTP) là của Task 8/9 và
| của nút "Gửi thử" (`SendTestPushTest`).
|
| Máy chủ push là bản giả ở tầng HTTP ({@see FakePushServer}): kênh của gói mã hoá, ký VAPID, gửi, đọc
| mã trả lời thật. Hàng đợi `sync` của `phpunit.xml` chạy job ngay sau commit.
*/

beforeEach(function () {
    config(WebPushTestKeys::config());
});

/** Một dòng tiến độ đã công bố của một vụ, và một tài khoản cổng R12 của đúng khách hàng đó. */
function pushAlertStageLog(): array
{
    $log = StageLog::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $log->matter->client_id]);

    return [$log, $account];
}

/**
 * Mọi câu `INSERT` vào `$table` hỏng như một lỗi CSDL thật (khoá chờ quá hạn). KHÔNG `Schema::drop()`:
 * trên MariaDB (`test:mariadb`) một câu DDL tự commit transaction của `RefreshDatabase`, và bảng mất
 * luôn cho mọi test chạy sau trong cùng tiến trình.
 */
function pushAlertFailInsertsInto(string $table): void
{
    DB::beforeExecuting(function (string $sql, array $bindings) use ($table): void {
        if (preg_match('/^insert into [`"]?'.preg_quote($table, '/').'[`"]?\s/i', $sql) === 1) {
            throw new QueryException(DB::getDefaultConnection(), $sql, $bindings, new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded'));
        }
    });
}

/** Mọi dòng nhật ký của kênh push, cũ trước. */
function pushLedgerRows()
{
    return OutboundMessage::query()->withoutGlobalScopes()->where('channel', OutboundChannel::Push)->orderBy('id')->get();
}

/**
 * Plan Task 7: "Người nhận không có thiết bị: không job, không dòng nhật ký."
 *
 * Mutation probe: bỏ lời hỏi `pushSubscriptions()->exists()` ở `SendPushAlert::handle()` → một job
 * được xếp cho người không có máy nào → ĐỎ.
 */
it('queues nothing and writes no ledger row for a recipient without a device', function () {
    Queue::fake();
    [$log, $account] = pushAlertStageLog();

    $queued = app(SendPushAlert::class)->handle(collect([$account]), PushTopic::ClientStageUpdate, $log);

    expect($queued)->toBe(0);
    Queue::assertNothingPushed();
    expect(OutboundMessage::query()->withoutGlobalScopes()->count())->toBe(0);
});

/**
 * R12: `PushAlert` là Laravel Notification `ShouldQueue`, `afterCommit`, `tries = 3`,
 * `backoff = [60, 300]`, trên hàng đợi RIÊNG `push`, kênh `WebPushChannel`. Mỗi người nhận có máy
 * một job; người không có máy không có job.
 */
it('queues one PushAlert per recipient with a device, on the push queue, after commit, three tries', function () {
    Queue::fake();
    [$log, $first] = pushAlertStageLog();
    $second = ClientUser::factory()->activated()->create(['client_id' => $first->client_id]);
    $without = ClientUser::factory()->activated()->create(['client_id' => $first->client_id]);
    FakePushServer::device($first, 'mot');
    FakePushServer::device($first, 'mot-b');
    FakePushServer::device($second, 'hai');

    $queued = app(SendPushAlert::class)->handle(collect([$first, $second, $without]), PushTopic::ClientStageUpdate, $log);

    expect($queued)->toBe(2);
    Queue::assertPushedOn('push', SendQueuedNotifications::class);
    Queue::assertPushed(SendQueuedNotifications::class, 2);
    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
        return $job->notification instanceof PushAlert
            && $job->channels === [WebPushChannel::class]
            && $job->queue === 'push'
            && $job->afterCommit === true
            && $job->tries === 3
            && $job->backoff() === [60, 300];
    });

    $notified = collect(Queue::pushed(SendQueuedNotifications::class))
        ->map(fn (SendQueuedNotifications $job): string => $job->notifiables->first()->getMorphClass().':'.$job->notifiables->first()->getKey())
        ->sort()->values()->all();

    expect($notified)->toBe(collect(['client_user:'.$first->id, 'client_user:'.$second->id])->sort()->values()->all());
});

it('queues the alert once when the same person is in the collection twice', function () {
    Queue::fake();
    [$log, $account] = pushAlertStageLog();
    FakePushServer::device($account, 'trung');

    $queued = app(SendPushAlert::class)->handle(collect([$account, $account->fresh()]), PushTopic::ClientStageUpdate, $log);

    expect($queued)->toBe(1);
    Queue::assertPushed(SendQueuedNotifications::class, 1);
});

/**
 * Hàng đợi THẬT (`database`, cấu hình của máy chủ): job nằm ở hàng `push`, hàng `default` trống —
 * lượt `queue.drain` (không `--queue`) không bao giờ rút nó, nên lịch `queue.push` là bắt buộc
 * (`SystemHealthTest`).
 */
it('writes the job to the push queue of the database connection, never to the default queue', function () {
    config(['queue.default' => 'database']);
    [$log, $account] = pushAlertStageLog();
    FakePushServer::device($account, 'csdl');

    app(SendPushAlert::class)->handle(collect([$account]), PushTopic::ClientStageUpdate, $log);

    expect(DB::table('jobs')->where('queue', 'push')->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', '!=', 'push')->count())->toBe(0);
});

/**
 * R12 + M6.5 R2: xếp SAU commit. Transaction rollback thì không job nào chạy — không request ra máy
 * chủ push, không dòng nhật ký. Chạy trên hàng đợi `sync` THẬT (không `Queue::fake()`, bản giả
 * không biết `afterCommit`): thiếu `afterCommit`, job chạy NGAY trong transaction và request đã đi.
 *
 * Mutation probe: bỏ `$this->afterCommit()` ở hàm dựng `PushAlert` → request được gửi → ĐỎ.
 */
it('sends nothing when the surrounding transaction rolls back', function () {
    $server = FakePushServer::start();
    [$log, $account] = pushAlertStageLog();
    FakePushServer::device($account, 'rollback');

    try {
        DB::transaction(function () use ($log, $account): void {
            app(SendPushAlert::class)->handle(collect([$account]), PushTopic::ClientStageUpdate, $log);

            throw new RuntimeException('rollback của test');
        });
    } catch (RuntimeException) {
    }

    expect($server->requests)->toBe([])
        ->and(pushLedgerRows())->toHaveCount(0)
        ->and($account->pushSubscriptions()->count())->toBe(1);
});

it('sends after the surrounding transaction commits', function () {
    $server = FakePushServer::start();
    [$log, $account] = pushAlertStageLog();
    $endpoint = FakePushServer::device($account, 'commit');

    DB::transaction(function () use ($log, $account): void {
        app(SendPushAlert::class)->handle(collect([$account]), PushTopic::ClientStageUpdate, $log);
    });

    expect($server->endpoints())->toBe([$endpoint]);
});

/**
 * R7: thiếu khoá VAPID thì push tắt êm — không job.
 *
 * Mutation probe: bỏ lời hỏi `VapidKeys::configured()` ở `SendPushAlert::handle()` → job được xếp → ĐỎ.
 */
it('queues nothing when the server has no VAPID keys', function () {
    Queue::fake();
    [$log, $account] = pushAlertStageLog();
    FakePushServer::device($account, 'khong-khoa');
    config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);

    $queued = app(SendPushAlert::class)->handle(collect([$account]), PushTopic::ClientStageUpdate, $log);

    expect($queued)->toBe(0);
    Queue::assertNothingPushed();
});

/**
 * Khoá bị gỡ GIỮA lúc xếp và lúc job chạy (người vận hành xoá dòng `VAPID_*` rồi `config:cache`):
 * `PushAlert::shouldSend()` hỏi lại `VapidKeys::configured()`, job kết thúc êm — không request,
 * không dòng `failed` 401/403 nào cho mọi máy.
 *
 * Mutation probe: `shouldSend()` trả `true` → request đi (bản giả trả 201) → ĐỎ.
 */
it('drops a queued alert quietly when the VAPID keys were removed before the queue ran', function () {
    config(['queue.default' => 'database']);
    $server = FakePushServer::start();
    [$log, $account] = pushAlertStageLog();
    FakePushServer::device($account, 'go-khoa');

    app(SendPushAlert::class)->handle(collect([$account]), PushTopic::ClientStageUpdate, $log);
    config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);
    Artisan::call('queue:work', ['--queue' => 'push', '--once' => true, '--stop-when-empty' => true]);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($server->requests)->toBe([])
        ->and(pushLedgerRows())->toHaveCount(0);
});

/**
 * Plan Task 7: "Có hai thiết bị, một trả 201 và một trả 410: một dòng `sent`, một dòng `failed`, và
 * dòng đăng ký 410 bị xoá. Tương tự với 404." Mỗi thiết bị MỘT dòng (R13), `recipient` là
 * `client_user:{id}`, `template` là giá trị `PushTopic`, `related` là bản ghi của THƯ tương ứng.
 */
it('writes one ledger row per device and deletes the subscription the push service reports as gone', function (int $status, string $reason) {
    [$log, $account] = pushAlertStageLog();
    $alive = FakePushServer::endpoint('con-song');
    $gone = FakePushServer::endpoint('da-mat');
    FakePushServer::start([$gone => $status]);
    FakePushServer::device($account, 'con-song');
    FakePushServer::device($account, 'da-mat');

    app(SendPushAlert::class)->handle(collect([$account]), PushTopic::ClientStageUpdate, $log);

    $rows = pushLedgerRows();
    $sent = $rows->firstWhere('status', OutboundStatus::Sent);
    $failed = $rows->firstWhere('status', OutboundStatus::Failed);

    expect($rows)->toHaveCount(2)
        ->and($sent)->not->toBeNull()
        ->and($failed)->not->toBeNull()
        ->and($rows->pluck('recipient')->unique()->all())->toBe(['client_user:'.$account->id])
        ->and($rows->pluck('template')->unique()->all())->toBe(['client.stage_update'])
        ->and($rows->pluck('related_type')->unique()->all())->toBe(['stage_log'])
        ->and($rows->pluck('related_id')->unique()->all())->toBe([$log->id])
        ->and($sent->sent_at)->not->toBeNull()
        ->and($sent->error)->toBeNull()
        ->and($failed->sent_at)->toBeNull()
        ->and($failed->error)->toBe("HTTP {$status} {$reason}")
        ->and($account->pushSubscriptions()->pluck('endpoint')->all())->toBe([$alive]);
})->with([
    '410' => [410, 'Gone'],
    '404' => [404, 'Not Found'],
]);

/**
 * Plan Task 7: "Với 500: đăng ký còn nguyên, dòng `failed` có mã lỗi." Cùng với 401/403 (khoá VAPID
 * lệch — R7: gói không tự dọn, `vkcrm:push-reset` dọn).
 */
it('keeps the subscription and records the status code when the push service fails otherwise', function (int $status) {
    [$log, $account] = pushAlertStageLog();
    $endpoint = FakePushServer::endpoint('loi');
    FakePushServer::start([$endpoint => $status]);
    FakePushServer::device($account, 'loi');

    app(SendPushAlert::class)->handle(collect([$account]), PushTopic::ClientStageUpdate, $log);

    $row = pushLedgerRows()->sole();

    expect($row->status)->toBe(OutboundStatus::Failed)
        ->and($row->error)->toStartWith("HTTP {$status} ")
        ->and($account->pushSubscriptions()->pluck('endpoint')->all())->toBe([$endpoint]);
})->with([500, 401, 403]);

/**
 * R13: "`recipient` là `client_user:12` hoặc `user:7`, không phải endpoint", `payload` chỉ `title` và
 * `body`. Endpoint là một URL mang quyền gửi (R8) — kể cả khi máy chủ push NHẮC LẠI nó trong thân trả
 * lời, hay khi lỗi kết nối của Guzzle ghép nó vào câu báo lỗi ("… for https://…"), cột `error` cũng
 * không mang nó.
 *
 * Mutation probe: bỏ bước làm sạch ở `RecordOutboundPush::error()` → endpoint lọt vào `error` → ĐỎ.
 */
it('never writes the endpoint into the ledger, not even when the push service or Guzzle echoes it', function () {
    [$log, $account] = pushAlertStageLog();
    $echoing = FakePushServer::endpoint('nhac-lai');
    $unreachable = FakePushServer::endpoint('mat-ket-noi');
    FakePushServer::start([
        $echoing => fn (Request $request) => Http::response('{"reason":"BadSubscription","endpoint":"'.$request->url().'"}', 400),
        $unreachable => fn (Request $request) => throw new ConnectException(
            'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.$request->url(),
            new PsrRequest('POST', $request->url()),
        ),
    ]);
    FakePushServer::device($account, 'nhac-lai');
    FakePushServer::device($account, 'mat-ket-noi');

    app(SendPushAlert::class)->handle(collect([$account]), PushTopic::ClientStageUpdate, $log);

    $rows = pushLedgerRows();

    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        $stored = json_encode($row->getAttributes(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        expect($stored)->not->toContain('kiem-thu-')
            ->not->toContain('fcm.googleapis.com')
            ->and($row->recipient)->toBe('client_user:'.$account->id)
            ->and(array_keys($row->payload))->toBe(['title', 'body']);
    }

    expect($rows->firstWhere('error', '!=', null)?->error)->not->toBeNull()
        ->and($rows->pluck('error')->implode(' | '))->toContain('HTTP 400 Bad Request')
        ->toContain('BadSubscription')
        ->toContain('cURL error 28');
});

/**
 * Nhân sự: `recipient` là `user:{id}`; `related` của mốc hạn là `deadline` — đúng bí danh mà thư
 * nhắc hạn mang, nên luật "ai xem dòng nào" của nhật ký thư (`OutboundMessage::scopeVisibleTo()`)
 * áp nguyên cho dòng push. Mốc bậc 1 ngày mang `Urgency: high` và `TTL` 24 giờ (R11).
 */
it('records a staff device as user:{id} with the deadline as related record, high urgency and a 24 hour TTL', function () {
    $server = FakePushServer::start();
    $lawyer = User::factory()->create();
    $deadline = Deadline::factory()->create();
    FakePushServer::device($lawyer, 'luat-su');

    app(SendPushAlert::class)->handle(collect([$lawyer]), PushTopic::StaffDeadlineReminder, $deadline, 'd1');

    $row = pushLedgerRows()->sole();

    expect($row->recipient)->toBe('user:'.$lawyer->id)
        ->and($row->template)->toBe('staff.deadline_reminder')
        ->and($row->related_type)->toBe('deadline')
        ->and($row->related_id)->toBe($deadline->id)
        ->and($row->status)->toBe(OutboundStatus::Sent)
        ->and($server->requests[0]['urgency'])->toBe('high')
        ->and($server->requests[0]['ttl'])->toBe('86400')
        ->and($server->requests[0]['timeout'])->toBe(10);
});

/**
 * "Đo trước" của kế hoạch (R6) — điều kiện thứ tự của {@see FakePushServer}: một bộ chặn đăng ký SAU
 * khi kênh đã được phân giải không bao giờ được hỏi; request đi ra mạng thật (ở đây một cổng đóng
 * của chính máy, lỗi kết nối ngay).
 */
it('a fake registered after the channel was built does not intercept: register it first', function () {
    [$log, $account] = pushAlertStageLog();
    $local = 'https://127.0.0.1:9/kiem-thu-thu-tu';
    $keys = WebPushTestKeys::subscription();
    $account->updatePushSubscription($local, $keys['p256dh'], $keys['auth'], 'aes128gcm');

    app(ChannelManager::class)->channel(WebPushChannel::class);

    $intercepted = 0;
    Http::fake(function () use (&$intercepted) {
        $intercepted++;

        return Http::response('', 201);
    });

    app(SendPushAlert::class)->handle(collect([$account]), PushTopic::ClientStageUpdate, $log);

    $row = pushLedgerRows()->sole();

    // Host đứng một mình được giữ trong câu lỗi ("Failed to connect to 127.0.0.1 port 9"): nó chỉ nói
    // máy chủ push nào. Path của endpoint — phần mang quyền gửi — thì không.
    expect($intercepted)->toBe(0)
        ->and($row->status)->toBe(OutboundStatus::Failed)
        ->and($row->error)->toContain('cURL error')
        ->and($row->error)->not->toContain('kiem-thu-thu-tu')
        ->and($row->error)->not->toContain($local);
});

/**
 * Lời gọi sai (lập trình) ném NGAY, kể cả khi không ai có máy — Task 8/9 thấy lỗi trong test của
 * chính mình, không phải một push im lặng sai bản ghi: bản ghi liên quan sai loại (dòng nhật ký sẽ
 * mang `related_type` khác thư), người nhận sai panel (nội dung và đường dẫn của panel kia), bậc mốc
 * hạn lạ hay bậc trên một chủ đề không có bậc.
 */
it('refuses a misuse before queuing anything', function (Closure $call) {
    Queue::fake();

    expect($call)->toThrow(InvalidArgumentException::class);
    Queue::assertNothingPushed();
})->with([
    'bản ghi sai loại' => [fn () => app(SendPushAlert::class)->handle(collect([ClientUser::factory()->activated()->create()]), PushTopic::ClientStageUpdate, Deadline::factory()->create())],
    'thiếu bản ghi' => [fn () => app(SendPushAlert::class)->handle(collect([ClientUser::factory()->activated()->create()]), PushTopic::ClientStageUpdate, null)],
    'nhân sự nhận chủ đề của khách' => [fn () => app(SendPushAlert::class)->handle(collect([User::factory()->create()]), PushTopic::ClientStageUpdate, StageLog::factory()->create())],
    'khách nhận chủ đề của nhân sự' => [fn () => app(SendPushAlert::class)->handle(collect([ClientUser::factory()->activated()->create()]), PushTopic::StaffDeadlineReminder, Deadline::factory()->create(), 'd1')],
    'mốc hạn thiếu bậc' => [fn () => app(SendPushAlert::class)->handle(collect([User::factory()->create()]), PushTopic::StaffDeadlineReminder, Deadline::factory()->create())],
    'bậc lạ' => [fn () => app(SendPushAlert::class)->handle(collect([User::factory()->create()]), PushTopic::StaffDeadlineReminder, Deadline::factory()->create(), 'd2')],
    'bậc trên chủ đề không có bậc' => [fn () => app(SendPushAlert::class)->handle(collect([ClientUser::factory()->activated()->create()]), PushTopic::ClientStageUpdate, StageLog::factory()->create(), 'd1')],
]);

/**
 * R12 "push hỏng không bao giờ làm hỏng hay chậm thư": `SendPushAlert` chạy SAU khi thư đã xếp, ở
 * nơi gửi thư; một lỗi lúc chạy (ở đây: câu ghi vào bảng `jobs` hỏng) được `report()` và người nhận
 * đó bị bỏ qua — không ngoại lệ nào lên tới nơi gửi thư (listener của nó sẽ chạy lại cả vòng thư).
 *
 * Mutation probe: bỏ `try`/`catch` quanh phần xếp ở `SendPushAlert::handle()` → ngoại lệ lên tới test → ĐỎ.
 */
it('never throws a runtime failure back to the mail path', function () {
    Exceptions::fake();
    config(['queue.default' => 'database']);
    [$log, $account] = pushAlertStageLog();
    FakePushServer::device($account, 'hong-hang-doi');
    pushAlertFailInsertsInto('jobs');

    $queued = app(SendPushAlert::class)->handle(collect([$account]), PushTopic::ClientStageUpdate, $log);

    expect($queued)->toBe(0);
    Exceptions::assertReported(QueryException::class);
});

/**
 * `RecordOutboundPush` chạy BÊN TRONG vòng xử lý báo cáo của kênh: một lần ghi nhật ký hỏng không
 * được cắt ngang báo cáo của máy kế tiếp (đăng ký 410 vẫn bị xoá) hay làm job thử lại (gửi lại tới mọi
 * máy).
 *
 * Mutation probe: bỏ `try`/`catch` ở `RecordOutboundPush::record()` → ngoại lệ lên job → ĐỎ.
 */
it('keeps handling the other devices when a ledger row cannot be written', function () {
    [$log, $account] = pushAlertStageLog();
    $gone = FakePushServer::endpoint('sau-loi-ghi');
    $server = FakePushServer::start([$gone => 410]);
    FakePushServer::device($account, 'truoc-loi-ghi');
    FakePushServer::device($account, 'sau-loi-ghi');
    pushAlertFailInsertsInto('outbound_messages');

    app(SendPushAlert::class)->handle(collect([$account]), PushTopic::ClientStageUpdate, $log);

    expect($server->requests)->toHaveCount(2)
        ->and(pushLedgerRows())->toHaveCount(0)
        ->and($account->pushSubscriptions()->pluck('endpoint')->all())->toBe([FakePushServer::endpoint('truoc-loi-ghi')]);
});
