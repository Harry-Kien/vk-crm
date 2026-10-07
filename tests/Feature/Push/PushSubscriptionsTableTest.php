<?php

use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;

/*
|--------------------------------------------------------------------------
| M12 Task 4 — bảng `push_subscriptions` (R8): migration của gói + cột của dự án
|--------------------------------------------------------------------------
|
| Đi qua trait `HasPushSubscriptions` trên `User`/`ClientUser` — cách DUY NHẤT màn hình được chạm
| vào bảng này (R8). Phần đo riêng MariaDB (độ dài, bộ ký tự) chạy bằng
| `test:mariadb`; trên SQLite chỉ khẳng định phần mà SQLite đo được.
*/

function pushTestEndpoint(string $suffix): string
{
    return 'https://fcm.googleapis.com/fcm/send/'.$suffix;
}

it('có đủ cột của gói và hai cột của dự án', function () {
    expect(Schema::hasColumns('push_subscriptions', [
        'id', 'subscribable_type', 'subscribable_id', 'endpoint', 'public_key', 'auth_token',
        'content_encoding', 'device_label', 'last_seen_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('lưu chủ của đăng ký bằng bí danh morph user / client_user, không bằng tên lớp', function () {
    $staff = User::factory()->create();
    $client = ClientUser::factory()->create();

    $staff->updatePushSubscription(pushTestEndpoint('nhan-su'));
    $client->updatePushSubscription(pushTestEndpoint('khach'));

    expect(DB::table('push_subscriptions')->orderBy('id')->pluck('subscribable_type')->all())
        ->toBe(['user', 'client_user'])
        ->and($staff->pushSubscriptions()->pluck('endpoint')->all())->toBe([pushTestEndpoint('nhan-su')])
        ->and($client->pushSubscriptions()->pluck('endpoint')->all())->toBe([pushTestEndpoint('khach')]);
});

it('endpoint là duy nhất trên toàn bảng: một endpoint không thể thuộc hai người cùng lúc', function () {
    $first = ClientUser::factory()->create();
    $second = ClientUser::factory()->create();

    $first->pushSubscriptions()->create(['endpoint' => pushTestEndpoint('dung-chung')]);

    expect(fn () => $second->pushSubscriptions()->create(['endpoint' => pushTestEndpoint('dung-chung')]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('điện thoại dùng chung: gói chuyển endpoint sang chủ mới bằng cách xoá dòng của chủ cũ (R6, R8)', function () {
    $before = User::factory()->create();
    $after = ClientUser::factory()->create();

    $before->updatePushSubscription(pushTestEndpoint('may-chung'));
    $after->updatePushSubscription(pushTestEndpoint('may-chung'));

    expect($before->pushSubscriptions()->count())->toBe(0)
        ->and($after->pushSubscriptions()->pluck('endpoint')->all())->toBe([pushTestEndpoint('may-chung')])
        ->and(DB::table('push_subscriptions')->count())->toBe(1);
});

it('giữ nguyên endpoint dài đúng 1024 ký tự, device_label 100 ký tự và last_seen_at', function () {
    $account = ClientUser::factory()->create();
    $endpoint = 'https://fcm.googleapis.com/fcm/send/'.str_repeat('a', 1024 - 36);
    $label = str_repeat('x', 100);

    $subscription = $account->updatePushSubscription($endpoint);
    $subscription->forceFill(['device_label' => $label, 'last_seen_at' => '2026-10-03 09:15:00'])->save();

    $row = DB::table('push_subscriptions')->first();

    expect(strlen($endpoint))->toBe(PushSubscription::ENDPOINT_MAX_LENGTH)
        ->and($row->endpoint)->toBe($endpoint)
        ->and($row->device_label)->toBe($label)
        ->and((string) $row->last_seen_at)->toStartWith('2026-10-03 09:15:00');
});

it('device_label và last_seen_at để trống được (một dòng do gói tự tạo không có chúng)', function () {
    ClientUser::factory()->create()->updatePushSubscription(pushTestEndpoint('khong-nhan'));

    $row = DB::table('push_subscriptions')->first();

    expect($row->device_label)->toBeNull()
        ->and($row->last_seen_at)->toBeNull();
});

it('đo cột và chỉ mục trên CSDL đang chạy: unique trên endpoint; MariaDB thêm varchar(1024) ascii và varchar(100)', function () {
    $unique = collect(Schema::getIndexes('push_subscriptions'))
        ->first(fn (array $index): bool => $index['unique'] && $index['columns'] === ['endpoint']);

    expect($unique)->not->toBeNull();

    $columns = collect(Schema::getColumns('push_subscriptions'))->keyBy('name');

    expect($columns['device_label']['nullable'])->toBeTrue()
        ->and($columns['last_seen_at']['nullable'])->toBeTrue();

    if (in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        expect($columns['endpoint']['type'])->toBe('varchar(1024)')
            ->and($columns['endpoint']['collation'])->toStartWith('ascii_')
            ->and($columns['device_label']['type'])->toBe('varchar(100)')
            ->and($columns['device_label']['collation'])->toStartWith('utf8mb4_');
    }
});
