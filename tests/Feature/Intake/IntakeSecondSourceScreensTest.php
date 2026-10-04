<?php

use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\PartiesRelationManager;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * M10 Task 2, bẫy 3 — nguồn dò thứ hai của `RunConflictCheck` đi qua MÀN HÌNH thật (Livewire), không
 * gọi thẳng Action: form mở vụ (`CreateMatter` → `OpenMatter`) và tab "Các bên"
 * (`PartiesRelationManager` → `AddMatterParty`) hiện khớp từ một lần tiếp nhận chưa chuyển đổi bằng
 * mã `TN-…` và nhãn "Đã liên hệ văn phòng ngày …" (đặt ở cột "loại vụ việc" của `ConflictMatch`),
 * đòi xác nhận như mọi Vàng, và KHÔNG lộ câu chuyện người liên hệ đã kể. Các test Action và mọi
 * mutation probe của nguồn thứ hai nằm ở `IntakeSecondSourceTest.php`.
 *
 * Hàm toàn cục mang tiền tố riêng `iss…` (ParaTest chạy mỗi tệp trong một tiến trình riêng — xem
 * docblock `ConflictCheckFlowTest.php`).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/** Một lần tiếp nhận chưa chuyển đổi, đã qua cổng và đã kể chuyện — câu chuyện là thứ không được lộ. */
function issToldIntake(): IntakeRequest
{
    $assistant = User::factory()->withRole(Role::Assistant)->create();

    $intake = app(RecordIntake::class)->handle($assistant, [
        'contact_name' => 'Nguyễn Văn An',
        'contact_phone' => '0901111222',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ])->intake;

    app(RecordPrivacyNotice::class)->handle($assistant, $intake, true);
    app(UpdateIntakeSummary::class)->handle($assistant, $intake, 'Bí mật: ông An kể về vụ lừa đảo của người thân.');

    return $intake->fresh();
}

function issMatterType(): MatterType
{
    $type = MatterType::factory()->withStages()->create();
    ChecklistTemplate::factory()->withItems(1)->for($type, 'matterType')->create();

    return $type;
}

/** @return Collection<int, Notification> */
function issNotifications(): Collection
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications;
}

it('shows the create-matter screen a match from an unconverted intake, labelled with its code and date, without the story', function () {
    $intake = issToldIntake();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $client = Client::factory()->create();
    $type = issMatterType();

    $this->actingAs($manager, 'web');

    $component = $this->livewire(CreateMatter::class)
        ->fillForm([
            'client_id' => $client->id,
            'client_role' => PartyRole::Plaintiff->value,
            'matter_type_id' => $type->id,
            'title' => 'Vụ có bên từng liên hệ văn phòng',
            'lead_lawyer_id' => $manager->id,
            'summary_for_client' => 'Tóm tắt gửi khách hàng.',
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Người tên khác hẳn',
                'phone' => '+84 901 111 222',
            ]],
        ]);

    $component->call('create')
        ->assertHasFormErrors(['acknowledge_conflict'])
        ->assertSee(__('matters.conflict.heading_attention'))
        ->assertSee($intake->code)
        ->assertSee(__('conflicts.intake_contacted', ['date' => $intake->received_at->format('d/m/Y')]))
        ->assertDontSee('lừa đảo')
        ->assertDontSee('Bí mật');

    expect($component->instance()->conflictResult['level'])->toBe('yellow')
        ->and(Matter::query()->where('title', 'Vụ có bên từng liên hệ văn phòng')->exists())->toBeFalse();

    $component->fillForm(['acknowledge_conflict' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Matter::query()->where('title', 'Vụ có bên từng liên hệ văn phòng')->exists())->toBeTrue();
});

it('shows the parties tab a match from an unconverted intake in its notice, without the story, then saves once acknowledged', function () {
    $intake = issToldIntake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $livewire = $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);

    $livewire->callTableAction('create', data: [
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => 'Người tên khác hẳn',
        'phone' => '0901 111 222',
    ])->assertHasTableActionErrors(['acknowledge_conflict']);

    $notice = issNotifications()->first(fn (Notification $n): bool => $n->getTitle() === __('matters.parties.conflict_check_title_attention'));

    expect($notice)->not->toBeNull()
        ->and($notice->getBody())->toContain($intake->code)
        ->and($notice->getBody())->toContain(e(__('conflicts.intake_contacted', ['date' => $intake->received_at->format('d/m/Y')])))
        ->and($notice->getBody())->not->toContain('lừa đảo')
        ->and($notice->getBody())->not->toContain('Bí mật')
        ->and($matter->parties()->where('name', 'Người tên khác hẳn')->exists())->toBeFalse();

    $livewire->setTableActionData([
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => 'Người tên khác hẳn',
        'phone' => '0901 111 222',
        'acknowledge_conflict' => true,
    ])->callMountedTableAction()->assertHasNoTableActionErrors();

    expect($matter->parties()->where('name', 'Người tên khác hẳn')->exists())->toBeTrue();
});

it('keeps the parties tab green when the only earlier contact with that phone was converted into a matter', function () {
    $intake = issToldIntake();
    $intake->forceFill(['status' => IntakeStatus::Won, 'matter_id' => Matter::factory()->create()->id])->save();

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => 'Người tên khác hẳn',
        'phone' => '0901 111 222',
    ])->assertHasNoTableActionErrors();

    expect($matter->parties()->where('name', 'Người tên khác hẳn')->exists())->toBeTrue();
});
