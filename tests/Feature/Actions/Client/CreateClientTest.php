<?php

use App\Actions\Client\CreateClient;
use App\Enums\ClientType;
use App\Enums\Role;
use App\Exceptions\ClientLookupThrottled;
use App\Exceptions\DuplicateClientDetected;
use App\Models\Client;
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

/**
 * Rà soát toàn hệ thống 2026-10-22, làn intake. Trợ lý vừa tạo hồ sơ khách ở màn hình "Khách hàng"
 * (khách CHƯA có vụ việc nào); luật sư mở vụ đầu tiên bằng khối "Tạo khách mới" và gõ đúng số điện
 * thoại đó mà không dùng ô tra. Docblock `MatterForm::newClientFields()` hứa: KHÔNG tạo hồ sơ thứ hai.
 * Trước bản sửa, `CreateClient` chỉ dò trùng trong `matter_parties` — khách chưa có vụ không để lại
 * dòng nào ở đó, nên văn phòng có hai hồ sơ cho cùng một người.
 */
it('reuses a client that has no matter yet when a lawyer types the same phone as a new client', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $existing = app(CreateClient::class)->handle($assistant, [
        'type' => ClientType::Individual->value,
        'name' => 'Khách trợ lý vừa tạo',
        'phone' => '0912 345 678',
    ]);

    $resolved = app(CreateClient::class)->resolve($lawyer, [
        'type' => ClientType::Individual->value,
        'name' => 'Tên gõ hơi khác',
        'phone' => '+84912345678',
    ]);

    expect($resolved->exists)->toBeTrue()
        ->and($resolved->getKey())->toBe($existing->getKey());
});

it('reuses a client that has no matter yet when a lawyer types the same id number as a new client', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $existing = app(CreateClient::class)->handle($assistant, [
        'type' => ClientType::Organization->value,
        'name' => 'Công ty TNHH Trùng Mã Số Thuế',
        'id_number' => '0101234567',
    ]);

    $resolved = app(CreateClient::class)->resolve($lawyer, [
        'type' => ClientType::Organization->value,
        'name' => 'Cty TNHH Trùng MST',
        'id_number' => '0101 234 567',
    ]);

    expect($resolved->exists)->toBeTrue()
        ->and($resolved->getKey())->toBe($existing->getKey());
});

it('warns a client manager about a duplicate of a client that has no matter yet', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $existing = app(CreateClient::class)->handle($assistant, [
        'type' => ClientType::Individual->value,
        'name' => 'Khách chưa có vụ',
        'phone' => '0912345678',
    ]);

    $thrown = null;

    try {
        app(CreateClient::class)->handle($manager, [
            'type' => ClientType::Individual->value,
            'name' => 'Khách chưa có vụ',
            'phone' => '0912345678',
        ]);
    } catch (DuplicateClientDetected $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->client->getKey())->toBe($existing->getKey())
        ->and(Client::count())->toBe(1);
});
