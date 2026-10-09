<?php

use App\Actions\Deadline\AddMatterDeadline;
use App\Actions\Deadline\UpdateDeadline;
use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\DeadlineSeverity;
use App\Enums\InstalmentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Exceptions\MatterClosedForDeadlines;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\ClientRequest;
use App\Models\Contract;
use App\Models\Deadline;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\MatterOpenWork;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Livewire\Features\SupportTesting\Testable;

/*
 * Làn fm, mục A1 (kiểm tra nghiệp vụ 2026-10-09): đóng vụ phải cho người bấm thấy việc còn dở và bắt
 * tích xác nhận; vụ đã đóng không nhận thêm (hay sửa) mốc thời hạn mà hệ thống sẽ không bao giờ nhắc.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function closeFormData(array $overrides = []): array
{
    return [
        'to_stage' => 'closed',
        'occurred_at' => today()->toDateString(),
        'internal_note' => 'Toà đã xử xong, đóng hồ sơ.',
        'public_content' => null,
        'next_step' => null,
        'client_action' => null,
        'expected_next_update_at' => null,
        'publish' => false,
        ...$overrides,
    ];
}

it('lists the open work and the reopen rule when the lawyer picks a closing stage', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('first_instance')->create(['lead_lawyer_id' => $lawyer->id]);
    Deadline::factory()->for($matter)->create(['name' => 'Hạn kháng cáo bản án sơ thẩm', 'responsible_user_id' => $lawyer->id, 'due_date' => '2026-12-20']);
    Deadline::factory()->for($matter)->create(['name' => 'Hạn đã xong từ trước', 'responsible_user_id' => $lawyer->id, 'is_completed' => true, 'completed_at' => now()]);
    ClientRequest::factory()->create(['matter_id' => $matter->id, 'status' => ClientRequestStatus::New]);
    ClientRequest::factory()->create(['matter_id' => $matter->id, 'status' => ClientRequestStatus::Closed]);
    MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create();
    MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Accepted)->create();
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 5_000_000]);
    Instalment::factory()->for($contract)->create(['amount' => 5_000_000, 'status' => InstalmentStatus::Pending]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('transitionStage');

    // Chưa chọn giai đoạn kết thúc: chưa có khối cảnh báo.
    expect(closingTexts($component))->toBeNull();

    $component->setTableActionData(['to_stage' => 'appeal']);
    expect(closingTexts($component))->toBeNull();

    $component->setTableActionData(['to_stage' => 'closed']);
    $texts = implode("\n", closingTexts($component));

    expect($texts)->toContain('Hạn kháng cáo bản án sơ thẩm (hạn 20/12/2026)')
        ->not->toContain('Hạn đã xong từ trước')
        ->toContain(__('lifecycle.open_work.client_requests', ['count' => 1]))
        ->toContain(__('lifecycle.open_work.checklist_pending', ['count' => 1]))
        ->toContain('5.000.000')
        ->toContain(__('lifecycle.close.consequences'));
});

it('does not show the closing block when an admin moves a closed matter between two closing stages', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('closed')->create(['closed_at' => now()->subDay()]);

    $this->actingAs($admin, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('transitionStage')
        ->setTableActionData(['to_stage' => 'closed']);

    expect(closingTexts($component))->toBeNull();
});

/**
 * Nội dung chữ của khối "Trước khi kết thúc vụ việc" trong form đang mount, hoặc `null` khi khối ẩn.
 * Đọc từ schema thật (cùng cách `TransitionStageActionTest` đọc các câu cảnh báo), vì modal không
 * nằm trong HTML mà Livewire test trả về.
 *
 * @return list<string>|null
 */
function closingTexts(Testable $component): ?array
{
    $formName = $component->instance()->getMountedActionSchemaName();
    /** @var Schema $schema */
    $schema = $component->instance()->{$formName};

    $section = $schema->getComponent('closingSafeguards', withHidden: true);

    expect($section)->toBeInstanceOf(Section::class)
        ->and($section->getHeading())->toBe(__('lifecycle.close.heading'));

    if (! $section->isVisible()) {
        return null;
    }

    return collect($section->getChildSchema()->getFlatComponents(withHidden: false))
        ->filter(fn ($c) => $c instanceof Text)
        ->map(fn ($c) => (string) $c->getContent())
        ->values()
        ->all();
}

it('hides the outstanding balance from someone without billing.view', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->atStage('first_instance')->create(['lead_lawyer_id' => $lawyer->id]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 7_000_000]);
    Instalment::factory()->for($contract)->create(['amount' => 7_000_000, 'status' => InstalmentStatus::Pending]);

    $lines = MatterOpenWork::closingLines($matter, $assistant);

    expect($assistant->can('billing.view'))->toBeFalse()
        ->and(implode("\n", $lines))->not->toContain('7.000.000')
        ->and(implode("\n", MatterOpenWork::closingLines($matter, $lawyer)))->toContain('7.000.000');
});

it('refuses to close the matter until the confirmation box is ticked, then closes it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('first_instance')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('transitionStage', data: closeFormData())
        ->assertHasTableActionErrors(['confirm_close']);

    expect($matter->fresh()->isClosed())->toBeFalse()
        ->and($matter->fresh()->stage)->toBe('first_instance');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('transitionStage', data: closeFormData(['confirm_close' => true]))
        ->assertHasNoTableActionErrors();

    expect($matter->fresh()->isClosed())->toBeTrue();
});

it('does not ask for the closing confirmation on a non-closing stage', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('first_instance')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('transitionStage', data: closeFormData(['to_stage' => 'appeal']))
        ->assertHasNoTableActionErrors();

    expect($matter->fresh()->stage)->toBe('appeal');
});

it('hides the add and edit deadline buttons on a closed matter and says why', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('closed')->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => now()->subDay()]);
    $deadline = Deadline::factory()->for($matter)->create(['responsible_user_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(DeadlinesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->assertTableActionHidden('create')
        ->assertTableActionHidden('edit', $deadline)
        // Đánh dấu xong (dọn việc) vẫn làm được.
        ->assertTableActionVisible('complete', $deadline)
        ->assertSee(__('lifecycle.deadlines.closed_notice'));
});

it('shows the add deadline button on an open matter with no closed notice', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(DeadlinesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->assertTableActionVisible('create')
        ->assertDontSee(__('lifecycle.deadlines.closed_notice'));
});

it('refuses to add a deadline to a closed matter in the action itself', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('closed')->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => now()->subDay()]);

    expect(fn () => app(AddMatterDeadline::class)->handle($matter, $lawyer, 'Hạn yêu cầu thi hành án', today()->addDays(30)->toDateString()))
        ->toThrow(MatterClosedForDeadlines::class, __('lifecycle.deadlines.closed_refused'));

    expect(Deadline::query()->where('matter_id', $matter->id)->exists())->toBeFalse();
});

it('refuses to edit a deadline of a closed matter in the action itself', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('closed')->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => now()->subDay()]);
    $deadline = Deadline::factory()->for($matter)->create(['responsible_user_id' => $lawyer->id, 'name' => 'Hạn cũ']);

    expect(fn () => app(UpdateDeadline::class)->handle($deadline, $lawyer, 'Hạn mới', today()->addDays(30)->toDateString(), DeadlineSeverity::Normal))
        ->toThrow(MatterClosedForDeadlines::class);

    expect($deadline->fresh()->name)->toBe('Hạn cũ');
});
