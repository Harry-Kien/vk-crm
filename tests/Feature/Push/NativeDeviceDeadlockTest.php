<?php

use App\Actions\Push\RegisterNativeDevice;
use App\Enums\Role;
use App\Models\NativePushDevice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| M15 Task 2, vòng sửa 2 — deadlock lúc đăng ký lần đầu được chạy lại Ở MỨC GIAO DỊCH 0
|--------------------------------------------------------------------------
|
| `RefreshDatabase` bọc mỗi test trong một giao dịch, nên `DB::transaction(attempts: 3)` của
| `RegisterNativeDevice` luôn là giao dịch LỒNG ở các test khác (Laravel không chạy lại khi lồng). Request
| thật thì là giao dịch ngoài cùng. Tệp này huỷ giao dịch bao ngoài của khung test để `handle()` chạy ở
| mức 0 như trên máy thật — cái duy nhất ghim `attempts: 3` (bỏ nó thì test này đỏ) — rồi tự dọn dữ liệu
| đã commit thật để không rò sang test khác.
*/

beforeEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

afterEach(function () {
    NativePushDevice::flushEventListeners();
    DB::table('native_push_devices')->delete();
    Activity::query()->delete();
    DB::table('model_has_roles')->delete();
    DB::table('role_has_permissions')->delete();
    DB::table('roles')->delete();
    DB::table('permissions')->delete();
    DB::table('users')->delete();
});

function deadlock(): QueryException
{
    return new QueryException('mariadb', 'insert into native_push_devices', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
}

it('retries a first-registration deadlock at transaction level 0 and ends with one row', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $thrown = 0;
    NativePushDevice::creating(function () use (&$thrown): void {
        if ($thrown++ === 0) {
            throw deadlock();
        }
    });

    expect(DB::transactionLevel())->toBe(0);

    app(RegisterNativeDevice::class)->handle($staff, ['token' => hash('sha256', 'x'), 'environment' => 'sandbox', 'app_version' => '1.0'], null);

    expect($thrown)->toBe(2)
        ->and(DB::table('native_push_devices')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'push_device_added')->count())->toBe(1);
});

it('gives up after three deadlocks', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $thrown = 0;
    NativePushDevice::creating(function () use (&$thrown): void {
        $thrown++;

        throw deadlock();
    });

    expect(fn () => app(RegisterNativeDevice::class)->handle($staff, ['token' => hash('sha256', 'y'), 'environment' => 'sandbox', 'app_version' => '1.0'], null))
        ->toThrow(QueryException::class)
        ->and($thrown)->toBe(3)
        ->and(DB::table('native_push_devices')->count())->toBe(0);
});
