<?php

use App\Actions\Schedule\ExpireClientAccess;
use App\Filament\Portal\Pages\MyMatters;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schedule;
use Spatie\Activitylog\Models\Activity;

/**
 * M7 Task 5 (R4, SPEC §6.12): tác vụ hằng ngày `ExpireClientAccess`.
 *
 * Việc của job CHỈ là vô hiệu hoá tài khoản cổng — vụ việc rời cổng là việc của hai tầng ranh giới
 * (`tests/Feature/Portal/ClientAccessExpiryTest.php`), đúng ngay cả khi job chưa chạy. Job vô hiệu
 * hoá tài khoản `client_users` đang hoạt động của một khách có ÍT NHẤT MỘT vụ đã hết hạn tra cứu VÀ
 * KHÔNG còn vụ nào hiển thị trên cổng; lưu TỪNG model để `LogsActivity` ghi, kèm một dòng audit
 * `portal_account_deactivated`; không đổi một cột nào của vụ việc.
 *
 * "Hôm nay" của mọi test: 21/10/2026, 00:30 giờ Việt Nam — giờ chạy của mục lịch.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon::parse('2026-10-21 00:30:00'));
});

/** Một khách, một tài khoản cổng đã kích hoạt. */
function ecaClient(): array
{
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id]);

    return [$client, $account];
}

/** Một vụ đã kết thúc, trên cổng, khách được tra cứu tới hết ngày `$until`. */
function ecaClosedMatter(Client $client, string $until = '2026-10-20'): Matter
{
    $matter = Matter::factory()->for($client)->create([
        'is_published_to_portal' => true,
        'closed_at' => '2026-07-22',
    ]);

    MatterArchive::factory()->create(['matter_id' => $matter->id, 'client_access_until' => $until]);

    return $matter;
}

function ecaRun(): array
{
    return (new ExpireClientAccess)->handle();
}

function ecaEvent(): ScheduledEvent
{
    $event = collect(Schedule::events())->first(fn (ScheduledEvent $event): bool => $event->description === 'client-access.expire');

    expect($event)->not->toBeNull();

    return $event;
}

function ecaDeactivationRows(ClientUser $account): int
{
    return Activity::query()
        ->where('event', 'portal_account_deactivated')
        ->where('subject_type', $account->getMorphClass())
        ->where('subject_id', $account->getKey())
        ->count();
}

// =========================================================================================
// Mục lịch
// =========================================================================================

/**
 * Giờ Việt Nam, không phải UTC: 00:30 Việt Nam là 17:30 UTC hôm trước, và 00:30 UTC là 07:30 Việt
 * Nam — một mục lịch lỡ rơi về UTC sẽ tới hạn ở 07:30 chứ không ở 00:30.
 */
it('đăng ký mục lịch client-access.expire chạy đúng 00:30 giờ Việt Nam mỗi ngày, và chỉ lúc đó', function () {
    $event = ecaEvent();
    $day = Carbon::parse('2026-10-21')->startOfDay();

    $this->travelTo($day->copy()->setTime(0, 30));
    expect($event->isDue(app()))->toBeTrue('phải tới hạn lúc 00:30');

    foreach (['00:00', '00:29', '00:31', '01:30', '07:30', '17:30', '23:59'] as $notDue) {
        [$h, $m] = explode(':', $notDue);
        $this->travelTo($day->copy()->setTime((int) $h, (int) $m));
        expect($event->isDue(app()))->toBeFalse("không được tới hạn lúc {$notDue}");
    }
});

it('mục client-access.expire không chồng lên chính nó, và khoá hết hạn trong một giờ chứ không 24 giờ', function () {
    $event = ecaEvent();

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});

it('chạy mục lịch đã đăng ký là chạy đúng ExpireClientAccess', function () {
    [$client, $account] = ecaClient();
    ecaClosedMatter($client);

    ecaEvent()->run(app());

    expect($account->fresh()->is_active)->toBeFalse();
});

// =========================================================================================
// Ai bị vô hiệu hoá
// =========================================================================================

it('vô hiệu hoá mọi tài khoản đang hoạt động của khách có vụ duy nhất đã hết hạn tra cứu', function () {
    [$client, $account] = ecaClient();
    $sibling = ClientUser::factory()->activated()->create(['client_id' => $client->id]);
    ecaClosedMatter($client);

    expect(ecaRun())->toBe(['deactivated' => 2, 'failed' => 0])
        ->and($account->fresh()->is_active)->toBeFalse()
        ->and($sibling->fresh()->is_active)->toBeFalse();
});

it('không đụng tới khách mới có vụ đầu tiên chưa công bố lên cổng', function () {
    [$client, $account] = ecaClient();
    Matter::factory()->for($client)->unpublished()->create();

    // Vế dương trong cùng test: một khách khác, vụ duy nhất đã hết hạn, bị vô hiệu hoá.
    [$expiredClient, $expiredAccount] = ecaClient();
    ecaClosedMatter($expiredClient);

    ecaRun();

    expect($account->fresh()->is_active)->toBeTrue()
        ->and(ecaDeactivationRows($account))->toBe(0)
        ->and($expiredAccount->fresh()->is_active)->toBeFalse();
});

it('không đụng tới khách còn một vụ khác đang hiển thị trên cổng', function () {
    [$client, $account] = ecaClient();
    ecaClosedMatter($client);
    Matter::factory()->for($client)->create(['is_published_to_portal' => true]);

    expect(ecaRun())->toBe(['deactivated' => 0, 'failed' => 0])
        ->and($account->fresh()->is_active)->toBeTrue();
});

it('không đụng tới khách mà vụ đang ở đúng ngày cuối của hạn tra cứu', function () {
    [$client, $account] = ecaClient();
    ecaClosedMatter($client, until: '2026-10-21');

    ecaRun();

    expect($account->fresh()->is_active)->toBeTrue();

    $this->travelTo(Carbon::parse('2026-10-22 00:30:00'));
    ecaRun();

    expect($account->fresh()->is_active)->toBeFalse();
});

it('không đụng tới khách mà vụ đã được mở lại (client_access_until về null)', function () {
    [$client, $account] = ecaClient();
    $matter = ecaClosedMatter($client);
    $matter->archive()->update(['client_access_until' => null]);
    $matter->update(['closed_at' => null]);

    ecaRun();

    expect($account->fresh()->is_active)->toBeTrue();
});

/**
 * R4 đọc theo đúng chữ: "ít nhất một vụ đã hết hạn VÀ không còn vụ nào hiển thị trên cổng". Một khách
 * quay lại với một vụ MỚI chưa công bố, trong khi vụ cũ đã hết hạn, thoả cả hai vế — tài khoản bị vô
 * hiệu hoá; nhân sự bật lại tay khi công bố vụ mới (ghi trong "Ghi chú M7").
 */
it('vô hiệu hoá khách có vụ cũ đã hết hạn và một vụ mới chưa công bố: R4 theo đúng chữ', function () {
    [$client, $account] = ecaClient();
    ecaClosedMatter($client);
    Matter::factory()->for($client)->unpublished()->create();

    ecaRun();

    expect($account->fresh()->is_active)->toBeFalse();
});

/**
 * Vế 1 đếm vụ CHƯA xoá mềm (docblock `ExpireClientAccess`): một vụ đã hết hạn rồi bị xoá mềm không
 * còn là "một vụ đã hết hạn tra cứu" của khách — khách không còn vụ nào khác thì cũng không bị
 * khoá, vì khoá tài khoản dựa trên một hồ sơ đã bị xoá là dựa trên dữ liệu văn phòng đã rút lại.
 */
it('không đếm vụ đã hết hạn nhưng đã bị xoá mềm', function () {
    [$client, $account] = ecaClient();
    ecaClosedMatter($client)->delete();

    // Vế dương trong cùng test: khách khác, vụ hết hạn còn nguyên, bị vô hiệu hoá.
    [$other, $otherAccount] = ecaClient();
    ecaClosedMatter($other);

    expect(ecaRun())->toBe(['deactivated' => 1, 'failed' => 0])
        ->and($account->fresh()->is_active)->toBeTrue()
        ->and(ecaDeactivationRows($account))->toBe(0)
        ->and($otherAccount->fresh()->is_active)->toBeFalse();
});

it('không đụng tới tài khoản đã bị xoá mềm hay tài khoản của khách khác', function () {
    [$client, $account] = ecaClient();
    $trashed = ClientUser::factory()->activated()->create(['client_id' => $client->id]);
    $trashed->delete();
    ecaClosedMatter($client);

    [$otherClient, $otherAccount] = ecaClient();

    ecaRun();

    expect($account->fresh()->is_active)->toBeFalse()
        ->and(ClientUser::withTrashed()->find($trashed->id)->is_active)->toBeTrue()
        ->and($otherAccount->fresh()->is_active)->toBeTrue();
});

// =========================================================================================
// Dấu vết và tính lặp lại
// =========================================================================================

it('việc vô hiệu hoá có dòng trong activity log: LogsActivity ghi is_active, và một dòng portal_account_deactivated', function () {
    [$client, $account] = ecaClient();
    ecaClosedMatter($client);

    ecaRun();

    $updated = Activity::query()
        ->where('event', 'updated')
        ->where('subject_type', $account->getMorphClass())
        ->where('subject_id', $account->id)
        ->sole();

    expect($updated->properties['attributes']['is_active'])->toBeFalse()
        ->and($updated->properties['old']['is_active'])->toBeTrue();

    $audit = Activity::query()
        ->where('event', 'portal_account_deactivated')
        ->where('subject_id', $account->id)
        ->sole();

    expect($audit->subject_type)->toBe($account->getMorphClass())
        ->and($audit->causer_id)->toBeNull()
        ->and($audit->properties['reason'])->toBe('client_access_expired');
});

it('chạy lại không ghi thêm gì: tài khoản đã vô hiệu không bị đụng lần hai', function () {
    [$client, $account] = ecaClient();
    ecaClosedMatter($client);

    ecaRun();
    $rows = Activity::query()->count();

    // Khách đã xử lý rơi khỏi tập ứng viên (không còn tài khoản đang hoạt động), nên lần chạy sau
    // không mở một transaction nào cho họ — số transaction mỗi đêm không lớn dần theo số vụ đã lưu
    // trữ qua năm tháng.
    $transactions = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
        $transactions++;
    });

    expect(ecaRun())->toBe(['deactivated' => 0, 'failed' => 0])
        ->and($transactions)->toBe(0)
        ->and(Activity::query()->count())->toBe($rows)
        ->and(ecaDeactivationRows($account))->toBe(1);
});

it('tài khoản đã bị nhân sự vô hiệu hoá từ trước không nhận thêm dòng nhật ký nào', function () {
    [$client, $account] = ecaClient();
    $alreadyOff = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => false]);
    ecaClosedMatter($client);

    $rowsBefore = Activity::query()
        ->where('subject_type', $alreadyOff->getMorphClass())
        ->where('subject_id', $alreadyOff->id)
        ->count();

    expect(ecaRun())->toBe(['deactivated' => 1, 'failed' => 0])
        ->and($account->fresh()->is_active)->toBeFalse()
        ->and(Activity::query()
            ->where('subject_type', $alreadyOff->getMorphClass())
            ->where('subject_id', $alreadyOff->id)
            ->count())->toBe($rowsBefore);
});

/**
 * Tài khoản bị vô hiệu không tự bật lại khi vụ được mở lại — nhân sự bật tay. Job không bao giờ
 * đặt `is_active = true`.
 */
it('không tự bật lại tài khoản khi vụ được mở lại', function () {
    [$client, $account] = ecaClient();
    $matter = ecaClosedMatter($client);

    ecaRun();

    $matter->archive()->update(['client_access_until' => null]);
    $matter->update(['closed_at' => null]);

    ecaRun();

    expect($account->fresh()->is_active)->toBeFalse();
});

// =========================================================================================
// Không sửa dữ liệu vụ việc (R4)
// =========================================================================================

it('không đổi một cột nào của vụ việc, lưu trữ hay tài liệu', function () {
    [$client] = ecaClient();
    $matter = ecaClosedMatter($client);
    Document::factory()->for($matter)->create();

    $snapshot = fn (): array => [
        DB::table('matters')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        DB::table('matter_archives')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        DB::table('documents')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];

    $before = $snapshot();

    expect(ecaRun()['deactivated'])->toBe(1)
        ->and($snapshot())->toEqual($before);
});

// =========================================================================================
// Phiên đang mở, chạy đua, lỗi giữa chừng
// =========================================================================================

/**
 * Phiên đang mở mất ở request kế tiếp (`EnsurePortalAccountIsActive`). Job chạy trong lúc một phiên
 * cổng ĐANG mở trong cùng tiến trình — tức `ClientPortalScope` đang hoạt động theo khách đó — nên
 * test này cũng đo việc job không để scope của phiên ambient cắt truy vấn của chính nó.
 */
it('phiên đang mở của tài khoản bị vô hiệu mất ở request kế tiếp', function () {
    [$client, $account] = ecaClient();
    ecaClosedMatter($client);

    $this->actingAs($account, 'client');
    $this->get(MyMatters::getUrl(panel: 'portal'))->assertOk();

    ecaRun();

    $this->actingAs($account->fresh(), 'client');

    $this->get(MyMatters::getUrl(panel: 'portal'))->assertRedirect('/portal/login');

    expect(auth('client')->check())->toBeFalse();
});

/**
 * Tập ứng viên được dựng TRƯỚC vòng lặp. Nếu, trong lúc job đang xử lý khách A, văn phòng công bố
 * một vụ của khách B (cũng là ứng viên), job phải ĐỌC LẠI điều kiện của B dưới khoá chứ không tin
 * danh sách cũ — nếu không, B mất tài khoản ngay sau khi vừa được công bố hồ sơ.
 */
it('đọc lại điều kiện dưới khoá: một vụ vừa được công bố giữa lúc job chạy giữ tài khoản của khách đó', function () {
    [$first, $firstAccount] = ecaClient();
    ecaClosedMatter($first);

    [$second, $secondAccount] = ecaClient();
    ecaClosedMatter($second);
    $pending = Matter::factory()->for($second)->unpublished()->create();

    $published = false;

    ClientUser::updating(function (ClientUser $user) use ($firstAccount, $pending, &$published): void {
        if (! $published && $user->is($firstAccount)) {
            $published = true;
            DB::table('matters')->where('id', $pending->id)->update(['is_published_to_portal' => true]);
        }
    });

    ecaRun();

    expect($published)->toBeTrue()
        ->and($firstAccount->fresh()->is_active)->toBeFalse()
        ->and($secondAccount->fresh()->is_active)->toBeTrue();
});

/**
 * Cùng lý lẽ, cho vế 1: một vụ hết hạn của khách B được MỞ LẠI giữa lúc job đang xử lý khách A. Vụ
 * mở lại ở đây chưa công bố, nên vế 2 ("không còn vụ nào trên cổng") vẫn đúng — chỉ lần đọc lại vế 1
 * dưới khoá giữ được tài khoản của B.
 */
it('đọc lại điều kiện dưới khoá: một vụ vừa được mở lại giữa lúc job chạy giữ tài khoản của khách đó', function () {
    [$first, $firstAccount] = ecaClient();
    ecaClosedMatter($first);

    [$second, $secondAccount] = ecaClient();
    $reopening = ecaClosedMatter($second);
    $reopening->update(['is_published_to_portal' => false]);

    $reopened = false;

    ClientUser::updating(function (ClientUser $user) use ($firstAccount, $reopening, &$reopened): void {
        if (! $reopened && $user->is($firstAccount)) {
            $reopened = true;
            DB::table('matter_archives')->where('matter_id', $reopening->id)->update(['client_access_until' => null]);
            DB::table('matters')->where('id', $reopening->id)->update(['closed_at' => null]);
        }
    });

    ecaRun();

    expect($reopened)->toBeTrue()
        ->and($firstAccount->fresh()->is_active)->toBeFalse()
        ->and($secondAccount->fresh()->is_active)->toBeTrue();
});

/**
 * Và cho chính tập tài khoản: nhân sự tự khoá tài khoản cuối cùng của khách B giữa lúc job đang xử
 * lý khách A. B vẫn là ứng viên (danh sách dựng trước), nhưng dưới khoá B không còn tài khoản nào
 * đang hoạt động — job bỏ qua B, không coi đó là lỗi, không ghi gì.
 */
it('đọc lại tài khoản dưới khoá: khách mà nhân sự vừa tự khoá tài khoản giữa lúc job chạy được bỏ qua, không thành lỗi', function () {
    [$first, $firstAccount] = ecaClient();
    ecaClosedMatter($first);

    [$second, $secondAccount] = ecaClient();
    ecaClosedMatter($second);

    $lockedByStaff = false;

    ClientUser::updating(function (ClientUser $user) use ($firstAccount, $secondAccount, &$lockedByStaff): void {
        if (! $lockedByStaff && $user->is($firstAccount)) {
            $lockedByStaff = true;
            DB::table('client_users')->where('id', $secondAccount->id)->update(['is_active' => false]);
        }
    });

    expect(ecaRun())->toBe(['deactivated' => 1, 'failed' => 0])
        ->and($lockedByStaff)->toBeTrue()
        ->and(ecaDeactivationRows($secondAccount))->toBe(0);
});

it('một khách lỗi giữa chừng không chặn những khách khác, và lỗi được báo lại', function () {
    Exceptions::fake();

    [$broken, $brokenAccount] = ecaClient();
    ecaClosedMatter($broken);

    [$fine, $fineAccount] = ecaClient();
    ecaClosedMatter($fine);

    ClientUser::saving(function (ClientUser $user) use ($brokenAccount): void {
        if ($user->is($brokenAccount)) {
            throw new RuntimeException('Ổ đĩa đầy giữa lúc ghi');
        }
    });

    expect(ecaRun())->toBe(['deactivated' => 1, 'failed' => 1])
        ->and($brokenAccount->fresh()->is_active)->toBeTrue()
        ->and(ecaDeactivationRows($brokenAccount))->toBe(0)
        ->and($fineAccount->fresh()->is_active)->toBeFalse();

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Ổ đĩa đầy giữa lúc ghi');
});
