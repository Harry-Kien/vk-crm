<?php

use App\Enums\IntakeStatus;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fi, A1 — người nghỉ việc / đổi vai còn giữ lead tiếp nhận đang mở. `GuardsStaffOffboarding` (qua
 * `EditUser` — tắt "Đang hoạt động", đổi chức danh — và `UserPolicy::delete()` — nút Xoá) từng chỉ đếm vụ
 * việc, mốc hạn, yêu cầu khách: một luật sư được "Chuyển cho luật sư" (`consulting_lawyer_id`) hay một
 * người phụ trách lead (`assigned_to`) nghỉ việc thì lead nằm lại ở người không còn làm được gì với nó,
 * không ai được báo. Hai loại việc mới góp mặt trong cùng câu từ chối, cùng cách hiện như các loại cũ.
 * Hàm toàn cục mang tiền tố `sol…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Sắp Nghỉ']);
    $this->sales = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh Doanh Sắp Nghỉ']);
});

/** Lead đang mở (bước "Đã liên hệ"), người theo lead là nhân viên kinh doanh. */
function solLead(array $attributes = [], IntakeStatus $status = IntakeStatus::Contacted): IntakeRequest
{
    return IntakeRequest::factory()->status($status)->create($attributes);
}

/**
 * Câu từ chối ghép như `GuardsStaffOffboarding::composeOpenWorkReason()`: mảnh nào còn > 0 thì góp mặt, theo
 * thứ tự vụ lead, mốc hạn, yêu cầu khách, lead được chuyển tư vấn, lead đang phụ trách.
 */
function solMessage(string $intro, int $matters = 0, int $consulting = 0, int $assigned = 0): string
{
    $parts = [];

    if ($matters > 0) {
        $parts[] = __('users.offboarding.open_work_lead_matters', ['count' => $matters]);
    }

    if ($consulting > 0) {
        $parts[] = __('users.offboarding.open_work_consulting_leads', ['count' => $consulting]);
    }

    if ($assigned > 0) {
        $parts[] = __('users.offboarding.open_work_assigned_leads', ['count' => $assigned]);
    }

    return $intro.' '.implode('; ', $parts).'. '.__('users.offboarding.open_work_outro');
}

function solOffboardIntro(User $user): string
{
    return __('users.offboarding.open_work_intro', ['name' => $user->name]);
}

function solDemotionIntro(User $user): string
{
    return __('users.offboarding.demotion_intro', ['name' => $user->name]);
}

it('refuses to deactivate a lawyer who still holds an open lead handed to them, naming the count and the way out', function () {
    solLead(['assigned_to' => $this->sales->id, 'consulting_lawyer_id' => $this->lawyer->id]);
    solLead(['assigned_to' => $this->sales->id, 'consulting_lawyer_id' => $this->lawyer->id], IntakeStatus::Quoted);

    $this->actingAs($this->admin, 'web');

    $component = $this->livewire(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    expect($component->errors()->first('data.is_active'))
        ->toBe(solMessage(solOffboardIntro($this->lawyer), consulting: 2))
        ->toContain(__('leads.handoff.action'))
        ->and($this->lawyer->fresh()->is_active)->toBeTrue();
});

it('refuses to delete a lawyer who still holds an open lead handed to them', function () {
    solLead(['assigned_to' => $this->sales->id, 'consulting_lawyer_id' => $this->lawyer->id]);

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->callAction('delete')
        ->assertNotified(solMessage(solOffboardIntro($this->lawyer), consulting: 1));

    expect($this->lawyer->fresh()->trashed())->toBeFalse();
});

it('refuses to deactivate a sales person who is still the owner of an open lead', function () {
    solLead(['assigned_to' => $this->sales->id]);

    $this->actingAs($this->admin, 'web');

    $component = $this->livewire(EditUser::class, ['record' => $this->sales->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    expect($component->errors()->first('data.is_active'))
        ->toBe(solMessage(solOffboardIntro($this->sales), assigned: 1))
        ->and($this->sales->fresh()->is_active)->toBeTrue();
});

it('lists lead matters and both kinds of lead in one sentence', function () {
    Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id, 'closed_at' => null]);
    solLead(['assigned_to' => $this->sales->id, 'consulting_lawyer_id' => $this->lawyer->id]);
    solLead(['assigned_to' => $this->lawyer->id]);

    $this->actingAs($this->admin, 'web');

    $component = $this->livewire(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    expect($component->errors()->first('data.is_active'))
        ->toBe(solMessage(solOffboardIntro($this->lawyer), matters: 1, consulting: 1, assigned: 1));
});

it('does not count leads that are finished: declined, lost, merged, converted or anonymised', function () {
    foreach ([IntakeStatus::Declined, IntakeStatus::Lost, IntakeStatus::Merged, IntakeStatus::Won] as $status) {
        solLead(['assigned_to' => $this->sales->id, 'consulting_lawyer_id' => $this->lawyer->id], $status);
    }

    solLead(['assigned_to' => $this->sales->id, 'consulting_lawyer_id' => $this->lawyer->id, 'matter_id' => Matter::factory()->create()->id]);
    solLead(['assigned_to' => $this->sales->id, 'consulting_lawyer_id' => $this->lawyer->id, 'anonymised_at' => now()]);

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->lawyer->fresh()->is_active)->toBeFalse();
});

it('refuses to move a lawyer holding a handed-over lead to sales or assistant, who cannot open a matter from it', function (UserPosition $target) {
    solLead(['assigned_to' => $this->sales->id, 'consulting_lawyer_id' => $this->lawyer->id]);

    $this->actingAs($this->admin, 'web');

    $component = $this->livewire(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->fillForm(['position' => $target->value])
        ->call('save')
        ->assertHasFormErrors(['position']);

    expect($component->errors()->first('data.position'))
        ->toBe(solMessage(solDemotionIntro($this->lawyer), consulting: 1))
        ->and($this->lawyer->fresh()->position)->toBe(UserPosition::Lawyer);
})->with([
    'sales' => UserPosition::Sales,
    'assistant' => UserPosition::Assistant,
    'accountant' => UserPosition::Accountant,
]);

it('lets a lawyer who only owns an open lead move to sales or assistant, who can still own a lead', function (UserPosition $target) {
    solLead(['assigned_to' => $this->lawyer->id]);

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->fillForm(['position' => $target->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->lawyer->fresh()->position)->toBe($target);
})->with([
    'sales' => UserPosition::Sales,
    'assistant' => UserPosition::Assistant,
]);

it('refuses to move a lead owner to accountant, who has no access to leads at all', function () {
    solLead(['assigned_to' => $this->lawyer->id]);

    $this->actingAs($this->admin, 'web');

    $component = $this->livewire(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->fillForm(['position' => UserPosition::Accountant->value])
        ->call('save')
        ->assertHasFormErrors(['position']);

    expect($component->errors()->first('data.position'))
        ->toBe(solMessage(solDemotionIntro($this->lawyer), assigned: 1))
        ->and($this->lawyer->fresh()->position)->toBe(UserPosition::Lawyer);
});

it('offboards the lawyer once the lead is handed to another lawyer through "Chuyển cho luật sư"', function () {
    $lead = solLead(['assigned_to' => $this->sales->id, 'consulting_lawyer_id' => $this->lawyer->id]);
    $other = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $lead->getRouteKey()])
        ->callAction(TestAction::make('handToLawyer'), ['consulting_lawyer_id' => $other->id])
        ->assertHasNoActionErrors();

    $this->livewire(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($lead->fresh()->consulting_lawyer_id)->toBe($other->id)
        ->and($this->lawyer->fresh()->is_active)->toBeFalse();
});

/*
 * Làn fi, vòng sửa 1 (I3): "Khoá truy cập ngay" là ca nghỉ đột xuất của A1, và sau khi khoá (`is_active =
 * false`) luật chặn vô hiệu hoá không bao giờ chạy nữa — chỉ nút Xoá còn bắt được lead. Nên hộp xác nhận và
 * dòng nhật ký `staff_access_suspended` đếm cả lead đang mở, và chỉ đúng đường chuyển lead ("Bàn giao hàng
 * loạt" không chuyển lead).
 */
it('names the open leads and the way to move them in the "Khoá truy cập ngay" confirmation, and logs their counts', function () {
    solLead(['assigned_to' => $this->sales->id, 'consulting_lawyer_id' => $this->lawyer->id]);
    solLead(['assigned_to' => $this->sales->id, 'consulting_lawyer_id' => $this->lawyer->id], IntakeStatus::Quoted);
    solLead(['assigned_to' => $this->lawyer->id]);
    solLead(['assigned_to' => $this->sales->id, 'consulting_lawyer_id' => $this->lawyer->id], IntakeStatus::Lost);

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->mountAction('suspendAccess')
        ->assertMountedActionModalSee('2 lead được chuyển tư vấn')
        ->assertMountedActionModalSee('1 lead phụ trách')
        ->assertMountedActionModalSee('"Chuyển cho luật sư"')
        ->assertMountedActionModalSee('"Người phụ trách"');

    $this->livewire(EditUser::class, ['record' => $this->lawyer->getRouteKey()])
        ->callAction('suspendAccess', data: ['reason' => 'Nghỉ việc đột xuất, chưa kịp bàn giao các lead.'])
        ->assertHasNoActionErrors();

    $audit = Activity::query()->where('event', 'staff_access_suspended')->sole();

    expect($this->lawyer->fresh()->is_active)->toBeFalse()
        ->and($audit->properties['open_consulting_leads'])->toBe(2)
        ->and($audit->properties['open_assigned_leads'])->toBe(1);
});

/*
 * Làn fi, vòng sửa 1 (I4): lead người này phụ trách KHÔNG chặn lần đổi sang Nhân viên kinh doanh
 * (`EditUser::ASSIGNED_LEADS_BLOCK_DEMOTION` chỉ có Kế toán) — câu gợi ý trên ô chức danh nói đúng như vậy.
 */
it('says in the position hint that a move to sales is not blocked by the leads the person owns', function () {
    $otherAdmin = User::factory()->position(UserPosition::Admin)->withRole(Role::Admin)->create();

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditUser::class, ['record' => $otherAdmin->getRouteKey()])
        ->assertSee('mọi việc dở dang trừ lead người này phụ trách')
        ->assertDontSee('với Kế toán và Nhân viên kinh doanh: mọi việc dở dang');
});
