<?php

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\EditMatter;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('returns 200 for the edit page to someone who can view the matter', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web')
        ->get(MatterResource::getUrl('edit', ['record' => $matter], panel: 'admin'))
        ->assertOk();
});

/** Route binding phải áp cùng listableBy() như view/index — cùng luật, cùng lý do (SPEC §5). */
it('returns 404 for the edit page to an outsider', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create();

    $this->actingAs($outsider, 'web')
        ->get(MatterResource::getUrl('edit', ['record' => $matter], panel: 'admin'))
        ->assertNotFound();
});

/** Test bắt buộc của brief: sửa số thụ lý qua Livewire, audit nêu trường đã đổi. */
it('saves case_number through the edit screen and records which field changed', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'case_number' => null]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm([
            'title' => $matter->title,
            'summary_for_client' => $matter->summary_for_client,
            'court_name' => $matter->court_name,
            'case_number' => '99/2026/DS-ST',
            'confidentiality' => $matter->confidentiality->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($matter->fresh()->case_number)->toBe('99/2026/DS-ST');

    $activity = Activity::query()->where('event', 'matter_details_updated')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('changed_fields'))->toBe(['case_number']);
});

/**
 * `handleRecordUpdate()` dịch `AuthorizationException` (R5) thành lỗi gắn vào ô `confidentiality`
 * — nhánh này KHÔNG chạm được qua `fillForm()` bình thường vì ô đã `disabled()` cho trợ lý (xem
 * test dưới), nên gọi thẳng phương thức `protected` qua `Closure::bind`, đúng thành ngữ
 * `CreateMatterTest::createMatterGuard()`, để mô phỏng một request bị chỉnh sửa tay bỏ qua
 * `disabled()` — cổng thật (Action) vẫn phải đứng vững, không phải một trang 500.
 */
function editMatterRecordUpdateGuard(EditMatter $page): Closure
{
    return Closure::bind(
        fn (Matter $record, array $data): Matter => $this->handleRecordUpdate($record, $data),
        $page,
        EditMatter::class,
    );
}

it('turns a bypassed confidentiality change from an assistant into a field error, not a 500', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'confidentiality' => Confidentiality::Normal,
    ]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    $instance = $this->livewire(EditMatter::class, ['record' => $matter->getKey()])->instance();
    $guard = editMatterRecordUpdateGuard($instance);

    expect(fn () => $guard($matter, [
        'title' => $matter->title,
        'confidentiality' => Confidentiality::Restricted->value,
    ]))->toThrow(ValidationException::class);

    expect($matter->fresh()->confidentiality)->toBe(Confidentiality::Normal);
});

/**
 * Test bắt buộc của brief: trợ lý đổi confidentiality bị từ chối. Ô này `disabled()` với trợ lý
 * (`MatterEditForm`), nên Filament không dehydrate nó — giá trị không bao giờ tới được
 * `handleRecordUpdate()`/`UpdateMatterDetails`, và lưu vẫn THÀNH CÔNG cho các trường khác, chỉ
 * riêng `confidentiality` không đổi. Đây là tầng UI; tầng CỔNG THẬT (Action tự hỏi lại
 * `Gate::authorize('updateConfidentiality', ...)` khi giá trị thật sự đổi) được ghim riêng ở
 * `UpdateMatterDetailsTest` ("refuses an assistant who tries to change confidentiality"), nơi
 * Action được gọi trực tiếp với một payload không đi qua `disabled()` của form.
 */
it('keeps confidentiality unchanged for an assistant, because the field is disabled on the edit screen', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'confidentiality' => Confidentiality::Normal,
    ]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->assertFormFieldIsDisabled('confidentiality')
        ->fillForm([
            'title' => 'Sửa bởi trợ lý, không đụng độ mật',
            'confidentiality' => Confidentiality::Restricted->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($matter->fresh()->confidentiality)->toBe(Confidentiality::Normal)
        ->and($matter->fresh()->title)->toBe('Sửa bởi trợ lý, không đụng độ mật');
});

/** Cặp dương: cùng vụ việc, cùng thay đổi, nhưng do luật sư phụ trách — phải lưu được. */
it('lets the lead lawyer change confidentiality through the edit screen', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'confidentiality' => Confidentiality::Normal,
    ]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm([
            'title' => $matter->title,
            'confidentiality' => Confidentiality::Restricted->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($matter->fresh()->confidentiality)->toBe(Confidentiality::Restricted);
});

/** client_id, matter_type_id và lead_lawyer_id không có mặt trên form sửa. */
it('has no client_id, matter_type_id, or lead_lawyer_id field on the edit form', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->assertFormFieldDoesNotExist('client_id')
        ->assertFormFieldDoesNotExist('matter_type_id')
        ->assertFormFieldDoesNotExist('lead_lawyer_id');
});

// =========================================================================================
// "Huỷ hồ sơ mở nhầm" (CancelMatter header action)
// =========================================================================================

it('lets an admin cancel a wrongly opened matter with a reason', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->callAction('cancelMatter', data: ['reason' => 'Mở nhầm khách hàng, mở lại vụ việc đúng.']);

    expect($matter->fresh()->trashed())->toBeTrue();
});

/** Cặp âm: gửi lý do rỗng thì bị từ chối, và vụ việc vẫn còn nguyên. */
it('refuses to cancel a matter without a reason through the edit screen', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->callAction('cancelMatter', data: ['reason' => '']);

    expect($matter->fresh())->not->toBeNull()
        ->and($matter->fresh()->trashed())->toBeFalse();
});

/** Cổng hiển thị: một trưởng phòng, không phải admin, không thấy nút huỷ. */
it('hides the cancel-matter action from a manager, who is not an admin', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create();

    $this->actingAs($manager, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->assertActionHidden('cancelMatter');
});

it('shows the cancel-matter action to an admin', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->assertActionVisible('cancelMatter');
});
