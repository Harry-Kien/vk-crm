<?php

use App\Actions\Client\CreateClient;
use App\Enums\ClientType;
use App\Enums\Role;
use App\Exceptions\ClientLookupThrottled;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Fix round 2 (Minor): `CreateClient::guardThrottle()` chọn định danh để băm bằng `filled()`,
 * không `??`. Test này gọi Action TRỰC TIẾP (ngoại lệ có chủ đích với quy ước "test cho một màn
 * hình phải đi qua Livewire" — đây không phải test một màn hình, mà một chi tiết tính đúng nội bộ
 * của chính Action, và không màn hình Filament nào của dự án hôm nay dựng ra được tình huống
 * `phone` là chuỗi rỗng TƯỜNG MINH: dehydrate của Filament bỏ hẳn khoá đó khi ô để trống, không
 * gửi `''` — xem docblock `CreateClient::guardThrottle()`. Cùng quy ước với
 * `tests/Feature/Actions/OpenMatterTest.php` (gọi Action trực tiếp để đo đúng hợp đồng của nó).
 */
it('hashes the id_number instead of an explicit blank phone string once the lookup throttle is hit', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    // Dùng hết 20/20 của bộ đếm dùng chung (App\Support\ClientLookupThrottle) TRƯỚC — cùng khoá
    // với FindClientByIdentifier, nên chạm trần bằng cách hit() trực tiếp là hợp lệ và nhanh hơn
    // dựng 20 lượt tra thật qua Livewire.
    $key = 'client-lookup:'.sha1(User::class.'|'.$lawyer->getKey());
    for ($i = 0; $i < 20; $i++) {
        RateLimiter::hit($key, 3600);
    }

    $thrown = null;

    try {
        app(CreateClient::class)->handle($lawyer, [
            'type' => ClientType::Individual->value,
            'name' => 'Khách mới',
            // Tường minh: chuỗi RỖNG, không phải thiếu khoá và không phải `null` — đúng tình
            // huống `??` xử lý sai (`'' ?? $x` giữ nguyên `''`, không rơi xuống `$x`).
            'phone' => '',
            'id_number' => '079088776655',
        ]);
    } catch (ClientLookupThrottled $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull();

    $throttled = Activity::query()->where('event', 'client_lookup_throttled')->latest('id')->first();

    expect($throttled)->not->toBeNull()
        ->and($throttled->properties->get('identifier_hash'))->toBe(hash_hmac('sha256', '079088776655', config('app.key')))
        ->and($throttled->properties->get('identifier_hash'))->not->toBe(hash('sha256', '079088776655'));
});
