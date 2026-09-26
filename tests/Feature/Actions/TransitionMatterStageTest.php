<?php

use App\Actions\TransitionMatterStage;
use App\Enums\Permission;
use App\Enums\Role;
use App\Events\StageLogPublished;
use App\Exceptions\InvalidStageTransition;
use App\Exceptions\MatterNotPublishedToPortal;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role as SpatieRole;

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

it('refuses to publish when the matter is not published to portal (Important finding 4)', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer, ['is_published_to_portal' => false]);
    $this->actingAs($lawyer, 'web');

    $before = $matter->last_client_update_at;

    expect(fn () => app(TransitionMatterStage::class)->handle(
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
    ))->toThrow(MatterNotPublishedToPortal::class);

    expect(StageLog::query()->count())->toBe(0)
        ->and($matter->fresh()->stage)->toBe('intake')
        ->and($matter->fresh()->last_client_update_at)->toEqual($before);
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

// --- Fix round 1 (code review) -------------------------------------------------------------

it('leaves stage_entered_at untouched on a same-stage update, even when occurred_at is backdated (Critical finding 1)', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer);
    $this->actingAs($lawyer, 'web');

    $originalEnteredAt = $matter->stage_entered_at;

    app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'intake',
        // Backdated on purpose: a buggy implementation that writes stage_entered_at
        // unconditionally would move the clock BACKWARDS here, not just reset it to "now".
        occurredAt: now()->subMonths(4),
        internalNote: 'Chưa có văn bản mới từ toà, đây là điều bình thường ở giai đoạn này',
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );

    expect($matter->fresh()->stage_entered_at->format('Y-m-d H:i:s'))
        ->toBe($originalEnteredAt->format('Y-m-d H:i:s'));
});

it('does not persist the StageLog or let the deferred event fire when a failure happens in an outer transaction after the Action returns (Critical finding 2)', function () {
    Event::fake([StageLogPublished::class]);

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer, ['is_published_to_portal' => true]);
    $this->actingAs($lawyer, 'web');

    // Simulates a future caller (e.g. a Filament page) that wraps the Action in its own
    // transaction and fails AFTER the Action has already returned successfully.
    try {
        DB::transaction(function () use ($matter, $lawyer) {
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

            throw new RuntimeException('forced failure after the Action committed its own inner transaction');
        });
    } catch (RuntimeException) {
        // expected — the assertions below are the actual test.
    }

    expect(StageLog::query()->count())->toBe(0)
        ->and($matter->fresh()->stage)->toBe('intake')
        ->and($matter->fresh()->last_client_update_at)->toBeNull();

    // StageLogPublished implements ShouldDispatchAfterCommit: because the outer transaction
    // rolled back, the deferred dispatch callback is discarded and never runs.
    Event::assertNotDispatched(StageLogPublished::class);
});

it('records the real actor as created_by and activity causer even with no authenticated session (Critical finding 3)', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer);
    // Deliberately no actingAs(): the Action must not rely on ambient auth() to know who acted.

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

    expect($stageLog->created_by)->toBe($lawyer->id);

    $activity = Activity::query()->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($lawyer))->toBeTrue();
});

/**
 * Cùng lỗi, ở dòng `matters` chứ không ở dòng `stage_logs`: `$matter->update()` đi qua
 * `HasBlameable::updating`, vốn ghi `updated_by` từ `auth('web')` ambient. Action đã biết actor
 * là ai, nên cột đó phải là actor. Phiên và actor cố ý là hai người khác nhau để một cài đặt đọc
 * phiên và một cài đặt đọc tham số không thể cho cùng đáp án.
 */
it('writes matters.updated_by from the actor passed in, not from the user in the session', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $someoneElse = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($someoneElse, 'web');

    $matter = matterWithLawyer($lawyer);

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

    expect($matter->fresh()->updated_by)->toBe($lawyer->id);
});

it('refuses to publish when the actor has matter.transitionStage but not stageLog.publish (Important finding 5)', function () {
    $user = User::factory()->create();
    $limitedRole = SpatieRole::findOrCreate('transition_only_test_role', 'web');
    $limitedRole->syncPermissions([Permission::MatterView->value, Permission::MatterTransitionStage->value]);
    $user->syncRoles([$limitedRole->name]);

    $matter = matterWithLawyer($user);
    $this->actingAs($user, 'web');

    expect(fn () => app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $user,
        toStage: 'collecting',
        occurredAt: now(),
        internalNote: null,
        publicContent: validPublicContent(),
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: true,
    ))->toThrow(AuthorizationException::class);

    expect(StageLog::query()->count())->toBe(0);
});

it('rejects a 29-character Vietnamese string even though it is more than 30 bytes long (Important finding 6)', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer);
    $this->actingAs($lawyer, 'web');

    // 'ệ' (U+1EC7) is 3 bytes in UTF-8: 29 of them is 87 bytes, well over 30, but still only
    // 29 real characters. A strlen()-based check would wrongly accept this.
    $vietnamese29Chars = str_repeat('ệ', 29);
    expect(mb_strlen($vietnamese29Chars))->toBe(29)
        ->and(strlen($vietnamese29Chars))->toBeGreaterThan(30);

    expect(fn () => app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'collecting',
        occurredAt: now(),
        internalNote: null,
        publicContent: $vietnamese29Chars,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: true,
    ))->toThrow(ValidationException::class);
});

it('issues exactly one UPDATE statement against matters per transition, not one per condition (Minor finding 8)', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer, ['is_published_to_portal' => true]);
    $this->actingAs($lawyer, 'web');

    $matterUpdateCount = 0;
    DB::listen(function ($query) use (&$matterUpdateCount) {
        if (str_starts_with(strtolower(trim($query->sql)), 'update') && str_contains($query->sql, 'matters')) {
            $matterUpdateCount++;
        }
    });

    // Touches both stage (real transition) AND last_client_update_at (publish to portal) in the
    // same call, so a pre-fix implementation that used two separate $matter->update() calls
    // would issue two UPDATE statements here instead of one.
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

    expect($matterUpdateCount)->toBe(1);
});

// --- Fix round 2 (review, task 1: occurred_at accepts a future date) -----------------------

it('refuses a future occurred_at, leaving the append-only stage log untouched', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer);
    $this->actingAs($lawyer, 'web');

    expect(fn () => app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'collecting',
        occurredAt: now()->addDay()->toDateString(),
        internalNote: null,
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    ))->toThrow(ValidationException::class);

    expect(StageLog::query()->count())->toBe(0)
        ->and($matter->fresh()->stage)->toBe('intake');
});

it('accepts occurred_at equal to today sent as a bare date string with no time component', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = matterWithLawyer($lawyer);
    $this->actingAs($lawyer, 'web');

    // Đúng dạng DatePicker gửi lên: chỉ có ngày, không giờ. Carbon::parse() mặc định 00:00:00,
    // phải khớp today()->startOfDay() — không được lệch bị coi là "tương lai" vì giờ hệ thống
    // hay múi giờ UTC/host, do PHP default timezone đã được LoadConfiguration đặt theo
    // config('app.timezone') (Asia/Ho_Chi_Minh).
    $stageLog = app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: 'collecting',
        occurredAt: today()->toDateString(),
        internalNote: null,
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );

    expect($stageLog->occurred_at->toDateString())->toBe(today()->toDateString());
});

/**
 * I-1 (fix round 4), dòng `matters` của cùng khiếm khuyết — xem docblock bản sinh đôi ở
 * `SetMatterPortalPublicationTest`. `isDirty('updated_by')` so với giá trị GỐC, nên khi cột đã
 * mang sẵn id của actor thì phép gán không "bẩn" và hook ambient thắng.
 */
it('keeps matters.updated_by on the actor even when the column already holds the actor id', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $someoneElse = User::factory()->withRole(Role::Admin)->create();

    $matter = matterWithLawyer($lawyer);
    Matter::query()->whereKey($matter->id)->update(['updated_by' => $lawyer->id]);

    $this->actingAs($someoneElse, 'web');

    app(TransitionMatterStage::class)->handle(
        matter: $matter->fresh(),
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

    expect($matter->fresh()->updated_by)->toBe($lawyer->id);
});

// --- R8 (M6.5 Task 5, findings stage-03/spec-gap-03): closed_at, một định nghĩa "đang mở" -----

/**
 * Vụ việc có một giai đoạn `is_terminal` (`closed`), tới được từ `intake`. Tách khỏi
 * `matterWithStages()` ở trên vì ba stage sẵn có ('intake'/'collecting'/'filed') không đánh dấu
 * `is_terminal`, và việc thêm cờ đó vào chúng sẽ đổi hành vi của mọi test khác trong tệp này.
 */
function matterWithTerminalStage(array $attributes = []): Matter
{
    $type = MatterType::factory()->create();

    $type->stages()->create([
        'key' => 'intake', 'label' => 'Tiếp nhận', 'client_label' => 'Tiếp nhận',
        'client_description' => 'Đã tiếp nhận', 'sort_order' => 1,
        'allowed_next' => ['closed'], 'default_next_update_days' => 14,
    ]);
    $type->stages()->create([
        'key' => 'closed', 'label' => 'Kết thúc', 'client_label' => 'Đã kết thúc',
        'client_description' => 'Đã kết thúc', 'sort_order' => 2, 'is_terminal' => true,
        'allowed_next' => [], 'default_next_update_days' => 30,
    ]);
    $type->unsetRelation('stages');

    return Matter::factory()->for($type, 'matterType')->create($attributes);
}

/** R8: vào một giai đoạn is_terminal ghi closed_at = now(). */
it('sets closed_at to now when transitioning into a terminal stage', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = matterWithTerminalStage();
    $this->actingAs($admin, 'web');

    expect($matter->closed_at)->toBeNull();

    app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $admin,
        toStage: 'closed',
        occurredAt: now(),
        internalNote: null,
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );

    $fresh = $matter->fresh();

    expect($fresh->stage)->toBe('closed')
        ->and($fresh->closed_at)->not->toBeNull()
        ->and($fresh->closed_at->isToday())->toBeTrue();
});

/**
 * R8: rời một giai đoạn is_terminal (đường bỏ qua của admin, vì "closed" ở trên không khai báo
 * allowed_next quay lại "intake") xoá closed_at về null.
 */
it('clears closed_at when an admin bypasses the way out of a terminal stage', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = matterWithTerminalStage();
    $this->actingAs($admin, 'web');

    app(TransitionMatterStage::class)->handle(
        matter: $matter, actor: $admin, toStage: 'closed', occurredAt: now(),
        internalNote: null, publicContent: null, nextStep: null, clientAction: null,
        expectedNextUpdateAt: null, publish: false,
    );

    expect($matter->fresh()->closed_at)->not->toBeNull();

    app(TransitionMatterStage::class)->handle(
        matter: $matter->fresh(), actor: $admin, toStage: 'intake', occurredAt: now(),
        internalNote: 'Mở lại vụ việc theo yêu cầu', publicContent: null, nextStep: null,
        clientAction: null, expectedNextUpdateAt: null, publish: false,
    );

    expect($matter->fresh()->stage)->toBe('intake')
        ->and($matter->fresh()->closed_at)->toBeNull();
});

/**
 * §6.3: một dòng cập nhật KHÔNG đổi giai đoạn (`toStage` bằng giai đoạn hiện tại) không được
 * tính lại `closed_at` — cùng luật với `stage_entered_at` ngay phía trên nó trong Action.
 */
it('does not touch closed_at on a same-stage update while already in a terminal stage', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = matterWithTerminalStage();
    $this->actingAs($admin, 'web');

    app(TransitionMatterStage::class)->handle(
        matter: $matter, actor: $admin, toStage: 'closed', occurredAt: now(),
        internalNote: null, publicContent: null, nextStep: null, clientAction: null,
        expectedNextUpdateAt: null, publish: false,
    );

    $closedAt = $matter->fresh()->closed_at;

    app(TransitionMatterStage::class)->handle(
        matter: $matter->fresh(), actor: $admin, toStage: 'closed', occurredAt: now(),
        internalNote: 'Một dòng cập nhật không đổi giai đoạn', publicContent: null,
        nextStep: null, clientAction: null, expectedNextUpdateAt: null, publish: false,
    );

    expect($matter->fresh()->closed_at->toDateString())->toBe($closedAt->toDateString());
});
