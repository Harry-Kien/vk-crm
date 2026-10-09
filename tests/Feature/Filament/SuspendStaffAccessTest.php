<?php

use App\Actions\Push\ForgetPushDevice;
use App\Actions\User\SuspendStaffAccess;
use App\Enums\AiAccessMode;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakePushServer;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fb, mục A5) — "Khoá truy cập ngay"
|--------------------------------------------------------------------------
| Nhân sự nghỉ đột xuất hoặc bị nghi lộ dữ liệu khi còn giữ 12 vụ, 30 mốc hạn: tắt "Đang hoạt động"
| bị chặn cho tới khi bàn giao xong (R7), và trong lúc đó người đó vẫn vào được hồ sơ. Nút mới khoá
| QUYỀN VÀO ngay, không chạm việc; việc dở dang vẫn đứng tên người đó để bàn giao sau.
| Mọi hành vi đo qua Livewire (trang sửa nhân sự) và HTTP, không gọi thẳng Action.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    McpOAuth::openServer();

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lawyer = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)
        ->withAiAccess(AiAccessMode::Read)->create(['name' => 'Luật Sư Nghỉ Đột Xuất']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->deadline = Deadline::factory()->for($this->matter)->create([
        'responsible_user_id' => $this->lawyer->id,
        'is_completed' => false,
    ]);
});

afterEach(function () {
    Livewire::flushState();
});

const FB_SUSPEND_REASON = 'Nghỉ việc đột xuất, nghi chép dữ liệu khách ra ngoài.';

it('locks a staff member who still holds open work right away, without touching the work', function () {
    $this->lawyer->forceFill(['remember_token' => 'token-ghi-nho-cu'])->save();
    $epoch = (int) $this->lawyer->session_epoch;
    $token = McpOAuth::accessToken($this, $this->lawyer);
    FakePushServer::device($this->lawyer, 'dien-thoai-ca-nhan');

    $this->actingAs($this->admin, 'web');

    Livewire::test(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->assertActionVisible('suspendAccess')
        ->callAction('suspendAccess', data: ['reason' => FB_SUSPEND_REASON])
        ->assertHasNoActionErrors()
        ->assertSchemaStateSet(['is_active' => false]);

    $fresh = $this->lawyer->fresh();

    expect($fresh->is_active)->toBeFalse()
        ->and((int) $fresh->session_epoch)->toBe($epoch + 1)
        ->and($fresh->remember_token)->not->toBe('token-ghi-nho-cu')
        ->and($fresh->ai_access)->toBe(AiAccessMode::Off)
        ->and(Passport::token()->newQuery()->where('user_id', $this->lawyer->id)->where('revoked', false)->exists())->toBeFalse()
        ->and($fresh->pushSubscriptions()->count())->toBe(0)
        // Việc dở dang đứng nguyên — bàn giao sau.
        ->and($this->matter->fresh()->lead_lawyer_id)->toBe($this->lawyer->id)
        ->and($this->deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);

    $audit = Activity::query()->where('event', 'staff_access_suspended')->sole();

    expect($audit->causer_id)->toBe($this->admin->id)
        ->and($audit->subject_id)->toBe($this->lawyer->id)
        ->and($audit->properties['reason'])->toBe(FB_SUSPEND_REASON)
        ->and($audit->properties['open_lead_matters'])->toBe(1)
        ->and($audit->properties['open_deadlines'])->toBe(1);

    expect(Activity::query()->where('event', 'push_device_removed')->sole()->properties['reason'])
        ->toBe(ForgetPushDevice::REASON_STAFF_SUSPENDED);

    unset($token);
});

it('signs the locked staff member out of the session they already had open, on their next request', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->callAction('suspendAccess', data: ['reason' => FB_SUSPEND_REASON])
        ->assertHasNoActionErrors();

    $this->actingAs($this->lawyer->fresh(), 'web')
        ->get('/admin')
        ->assertRedirect('/admin/login');

    expect(auth('web')->check())->toBeFalse();
});

it('asks for a reason of at least 20 characters before locking', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->callAction('suspendAccess', data: ['reason' => 'nghỉ'])
        ->assertHasActionErrors(['reason']);

    expect($this->lawyer->fresh()->is_active)->toBeTrue()
        ->and(Activity::query()->where('event', 'staff_access_suspended')->exists())->toBeFalse();
});

it('names the open work that stays behind in the confirmation', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->mountAction('suspendAccess')
        ->assertMountedActionModalSee(__('staff_access.suspend.modal_description', [
            'name' => 'Luật Sư Nghỉ Đột Xuất',
            'matters' => 1,
            'deadlines' => 1,
            'requests' => 0,
        ]));
});

it('does not offer the lock on the admin\'s own page, nor on an account that is already inactive', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
        ->assertActionHidden('suspendAccess');

    $inactive = User::factory()->withRole(Role::Assistant)->create(['is_active' => false]);

    Livewire::test(EditUser::class, ['record' => $inactive->getRouteKey()])
        ->assertActionHidden('suspendAccess');
});

/**
 * Luật "quản trị viên đang hoạt động cuối cùng" không tới được từ màn hình — người bấm luôn là một
 * admin KHÁC đang hoạt động (tự khoá bị chặn ở policy) — nên đo ở tầng Action, với một người gọi đã
 * bị vô hiệu hoá (đường console hay một phiên cũ), đúng tình huống mà chốt này phòng.
 */
it('refuses, in the action itself, to lock the last active administrator', function () {
    $formerAdmin = User::factory()->withRole(Role::Admin)->create(['is_active' => false]);

    expect(fn () => app(SuspendStaffAccess::class)->handle($formerAdmin, $this->admin, FB_SUSPEND_REASON))
        ->toThrow(ValidationException::class)
        ->and($this->admin->fresh()->is_active)->toBeTrue();
});

it('lets the admin switch the account back on afterwards through the normal toggle', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->callAction('suspendAccess', data: ['reason' => FB_SUSPEND_REASON])
        ->assertHasNoActionErrors();

    Livewire::test(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->fillForm(['is_active' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->lawyer->fresh()->is_active)->toBeTrue();
});
