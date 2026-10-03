<?php

use App\Actions\Schedule\RemindUnseenUpdates;
use App\Enums\Confidentiality;
use App\Enums\Role;
use App\Filament\Admin\Widgets\UnseenUpdatesWidget;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use App\Notifications\Staff\UnseenUpdatesAlert;
use App\Support\UnseenStageLogs;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

/**
 * SPEC §4.18, §7.1 mục 5 — dòng tiến độ đã công bố quá 5 ngày mà khách chưa mở: luật sư phụ trách
 * được báo TRONG HỆ THỐNG để GỌI ĐIỆN, và khách KHÔNG nhận thêm thư nào (lý do đã nằm trong SPEC:
 * khách không xem thường là khách không dùng được cổng, nên thêm một thư là thêm một thứ họ không
 * đọc).
 *
 * Định nghĩa "khách chưa xem" KHÔNG được đo lại ở đây — nó là của `App\Support\UnseenStageLogs`,
 * dùng chung với `UnseenUpdatesWidget` (`UnseenUpdatesWidgetTest`). Các test "không thuộc tập" bên
 * dưới ghim rằng Action thật sự đi qua nó, và test cuối phần định nghĩa so thẳng hai bên trên cùng
 * một bộ dữ liệu.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Hồ sơ đã công bố cổng, một dòng tiến độ đã công bố cách đây `$daysAgo` ngày mà chưa ai mở.
 *
 * @return array{0: Matter, 1: User, 2: StageLog, 3: ClientUser}
 */
function unseenMatter(int $daysAgo = 9, array $matterOverrides = []): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $matter = Matter::factory()->create(array_merge([
        'client_id' => $client->id,
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => true,
    ], $matterOverrides));

    return [$matter, $lawyer, unseenLog($matter, $daysAgo), $account];
}

function unseenLog(Matter $matter, int|Carbon $publishedAt): StageLog
{
    return StageLog::factory()->for($matter)->published()->create([
        'published_at' => $publishedAt instanceof Carbon ? $publishedAt : now()->subDays($publishedAt),
        'public_content' => 'Nội dung đã công bố cho khách',
        'internal_note' => 'GHI-CHU-NOI-BO-KHONG-DUOC-LO',
    ]);
}

function markSeen(StageLog $log, ClientUser $account): void
{
    StageLogView::factory()->create(['stage_log_id' => $log->id, 'client_user_id' => $account->id]);
}

// ---------------------------------------------------------------------------------------------
// Tập hồ sơ: cặp dương/âm cho MỖI điều kiện của định nghĩa dùng chung
// ---------------------------------------------------------------------------------------------

it('notifies the lead lawyer in-app about a published update nobody opened for more than five days', function () {
    [$matter, $lawyer, $log] = unseenMatter();

    $result = (new RemindUnseenUpdates)->handle();

    $notice = $lawyer->fresh()->notifications;
    expect($result)->toBe(['notified' => 1])
        ->and($notice)->toHaveCount(1)
        ->and($notice->first()->type)->toBe(UnseenUpdatesAlert::class)
        ->and($notice->first()->data['viewData'])->toBe(['matter_id' => $matter->id, 'stage_log_id' => $log->id])
        ->and($notice->first()->data['body'])->toContain($matter->code);
});

/**
 * SPEC §4.18: "không gửi thêm thư cho khách". Mail::fake() bắt cả thư gửi thẳng lẫn thư xếp hàng,
 * và sổ thư là chỗ thứ ba một thư lẻ sẽ để lại dấu.
 *
 * Mutation probe: thêm `Mail::to($account)->queue(...)` vào Action — test này ĐỎ.
 */
it('sends the client nothing at all', function () {
    Mail::fake();
    [, , , $account] = unseenMatter();

    (new RemindUnseenUpdates)->handle();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    expect(OutboundMessage::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($account->fresh()->notifications)->toHaveCount(0);
});

/**
 * Hàng "cặp dương" của mỗi hàng dưới đây là test đầu tiên của file: cùng một dữ liệu, chỉ khác đúng
 * điều kiện đang đo.
 *
 * Mutation probe (mỗi hàng): xoá điều kiện tương ứng khỏi `UnseenStageLogs::query()` — `is_published`,
 * `whereNotNull('published_at')`/`published_at < ...`, `whereDoesntHave('views')`,
 * `is_published_to_portal`, `whereDoesntHave('archive', … clientAccessExpired())` — hàng đó ĐỎ (vẫn
 * có thông báo). Hàng cuối (việc sau gộp M7, làn fu2): vụ đã kết thúc và đã quá hạn tra cứu rời cổng
 * khách (M7 Task 5) dù cờ giữ nguyên; cặp dương của nó là test "still reminds about a closed matter"
 * (còn hạn tra cứu).
 */
it('notifies nobody for an update outside the shared definition', function (Closure $arrange) {
    [$matter, $lawyer, $log, $account] = unseenMatter();
    $arrange($matter, $log, $account);

    $result = (new RemindUnseenUpdates)->handle();

    expect($result)->toBe(['notified' => 0])
        ->and($lawyer->fresh()->notifications)->toHaveCount(0);
})->with([
    'published only yesterday' => [fn (Matter $m, StageLog $l) => $l->update(['published_at' => now()->subDay()])],
    'never published (internal only)' => [fn (Matter $m, StageLog $l) => $l->update(['is_published' => false, 'published_at' => null])],
    'withdrawn after publishing (published_at kept)' => [fn (Matter $m, StageLog $l) => $l->update(['is_published' => false])],
    'the client opened it' => [fn (Matter $m, StageLog $l, ClientUser $a) => markSeen($l, $a)],
    'the matter is off the portal' => [fn (Matter $m) => $m->update(['is_published_to_portal' => false])],
    'the matter is soft-deleted' => [fn (Matter $m) => $m->delete()],
    'the matter is closed and its client access window has passed' => [function (Matter $m) {
        $m->update(['closed_at' => now()->subDays(120)]);
        MatterArchive::factory()->create(['matter_id' => $m->id, 'client_access_until' => today()->subDay()->toDateString()]);
    }],
]);

/** Đồng hồ là `published_at` (SPEC §7.1 mục 5), không phải `occurred_at`: cặp dương và cặp âm trên MỘT bảng. */
it('clocks the five days from published_at, not from occurred_at', function () {
    [$matter, $lawyer, $log] = unseenMatter();
    $log->update(['published_at' => now()->subDay()]);
    DB::table('stage_logs')->where('id', $log->id)->update(['occurred_at' => now()->subDays(40)]);

    expect((new RemindUnseenUpdates)->handle()['notified'])->toBe(0);

    DB::table('stage_logs')->where('id', $log->id)->update(['occurred_at' => now()->subDay(), 'published_at' => now()->subDays(9)]);

    expect((new RemindUnseenUpdates)->handle()['notified'])->toBe(1)
        ->and($lawyer->fresh()->notifications)->toHaveCount(1);
});

/** Ngưỡng "quá 5 ngày" là một bất đẳng thức THẬT: đúng 5 ngày thì chưa, 5 ngày và một phút thì rồi. */
it('is due strictly after five days, not on the fifth', function (string $publishedAt, int $expected) {
    $this->travelTo(Carbon::parse('2026-10-12 08:30:00'));
    [, $lawyer] = unseenMatter(daysAgo: 0);
    StageLog::query()->update(['published_at' => Carbon::parse($publishedAt)]);

    expect((new RemindUnseenUpdates)->handle()['notified'])->toBe($expected)
        ->and($lawyer->fresh()->notifications)->toHaveCount($expected);
})->with([
    'exactly five days' => ['2026-10-07 08:30:00', 0],
    'five days and a minute' => ['2026-10-07 08:29:00', 1],
    'four days' => ['2026-10-08 08:30:00', 0],
]);

/**
 * Cố ý KHÔNG lọc `closed_at` (docblock `UnseenUpdatesWidget`, mục 3): một cập nhật cuối trên một hồ
 * sơ vừa đóng mà khách chưa từng thấy là cuộc gọi đáng gọi nhất. Cặp dương của hàng "matter closed"
 * mà nếu ai đó thêm `->open()` vào định nghĩa dùng chung thì test này ĐỎ.
 */
it('still reminds about a closed matter, exactly like the widget lists it', function () {
    [$matter, $lawyer] = unseenMatter(matterOverrides: ['closed_at' => now()->subDays(2)]);
    // Dòng lưu trữ thật của một vụ vừa kết thúc: còn hạn tra cứu (cặp dương của hàng "client access
    // window has passed" ở trên — chỉ khác ngày).
    MatterArchive::factory()->create(['matter_id' => $matter->id, 'client_access_until' => today()->addDays(88)->toDateString()]);

    expect((new RemindUnseenUpdates)->handle()['notified'])->toBe(1)
        ->and($lawyer->fresh()->notifications)->toHaveCount(1);
});

/** Biên bản là của MỘT tài khoản nhưng câu hỏi là về KHÁCH HÀNG: một trong hai người mở là đủ. */
it('treats the update as seen once any account of the client opened it', function () {
    [$matter, $lawyer, $log, $account] = unseenMatter();
    ClientUser::factory()->activated()->create(['client_id' => $matter->client_id]);
    markSeen($log, $account);

    expect((new RemindUnseenUpdates)->handle()['notified'])->toBe(0);
});

/**
 * Tài khoản chưa kích hoạt hay bị khoá KHÔNG đổi định nghĩa (brief): đó đúng là lúc cần gọi. Hồ sơ
 * chỉ có một tài khoản chưa kích hoạt vẫn được nhắc.
 */
it('still reminds when the client account was never activated or is locked', function (Closure $arrange) {
    [$matter, $lawyer, , $account] = unseenMatter();
    $arrange($account);

    expect((new RemindUnseenUpdates)->handle()['notified'])->toBe(1);
})->with([
    'never activated' => [fn (ClientUser $a) => $a->update(['activated_at' => null])],
    'locked out' => [fn (ClientUser $a) => $a->update(['is_active' => false])],
]);

/** Định nghĩa dùng chung: widget (cho admin, nhìn thấy mọi vụ) và Action nói về CÙNG những hồ sơ. */
it('agrees with the widget about which matters have an unseen update', function () {
    // Trong tập.
    [$inA] = unseenMatter(9);
    [$inB] = unseenMatter(30);
    unseenLog($inB, 8);
    [$inClosed] = unseenMatter(7, ['closed_at' => now()->subDay()]);
    [$inRestricted] = unseenMatter(12, ['confidentiality' => Confidentiality::Restricted]);
    // Vụ đã kết thúc, hôm nay là ngày tra cứu cuối: còn trên cổng (việc sau gộp M7).
    [$inLastDay] = unseenMatter(10, ['closed_at' => now()->subDays(90)]);
    MatterArchive::factory()->create(['matter_id' => $inLastDay->id, 'client_access_until' => today()->toDateString()]);
    // Ngoài tập.
    [$expired] = unseenMatter(10, ['closed_at' => now()->subDays(91)]);
    MatterArchive::factory()->create(['matter_id' => $expired->id, 'client_access_until' => today()->subDay()->toDateString()]);
    unseenMatter(2);
    [, , $seenLog, $seenAccount] = unseenMatter(9);
    markSeen($seenLog, $seenAccount);
    unseenMatter(9, ['is_published_to_portal' => false]);
    [$trashed] = unseenMatter(9);
    $trashed->delete();

    (new RemindUnseenUpdates)->handle();

    $notified = DB::table('notifications')
        ->where('type', UnseenUpdatesAlert::class)
        ->get()
        ->map(fn ($n) => json_decode($n->data, true)['viewData']['matter_id'])
        ->unique()->sort()->values()->all();

    $admin = User::factory()->withRole(Role::Admin)->create();
    $listed = UnseenUpdatesWidget::rowsFor($admin)->pluck('matter_id')->unique()->sort()->values()->all();

    expect($listed)->toBe(collect([$inA->id, $inB->id, $inClosed->id, $inRestricted->id, $inLastDay->id])->sort()->values()->all())
        ->and($notified)->toBe($listed)
        ->and(UnseenStageLogs::AFTER_DAYS)->toBe(UnseenUpdatesWidget::UNSEEN_AFTER_DAYS);
});

// ---------------------------------------------------------------------------------------------
// Chống lặp: một thông báo cho mỗi (người nhận, vụ việc) mỗi "lô chưa xem" — khoá bằng dòng MỚI NHẤT
// ---------------------------------------------------------------------------------------------

it('sends one notice for a matter with three unseen updates, not three', function () {
    [$matter, $lawyer] = unseenMatter(20);
    unseenLog($matter, 15);
    $newest = unseenLog($matter, 8);

    $result = (new RemindUnseenUpdates)->handle();

    $notice = $lawyer->fresh()->notifications;
    expect($result)->toBe(['notified' => 1])
        ->and($notice)->toHaveCount(1)
        ->and($notice->first()->data['viewData']['stage_log_id'])->toBe($newest->id);
});

/** R4: cron gọi trùng (gia hạn gói, đổi múi giờ, người quản trị chạy tay) không sinh thông báo thứ hai. */
it('running the action twice in a row leaves exactly one notice, not two', function () {
    [, $lawyer] = unseenMatter();

    $first = (new RemindUnseenUpdates)->handle();
    $second = (new RemindUnseenUpdates)->handle();

    expect($first)->toBe(['notified' => 1])
        ->and($second)->toBe(['notified' => 0])
        ->and($lawyer->fresh()->notifications)->toHaveCount(1);
});

/**
 * Một dòng MỚI được công bố rồi lại quá 5 ngày chưa xem → nhắc lại. Vế đối: một dòng mới vẫn còn
 * trong 5 ngày thì dòng chưa xem MỚI NHẤT (theo định nghĩa) vẫn là dòng cũ, nên KHÔNG nhắc lại.
 *
 * Mutation probe: khoá chống lặp theo `matter_id` một mình (xoá `where('data->viewData->stage_log_id', ...)`)
 * — hàng "a newer update turns five days old" ĐỎ; khoá theo `stage_log_id` của dòng CŨ NHẤT thay vì
 * MỚI NHẤT — hàng đó cũng ĐỎ.
 */
it('reminds again only when a newer unseen update has itself turned five days old', function () {
    $this->travelTo(Carbon::parse('2026-10-01 08:30:00'));
    [$matter, $lawyer, $first] = unseenMatter(10);

    expect((new RemindUnseenUpdates)->handle()['notified'])->toBe(1);

    // Ngày 4/10: một cập nhật mới được công bố. Còn trong 5 ngày -> chưa tới lượt, dòng cũ đã nhắc rồi.
    $this->travelTo(Carbon::parse('2026-10-04 08:30:00'));
    $newer = unseenLog($matter, now());

    expect((new RemindUnseenUpdates)->handle()['notified'])->toBe(0);

    // Ngày 10/10: dòng mới đã sáu ngày, vẫn chưa ai mở -> nhắc lại, khoá bằng dòng mới.
    $this->travelTo(Carbon::parse('2026-10-10 08:30:00'));

    expect((new RemindUnseenUpdates)->handle()['notified'])->toBe(1)
        ->and((new RemindUnseenUpdates)->handle()['notified'])->toBe(0);

    $rows = $lawyer->fresh()->notifications;
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('data.viewData.stage_log_id')->sort()->values()->all())
        ->toBe(collect([$first->id, $newer->id])->sort()->values()->all());
});

it('does not let a notice about one matter silence the same lawyer for another matter', function () {
    [$first, $lawyer] = unseenMatter(9);
    $second = Matter::factory()->create([
        'client_id' => $first->client_id,
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => true,
    ]);
    unseenLog($second, 9);

    $result = (new RemindUnseenUpdates)->handle();

    expect($result)->toBe(['notified' => 2])
        ->and($lawyer->fresh()->notifications->pluck('data.viewData.matter_id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

/** Khi khách mở trang, dòng không còn "chưa xem" nên không có gì để nhắc — kể cả khi lượt nhắc trước đã có. */
it('stops once the client opens the page', function () {
    [, $lawyer, $log, $account] = unseenMatter();

    (new RemindUnseenUpdates)->handle();
    markSeen($log, $account);

    expect((new RemindUnseenUpdates)->handle())->toBe(['notified' => 0])
        ->and($lawyer->fresh()->notifications)->toHaveCount(1);
});

/**
 * Trí nhớ chống lặp là bảng `notifications` (R3): xoá dòng ở chuông thì lượt sau nhắc lại MỘT lần,
 * rồi lại im. Ghim hệ quả đã ghi trong docblock của Action, không để nó là một bất ngờ.
 */
it('comes back once if the lawyer clears it while the client still has not opened the page', function () {
    [, $lawyer] = unseenMatter();

    (new RemindUnseenUpdates)->handle();
    $lawyer->notifications()->delete();

    expect((new RemindUnseenUpdates)->handle())->toBe(['notified' => 1])
        ->and((new RemindUnseenUpdates)->handle())->toBe(['notified' => 0])
        ->and($lawyer->fresh()->notifications)->toHaveCount(1);
});

// ---------------------------------------------------------------------------------------------
// Danh sách ứng viên dựng TRƯỚC vòng lặp; điều kiện phải được đọc lại sau khi khoá dòng
// ---------------------------------------------------------------------------------------------

/** Chạy `$change` ĐÚNG MỘT lần, ngay sau truy vấn dựng danh sách ứng viên — xem CheckStaleMattersTest. */
function afterUnseenCandidateListIsBuilt(Closure $change): void
{
    $fired = false;

    DB::listen(function ($query) use (&$fired, $change) {
        if (! $fired && preg_match('/^select distinct [`"]matter_id[`"] from [`"]stage_logs[`"]/i', $query->sql) === 1) {
            $fired = true;
            $change();
        }
    });
}

/**
 * `Exceptions::fake()` + `assertNothingReported()`: Action bắt mọi `Throwable` của từng hồ sơ rồi
 * `report()`, nên nếu khối đọc lại bị gỡ thì hồ sơ vẫn bị bỏ qua — nhưng bằng một NGOẠI LỆ bị nuốt
 * (`$unseen->first()` là null), không bằng điều kiện. Test chỉ đếm thông báo sẽ xanh cả khi đó.
 *
 * Mutation probe: xoá khối `if ($unseen->isEmpty()) { return; }` khỏi
 * `RemindUnseenUpdates::processOne()` — hai test này ĐỎ (ngoại lệ được báo cáo).
 */
it('skips a matter whose update the client opened after the candidate list was built', function () {
    Exceptions::fake();
    [, $lawyer, $log, $account] = unseenMatter();

    afterUnseenCandidateListIsBuilt(fn () => markSeen($log, $account));

    expect((new RemindUnseenUpdates)->handle())->toBe(['notified' => 0])
        ->and($lawyer->fresh()->notifications)->toHaveCount(0);
    Exceptions::assertNothingReported();
});

it('skips a matter that was taken off the portal after the candidate list was built', function () {
    Exceptions::fake();
    [$matter, $lawyer] = unseenMatter();

    afterUnseenCandidateListIsBuilt(fn () => DB::table('matters')->where('id', $matter->id)->update(['is_published_to_portal' => false]));

    expect((new RemindUnseenUpdates)->handle())->toBe(['notified' => 0])
        ->and($lawyer->fresh()->notifications)->toHaveCount(0);
    Exceptions::assertNothingReported();
});

/** Cặp dương: cùng cơ chế nghe truy vấn, không đổi gì — hồ sơ vẫn được nhắc. */
it('still handles the matter when nothing changes after the candidate list was built', function () {
    [, $lawyer] = unseenMatter();

    afterUnseenCandidateListIsBuilt(fn () => null);

    expect((new RemindUnseenUpdates)->handle()['notified'])->toBe(1)
        ->and($lawyer->fresh()->notifications)->toHaveCount(1);
});

// ---------------------------------------------------------------------------------------------
// Người nhận (R3) và vụ `restricted` (Review Focus 1)
// ---------------------------------------------------------------------------------------------

it('falls back down the recipient chain when the lead lawyer is deactivated', function () {
    [$matter, $lawyer] = unseenMatter();
    $lawyer->update(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();

    (new RemindUnseenUpdates)->handle();

    expect($lawyer->fresh()->notifications)->toHaveCount(0)
        ->and($manager->fresh()->notifications)->toHaveCount(1)
        ->and($manager->fresh()->notifications->first()->data['viewData']['matter_id'])->toBe($matter->id);
});

/**
 * Cặp âm/dương, cùng MỘT dữ liệu, chỉ khác vụ có `restricted` hay không: manager xem được vụ
 * thường nên nhận (dương, ở test trên); vụ `restricted` thì manager KHÔNG nhận, admin thế chỗ và
 * là người DUY NHẤT thấy mã hồ sơ.
 */
it('sends the notice of a restricted matter to the admin, never to a manager who cannot view it', function () {
    [$matter, $lawyer] = unseenMatter(matterOverrides: ['confidentiality' => Confidentiality::Restricted]);
    $lawyer->update(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();

    (new RemindUnseenUpdates)->handle();

    expect($manager->fresh()->notifications)->toHaveCount(0)
        ->and($admin->fresh()->notifications)->toHaveCount(1)
        ->and($admin->fresh()->notifications->first()->data['body'])->toContain($matter->code);
});

it('keeps the notice of a restricted matter with its active lead lawyer and nobody else', function () {
    [$matter, $lawyer] = unseenMatter(matterOverrides: ['confidentiality' => Confidentiality::Restricted]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();

    (new RemindUnseenUpdates)->handle();

    expect($lawyer->fresh()->notifications)->toHaveCount(1)
        ->and($manager->fresh()->notifications)->toHaveCount(0)
        ->and($admin->fresh()->notifications)->toHaveCount(0);
});

// ---------------------------------------------------------------------------------------------
// Nội dung thông báo
// ---------------------------------------------------------------------------------------------

/** Nói việc phải làm; không trích `internal_note` (SPEC §4.8) hay nội dung nội bộ nào của hồ sơ. */
it('tells the lawyer to phone the client and quotes nothing internal', function () {
    [$matter, $lawyer] = unseenMatter(matterOverrides: [
        'description_internal' => 'MO-TA-NOI-BO-KHONG-DUOC-LO',
    ]);
    $matter->client->update(['note' => 'GHI-CHU-KHACH-KHONG-DUOC-LO']);

    (new RemindUnseenUpdates)->handle();

    $data = $lawyer->fresh()->notifications->first()->data;
    $text = $data['title'].' '.$data['body'];

    expect($text)->toContain('gọi điện')
        ->and($text)->toContain('5 ngày')
        ->and($text)->not->toContain('GHI-CHU-NOI-BO-KHONG-DUOC-LO')
        ->and($text)->not->toContain('MO-TA-NOI-BO-KHONG-DUOC-LO')
        ->and($text)->not->toContain('GHI-CHU-KHACH-KHONG-DUOC-LO');
});

/** Thân thông báo vẽ THÔ trong chuông (sanitizeHtml giữ `<a href>`): tiêu đề do nhân sự gõ phải được thoát. */
it('escapes a staff-typed matter title in the notice body', function () {
    [, $lawyer] = unseenMatter(matterOverrides: ['title' => 'Tranh chấp <a href="https://x.test">bấm vào</a>']);

    (new RemindUnseenUpdates)->handle();

    $body = $lawyer->fresh()->notifications->first()->data['body'];
    expect($body)->not->toContain('<a href')
        ->and($body)->toContain('&lt;a href');
});

/** Chuông của panel đọc được dòng này qua `Notification::fromDatabase()` như mọi thông báo Filament khác. */
it('writes a row the Filament bell can read back', function () {
    [$matter, $lawyer] = unseenMatter();

    (new RemindUnseenUpdates)->handle();

    $row = $lawyer->fresh()->notifications->first();
    $rendered = FilamentNotification::fromDatabase($row);

    expect($rendered->getTitle())->toBe($row->data['title'])
        ->and($rendered->getBody())->toContain($matter->code);
});

// ---------------------------------------------------------------------------------------------
// Lịch: 08:30 giờ Việt Nam, sau `missing-documents.remind` (08:00)
// ---------------------------------------------------------------------------------------------

it('registers the unseen updates reminder under its stable id', function () {
    $names = collect(Schedule::events())->map(fn ($event) => $event->description)->filter()->values()->all();

    expect($names)->toContain('unseen-updates.remind');
});

/**
 * "08:30 hằng ngày" — giờ Việt Nam (08:30 Việt Nam là 01:30 UTC; nếu sự kiện rơi về UTC thì
 * `isDue()` lúc 08:30 giờ máy Việt Nam sai). Cùng lý lẽ `deadlines.check`.
 *
 * Mutation probe: đổi `dailyAt('08:30')` thành `dailyAt('08:00')` — mọi hàng ĐỎ.
 */
it('says the unseen updates reminder is due at 08:30 Vietnam time, and only then', function () {
    expect(config('app.timezone'))->toBe('Asia/Ho_Chi_Minh');

    $event = collect(Schedule::events())->first(fn ($e) => $e->description === 'unseen-updates.remind');

    expect($event)->not->toBeNull();

    $today = today()->startOfDay();

    $this->travelTo($today->copy()->setTime(8, 30));
    expect($event->isDue(app()))->toBeTrue('phải tới hạn lúc 08:30');

    foreach (['00:00', '01:30', '08:00', '08:29', '08:31', '12:00', '23:59'] as $notDue) {
        [$h, $m] = explode(':', $notDue);
        $this->travelTo($today->copy()->setTime((int) $h, (int) $m));
        expect($event->isDue(app()))->toBeFalse("không được tới hạn lúc {$notDue}");
    }
});

/** Nó ghi thông báo — hai tiến trình chồng nhau là hai lần nhắc; khoá hết hạn sau 60 phút, không phải 1440. */
it('lets a killed unseen updates reminder hold its overlap lock for an hour at most', function () {
    $event = collect(Schedule::events())->first(fn ($e) => $e->description === 'unseen-updates.remind');

    expect($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});

/** Scheduler gọi Action qua `__invoke()`, không qua một closure mang nghiệp vụ. */
it('can be invoked the way the scheduler does', function () {
    [, $lawyer] = unseenMatter();

    $result = (new RemindUnseenUpdates)();

    expect($result)->toBe(['notified' => 1])
        ->and($lawyer->fresh()->notifications)->toHaveCount(1);
});
