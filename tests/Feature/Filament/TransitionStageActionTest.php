<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Actions\TransitionStageAction;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Livewire\Features\SupportTesting\Testable;

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

/**
 * Fix round 1, finding 1: TransitionMatterStage::handle() tự ném ValidationException với khoá thô
 * khi publish=true và public_content dưới 30 ký tự, nhưng khoá đó không khớp state path đầy đủ
 * của action đang mount nên không bao giờ hiện lên đúng ô — modal chỉ lặng lẽ rollback. Ruling A:
 * public_content phải required()+minLength(30) NGAY TRONG SCHEMA khi publish bật, để Filament tự
 * validate và gắn lỗi đúng trường trước khi Action từng được gọi.
 */
it('reports a validation error on public_content when publishing with fewer than 30 characters, and creates no StageLog', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create([
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => true,
    ]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('transitionStage', data: [
        'to_stage' => 'collecting_documents',
        'occurred_at' => today()->toDateString(),
        'public_content' => str_repeat('a', 29),
        'publish' => true,
    ])->assertHasTableActionErrors(['public_content']);

    expect($matter->refresh()->stage)->toBe('intake')
        ->and(StageLog::query()->where('matter_id', $matter->id)->exists())->toBeFalse();
});

/**
 * Fix round 1, finding 2 (ruling B): không để luật sư tự bật công tắc "Công bố cho khách ngay"
 * trên một vụ chưa bật portal rồi mới nhận một DomainException không ai báo trước — khoá hẳn công
 * tắc khi `is_published_to_portal = false`.
 */
it('disables the publish toggle when the matter is not published to the portal', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->unpublished()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])
        ->mountTableAction('transitionStage')
        ->assertFormFieldDisabled('publish');
});

it('enables the publish toggle when the matter is already published to the portal', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])
        ->mountTableAction('transitionStage')
        ->assertFormFieldEnabled('publish');
});

/**
 * Fix round 1, finding 3 (ruling C): đổi giai đoạn đích không được ghi đè nội dung luật sư đã tự
 * gõ. Dùng `->set()` trực tiếp trên state path thật của action đang mount
 * (`mountedActions.{index}.data.*`) — KHÔNG dùng callTableAction()/setTableActionData(), vì hai
 * hàm đó tắt afterStateUpdated trong lúc điền dữ liệu hàng loạt (xem
 * Filament\Forms\Testing\TestsForms::fillForm — gọi disableSchemaStateUpdateHooksForTesting()),
 * nên sẽ không bao giờ kích hoạt được đúng logic cần kiểm tra ở đây.
 */
it('keeps custom public_content the lawyer already typed when the target stage changes again', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);

    $component->mountTableAction('transitionStage')
        ->set('mountedActions.0.data.to_stage', 'collecting_documents');

    $customContent = 'Nội dung do luật sư tự soạn riêng cho khách, không phải mẫu gợi ý.';

    $component->set('mountedActions.0.data.public_content', $customContent);

    // Sửa lại lựa chọn giai đoạn: nội dung tự soạn phải được GIỮ NGUYÊN, không bị mẫu của giai
    // đoạn mới ghi đè.
    $component->set('mountedActions.0.data.to_stage', 'on_hold');

    $component->assertTableActionDataSet(['public_content' => $customContent]);
});

/**
 * Đối chứng của test trên: nếu luật sư CHƯA sửa gì (nội dung vẫn còn nguyên mẫu của giai đoạn
 * trước), đổi giai đoạn vẫn phải cập nhật gợi ý theo giai đoạn mới như thiết kế ban đầu.
 */
it('still refreshes the public_content template when the target stage changes and nothing was customised', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);

    $component->mountTableAction('transitionStage')
        ->set('mountedActions.0.data.to_stage', 'collecting_documents')
        ->set('mountedActions.0.data.to_stage', 'on_hold');

    $component->assertTableActionDataSet([
        'public_content' => $matter->matterType->stage('on_hold')->client_description,
    ]);
});

/*
|--------------------------------------------------------------------------
| Task 7 (R12, phát hiện `stage/stage-06` — nửa "luật sư không biết khách không được báo"):
| BuildsStageUpdateSchema::noActivatedAccountWarning() dùng CHUNG điều kiện với
| NotifyClientOfStageUpdate::hasEligibleRecipient(), nên cảnh báo trên form không bao giờ lệch
| với chính Action gửi thư thật.
|--------------------------------------------------------------------------
|
| `assertSee()` không đọc được nội dung của modal action trên RelationManager: `.html()` của một
| test Livewire chỉ render lại CHÍNH component đó (bảng), không phải toàn trang panel nơi modal
| action thật sự được vẽ (đã tự đo: một trường luôn hiện như `to_stage`/`occurred_at` cũng vắng
| mặt trong cùng phép `.html()`). Vì vậy đọc thẳng CÂY SCHEMA đang mount — cùng kỹ thuật
| `assertFormFieldDisabled()`/`assertFormFieldEnabled()` của chính Filament dùng (đọc
| `Schema::getFlatFields()` thay vì đọc HTML) — chỉ khác Text không phải Field nên phải dùng
| `getFlatComponents()` (bao gồm mọi component, không riêng field) rồi tự lọc theo NỘI DUNG, vì
| Text không có tên state path để tra theo khoá như một field.
*/

/** Tìm ĐÚNG component Text của cảnh báo này trong cây schema đang mount — không lẫn với Text ẩn
 *  bên trong helperText() của các field khác (đã đo: cùng là instance Text). */
function noActivatedAccountWarningComponent(Testable $component): Text
{
    $formName = $component->instance()->getMountedActionSchemaName();
    /** @var Schema $schema */
    $schema = $component->instance()->{$formName};

    $warning = __('matters.transition_form.no_activated_account_warning');

    $matches = array_values(array_filter(
        $schema->getFlatComponents(withHidden: true),
        fn ($c) => $c instanceof Text && $c->getContent() === $warning,
    ));

    expect($matches)->toHaveCount(1, 'Không tìm thấy đúng một component cảnh báo trong schema đang mount.');

    return $matches[0];
}

it('warns on the transition-stage form when the matter is published but the client has no eligible portal account', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create([
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => true,
    ]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('transitionStage');

    expect(noActivatedAccountWarningComponent($component)->isVisible())->toBeTrue();
});

/** Vế dương: khách CÓ một tài khoản cổng đủ điều kiện (đang hoạt động, đã kích hoạt) thì không cảnh báo. */
it('hides the warning when the client has an active, activated portal account', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $matter = Matter::factory()->atStage('intake')->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => true,
    ]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('transitionStage');

    expect(noActivatedAccountWarningComponent($component)->isVisible())->toBeFalse();
});

/** Vế dương thứ hai: vụ CHƯA bật cổng — không ai định nhận thư ngay nên cảnh báo cũng không cần hiện. */
it('hides the warning when the matter is not published to the portal at all', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->unpublished()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('transitionStage');

    expect(noActivatedAccountWarningComponent($component)->isVisible())->toBeFalse();
});

/** Cùng cảnh báo dùng chung ở nút "Thêm cập nhật" — BuildsStageUpdateSchema là trait dùng chung. */
it('shows the same warning on the add-update form', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create([
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => true,
    ]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('addUpdate');

    expect(noActivatedAccountWarningComponent($component)->isVisible())->toBeTrue();
});
