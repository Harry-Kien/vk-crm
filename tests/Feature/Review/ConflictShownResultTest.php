<?php

use App\Actions\AddMatterParty;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Actions\OpenMatter;
use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ConvertIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\PartiesRelationManager;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Spatie\Activitylog\Models\Activity;

/*
 * Rà soát toàn hệ thống 2026-10-22, làn harden — việc 1.
 *
 * Xác nhận "đã xem xét" một kết quả kiểm tra xung đột (Vàng, hay Xanh thiếu định danh) và ghi đè Đỏ
 * phải là quyết định về ĐÚNG bảng kết quả người dùng đã thấy. Trước bản sửa, Action chỉ so MỨC: một
 * khớp mới xuất hiện giữa lúc màn hình hiện bảng và lúc bấm lưu (cùng mức) được ghi vào
 * `confirmed_pairs` như đã xác nhận — dù chưa ai nhìn thấy nó (SPEC §6.10, nghĩa vụ nghề nghiệp).
 *
 * Hàm toàn cục mang tiền tố `csr…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/** Một bên chỉ có tên ở một vụ khác — khớp tầng tên, tối đa Vàng. */
function csrNamesake(string $name): Matter
{
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Related, 'name' => $name]);

    return $matter;
}

/** Khách hàng hiện hữu mang số CCCD `$idNumber` ở một vụ khác — nguồn của mức Đỏ. */
function csrFirmClientWithId(string $idNumber, string $name): Matter
{
    $client = Client::factory()->create(['id_number' => $idNumber, 'name' => $name]);
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    return $matter;
}

function csrClientVisibleTo(User $lawyer): Client
{
    $client = Client::factory()->create();
    $prior = Matter::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $lawyer->id]);
    MatterParty::factory()->for($prior)->ourClient($client, PartyRole::Plaintiff)->create();

    return $client;
}

/** @return array<string, mixed> */
function csrOpenForm(Client $client, User $lead, MatterType $type, array $otherParties): array
{
    return [
        'client_id' => $client->id,
        'client_role' => PartyRole::Plaintiff->value,
        'matter_type_id' => $type->id,
        'title' => 'Vụ kiểm tra bảng đã hiện',
        'lead_lawyer_id' => $lead->id,
        'summary_for_client' => 'Tóm tắt.',
        'other_parties' => $otherParties,
    ];
}

/** @return list<string> */
function csrShownCodes(?array $result): array
{
    return collect($result['matches'] ?? [])->pluck('matter_code')->sort()->values()->all();
}

it('refuses a yellow acknowledgement on the open-matter form when a new match appeared after the table was shown', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = csrClientVisibleTo($lawyer);
    $type = MatterType::factory()->withStages()->create();
    $first = csrNamesake('Lê Thị Hoa');

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(CreateMatter::class)->fillForm(csrOpenForm($client, $lawyer, $type, [[
        'role' => PartyRole::Defendant->value,
        'name' => 'Lê Thị Hoa',
        'id_number' => '033344455566',
        'phone' => '0977888999',
    ]]));

    $component->call('create')->assertHasFormErrors(['acknowledge_conflict']);
    expect(csrShownCodes($component->instance()->conflictResult))->toBe([$first->code]);

    // Trong lúc người dùng đọc bảng, một vụ khác ghi thêm một bên trùng tên: cùng mức Vàng, khớp MỚI.
    $second = csrNamesake('Lê Thị Hoa');

    $component->fillForm(['acknowledge_conflict' => true])
        ->call('create')
        ->assertHasFormErrors(['acknowledge_conflict' => [__('exceptions.conflict_result_changed')]]);

    expect(Matter::query()->where('title', 'Vụ kiểm tra bảng đã hiện')->exists())->toBeFalse()
        // Bảng hiện lại là bảng MỚI, dấu tích cũ đã gỡ.
        ->and(csrShownCodes($component->instance()->conflictResult))->toBe(collect([$first->code, $second->code])->sort()->values()->all())
        ->and($component->instance()->data['acknowledge_conflict'])->toBeFalse();

    // Xác nhận lại trên bảng mới thì lưu được.
    $component->fillForm(['acknowledge_conflict' => true])->call('create')->assertHasNoFormErrors();

    expect(Matter::query()->where('title', 'Vụ kiểm tra bảng đã hiện')->exists())->toBeTrue();
});

it('refuses a red override on the open-matter form when a new red match appeared after the table was shown', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $client = Client::factory()->create();
    $type = MatterType::factory()->withStages()->create();
    $first = csrFirmClientWithId('079099000111', 'Khách Cũ Một');

    $this->actingAs($manager, 'web');

    $component = $this->livewire(CreateMatter::class)->fillForm(csrOpenForm($client, $manager, $type, [[
        'role' => PartyRole::Defendant->value,
        'name' => 'Bị đơn trùng CCCD',
        'id_number' => '079099000111',
        'phone' => '0977000111',
    ]]));

    $component->call('create')->assertHasFormErrors(['override_reason']);
    expect(csrShownCodes($component->instance()->conflictResult))->toContain($first->code);

    $second = csrFirmClientWithId('079099000111', 'Khách Cũ Hai');

    $component->fillForm(['override_reason' => 'Đã gọi khách cũ một, không cùng việc.'])
        ->call('create')
        ->assertHasFormErrors(['override_reason' => [__('exceptions.conflict_result_changed_override')]]);

    expect(Matter::query()->where('title', 'Vụ kiểm tra bảng đã hiện')->exists())->toBeFalse()
        ->and(csrShownCodes($component->instance()->conflictResult))->toContain($second->code)
        // Lý do viết cho bảng cũ không sống sót sang bảng mới.
        ->and($component->instance()->data['override_reason'])->toBeNull();

    $component->fillForm(['override_reason' => 'Đã gọi cả hai khách cũ, không cùng việc.'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Matter::query()->where('title', 'Vụ kiểm tra bảng đã hiện')->exists())->toBeTrue();
});

it('refuses a yellow acknowledgement in the add-party modal when a new match appeared after it was shown', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    csrNamesake('Phan Văn Trùng');

    $this->actingAs($lawyer, 'web');

    $data = ['role' => PartyRole::Defendant->value, 'is_our_client' => false, 'name' => 'Phan Văn Trùng', 'phone' => '0977111222'];

    $livewire = $this->livewire(PartiesRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
    $livewire->callTableAction('create', data: $data)->assertHasTableActionErrors(['acknowledge_conflict']);

    $second = csrNamesake('Phan Văn Trùng');

    $livewire->setTableActionData([...$data, 'acknowledge_conflict' => true])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['acknowledge_conflict' => [__('exceptions.conflict_result_changed')]]);

    expect($matter->parties()->where('name', 'Phan Văn Trùng')->exists())->toBeFalse()
        ->and(csrShownCodes($livewire->instance()->conflictResult))->toContain($second->code);

    $livewire->setTableActionData([...$data, 'acknowledge_conflict' => true])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($matter->parties()->where('name', 'Phan Văn Trùng')->exists())->toBeTrue();

    $added = Activity::query()->where('event', 'matter_party_added')->latest('id')->first();
    expect($added->properties->get('confirmed_pairs'))->toHaveCount(2);
});

it('refuses a yellow acknowledgement in the edit-party modal when a new match appeared after it was shown', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $party = MatterParty::factory()->for($matter)->create(['role' => PartyRole::Defendant, 'name' => 'Tên Gõ Nhầm']);
    $party->identify(null, '0977333444')->save();
    csrNamesake('Đỗ Văn Sửa');

    $this->actingAs($lawyer, 'web');

    $data = ['role' => PartyRole::Defendant->value, 'is_our_client' => false, 'name' => 'Đỗ Văn Sửa'];

    $livewire = $this->livewire(PartiesRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
    $livewire->callTableAction('editParty', $party, data: $data)->assertHasTableActionErrors(['acknowledge_conflict']);

    csrNamesake('Đỗ Văn Sửa');

    $livewire->setTableActionData([...$data, 'acknowledge_conflict' => true])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['acknowledge_conflict' => [__('exceptions.conflict_result_changed')]]);

    expect($party->fresh()->name)->toBe('Tên Gõ Nhầm');

    $livewire->setTableActionData([...$data, 'acknowledge_conflict' => true])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($party->fresh()->name)->toBe('Đỗ Văn Sửa');
});

it('refuses a yellow acknowledgement on the intake conversion page when a new match appeared after the table was shown', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = MatterType::factory()->withStages()->create(['is_active' => true]);
    $intake = app(RecordIntake::class)->handle($lawyer, [
        'contact_name' => 'Trần Thị Chuyển',
        'contact_phone' => '0832 270 111',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
        'matter_type_id' => $type->id,
        'quoted_amount' => '15.000.000',
    ], [['name' => 'Công Ty Đối Diện', 'role' => PartyRole::Defendant, 'phone' => '0977 000 333', 'id_number' => '079088000333']])->intake;
    app(RecordPrivacyNotice::class)->handle($lawyer, $intake, true);
    app(UpdateIntakeSummary::class)->handle($lawyer, $intake->fresh(), 'Câu chuyện đủ để chuyển thành vụ việc.');

    // Sau khi tiếp nhận (Xanh), một vụ khác có người trùng tên người liên hệ.
    csrNamesake('Trần Thị Chuyển');

    $this->actingAs($lawyer, 'web');

    $page = $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()]);
    $page->call('convert')->assertHasErrors(['data.acknowledge_conflict']);

    csrNamesake('Trần Thị Chuyển');

    $page->fillForm(['acknowledge_conflict' => true])
        ->call('convert')
        ->assertHasErrors(['data.acknowledge_conflict' => [__('exceptions.conflict_result_changed')]]);

    expect($intake->fresh()->matter_id)->toBeNull();

    $page->fillForm(['acknowledge_conflict' => true])->call('convert')->assertHasNoErrors();

    expect($intake->fresh()->matter_id)->not->toBeNull();
});

it('refuses the intake yellow acknowledgement when a new match appeared after the page showed the table', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $first = csrNamesake('Phạm Văn Vàng');

    $intake = app(RecordIntake::class)->handle($assistant, [
        'contact_name' => 'Người Gọi Mẫu',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], [['name' => 'Phạm Văn Vàng', 'role' => PartyRole::Defendant]])->intake;
    app(RecordPrivacyNotice::class)->handle($assistant, $intake, true);

    expect($intake->fresh()->conflict_level)->toBe(ConflictLevel::Yellow);

    $this->actingAs($assistant, 'web');

    $page = $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertSee($first->code);

    $second = csrNamesake('Phạm Văn Vàng');

    $page->callAction(TestAction::make('acknowledge')->schemaComponent('checkActions'));

    Notification::assertNotified(
        Notification::make()->title(__('actions.failed_title'))->body(__('exceptions.conflict_result_changed'))->danger()->persistent(),
    );

    // Trang hiện lại bảng MỚI ngay trong lượt bị từ chối.
    $page->assertSee($second->code);

    expect($intake->fresh()->conflict_acknowledged_by)->toBeNull();

    $page->callAction(TestAction::make('acknowledge')->schemaComponent('checkActions'))->assertHasNoErrors();

    expect($intake->fresh()->conflict_acknowledged_by)->toBe($assistant->id);
});

it('refuses an acknowledgement at the Action when the caller says which table it showed and the table changed', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = csrClientVisibleTo($lawyer);
    $type = MatterType::factory()->withStages()->create();
    csrNamesake('Võ Thị Hành');

    $attributes = ['client_id' => $client->id, 'client_role' => PartyRole::Plaintiff, 'matter_type_id' => $type->id, 'title' => 'Vụ gọi thẳng Action', 'lead_lawyer_id' => $lawyer->id];
    $parties = [['role' => PartyRole::Defendant, 'name' => 'Võ Thị Hành', 'phone' => '0977444555']];

    try {
        app(OpenMatter::class)->handle($lawyer, $attributes, $parties);
        $this->fail('Vàng phải đòi xác nhận.');
    } catch (ConflictAcknowledgementRequired $exception) {
        $shown = $exception->result->displayFingerprint();
    }

    csrNamesake('Võ Thị Hành');

    expect(fn () => app(OpenMatter::class)->handle($lawyer, $attributes, $parties, acknowledged: ConflictLevel::Yellow, shownFingerprint: $shown))
        ->toThrow(ConflictAcknowledgementRequired::class, __('exceptions.conflict_result_changed'));

    expect(Matter::query()->where('title', 'Vụ gọi thẳng Action')->exists())->toBeFalse();

    // Cùng luật ở AddMatterParty.
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    try {
        app(AddMatterParty::class)->handle($matter, $lawyer, $parties[0]);
        $this->fail('Vàng phải đòi xác nhận.');
    } catch (ConflictAcknowledgementRequired $exception) {
        $shown = $exception->result->displayFingerprint();
    }

    csrNamesake('Võ Thị Hành');

    expect(fn () => app(AddMatterParty::class)->handle($matter, $lawyer, $parties[0], acknowledged: ConflictLevel::Yellow, shownFingerprint: $shown))
        ->toThrow(ConflictAcknowledgementRequired::class, __('exceptions.conflict_result_changed'));

    expect($matter->parties()->where('name', 'Võ Thị Hành')->exists())->toBeFalse();
});
