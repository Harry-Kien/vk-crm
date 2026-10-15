<?php

use App\Actions\Intake\HandIntakeToLawyer;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\IntakeStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ListIntakeRequests;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Notifications\Staff\IntakeHandedToLawyer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
 * Làn kd, Task 1 — "Chuyển cho luật sư": khi lead cần tư vấn chuyên môn hay mở vụ, nhân viên kinh doanh
 * giao lead cho MỘT luật sư tư vấn (`intake_requests.consulting_lawyer_id`, trường riêng — `assigned_to`
 * vẫn là người theo lead, nên nhân viên kinh doanh vẫn theo dõi được lead mình được giao). Luật sư tư vấn
 * thấy lead (`IntakeRequest::scopeVisibleTo()`), nhận thông báo chỉ mang mã, và chuyển thành vụ việc theo
 * đúng quyền hiện có. Chỉ người mở được vụ (`intake.convert` + `matter.create`, đang làm việc) nhận được
 * lead. Hàm toàn cục mang tiền tố `lho…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->sales = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh Doanh Một']);
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Tư Vấn']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng']);
    $this->lead = IntakeRequest::factory()->status(IntakeStatus::Contacted)->create([
        'created_by' => $this->manager->id,
        'assigned_to' => $this->sales->id,
        'contact_name' => 'Khách Cần Luật Sư',
        'contact_phone' => '0901112233',
    ]);
});

function lhoHandoff(): TestAction
{
    return TestAction::make('handToLawyer');
}

it('lets a sales person hand their lead to a lawyer, who is told with the code only and can then open and convert it', function () {
    $this->actingAs($this->sales, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $this->lead->getRouteKey()])
        ->assertActionVisible(lhoHandoff())
        ->callAction(lhoHandoff(), ['consulting_lawyer_id' => $this->lawyer->id])
        ->assertHasNoActionErrors()
        ->assertSee('Luật Sư Tư Vấn');

    $lead = $this->lead->fresh();

    expect($lead->consulting_lawyer_id)->toBe($this->lawyer->id)
        ->and($lead->handed_to_lawyer_at)->not->toBeNull()
        // Người theo lead không đổi: nhân viên kinh doanh vẫn là người được giao, vẫn thấy lead.
        ->and($lead->assigned_to)->toBe($this->sales->id)
        ->and($lead->isVisibleTo($this->sales))->toBeTrue();

    $notification = $this->lawyer->fresh()->notifications()->sole();

    expect($notification->type)->toBe(IntakeHandedToLawyer::class)
        ->and($notification->data['body'])->toContain($lead->code)
        ->and($notification->data['body'])->not->toContain('Khách Cần Luật Sư')
        ->and($notification->data['body'])->not->toContain('0901112233')
        ->and($this->sales->fresh()->notifications()->count())->toBe(0);

    $audit = Activity::query()->where('event', 'intake_handed_to_lawyer')->sole();

    expect($audit->subject_id)->toBe($lead->id)
        ->and($audit->causer_id)->toBe($this->sales->id)
        ->and($audit->properties['lawyer_id'])->toBe($this->lawyer->id);

    // Luật sư tư vấn thấy lead trong danh sách và có nút chuyển thành vụ việc.
    $this->actingAs($this->lawyer, 'web');

    $this->livewire(ListIntakeRequests::class)->assertCanSeeTableRecords([$lead]);
    $this->livewire(EditIntakeRequest::class, ['record' => $lead->getRouteKey()])
        ->assertOk()
        ->assertActionVisible('convert');
});

it('offers only active staff who can open a matter as the consulting lawyer', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Không Mở Vụ']);
    $otherSales = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh Doanh Hai']);
    $accountant = User::factory()->withRole(Role::Accountant)->create(['name' => 'Kế Toán Không']);
    $retired = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Đã Nghỉ', 'is_active' => false]);

    $options = HandIntakeToLawyer::candidateOptions();

    expect($options)->toHaveKey($this->lawyer->id)
        ->and($options)->toHaveKey($this->manager->id)
        ->and($options)->not->toHaveKey($assistant->id)
        ->and($options)->not->toHaveKey($otherSales->id)
        ->and($options)->not->toHaveKey($accountant->id)
        ->and($options)->not->toHaveKey($retired->id)
        ->and($options)->not->toHaveKey($this->sales->id);

    // Màn hình: chọn một người ngoài danh sách bị từ chối, bản ghi không đổi.
    $this->actingAs($this->sales, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $this->lead->getRouteKey()])
        ->callAction(lhoHandoff(), ['consulting_lawyer_id' => $assistant->id])
        ->assertHasActionErrors();

    expect($this->lead->fresh()->consulting_lawyer_id)->toBeNull();

    // Action là cổng thật: một luật sư đã nghỉ việc cũng bị từ chối.
    expect(fn () => app(HandIntakeToLawyer::class)->handle($this->sales, $this->lead, $retired))
        ->toThrow(ValidationException::class, __('leads.handoff.errors.not_a_lawyer'));
});

it('refuses to hand over a lead that is done, and hides the button there', function () {
    $won = IntakeRequest::factory()->status(IntakeStatus::Won)->create(['created_by' => $this->sales->id]);
    $lost = IntakeRequest::factory()->status(IntakeStatus::Lost)->create(['created_by' => $this->sales->id]);

    $this->actingAs($this->sales, 'web');

    foreach ([$won, $lost] as $closed) {
        $this->livewire(EditIntakeRequest::class, ['record' => $closed->getRouteKey()])
            ->assertActionHidden(lhoHandoff());

        expect(fn () => app(HandIntakeToLawyer::class)->handle($this->sales, $closed, $this->lawyer))
            ->toThrow(ValidationException::class, __('leads.handoff.errors.closed'));
    }
});

it('refuses to hand a lead to the lawyer who already has it', function () {
    app(HandIntakeToLawyer::class)->handle($this->sales, $this->lead, $this->lawyer);

    expect(fn () => app(HandIntakeToLawyer::class)->handle($this->sales, $this->lead->fresh(), $this->lawyer))
        ->toThrow(ValidationException::class, __('leads.handoff.errors.same_lawyer'));

    expect($this->lawyer->fresh()->notifications()->count())->toBe(1);
});

it('refuses a handoff by someone who cannot see the lead', function () {
    $stranger = User::factory()->withRole(Role::Sales)->create();

    expect(fn () => app(HandIntakeToLawyer::class)->handle($stranger, $this->lead, $this->lawyer))
        ->toThrow(AuthorizationException::class);

    expect($this->lead->fresh()->consulting_lawyer_id)->toBeNull();
});

it('moves the lead from one consulting lawyer to another: the first one no longer sees it', function () {
    $second = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Thứ Hai']);

    app(HandIntakeToLawyer::class)->handle($this->sales, $this->lead, $this->lawyer);
    app(HandIntakeToLawyer::class)->handle($this->sales, $this->lead->fresh(), $second);

    $lead = $this->lead->fresh();

    expect($lead->consulting_lawyer_id)->toBe($second->id)
        ->and($lead->isVisibleTo($second))->toBeTrue()
        ->and($lead->isVisibleTo($this->lawyer))->toBeFalse()
        ->and(IntakeRequest::query()->visibleTo($this->lawyer)->whereKey($lead->id)->exists())->toBeFalse()
        ->and(IntakeRequest::query()->visibleTo($second)->whereKey($lead->id)->exists())->toBeTrue()
        ->and($lead->isVisibleTo($this->sales))->toBeTrue();
});

it('does not notify a lawyer who hands a lead to themselves, nor a consulting lawyer who left or can no longer see it', function () {
    $own = IntakeRequest::factory()->status(IntakeStatus::Contacted)->create(['created_by' => $this->lawyer->id]);

    app(HandIntakeToLawyer::class)->handle($this->lawyer, $own, $this->lawyer);

    expect($own->fresh()->consulting_lawyer_id)->toBe($this->lawyer->id)
        ->and($this->lawyer->fresh()->notifications()->count())->toBe(0);

    // Lớp người nhận hỏi lại: luật sư tư vấn đã nghỉ việc, hay không còn thấy bản ghi, thì không ai nhận.
    app(HandIntakeToLawyer::class)->handle($this->sales, $this->lead, $this->lawyer);
    $lead = $this->lead->fresh();
    $recipients = app(ResolveStaffRecipients::class);

    expect($recipients->forIntakeHandoff($lead)->pluck('id')->all())->toBe([$this->lawyer->id]);

    $this->lawyer->forceFill(['is_active' => false])->save();
    expect($recipients->forIntakeHandoff($lead->fresh())->all())->toBe([]);

    $this->lawyer->forceFill(['is_active' => true])->save();
    $this->lawyer->syncRoles([Role::Accountant->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($recipients->forIntakeHandoff($lead->fresh())->all())->toBe([]);
});

it('needs both intake.convert and matter.create to receive a lead — either alone is not enough', function () {
    $convertOnly = User::factory()->create(['name' => 'Chỉ Chuyển Đổi']);
    $convertOnly->givePermissionTo([Permission::IntakeCreate->value, Permission::IntakeConvert->value]);
    $createOnly = User::factory()->create(['name' => 'Chỉ Mở Vụ']);
    $createOnly->givePermissionTo([Permission::IntakeCreate->value, Permission::MatterCreate->value]);
    $both = User::factory()->create(['name' => 'Cả Hai Quyền']);
    $both->givePermissionTo([Permission::IntakeCreate->value, Permission::IntakeConvert->value, Permission::MatterCreate->value]);

    expect(HandIntakeToLawyer::canReceive($convertOnly->fresh()))->toBeFalse()
        ->and(HandIntakeToLawyer::canReceive($createOnly->fresh()))->toBeFalse()
        ->and(HandIntakeToLawyer::canReceive($both->fresh()))->toBeTrue();
});
