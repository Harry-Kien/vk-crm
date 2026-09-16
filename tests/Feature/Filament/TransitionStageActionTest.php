<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Actions\TransitionStageAction;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * SPEC §7.3: "chỉ hiện các giai đoạn hợp lệ theo allowed_next" — KHÔNG bao gồm giai đoạn hiện
 * tại (đó là việc của nút "Thêm cập nhật", SPEC §6.3). Tách static trên chính Action để test
 * không cần dựng Livewire — xem docblock TransitionStageAction.
 */
it('lists only the stages allowed from the current stage in the picker', function () {
    $matter = Matter::factory()->atStage('intake')->create();

    expect(TransitionStageAction::stageOptions($matter))->toBe([
        'collecting_documents' => 'Thu thập hồ sơ',
        'on_hold' => 'Tạm dừng',
    ]);
});

/**
 * Trợ lý (Assistant) có matter.view + matter.update nhưng không có matter.transitionStage
 * (SPEC §5) nên không mở được modal của CẢ hai nút — cùng một điều kiện ->visible(), xem
 * docblock BuildsStageUpdateSchema.
 */
it('hides the transition-stage and add-update actions from an assistant', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])
        ->assertTableActionHidden('transitionStage')
        ->assertTableActionHidden('addUpdate');
});

/**
 * SPEC §7.3 + §6.2: gửi form "Chuyển giai đoạn" phải gọi đúng TransitionMatterStage và tạo một
 * StageLog thật, đổi matters.stage.
 */
it('submits the transition-stage form, calls the action, and creates a StageLog', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('transitionStage', data: [
        'to_stage' => 'collecting_documents',
        'occurred_at' => today()->toDateString(),
        'internal_note' => 'Đã gọi điện xác nhận với khách.',
        'public_content' => null,
        'next_step' => 'Chờ khách gửi thêm giấy tờ.',
        'client_action' => 'Vui lòng gửi CMND/CCCD bản sao.',
        'expected_next_update_at' => today()->addDays(10)->toDateString(),
        'publish' => false,
    ]);

    expect($matter->refresh()->stage)->toBe('collecting_documents');

    $log = StageLog::query()->where('matter_id', $matter->id)->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->from_stage)->toBe('intake')
        ->and($log->to_stage)->toBe('collecting_documents')
        ->and($log->internal_note)->toBe('Đã gọi điện xác nhận với khách.')
        ->and($log->created_by)->toBe($lawyer->id);
});

/**
 * SPEC §6.3: "Thêm cập nhật" là CÙNG một Action, to_stage = giai đoạn hiện tại — phải tạo một
 * StageLog mà from_stage và to_stage đều bằng giai đoạn hiện tại, và KHÔNG đổi matters.stage.
 */
it('submits the add-update form without changing the matter stage', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('addUpdate', data: [
        'occurred_at' => today()->toDateString(),
        'internal_note' => null,
        'public_content' => null,
        'next_step' => 'Tuần này chưa có văn bản mới từ toà, đây là điều bình thường ở giai đoạn này.',
        'client_action' => null,
        'expected_next_update_at' => today()->addDays(7)->toDateString(),
        'publish' => false,
    ]);

    expect($matter->refresh()->stage)->toBe('intake');

    $log = StageLog::query()->where('matter_id', $matter->id)->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->from_stage)->toBe('intake')
        ->and($log->to_stage)->toBe('intake');
});

/**
 * SPEC §7.3: công tắc "Công bố cho khách ngay" mặc định BẬT khi vụ việc đã bật portal, TẮT khi
 * chưa — test bắt buộc của brief.
 */
it('defaults the publish switch off when the matter is not published to the portal', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->unpublished()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])
        ->mountTableAction('transitionStage')
        ->assertTableActionDataSet(['publish' => false]);
});

it('defaults the publish switch on when the matter is already published to the portal', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])
        ->mountTableAction('transitionStage')
        ->assertTableActionDataSet(['publish' => true]);
});
