<?php

use App\Enums\IntakeSource;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\CreateIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Models\Client;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Notifications\Staff\IntakeAssigned;
use App\Notifications\Staff\IntakeRedPending;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

/*
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, hai mục nhỏ) — người được giao một lần tiếp nhận được báo, và
 * trưởng phòng/quản trị được báo khi một lần tiếp nhận vừa ra Đỏ chờ xử lý. Thông báo trong hệ thống chỉ
 * mang mã bản ghi. Qua màn hình. Hàm toàn cục mang tiền tố `ial…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function ialStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

function ialCreateData(array $overrides = []): array
{
    return [...[
        'contact_name' => 'Lê Văn Gọi',
        'contact_phone' => '0901234567',
        'contact_role' => PartyRole::Plaintiff->value,
        'source' => IntakeSource::Phone->value,
        'privacy_notice' => true,
        'parties' => [['role' => PartyRole::Defendant->value, 'name' => 'Bị Đơn Chưa Ai Biết', 'phone' => '0977000111', 'id_number' => null]],
    ], ...$overrides];
}

/** @return list<string> loại thông báo người này đã nhận */
function ialNotificationTypes(User $user): array
{
    return $user->fresh()->notifications()->pluck('type')->all();
}

it('tells the lawyer a call was assigned to them, with only the code, and not the person who assigned it to themselves', function () {
    $assistant = ialStaff();
    $lawyer = ialStaff(Role::Lawyer);
    $this->actingAs($assistant, 'web');

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(ialCreateData(['assigned_to' => $lawyer->id]))
        ->call('create')
        ->assertHasNoFormErrors();

    $intake = IntakeRequest::query()->sole();
    $notification = $lawyer->fresh()->notifications()->sole();

    expect($notification->type)->toBe(IntakeAssigned::class)
        ->and($notification->data['body'])->toContain($intake->code)
        ->and($notification->data['body'])->not->toContain('Lê Văn Gọi')
        ->and($notification->data['body'])->not->toContain('0901234567')
        ->and(ialNotificationTypes($assistant))->toBe([]);

    // Đổi người phụ trách trên trang bản ghi: người mới được báo.
    $other = ialStaff(Role::Lawyer);
    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->fillForm(['assigned_to' => $other->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ialNotificationTypes($other))->toBe([IntakeAssigned::class]);

    // Lưu một ô khác: người đang được giao không được báo lại.
    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->fillForm(['referred_by' => 'Chị Tư'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ialNotificationTypes($other))->toBe([IntakeAssigned::class]);

    // Tự giao cho chính mình: không thông báo.
    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->fillForm(['assigned_to' => $assistant->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ialNotificationTypes($assistant))->toBe([]);
});

it('tells the managers, once, that a call came out red, and nobody else', function () {
    $client = Client::factory()->create(['phone' => '0912000111', 'id_number' => null, 'name' => 'Khách Hiện Hữu']);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();
    $assistant = ialStaff();
    $manager = ialStaff(Role::Manager);
    $lawyer = ialStaff(Role::Lawyer);
    $retired = tap(ialStaff(Role::Manager), fn (User $user) => $user->forceFill(['is_active' => false])->save());
    $this->actingAs($assistant, 'web');

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(ialCreateData(['parties' => [['role' => PartyRole::Defendant->value, 'name' => 'Bị Đơn Là Khách', 'phone' => '0912000111', 'id_number' => null]]]))
        ->call('create')
        ->assertHasNoFormErrors();

    $intake = IntakeRequest::query()->sole();
    expect($intake->hasUnresolvedRed())->toBeTrue();

    $notification = $manager->fresh()->notifications()->sole();

    expect($notification->type)->toBe(IntakeRedPending::class)
        ->and($notification->data['body'])->toContain($intake->code)
        ->and($notification->data['body'])->not->toContain('Khách Hiện Hữu')
        ->and(ialNotificationTypes($assistant))->toBe([])
        ->and(ialNotificationTypes($lawyer))->toBe([])
        ->and(ialNotificationTypes($retired))->toBe([]);

    // Chạy lại khi Đỏ vẫn chờ: không báo lại.
    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->callAction(TestAction::make('rerun')->schemaComponent('checkActions'))
        ->assertHasNoErrors();

    expect($manager->fresh()->notifications()->count())->toBe(1);
});
