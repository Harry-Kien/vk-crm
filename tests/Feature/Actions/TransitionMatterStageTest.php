<?php

use App\Actions\TransitionMatterStage;
use App\Enums\Role;
use App\Events\StageLogPublished;
use App\Exceptions\InvalidStageTransition;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Vụ việc ở giai đoạn 'intake', chỉ cho phép đi tiếp sang 'collecting' (SPEC §6.2 bước 1).
 * 'filed' KHÔNG nằm trong allowed_next của 'intake' — dùng để test transition không hợp lệ.
 */
function matterWithStages(array $attributes = []): Matter
{
    $type = MatterType::factory()->create();

    $type->stages()->create([
        'key' => 'intake', 'label' => 'Tiếp nhận', 'client_label' => 'Tiếp nhận',
        'client_description' => 'Đã tiếp nhận', 'sort_order' => 1,
        'allowed_next' => ['collecting'], 'default_next_update_days' => 14,
    ]);
    $type->stages()->create([
        'key' => 'collecting', 'label' => 'Thu thập hồ sơ', 'client_label' => 'Thu thập hồ sơ',
        'client_description' => 'Đang thu thập', 'sort_order' => 2,
        'allowed_next' => ['filed'], 'default_next_update_days' => 10,
    ]);
    $type->stages()->create([
        'key' => 'filed', 'label' => 'Đã nộp đơn', 'client_label' => 'Đã nộp đơn',
        'client_description' => 'Đã nộp đơn cho toà', 'sort_order' => 3,
        'allowed_next' => [], 'default_next_update_days' => 21,
    ]);
    $type->unsetRelation('stages');

    return Matter::factory()->for($type, 'matterType')->create($attributes);
}

/**
 * `MatterPolicy::transitionStage` đòi `view()`: một luật sư (chỉ có `matter.view`, không có
 * `matter.viewAny`) chỉ thấy vụ việc mà mình có tên trong đội ngũ — đặt làm lead_lawyer_id để
 * `Matter::created` tự thêm vào `team` với vai `lead` (xem Matter::booted()).
 */
function matterWithLawyer(User $lawyer, array $attributes = []): Matter
{
    return matterWithStages([...$attributes, 'lead_lawyer_id' => $lawyer->id]);
}

function validPublicContent(): string
{
    return 'Văn phòng đã hoàn tất bước này và sẽ tiếp tục theo dõi vụ việc của anh chị trong thời gian tới.';
}

it('throws InvalidStageTransition when to_stage is not in the current stage allowed_next', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer);
    $this->actingAs($lawyer, 'web');

    expect(fn () => app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'filed',
        occurredAt: now(),
        internalNote: 'Ghi chú nội bộ',
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    ))->toThrow(InvalidStageTransition::class);

    expect($matter->fresh()->stage)->toBe('intake')
        ->and(StageLog::query()->count())->toBe(0);
});

it('lets an admin bypass the allowed_next check and records the bypass in the activity log', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = matterWithStages();
    $this->actingAs($admin, 'web');

    $stageLog = app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $admin,
        toStage: 'filed',
        occurredAt: now(),
        internalNote: 'Bỏ qua quy trình bình thường vì lý do đặc biệt',
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );

    expect($stageLog->to_stage)->toBe('filed')
        ->and($matter->fresh()->stage)->toBe('filed');

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($admin))->toBeTrue()
        ->and($activity->properties->get('bypassed_allowed_next'))->toBeTrue();
});

it('does not let a non-admin bypass the allowed_next check even with matter.transitionStage', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer);
    $this->actingAs($lawyer, 'web');

    expect(fn () => app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'filed',
        occurredAt: now(),
        internalNote: null,
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    ))->toThrow(InvalidStageTransition::class);
});

it('throws a validation error when publish is true and public_content is 29 characters', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer);
    $this->actingAs($lawyer, 'web');

    $shortContent = str_repeat('a', 29);
    expect(mb_strlen($shortContent))->toBe(29);

    expect(fn () => app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'collecting',
        occurredAt: now(),
        internalNote: null,
        publicContent: $shortContent,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: true,
    ))->toThrow(ValidationException::class);

    expect(StageLog::query()->count())->toBe(0)
        ->and($matter->fresh()->stage)->toBe('intake');
});

it('accepts public_content of exactly 30 characters when publish is true', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer);
    $this->actingAs($lawyer, 'web');

    $content = str_repeat('a', 30);

    $stageLog = app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'collecting',
        occurredAt: now(),
        internalNote: null,
        publicContent: $content,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: true,
    );

    expect($stageLog->is_published)->toBeTrue()
        ->and($stageLog->public_content)->toBe($content);
});

it('creates a same-stage update log with from_stage == to_stage == the current stage (SPEC §6.3)', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer);
    $this->actingAs($lawyer, 'web');

    $stageLog = app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'intake',
        occurredAt: now(),
        internalNote: 'Chưa có văn bản mới từ toà',
        publicContent: 'Tuần này chưa có văn bản mới từ toà, đây là điều bình thường ở giai đoạn này.',
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );

    expect($stageLog->from_stage)->toBe('intake')
        ->and($stageLog->to_stage)->toBe('intake')
        ->and($matter->fresh()->stage)->toBe('intake');
});

it('computes expected_next_update_at from the new stage default_next_update_days when not supplied', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer);
    $this->actingAs($lawyer, 'web');

    $stageLog = app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'collecting',
        occurredAt: now(),
        internalNote: null,
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );

    expect($stageLog->expected_next_update_at->toDateString())
        ->toBe(now()->addDays(10)->toDateString());
});

it('respects an explicitly supplied expected_next_update_at instead of computing one', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer);
    $this->actingAs($lawyer, 'web');

    $explicit = now()->addDays(3)->toDateString();

    $stageLog = app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'collecting',
        occurredAt: now(),
        internalNote: null,
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: $explicit,
        publish: false,
    );

    expect($stageLog->expected_next_update_at->toDateString())->toBe($explicit);
});

it('dispatches StageLogPublished and updates last_client_update_at only when publish AND is_published_to_portal are both true', function () {
    Event::fake([StageLogPublished::class]);

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer, ['is_published_to_portal' => true]);
    $this->actingAs($lawyer, 'web');

    $before = $matter->last_client_update_at;

    app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'collecting',
        occurredAt: now(),
        internalNote: null,
        publicContent: validPublicContent(),
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: true,
    );

    Event::assertDispatched(StageLogPublished::class);
    expect($matter->fresh()->last_client_update_at)->not->toEqual($before);
});

it('does not dispatch StageLogPublished when publish is true but is_published_to_portal is false', function () {
    Event::fake([StageLogPublished::class]);

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer, ['is_published_to_portal' => false]);
    $this->actingAs($lawyer, 'web');

    $before = $matter->last_client_update_at;

    app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'collecting',
        occurredAt: now(),
        internalNote: null,
        publicContent: validPublicContent(),
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: true,
    );

    Event::assertNotDispatched(StageLogPublished::class);
    expect($matter->fresh()->last_client_update_at)->toEqual($before);
});

it('does not dispatch StageLogPublished when is_published_to_portal is true but publish is false', function () {
    Event::fake([StageLogPublished::class]);

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer, ['is_published_to_portal' => true]);
    $this->actingAs($lawyer, 'web');

    $before = $matter->last_client_update_at;

    app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'collecting',
        occurredAt: now(),
        internalNote: null,
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );

    Event::assertNotDispatched(StageLogPublished::class);
    expect($matter->fresh()->last_client_update_at)->toEqual($before);
});

it('checks MatterPolicy::transitionStage itself and refuses an accountant regardless of the caller', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = matterWithStages();
    $this->actingAs($accountant, 'web');

    expect(fn () => app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $accountant,
        toStage: 'collecting',
        occurredAt: now(),
        internalNote: null,
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    ))->toThrow(AuthorizationException::class);

    expect(StageLog::query()->count())->toBe(0);
});

it('throws InvalidStageTransition when to_stage does not correspond to any configured stage, even for an admin', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = matterWithStages();
    $this->actingAs($admin, 'web');

    expect(fn () => app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $admin,
        toStage: 'not_a_real_stage',
        occurredAt: now(),
        internalNote: null,
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    ))->toThrow(InvalidStageTransition::class);
});

it('updates matters.stage and stage_entered_at to occurred_at on a valid transition', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer);
    $this->actingAs($lawyer, 'web');

    $occurredAt = now()->subDays(2);

    app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'collecting',
        occurredAt: $occurredAt,
        internalNote: null,
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );

    $fresh = $matter->fresh();
    expect($fresh->stage)->toBe('collecting')
        ->and($fresh->stage_entered_at->format('Y-m-d H:i:s'))->toBe($occurredAt->format('Y-m-d H:i:s'));
});

it('never leaks internal_note to the stage log public payload and keeps it as a separate column', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer);
    $this->actingAs($lawyer, 'web');

    $stageLog = app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'collecting',
        occurredAt: now(),
        internalNote: 'Bí mật nội bộ, không cho khách xem',
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );

    expect($stageLog->internal_note)->toBe('Bí mật nội bộ, không cho khách xem');
});
