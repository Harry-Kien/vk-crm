<?php

use App\Enums\ClientRequestStatus;
use App\Enums\Confidentiality;
use App\Enums\InstalmentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\EditMatter;
use App\Models\ClientRequest;
use App\Models\Contract;
use App\Models\Deadline;
use App\Models\Instalment;
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

/**
 * Fix round 1, minor: trang Sửa không được lặp lại bảy tab quan hệ của `ViewMatter`
 * (`MatterResource::getRelations()`) — nó chỉ có việc sửa năm trường, không phải một bản sao của
 * trang Xem. Không ghi đè `getRelationManagers()`, Filament tự kế thừa TOÀN BỘ danh sách quan hệ
 * từ resource cho MỌI trang của nó, kể cả trang Edit.
 */
it('does not repeat the seven ViewMatter relation-manager tabs on the edit page', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->assertDontSee(__('team.tab.title'));
});

/** Cặp dương: cùng tab đó VẪN hiện trên trang Xem — chỉ trang Sửa mới không lặp lại nó. */
it('still shows the team tab on the view page', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->get(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'))
        ->assertOk()
        ->assertSee(__('team.tab.title'), escape: false);
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
 * Fix round 1, finding minor "EditMatter.php:119": `handleRecordUpdate()` không còn tự ĐOÁN
 * "mọi `AuthorizationException` là lỗi `confidentiality`" — `UpdateMatterDetails` giờ tự ném
 * `ValidationException` gắn ĐÚNG tên trường ngay tại nơi phát hiện (`confidentiality` hoặc
 * `summary_for_client`), và trang chỉ còn việc DỊCH state path (khoá trần → `data.<trường>`),
 * không cần biết trước lỗi thuộc trường nào. Nhánh này KHÔNG chạm được qua `fillForm()` bình
 * thường vì ô đã `disabled()` cho trợ lý (xem test dưới), nên gọi thẳng phương thức `protected`
 * qua `Closure::bind`, đúng thành ngữ `CreateMatterTest::createMatterGuard()`, để mô phỏng một
 * request bị chỉnh sửa tay bỏ qua `disabled()` — cổng thật (Action) vẫn phải đứng vững, không
 * phải một trang 500.
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
 * Test bắt buộc của brief: trợ lý đổi confidentiality bị từ chối. Ô này `disabled()` (fix round
 * 1, finding I2: dùng `Gate::allows('updateConfidentiality', $record)`, không còn hand-written
 * role check), nên Filament không dehydrate nó — giá trị không bao giờ tới được
 * `handleRecordUpdate()`/`UpdateMatterDetails`, và lưu vẫn THÀNH CÔNG cho các trường khác, chỉ
 * riêng `confidentiality` không đổi. Đây là tầng UI; tầng CỔNG THẬT được ghim riêng ở
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

/**
 * Fix round 1, finding I2: chỉ CÒN lead của CHÍNH vụ việc này hoặc admin — một trưởng phòng
 * (trước bản sửa này KHÔNG bị khoá, vì luật cũ chỉ loại trợ lý) giờ cũng bị khoá ô này, vì họ
 * không phải lead của vụ việc cụ thể này.
 */
it('disables confidentiality for a manager who is not the lead of this matter', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($manager, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->assertFormFieldIsDisabled('confidentiality');
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
        ->assertFormFieldEnabled('confidentiality')
        ->fillForm([
            'title' => $matter->title,
            'confidentiality' => Confidentiality::Restricted->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($matter->fresh()->confidentiality)->toBe(Confidentiality::Restricted);
});

/**
 * Fix round 1, finding I2 (phần đội ngũ), đo qua đúng màn hình thật: lead chuyển sang restricted
 * trong khi đội ngũ còn một trợ lý — bị từ chối, và thông báo nêu đúng tên người đó.
 */
it('shows the blocked-by-team message on the edit screen when the lead tries to switch to restricted with an assistant still on the team', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Bích Vân']);
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'confidentiality' => Confidentiality::Normal,
    ]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($lawyer, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm([
            'title' => $matter->title,
            'confidentiality' => Confidentiality::Restricted->value,
        ])
        ->call('save')
        ->assertHasFormErrors(['confidentiality']);

    expect($matter->fresh()->confidentiality)->toBe(Confidentiality::Normal);
});

// =========================================================================================
// Fix round 1, finding I1: summary_for_client đòi stageLog.publish.
// =========================================================================================

it('disables summary_for_client for an assistant', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->assertFormFieldIsDisabled('summary_for_client');
});

/** Cặp dương: lead (có stageLog.publish) đổi được summary_for_client và lưu thành công. */
it('lets the lead lawyer change summary_for_client through the edit screen', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'summary_for_client' => 'Tóm tắt cũ',
    ]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->assertFormFieldEnabled('summary_for_client')
        ->fillForm([
            'title' => $matter->title,
            'summary_for_client' => 'Tóm tắt mới cho khách',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($matter->fresh()->summary_for_client)->toBe('Tóm tắt mới cho khách');
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

/**
 * Gộp M9 (xung đột 1): huỷ một hồ sơ còn dư nợ trên hợp đồng active là một câu dưới ô "Lý do" của
 * chính hộp thoại, không phải một lỗi 500 (hook `Matter::deleting` ném DomainException mà hộp
 * thoại không bắt). Câu chữ và điều kiện đo ở `CancelMatterTest`; ở đây đo ĐÍCH của lời từ chối.
 */
it('shows an outstanding balance as a reason error on the cancel dialog, not a 500', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 10_000_000]);
    Instalment::factory()->for($contract)->create(['amount' => 10_000_000, 'status' => InstalmentStatus::Pending]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->callAction('cancelMatter', data: ['reason' => 'Mở nhầm khách hàng, mở lại vụ việc đúng.'])
        ->assertHasActionErrors(['reason']);

    expect($matter->fresh()->trashed())->toBeFalse();
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

/**
 * Final review A-M3: người GIỮ một mốc hạn chưa xong hay một yêu cầu khách chưa đóng cũng sẽ hết
 * thấy vụ việc khi nó chuyển sang `restricted` — không riêng thành viên đội ngũ. Một người giữ
 * việc ngoài đội ngũ (dữ liệu cũ, trước luật người giữ việc chung) phải chặn lần chuyển y như một
 * thành viên, và câu từ chối nêu đúng tên họ.
 */
it('refuses switching to restricted while someone outside the team still holds an open deadline or client request', function (string $kind) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $holder = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Giữ Việc']);
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'confidentiality' => Confidentiality::Normal,
    ]);

    if ($kind === 'deadline') {
        Deadline::factory()->for($matter)->create(['responsible_user_id' => $holder->id, 'is_completed' => false]);
    } else {
        ClientRequest::factory()->for($matter)->create([
            'assigned_to' => $holder->id,
            'status' => ClientRequestStatus::InProgress,
        ]);
    }

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm([
            'title' => $matter->title,
            'confidentiality' => Confidentiality::Restricted->value,
        ])
        ->call('save')
        ->assertHasFormErrors(['confidentiality']);

    expect($component->errors()->first('data.confidentiality'))->toContain('Trưởng phòng Giữ Việc')
        ->and($matter->fresh()->confidentiality)->toBe(Confidentiality::Normal);
})->with(['deadline', 'request']);

it('does not count a completed deadline or a closed request against the switch to restricted', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $holder = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'confidentiality' => Confidentiality::Normal,
    ]);
    Deadline::factory()->for($matter)->create(['responsible_user_id' => $holder->id, 'is_completed' => true]);
    ClientRequest::factory()->for($matter)->create([
        'assigned_to' => $holder->id,
        'status' => ClientRequestStatus::Closed,
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
