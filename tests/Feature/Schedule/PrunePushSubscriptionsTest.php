<?php

use App\Actions\Schedule\PrunePushSubscriptions;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| M12 Task 6 (R9) — dọn đăng ký thông báo đẩy hằng ngày, 03:30 giờ Việt Nam
|--------------------------------------------------------------------------
|
| Dọn dẹp là VỆ SINH, không phải lớp bảo vệ: nơi quyết định thật là luật người nhận lúc gửi (một
| đăng ký còn sót của tài khoản đã vô hiệu không bao giờ được dùng, vì chủ của nó không bao giờ lọt
| vào danh sách người nhận). Lượt dọn bỏ:
|  - đăng ký của tài khoản không còn `is_active`, đã xoá mềm (hay không còn), hoặc — với khách — có
|    khách hàng đã xoá mềm;
|  - đăng ký không mở ứng dụng quá 180 ngày (`last_seen_at`; chưa từng có thì tính từ ngày bật).
| Chạy hai lần liên tiếp không đổi kết quả (M6 R4).
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-04 03:30:00'));
});

function pruneEndpoint(string $suffix): string
{
    return 'https://fcm.googleapis.com/fcm/send/'.$suffix.':APA91bPruneT6';
}

/**
 * Một máy của `$owner`, lần cuối mở ứng dụng `$lastSeenDaysAgo` ngày trước (`null` = chưa từng ghi),
 * bật `$createdDaysAgo` ngày trước.
 */
function pruneDevice(User|ClientUser $owner, string $suffix, ?int $lastSeenDaysAgo = 1, int $createdDaysAgo = 200): string
{
    $keys = WebPushTestKeys::subscription();
    $owner->updatePushSubscription(pruneEndpoint($suffix), $keys['p256dh'], $keys['auth'], 'aes128gcm');

    DB::table('push_subscriptions')->where('endpoint', pruneEndpoint($suffix))->update([
        'device_label' => 'Android · Chrome',
        'last_seen_at' => $lastSeenDaysAgo === null ? null : now()->subDays($lastSeenDaysAgo),
        'created_at' => now()->subDays($createdDaysAgo),
    ]);

    return pruneEndpoint($suffix);
}

/** @return list<string> endpoint còn lại, theo thứ tự byte */
function pruneRemaining(): array
{
    return DB::table('push_subscriptions')->orderBy('endpoint')->pluck('endpoint')->all();
}

function pruneClientUser(array $attributes = []): ClientUser
{
    return ClientUser::factory()->activated()->create($attributes);
}

it('prunes devices of inactive, deleted or orphaned owners and devices unseen for more than 180 days, and nothing else', function () {
    // Còn lại.
    $activeStaff = User::factory()->create();
    $activeClient = pruneClientUser();
    $kept = [
        pruneDevice($activeStaff, 'nhan-su-dang-dung'),
        pruneDevice($activeClient, 'khach-dang-dung'),
        pruneDevice($activeStaff, 'nhan-su-dung-180-ngay', lastSeenDaysAgo: 180),
        pruneDevice($activeClient, 'khach-chua-mo-nhung-moi-bat', lastSeenDaysAgo: null, createdDaysAgo: 180),
    ];

    // Bị dọn vì chủ.
    pruneDevice(User::factory()->create(['is_active' => false]), 'nhan-su-vo-hieu');
    $deletedStaff = User::factory()->create();
    pruneDevice($deletedStaff, 'nhan-su-xoa-mem');
    $deletedStaff->delete();
    pruneDevice(pruneClientUser(['is_active' => false]), 'khach-vo-hieu');
    $deletedAccount = pruneClientUser();
    pruneDevice($deletedAccount, 'khach-xoa-mem');
    $deletedAccount->delete();
    $accountOfDeletedClient = pruneClientUser();
    pruneDevice($accountOfDeletedClient, 'khach-hang-xoa-mem');
    $accountOfDeletedClient->client->delete();
    $vanished = User::factory()->create();
    pruneDevice($vanished, 'chu-khong-con');
    $vanished->forceDelete();

    // Bị dọn vì cũ.
    pruneDevice($activeStaff, 'nhan-su-181-ngay', lastSeenDaysAgo: 181);
    pruneDevice($activeClient, 'khach-181-ngay', lastSeenDaysAgo: 181);
    pruneDevice($activeClient, 'khach-chua-mo-tu-181-ngay', lastSeenDaysAgo: null, createdDaysAgo: 181);

    $result = (new PrunePushSubscriptions)->handle();

    sort($kept, SORT_STRING);
    expect(pruneRemaining())->toBe($kept)
        ->and($result)->toBe(['pruned' => 9, 'owner_ineligible' => 6, 'stale' => 3]);

    // Tiền đề: chủ của các máy còn lại vẫn là người nhận hợp lệ, và khách hàng của họ còn đó.
    expect(Client::query()->whereKey($activeClient->client_id)->exists())->toBeTrue();
});

it('changes nothing the second time it runs, and audits only the run that pruned', function () {
    $staff = User::factory()->create();
    $kept = pruneDevice($staff, 'con-lai');
    pruneDevice($staff, 'cu-qua', lastSeenDaysAgo: 400);
    pruneDevice(pruneClientUser(['is_active' => false]), 'vo-hieu');

    $first = (new PrunePushSubscriptions)->handle();
    $afterFirst = pruneRemaining();
    $second = (new PrunePushSubscriptions)->handle();

    expect($first)->toBe(['pruned' => 2, 'owner_ineligible' => 1, 'stale' => 1])
        ->and($second)->toBe(['pruned' => 0, 'owner_ineligible' => 0, 'stale' => 0])
        ->and(pruneRemaining())->toBe($afterFirst)
        ->and($afterFirst)->toBe([$kept]);

    $audit = Activity::query()->where('event', 'push_subscriptions_pruned')->sole();
    expect($audit->properties->all())->toBe(['count' => 2, 'owner_ineligible' => 1, 'stale' => 1, 'via' => 'schedule'])
        ->and($audit->causer_id)->toBeNull()
        ->and($audit->subject_id)->toBeNull()
        ->and(json_encode($audit->toArray()))->not->toContain('fcm.googleapis.com');
});

it('writes no audit row when there is nothing to prune', function () {
    pruneDevice(User::factory()->create(), 'moi-mo-hom-qua');

    expect((new PrunePushSubscriptions)->handle())->toBe(['pruned' => 0, 'owner_ineligible' => 0, 'stale' => 0])
        ->and(Activity::query()->where('event', 'push_subscriptions_pruned')->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Lịch: 03:30 giờ Việt Nam
// ---------------------------------------------------------------------------------------------

it('registers the push subscription prune under its stable id', function () {
    $names = collect(Schedule::events())->map(fn ($event) => $event->description)->filter()->values()->all();

    expect($names)->toContain('push-subscriptions.prune');
});

/**
 * "03:30 hằng ngày" — giờ Việt Nam: 03:30 Việt Nam là 20:30 UTC hôm trước, còn 03:30 UTC là 10:30
 * Việt Nam. Một sự kiện rơi về UTC tới hạn lúc 10:30 giờ máy và không tới hạn lúc 03:30.
 */
it('says the prune is due at 03:30 Vietnam time, and only then', function () {
    expect(config('app.timezone'))->toBe('Asia/Ho_Chi_Minh');

    $event = collect(Schedule::events())->first(fn ($e) => $e->description === 'push-subscriptions.prune');

    expect($event)->not->toBeNull();

    $today = today()->startOfDay();

    $this->travelTo($today->copy()->setTime(3, 30));
    expect($event->isDue(app()))->toBeTrue('phải tới hạn lúc 03:30');

    foreach (['00:00', '02:00', '03:00', '03:29', '03:31', '08:30', '10:30', '20:30', '23:59'] as $notDue) {
        [$h, $m] = explode(':', $notDue);
        $this->travelTo($today->copy()->setTime((int) $h, (int) $m));
        expect($event->isDue(app()))->toBeFalse("không được tới hạn lúc {$notDue}");
    }
});

/**
 * Chạy đúng sự kiện lịch đã đăng ký, như `schedule:run` chạy nó (`CallbackEvent::run()` gọi
 * `PrunePushSubscriptions@handle` qua container) — không một closure mang nghiệp vụ.
 */
it('prunes when the scheduled event itself runs', function () {
    pruneDevice(User::factory()->create(['is_active' => false]), 'chay-tu-lich');

    collect(Schedule::events())
        ->first(fn ($e) => $e->description === 'push-subscriptions.prune')
        ->run(app());

    expect(pruneRemaining())->toBe([])
        ->and(Activity::query()->where('event', 'push_subscriptions_pruned')->count())->toBe(1);
});

/** `__invoke()` cho ai gọi Action như một callable (cùng khuôn các Action lịch khác). */
it('can be invoked as a callable', function () {
    pruneDevice(User::factory()->create(['is_active' => false]), 'goi-nhu-ham');

    expect((new PrunePushSubscriptions)())->toBe(['pruned' => 1, 'owner_ineligible' => 1, 'stale' => 0])
        ->and(pruneRemaining())->toBe([]);
});
