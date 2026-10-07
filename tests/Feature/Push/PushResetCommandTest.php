<?php

use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| M12 Task 4 — `vkcrm:push-reset` (R7): xoá MỌI đăng ký sau khi đổi khoá VAPID
|--------------------------------------------------------------------------
|
| Đổi khoá riêng làm mọi đăng ký trên mọi điện thoại chết IM LẶNG: máy chủ push trả 401/403 chứ
| không 410, nên gói không tự dọn. Lệnh này là cách dọn, và dòng audit của nó chỉ mang SỐ dòng đã
| xoá — không bao giờ endpoint (R8: endpoint là một URL mang quyền gửi).
*/

function seedPushSubscriptionsForReset(): array
{
    $staff = User::factory()->create();
    $client = ClientUser::factory()->create();

    $staff->updatePushSubscription('https://fcm.googleapis.com/fcm/send/nhan-su-1');
    $staff->updatePushSubscription('https://web.push.apple.com/nhan-su-2');
    $client->updatePushSubscription('https://updates.push.services.mozilla.com/wpush/v2/khach-1');

    return [$staff, $client];
}

it('hỏi xác nhận, rồi xoá mọi đăng ký của cả nhân sự lẫn khách và in số dòng đã xoá', function () {
    seedPushSubscriptionsForReset();

    $this->artisan('vkcrm:push-reset')
        ->expectsConfirmation(__('push.reset.confirm', ['count' => 3]), 'yes')
        ->expectsOutputToContain(__('push.reset.done', ['count' => 3]))
        ->assertExitCode(0);

    expect(DB::table('push_subscriptions')->count())->toBe(0);
});

it('ghi audit push_subscriptions_reset chỉ mang số dòng, không endpoint, không người thực hiện', function () {
    seedPushSubscriptionsForReset();

    $this->artisan('vkcrm:push-reset', ['--force' => true])->assertExitCode(0);

    $row = Activity::query()->where('event', 'push_subscriptions_reset')->sole();

    expect($row->properties->all())->toBe(['count' => 3, 'via' => 'console'])
        ->and($row->causer_id)->toBeNull()
        ->and($row->subject_id)->toBeNull()
        ->and(json_encode($row->toArray()))->not->toContain('push.')
        ->and(json_encode($row->toArray()))->not->toContain('fcm.googleapis.com');
});

it('từ chối xác nhận thì không xoá gì và không ghi audit, mã thoát khác 0', function () {
    seedPushSubscriptionsForReset();

    $this->artisan('vkcrm:push-reset')
        ->expectsConfirmation(__('push.reset.confirm', ['count' => 3]), 'no')
        ->expectsOutputToContain(__('push.reset.cancelled'))
        ->assertExitCode(1);

    expect(DB::table('push_subscriptions')->count())->toBe(3)
        ->and(Activity::query()->where('event', 'push_subscriptions_reset')->exists())->toBeFalse();
});

it('chạy không tương tác mà thiếu --force thì từ chối, không xoá gì', function () {
    seedPushSubscriptionsForReset();

    // Artisan::call (không phải $this->artisan): lớp output giả của PendingCommand chặn mọi câu hỏi,
    // kể cả khi không tương tác — chỉ output thật mới trả câu trả lời MẶC ĐỊNH như trên máy chủ.
    $exitCode = Artisan::call('vkcrm:push-reset', ['--no-interaction' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain(__('push.reset.cancelled'))
        ->and(DB::table('push_subscriptions')->count())->toBe(3)
        ->and(Activity::query()->where('event', 'push_subscriptions_reset')->exists())->toBeFalse();
});

it('--force bỏ bước hỏi (kịch bản đổi khoá), bảng trống vẫn ghi một dòng audit số 0', function () {
    $this->artisan('vkcrm:push-reset', ['--force' => true])
        ->expectsOutputToContain(__('push.reset.done', ['count' => 0]))
        ->assertExitCode(0);

    expect(Activity::query()->where('event', 'push_subscriptions_reset')->sole()->properties->all())
        ->toBe(['count' => 0, 'via' => 'console']);
});
