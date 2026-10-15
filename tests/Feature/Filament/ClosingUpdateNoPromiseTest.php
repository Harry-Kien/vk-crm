<?php

use App\Actions\TransitionMatterStage;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Filament\Portal\Pages\MatterProgress;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Schema;

/*
 * Làn fm, mục B3 (kiểm tra nghiệp vụ 2026-10-09): cập nhật đóng vụ không hứa với khách "Dự kiến có tin
 * tiếp theo trước ngày …" — vụ đã kết thúc thì không còn tin tiếp theo nào để hẹn.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('records no expected next update when a line moves the matter into a closing stage', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('first_instance')->create(['lead_lawyer_id' => $lawyer->id]);

    $log = app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'closed',
        occurredAt: today(),
        internalNote: null,
        publicContent: 'Vụ việc đã hoàn tất, văn phòng gửi lại toàn bộ hồ sơ cho anh/chị.',
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: today()->addDays(10)->toDateString(),
        publish: true,
    );

    expect($log->expected_next_update_at)->toBeNull();
});

it('still computes the expected next update for a non-closing stage', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('first_instance')->create(['lead_lawyer_id' => $lawyer->id]);

    $log = app(TransitionMatterStage::class)->handle($matter, $lawyer, 'appeal', today(), null, null, null, null, null, false);

    expect($log->expected_next_update_at?->toDateString())->toBe(now()->addDays(30)->toDateString());
});

it('hides the expected-date field on the transition form once a closing stage is picked', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('first_instance')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('transitionStage')
        ->setTableActionData(['to_stage' => 'appeal']);

    expect(fmExpectedDateField($component)->isVisible())->toBeTrue();

    $component->setTableActionData(['to_stage' => 'closed']);

    expect(fmExpectedDateField($component)->isVisible())->toBeFalse();
});

it('hides the expected-date field on "add update" for a closed matter', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('closed')->create(['closed_at' => now()->subDay()]);

    $this->actingAs($admin, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('addUpdate');

    expect(fmExpectedDateField($component)->isVisible())->toBeFalse();
});

it('prints no "expected" line on the portal of a closed matter, even for an old line that carried one', function () {
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->atStage('closed')->create(['is_published_to_portal' => true, 'closed_at' => now()->subDay()]);
    StageLog::factory()->for($matter)->published()->create([
        'public_content' => 'Vụ việc đã hoàn tất, văn phòng gửi lại toàn bộ hồ sơ.',
        'expected_next_update_at' => now()->addDays(14)->toDateString(),
    ]);
    $open = Matter::factory()->for($client)->create(['is_published_to_portal' => true]);
    StageLog::factory()->for($open)->published()->create([
        'public_content' => 'Văn phòng đang soạn đơn cho anh/chị.',
        'expected_next_update_at' => now()->addDays(14)->toDateString(),
    ]);

    $date = now()->addDays(14)->format('d/m/Y');

    $this->actingAs($clientUser, 'client')
        ->get(MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->assertSee('Vụ việc đã hoàn tất, văn phòng gửi lại toàn bộ hồ sơ.', escape: false)
        ->assertDontSee(__('portal_progress.timeline.expected', ['date' => $date]), escape: false);

    $this->actingAs($clientUser, 'client')
        ->get(MatterProgress::getUrl(['record' => $open->getKey()], panel: 'portal'))
        ->assertOk()
        ->assertSee(__('portal_progress.timeline.expected', ['date' => $date]), escape: false);
});

function fmExpectedDateField($component): DatePicker
{
    $formName = $component->instance()->getMountedActionSchemaName();
    /** @var Schema $schema */
    $schema = $component->instance()->{$formName};

    return collect($schema->getFlatComponents(withHidden: true))
        ->first(fn ($c) => $c instanceof DatePicker && $c->getName() === 'expected_next_update_at');
}
