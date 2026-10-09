<?php

use App\Actions\TransitionMatterStage;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Exceptions\MatterStageChanged;
use App\Filament\Admin\Resources\Matters\Actions\TransitionStageAction;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Mail\Client\StageUpdate;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;

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
 * `stage/stage-04` (M6.5 Task 10): một luật sư truyền vào `stageOptions()` không đổi gì — chỉ
 * `Role::Admin` mới thấy thêm. Cặp dương của test admin ngay dưới.
 */
it('still lists only allowed_next when the actor passed in is not an admin', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create();

    expect(TransitionStageAction::stageOptions($matter, $lawyer))->toBe([
        'collecting_documents' => 'Thu thập hồ sơ',
        'on_hold' => 'Tạm dừng',
    ]);
});

/**
 * `stage/stage-04` (spec_gap, xử bằng quyền bỏ qua của admin trên giao diện): quyền bỏ qua
 * `allowed_next` của SPEC §6.2 bước 1 (`TransitionMatterStage::handle()`, đã có sẵn) chỉ có tác
 * dụng thật nếu giao diện cũng cho CHỌN — nếu không, admin không có đường nào sửa một vụ kẹt ở
 * giai đoạn cuối ('closed' có `allowed_next = []`) ngoài sửa thẳng CSDL. Admin thấy MỌI giai đoạn
 * khác giai đoạn hiện tại của loại vụ việc; giai đoạn NGOÀI `allowed_next` mang nhãn cảnh báo để
 * admin biết mình đang đi ngoài luồng thường, không bấm nhầm.
 */
it('lets an admin see every configured stage, with a warning suffix on the ones outside allowed_next', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('intake')->create();

    $options = TransitionStageAction::stageOptions($matter, $admin);

    $suffix = ' '.__('matters.transition_form.outside_allowed_next_suffix');

    expect($options)->toHaveKey('collecting_documents', 'Thu thập hồ sơ')
        ->and($options)->toHaveKey('on_hold', 'Tạm dừng')
        ->and($options)->toHaveKey('drafting', 'Soạn đơn'.$suffix)
        ->and($options)->toHaveKey('closed', 'Kết thúc'.$suffix)
        // Giai đoạn HIỆN TẠI ('intake') không phải một lựa chọn hợp lệ, kể cả cho admin — đó là
        // việc của "Thêm cập nhật" (SPEC §6.3), một nút RIÊNG.
        ->and($options)->not->toHaveKey('intake');
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
 * `stage/stage-04` (M6.5 Task 10): admin chuyển một vụ đã "Kết thúc" (allowed_next rỗng) về một
 * giai đoạn trước đó qua ĐÚNG form thật (không gọi thẳng Action) — phải đi qua được, và audit của
 * `TransitionMatterStage` phải ghi `bypassed_allowed_next = true` (bước 1, đã có sẵn ở Action; chỉ
 * giao diện là mới ở task này).
 */
it('lets an admin transition out of a terminal stage through the form, and records bypassed_allowed_next', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('closed')->create();

    $this->actingAs($admin, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('transitionStage', data: [
        'to_stage' => 'mediation',
        'occurred_at' => today()->toDateString(),
        'internal_note' => 'Kết thúc nhầm, mở lại để hoà giải tiếp.',
        'public_content' => null,
        'publish' => false,
    ])->assertHasNoTableActionErrors();

    expect($matter->refresh()->stage)->toBe('mediation');

    $activity = Activity::query()
        ->where('event', 'matter_stage_transitioned')
        ->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('bypassed_allowed_next'))->toBeTrue();
});

/**
 * Cặp âm của test trên: một LUẬT SƯ (không phải admin) không thấy 'mediation' trong danh sách của
 * cùng vụ việc ở 'closed' — gửi form vẫn báo lỗi ngay trên `to_stage`, không lọt qua bằng cách gõ
 * tay giá trị vào request (Select validate 'in' theo đúng options mà `stageOptions()` trả về).
 */
it('still refuses a lawyer trying the same out-of-allowed_next stage through the form', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('closed')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('transitionStage', data: [
        'to_stage' => 'mediation',
        'occurred_at' => today()->toDateString(),
        'publish' => false,
    ])->assertHasTableActionErrors(['to_stage']);

    expect($matter->refresh()->stage)->toBe('closed');
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
 * M9 Task 6, lỗi I6 — đường MÀN HÌNH: một chuỗi ngày hỏng gửi lên form "Chuyển giai đoạn" hay
 * "Thêm cập nhật" (request Livewire tự dựng, không qua lịch chọn ngày) thành lỗi trên đúng ô, không
 * phải trang 500, và không dòng tiến độ nào được ghi.
 *
 * Với `occurred_at` đây là test HỒI QUY (red-first yếu, nói thẳng): `DatePicker` của Filament 5 tự
 * gắn luật `date` (`DateTimePicker::setUp()`), nên form đã chặn trước khi Action được gọi. Với
 * `expected_next_update_at` thì ĐỎ THẬT trước bản sửa: luật `date` vẫn chặn lần lưu, nhưng lần vẽ
 * lại modal sau đó đọc ô đó cho bản xem trước cho khách bằng `$get()` — tức
 * `DateTimeStateCast::get()` của Filament, tức `Carbon::parse()` trên chính chuỗi hỏng —
 * `ViewException`, trang 500. Cổng thật của Action (API công khai) test ở `TransitionMatterStageTest`.
 */
it('shows a field error, not a 500, when the stage forms get a malformed date', function (string $action, string $field) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction($action, data: [
        ...($action === 'transitionStage' ? ['to_stage' => 'collecting_documents'] : []),
        'occurred_at' => today()->toDateString(),
        'expected_next_update_at' => today()->addDays(5)->toDateString(),
        'publish' => false,
        $field => 'ngày 31 tháng 2',
    ])->assertHasTableActionErrors([$field]);

    expect($matter->refresh()->stage)->toBe('intake')
        ->and(StageLog::query()->where('matter_id', $matter->id)->exists())->toBeFalse();
})->with(['transitionStage', 'addUpdate'])->with(['occurred_at', 'expected_next_update_at']);

/**
 * Cùng lỗi, đường thứ hai đọc lại ô ngày lúc form còn mở: đổi giai đoạn đích khi ô "dự kiến có tin
 * tiếp theo" đang chứa một chuỗi hỏng. Chuỗi hỏng là "không có ngày", nên ô được điền lại ngày gợi
 * ý của giai đoạn mới (`default_next_update_days` của nó) thay vì làm vỡ trang.
 */
it('refills a malformed expected date with the new stage default when the target stage changes, instead of a 500', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])
        ->mountTableAction('transitionStage')
        ->setTableActionData(['expected_next_update_at' => 'ngày 31 tháng 2'])
        ->setTableActionData(['to_stage' => 'collecting_documents'])
        ->assertTableActionDataSet([
            'expected_next_update_at' => now()->addDays($matter->matterType->stage('collecting_documents')->default_next_update_days)->toDateString(),
        ]);
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

    $warning = noActivatedAccountWarningComponent($component);

    expect($warning->isVisible())->toBeTrue();

    // Fix round 1 (minor): không chỉ isVisible() — đo THẬT màu trên markup đã render. `fi-color-warning`
    // là lớp Filament thật sự phát ra cho `->color('warning')` (đã tự đo bằng cách render component
    // này qua toSchemaHtml() và đọc markup; class="fi-color fi-color-warning fi-text-color-700
    // dark:fi-text-color-400 fi-sc-text"), không phải một chuỗi suy đoán.
    expect($warning->toSchemaHtml(true))->toContain('fi-color-warning');
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

/**
 * Nội dung mọi cảnh báo "khách sẽ không được báo" ĐANG HIỆN trên form đang mount — đọc cây schema
 * như `noActivatedAccountWarningComponent()`, lọc các `Text` màu cảnh báo có biểu tượng tam giác.
 *
 * @return list<string>
 */
function visibleRecipientWarnings(Testable $component): array
{
    $formName = $component->instance()->getMountedActionSchemaName();
    /** @var Schema $schema */
    $schema = $component->instance()->{$formName};

    return array_values(array_map(
        fn (Text $text): string => (string) $text->getContent(),
        array_filter(
            $schema->getFlatComponents(),
            fn ($c) => $c instanceof Text && $c->getColor() === 'warning' && $c->isVisible(),
        ),
    ));
}

/**
 * Rà soát cuối M7, I3 (rà soát Task 11, m1): `NotifyClientOfStageUpdate::handle()` bỏ mọi người
 * nhận không còn thấy vụ trên cổng của chính họ (`MatterPolicy::view` — vụ đã kết thúc và quá
 * `client_access_until`). Cảnh báo trên form phải hỏi ĐÚNG câu đó, không chỉ "khách có tài khoản đủ
 * điều kiện không": tài khoản còn hoạt động nhờ một vụ khác, nên trước bản sửa form im lặng, luật sư
 * bấm công bố, không thư nào đi và `notified_at` trống. Đi qua form thật tới tận lúc gửi, để chứng
 * minh cảnh báo và việc gửi thư không lệch nhau: hiện cảnh báo ⇔ không thư. Vế dương: hôm nay là
 * ngày tra cứu cuối thì không cảnh báo và thư đi.
 */
it('warns on the add-update form that a closed matter whose client access expired is off the portal, and then mails nobody', function (string $accessUntil, bool $expired) {
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $matter = Matter::factory()->atStage('closed')->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => true,
        'closed_at' => '2026-07-22 10:00:00',
    ]);
    MatterArchive::factory()->create(['matter_id' => $matter->id, 'client_access_until' => $accessUntil]);
    // Vụ thứ hai của cùng khách, còn trên cổng: lý do tài khoản vẫn hoạt động.
    Matter::factory()->create(['client_id' => $client->id, 'is_published_to_portal' => true]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('addUpdate');

    expect(visibleRecipientWarnings($component))
        ->toBe($expired ? [__('archive.stage_update.not_on_portal_warning')] : []);

    $component->setTableActionData([
        'public_content' => 'Văn phòng gửi lại bản sao biên bản bàn giao hồ sơ để anh chị lưu giữ.',
        'publish' => true,
    ])->callMountedTableAction()->assertHasNoTableActionErrors();

    $log = StageLog::query()->where('matter_id', $matter->id)->latest('id')->firstOrFail();

    Mail::assertSent(StageUpdate::class, $expired ? 0 : 1);
    expect($log->is_published)->toBeTrue()
        ->and($log->notified_at === null)->toBe($expired);
})->with([
    'đã hết hạn tra cứu từ hôm nay' => ['2026-10-20', true],
    'hôm nay là ngày tra cứu cuối' => ['2026-10-21', false],
]);

/**
 * Vụ đã kết thúc, đã hết hạn tra cứu, khách có tài khoản còn hoạt động nhờ một vụ khác — dữ liệu
 * chung của hai test form "Chuyển giai đoạn" dưới đây. Loại vụ được thêm MỘT giai đoạn kết thúc thứ
 * hai (`archived`), để có đường chuyển giữa hai giai đoạn kết thúc (vụ vẫn đóng, `closed_at` không
 * đổi) bên cạnh đường mở lại vụ (`intake`, không kết thúc).
 *
 * @return array{0: User, 1: Matter}
 */
function tsaExpiredClosedMatter(bool $account = true, bool $published = true, bool $expired = true): array
{
    test()->travelTo(Carbon::parse('2026-10-21 09:00:00'));

    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();

    if ($account) {
        ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
        Matter::factory()->create(['client_id' => $client->id, 'is_published_to_portal' => true]);
    }

    $matter = Matter::factory()->atStage('closed')->create([
        'client_id' => $client->id,
        'is_published_to_portal' => $published,
        'closed_at' => '2026-07-22 10:00:00',
    ]);
    $matter->matterType->stages()->create([
        'key' => 'archived', 'label' => 'Lưu kho', 'client_label' => 'Đã lưu kho', 'client_description' => null,
        'sort_order' => 99, 'is_terminal' => true, 'allowed_next' => [], 'default_next_update_days' => 14,
    ]);
    $matter->matterType->unsetRelation('stages');
    MatterArchive::factory()->create([
        'matter_id' => $matter->id,
        'client_access_until' => $expired ? '2026-10-20' : '2026-10-21',
    ]);

    return [$admin, $matter->fresh()];
}

/**
 * Cùng hai câu ở form "Chuyển giai đoạn": đúng MỘT câu hiện khi không ai sẽ nhận thư, và đúng câu
 * — "chưa có tài khoản" khi khách không có tài khoản đủ điều kiện, "vụ không còn trên cổng" khi có
 * tài khoản mà vụ đã hết hạn tra cứu; không câu nào khi vụ chưa bật công bố hay khi có người nhận.
 *
 * Trên form NÀY, câu "vụ không còn trên cổng" còn phụ thuộc giai đoạn đích: chuyển sang một giai
 * đoạn KHÔNG kết thúc là mở lại vụ (`closed_at` về null, `SyncMatterArchive` xoá
 * `client_access_until`), vụ trở lại cổng và thư đi — test kế tiếp đi hết đường đó. Nên câu chỉ hiện
 * khi giai đoạn đích ĐÃ CHỌN là giai đoạn kết thúc (vụ vẫn đóng, vẫn quá hạn tra cứu).
 */
it('shows exactly the right recipient warning on the transition-stage form', function (bool $account, bool $published, bool $expired, ?string $toStage, ?string $warningKey) {
    [$admin, $matter] = tsaExpiredClosedMatter($account, $published, $expired);

    $this->actingAs($admin, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('transitionStage');

    if ($toStage !== null) {
        $component->setTableActionData(['to_stage' => $toStage]);
    }

    expect(visibleRecipientWarnings($component))->toBe($warningKey === null ? [] : [__($warningKey)])
        // Câu thật, không phải khoá dịch trả về nguyên văn khi thiếu chuỗi.
        ->and(__('archive.stage_update.not_on_portal_warning'))->toContain('hết hạn tra cứu');
})->with([
    'có tài khoản, đã hết hạn, sang giai đoạn kết thúc khác' => [true, true, true, 'archived', 'archive.stage_update.not_on_portal_warning'],
    'có tài khoản, đã hết hạn, sang giai đoạn mở lại vụ' => [true, true, true, 'intake', null],
    'có tài khoản, đã hết hạn, chưa chọn giai đoạn' => [true, true, true, null, null],
    'không tài khoản, đã hết hạn' => [false, true, true, 'archived', 'matters.transition_form.no_activated_account_warning'],
    'không tài khoản, còn hạn' => [false, true, false, null, 'matters.transition_form.no_activated_account_warning'],
    'có tài khoản, còn hạn' => [true, true, false, 'archived', null],
    'có tài khoản, vụ chưa bật công bố' => [true, false, true, 'archived', null],
]);

/**
 * Vế còn lại của "cảnh báo ⇔ không thư" trên form "Chuyển giai đoạn": hai đường thật tới tận lúc
 * gửi. Sang giai đoạn kết thúc khác → vụ vẫn quá hạn tra cứu → cảnh báo, không thư. Mở lại vụ (sang
 * `intake`) → không cảnh báo, và khách THẬT SỰ nhận thư, vì vụ đã trở lại cổng.
 */
it('on the transition-stage form the off-portal warning matches who is mailed: kept closed warns and mails nobody, reopened mails the client', function (string $toStage, bool $warned) {
    Mail::fake();
    [$admin, $matter] = tsaExpiredClosedMatter();

    $this->actingAs($admin, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('transitionStage')
        ->setTableActionData(['to_stage' => $toStage]);

    expect(visibleRecipientWarnings($component))
        ->toBe($warned ? [__('archive.stage_update.not_on_portal_warning')] : []);

    $component->setTableActionData([
        'to_stage' => $toStage,
        'public_content' => 'Văn phòng cập nhật lại giai đoạn hồ sơ để anh chị theo dõi.',
        'publish' => true,
    ])->callMountedTableAction()->assertHasNoTableActionErrors();

    $log = StageLog::query()->where('matter_id', $matter->id)->latest('id')->firstOrFail();

    Mail::assertSent(StageUpdate::class, $warned ? 0 : 1);
    expect($log->to_stage)->toBe($toStage)
        ->and($log->is_published)->toBeTrue()
        ->and($log->notified_at === null)->toBe($warned)
        ->and($matter->fresh()->closed_at === null)->toBe(! $warned);
})->with([
    'sang giai đoạn kết thúc khác' => ['archived', true],
    'mở lại vụ' => ['intake', false],
]);

/*
|--------------------------------------------------------------------------
| `stage/stage-02` + `stage/stage-07` (M6.5 Task 10): bản xem trước (client-preview.blade.php),
| đúng đường thật của hai Action — không gọi thẳng view()/blade.
|--------------------------------------------------------------------------
*/

/** Cùng kỹ thuật `noActivatedAccountWarningComponent()` ngay trên, chỉ khác lọc theo kiểu View. */
function clientPreviewHtml(Testable $component): string
{
    $formName = $component->instance()->getMountedActionSchemaName();
    /** @var Schema $schema */
    $schema = $component->instance()->{$formName};

    $matches = array_values(array_filter(
        $schema->getFlatComponents(withHidden: true),
        fn ($c) => $c instanceof View,
    ));

    expect($matches)->toHaveCount(1, 'Không tìm thấy đúng một component xem trước trong schema đang mount.');

    return $matches[0]->toSchemaHtml(true);
}

it('shows the target stage label in the transition-stage preview, and the new not-publishing sentence naming it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('transitionStage')
        ->set('mountedActions.0.data.to_stage', 'collecting_documents')
        ->set('mountedActions.0.data.publish', false);

    $targetLabel = $matter->matterType->stage('collecting_documents')->client_label;
    $html = clientPreviewHtml($component);

    expect($html)->toContain($targetLabel)
        ->and($html)->toContain(__('matters.transition_form.preview_not_publishing_with_stage_change', [
            'stage' => $targetLabel,
        ]));
});

/**
 * `stage/stage-07`: "Thêm cập nhật" không đổi giai đoạn — bản xem trước của nó KHÔNG được vẽ nhãn
 * giai đoạn (kể cả nhãn của giai đoạn HIỆN TẠI), và câu không-công-bố phải là câu CHUNG, không
 * phải câu nêu tên "giai đoạn mới" (không có giai đoạn mới nào ở đây).
 */
it('never shows a stage label in the add-update preview, and keeps the generic not-publishing sentence', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('addUpdate')
        ->set('mountedActions.0.data.publish', false);

    $currentLabel = $matter->matterType->stage('intake')->client_label;
    $html = clientPreviewHtml($component);

    expect($html)->not->toContain($currentLabel)
        ->and($html)->toContain(__('matters.transition_form.preview_not_publishing'))
        ->and($html)->not->toContain('giai đoạn mới:');
});

/*
|--------------------------------------------------------------------------
| Fix round 1 — C1 (Critical): MatterStageChanged thoát ra thành trang 500.
|--------------------------------------------------------------------------
|
| Đây LÀ đúng ca "bấm hai lần, hai tab" của Review Focus 4 nhìn từ phía màn hình, không phải từ
| phía Action: `BuildsStageUpdateSchema::setUpStageUpdateAction()` chỉ bắt `MatterNotPublishedToPortal`
| trong `try/catch`, nên `MatterStageChanged` — thứ `TransitionMatterStage::handle()` ném khi giai
| đoạn đã đổi dưới chân người dùng (M6.5 Task 10, `stage/stage-05`) — thoát thẳng ra thành một
| `DomainException` không ai bắt, tức một trang 500 (SPEC §10.10 cấm điều này).
|
| **Vì sao GIẢ `TransitionMatterStage`, không dựng một race thật.** Đã tự đo (probe riêng, xoá
| sau khi đo): Filament DỰNG LẠI TOÀN BỘ schema — kể cả `options()` của Select `to_stage` — bằng
| `$livewire->getOwnerRecord()` ĐỌC LẠI TỪ CSDL ở MỌI lượt gọi Livewire tiếp theo (không chỉ lúc
| mount). Nghĩa là kịch bản "mount lúc A, người khác chuyển sang B, rồi gửi lại to_stage cũ" KHÔNG
| bao giờ chạm tới `TransitionMatterStage::handle()`: Select tự validate `to_stage` theo
| `allowed_next` của giai đoạn MỚI trước, và nếu lựa chọn cũ không còn hợp lệ thì dừng lại ở lỗi
| "giai đoạn mới đã chọn không hợp lệ" ngay trong form — đúng test "still refuses a lawyer..." đã
| có ở trên. `MatterStageChanged` chỉ sinh ra được khi HAI YÊU CẦU THẬT chồng lấn nhau trong lúc cả
| hai đã qua khỏi cổng validate đó (đúng cửa sổ mà `TransitionMatterStageConcurrencyTest`, hai tiến
| trình HĐH thật trên MariaDB, đo được) — một tệp Pest chạy trong một tiến trình không dựng lại
| được cửa sổ đó. Giả `TransitionMatterStage::handle()` ném thẳng exception này là cách duy nhất
| cô lập ĐÚNG một điều: màn hình có bắt được nó và nói tiếng Việt hay không — phần "có race thật
| không" đã có bằng chứng riêng ở test kia.
*/

it('shows a Vietnamese notification instead of a 500 when the matter changed stage behind the transition-stage modal', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id]);

    $mock = Mockery::mock(TransitionMatterStage::class);
    $mock->shouldReceive('handle')->once()->andThrow(MatterStageChanged::make($matter));
    app()->instance(TransitionMatterStage::class, $mock);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('transitionStage', data: [
        'to_stage' => 'collecting_documents',
        'occurred_at' => today()->toDateString(),
        'publish' => false,
    ]);

    $component->assertNotified(__('exceptions.matter_stage_changed', ['code' => $matter->code]));

    expect(StageLog::query()->count())->toBe(0)
        // halt() giữ modal mở thay vì đóng lại — người dùng không mất nội dung đã gõ.
        ->and($component->get('mountedActions'))->not->toBeEmpty();
});

/** Cùng lỗ hổng, cùng cách sửa — "Thêm cập nhật" gọi CHUNG một TransitionMatterStage. */
it('shows a Vietnamese notification instead of a 500 when the matter changed stage behind the add-update modal', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id]);

    $mock = Mockery::mock(TransitionMatterStage::class);
    $mock->shouldReceive('handle')->once()->andThrow(MatterStageChanged::make($matter));
    app()->instance(TransitionMatterStage::class, $mock);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('addUpdate', data: [
        'occurred_at' => today()->toDateString(),
        'next_step' => 'Tuần này chưa có văn bản mới từ toà.',
        'publish' => false,
    ]);

    $component->assertNotified(__('exceptions.matter_stage_changed', ['code' => $matter->code]));

    expect(StageLog::query()->count())->toBe(0)
        ->and($component->get('mountedActions'))->not->toBeEmpty();
});

// ---------------------------------------------------------------------------------------------
// M7 Task 3 — lưu trữ khi vụ việc kết thúc, đi qua ĐÚNG form "Chuyển giai đoạn" (không gọi thẳng
// Action): sự kiện `MatterStageChanged` -> listener `SyncMatterArchiveOnStageChange` ->
// `SyncMatterArchive`. Tầng Action có test riêng ở `TransitionMatterStageTest` và
// `SyncMatterArchiveTest`; ở đây chứng minh màn hình thật nối đủ cả chuỗi.
// ---------------------------------------------------------------------------------------------

/** Gửi form "Chuyển giai đoạn" của một vụ việc tới `$toStage` (người đăng nhập hiện tại). */
function submitTransitionForm(Matter $matter, string $toStage): Testable
{
    return test()->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('transitionStage', data: [
        'to_stage' => $toStage,
        'occurred_at' => today()->toDateString(),
        'internal_note' => 'Chuyển giai đoạn qua form — kiểm tra lưu trữ M7 Task 3.',
        'public_content' => null,
        'publish' => false,
        // Làn fm A1: đóng vụ trên màn hình đòi tích xác nhận (ô ẩn thì không được gửi).
        'confirm_close' => true,
    ]);
}

it('archives the matter with dates from config when the transition-stage form closes it', function () {
    config(['vkcrm.client_access_days' => 33, 'vkcrm.retention_years' => 6]);

    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('intake')->create();
    $this->actingAs($admin, 'web');

    submitTransitionForm($matter, 'closed')->assertHasNoTableActionErrors();

    $fresh = $matter->refresh();
    $archive = MatterArchive::query()->where('matter_id', $matter->id)->first();

    expect($fresh->closed_at)->not->toBeNull()
        ->and($archive)->not->toBeNull()
        ->and($archive->archived_by)->toBe($admin->id)
        ->and($archive->client_access_until->toDateString())
        ->toBe($fresh->closed_at->copy()->addDays(33)->toDateString())
        ->and($archive->retention_until->toDateString())
        ->toBe($fresh->closed_at->copy()->addYears(6)->toDateString());
});

it('clears client_access_until when an admin reopens through the form, and updates the same row on re-close', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('intake')->create();
    $this->actingAs($admin, 'web');

    submitTransitionForm($matter, 'closed')->assertHasNoTableActionErrors();
    $archiveId = MatterArchive::query()->where('matter_id', $matter->id)->value('id');
    expect($archiveId)->not->toBeNull();

    submitTransitionForm($matter->refresh(), 'mediation')->assertHasNoTableActionErrors();

    $reopened = MatterArchive::query()->find($archiveId);
    expect($matter->refresh()->closed_at)->toBeNull()
        ->and($reopened)->not->toBeNull()
        ->and($reopened->client_access_until)->toBeNull();

    submitTransitionForm($matter->refresh(), 'closed')->assertHasNoTableActionErrors();

    expect(MatterArchive::query()->where('matter_id', $matter->id)->count())->toBe(1)
        ->and(MatterArchive::query()->find($archiveId)->client_access_until)->not->toBeNull();
});

it('creates no archive when the form only moves between two non-terminal stages', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('intake')->create();
    $this->actingAs($admin, 'web');

    submitTransitionForm($matter, 'collecting_documents')->assertHasNoTableActionErrors();

    expect(MatterArchive::query()->where('matter_id', $matter->id)->exists())->toBeFalse();
});
