<?php

use App\Actions\Intake\AcknowledgeIntakeConflict;
use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\CreateIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Models\Client;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

/*
 * Làn kd, Task 1 — phán quyết: nhân viên kinh doanh KHÔNG thấy thông tin của khách hay vụ hiện hữu qua
 * kết quả kiểm tra xung đột. Họ chỉ thấy MỨC (Xanh/Vàng/Đỏ) và lời khuyên chuyển lead cho luật sư; không mã
 * hồ sơ, không lĩnh vực, không tên bên trùng, không tiêu chí khớp — ở cả khớp Vàng (khác người nhập có
 * `matter.view`, vẫn thấy đủ để tự xem trước khi xác nhận). Vì không thấy các khớp, họ không xác nhận được
 * "đã xem các khớp" (`AcknowledgeIntakeConflict` từ chối); gợi ý trùng "số này đã là khách của văn phòng"
 * cũng không hiện. Định nghĩa: ability `viewConflictMatches` = thấy được bản ghi VÀ có `matter.view`.
 * Hàm toàn cục mang tiền tố `scb…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    // Khách hiện hữu (SĐT 0912 000 111) trong vụ lĩnh vực "Lĩnh Vực Kín"; bên kia của vụ đó là "Ông Đối
    // Thủ Cũ" (SĐT 0977 111 222), không phải khách của văn phòng.
    $client = Client::factory()->create(['phone' => '0912000111', 'id_number' => null, 'name' => 'Khách Hiện Hữu Thật']);
    $this->matter = Matter::factory()->create([
        'client_id' => $client->id,
        'matter_type_id' => MatterType::factory()->withStages()->create(['name' => 'Lĩnh Vực Kín'])->id,
    ]);
    MatterParty::factory()->for($this->matter)->ourClient($client)->create();
    MatterParty::factory()->for($this->matter)->defendant()->identify(null, '0977111222')->create(['name' => 'Ông Đối Thủ Cũ']);

    $this->sales = User::factory()->withRole(Role::Sales)->create();
});

/** Nhân viên kinh doanh ghi một lead qua màn hình, rồi trả bản ghi vừa ghi. */
function scbRecord(User $actor, array $party, array $overrides = []): IntakeRequest
{
    test()->actingAs($actor, 'web');

    test()->livewire(CreateIntakeRequest::class)
        ->fillForm([...[
            'contact_name' => 'Người Gọi Tiềm Năng',
            'contact_phone' => '0901234567',
            'contact_role' => PartyRole::Plaintiff->value,
            'source' => IntakeSource::Phone->value,
            'privacy_notice' => true,
            'parties' => [[...['role' => PartyRole::Defendant->value, 'id_number' => null], ...$party]],
        ], ...$overrides])
        ->call('create')
        ->assertHasNoFormErrors();

    return IntakeRequest::query()->latest('id')->firstOrFail();
}

it('shows a sales person only the level of a yellow match and the advice to hand over, never the office records', function () {
    $intake = scbRecord($this->sales, ['name' => 'Tên Khác Người Gọi Khai', 'phone' => '0977111222']);

    expect($intake->conflict_level)->toBe(ConflictLevel::Yellow);

    $this->actingAs($this->sales, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertOk()
        ->assertSee(__('intake.check.heading_attention'))
        ->assertSee(__('leads.conflict.hidden_note'))
        ->assertDontSee($this->matter->code)
        ->assertDontSee('Ông Đối Thủ Cũ')
        // Bảng khớp (cột "Trùng theo", câu ranh giới) không được vẽ. Tên lĩnh vực thì có trong ô chọn "Lĩnh vực"
        // của form — danh mục mọi nhân sự đọc được — nên không đo bằng chữ đó.
        ->assertDontSee(__('matters.conflict.column_tier'))
        ->assertDontSee(__('matters.conflict.boundary_note'))
        ->assertActionDoesNotExist(TestAction::make('acknowledge')->schemaComponent('checkActions'));

    // Không chỉ view không vẽ: dữ liệu dựng cho khối kiểm tra đã không mang dòng khớp nào từ phía máy chủ.
    $panel = (fn (): array => $this->checkPanelViewData())
        ->call($this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])->instance());

    expect($panel['matchesHidden'])->toBeTrue()
        ->and($panel['result']['matches'])->toBe([])
        ->and($panel['result']['level'])->toBe(ConflictLevel::Yellow->value);

    // Cặp dương: luật sư được giao cùng bản ghi (có `matter.view`) thấy bảng khớp như trước.
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $intake->forceFill(['assigned_to' => $lawyer->id])->save();
    $this->actingAs($lawyer, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertSee($this->matter->code)
        ->assertSee('Ông Đối Thủ Cũ')
        ->assertSee(__('matters.conflict.column_tier'))
        ->assertDontSee(__('leads.conflict.hidden_note'))
        ->assertActionVisible(TestAction::make('acknowledge')->schemaComponent('checkActions'));
});

it('shows a sales person only the level of a red match, without the matter code a non-resolver normally sees', function () {
    $intake = scbRecord($this->sales, ['name' => 'Bị Đơn Là Khách', 'phone' => '0912000111']);

    expect($intake->conflict_level)->toBe(ConflictLevel::Red);

    $this->actingAs($this->sales, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertOk()
        ->assertSee(__('intake.check.heading_red'))
        ->assertSee(__('leads.conflict.hidden_note'))
        ->assertDontSee($this->matter->code)
        ->assertDontSee('Khách Hiện Hữu Thật')
        // Bảng khớp (cột "Trùng theo", câu ranh giới) không được vẽ. Tên lĩnh vực thì có trong ô chọn "Lĩnh vực"
        // của form — danh mục mọi nhân sự đọc được — nên không đo bằng chữ đó.
        ->assertDontSee(__('matters.conflict.column_tier'))
        ->assertDontSee(__('matters.conflict.boundary_note'));
});

it('still lets a sales person confirm a green check that only lacks identifiers, where there is no office record to see', function () {
    $intake = scbRecord($this->sales, ['name' => 'Bên Kia Chỉ Có Tên', 'phone' => null]);

    expect($intake->conflict_level)->toBe(ConflictLevel::Green)
        ->and($intake->conflict_result['incomplete_parties'])->toBe(['Bên Kia Chỉ Có Tên']);

    $this->actingAs($this->sales, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertSee(__('matters.conflict.incomplete', ['names' => 'Bên Kia Chỉ Có Tên']))
        ->assertDontSee(__('leads.conflict.hidden_note'))
        ->callAction(TestAction::make('acknowledge')->schemaComponent('checkActions'))
        ->assertHasNoActionErrors();

    expect($intake->fresh()->conflict_acknowledged_at)->not->toBeNull();
});

it('refuses a sales persons acknowledgement of matches they were never shown', function () {
    $intake = scbRecord($this->sales, ['name' => 'Tên Khác Người Gọi Khai', 'phone' => '0977111222']);

    expect(fn () => app(AcknowledgeIntakeConflict::class)->handle($this->sales, $intake, ConflictLevel::Yellow))
        ->toThrow(ValidationException::class, __('leads.errors.acknowledge_needs_lawyer'));

    expect($intake->fresh()->conflict_acknowledged_at)->toBeNull();

    // Cặp dương: luật sư được giao xác nhận được.
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $intake->forceFill(['assigned_to' => $lawyer->id])->save();

    app(AcknowledgeIntakeConflict::class)->handle($lawyer, $intake->fresh(), ConflictLevel::Yellow);

    expect($intake->fresh()->conflict_acknowledged_at)->not->toBeNull();
});

it('does not tell a sales person about matches carried over from an earlier call, rows they are not shown', function () {
    scbRecord($this->sales, ['name' => 'Tên Khác Người Gọi Khai', 'phone' => '0977111222']);
    // Cùng người gọi lại (cùng SĐT, cùng vai), không khai bên đối lập: kiểm tra mang sang bên đã khai lần trước.
    $again = scbRecord($this->sales, ['name' => 'Không Dùng'], ['parties' => []]);

    $this->actingAs($this->sales, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $again->getRouteKey()])
        ->assertOk()
        ->assertDontSee(__('intake.check.carried_note'));

    // Cặp dương: luật sư được giao thấy câu đó (và các dòng nó nói tới).
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $again->forceFill(['assigned_to' => $lawyer->id])->save();
    $this->actingAs($lawyer, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $again->getRouteKey()])
        ->assertSee(__('intake.check.carried_note'));
});

it('never tells a sales person that the caller is already a client of the office', function () {
    $intake = scbRecord($this->sales, ['name' => 'Bên Kia Mới Toanh', 'phone' => '0966555444'], ['contact_phone' => '0912000111']);

    $this->actingAs($this->sales, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertOk()
        ->assertDontSee(__('intake.duplicates.existing_client'))
        ->assertDontSee($this->matter->code);

    // Cặp dương: một trợ lý (có `matter.view`) ghi cùng cuộc gọi thì được báo.
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $other = scbRecord($assistant, ['name' => 'Bên Kia Khác Nữa', 'phone' => '0966555433'], ['contact_phone' => '0912000111', 'contact_name' => 'Người Gọi Thứ Hai']);

    $this->actingAs($assistant, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $other->getRouteKey()])
        ->assertOk()
        ->assertSee(__('intake.duplicates.existing_client'));
});

it('does not show a sales person the code of the matter their won lead became', function () {
    $intake = IntakeRequest::factory()->create([
        'created_by' => $this->sales->id,
        'status' => IntakeStatus::Won,
        'matter_id' => $this->matter->id,
    ]);

    $this->actingAs($this->sales, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertOk()
        ->assertSee(__('leads.decision.converted'))
        ->assertDontSee($this->matter->code);
});
