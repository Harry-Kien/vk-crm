<?php

use App\Actions\Deadline\ChangeDeadlineResponsible;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * `App\Actions\Deadline\ChangeDeadlineResponsible` (fix round 1, CRITICAL) — trước bản sửa này,
 * `responsible_user_id` không có đường ghi nào sau khi mốc được tạo (xem
 * `DeadlinesRelationManager.php:395-518`: bốn nút chỉ đổi `is_completed`/`is_published`). Hệ quả:
 * một trợ lý hoặc một luật sư cộng sự (không phải lead) còn đứng tên một mốc chưa xong không bao
 * giờ nghỉ việc được — `ReassignMatter` (Task 4) chỉ chuyển việc của LEAD. Test màn hình
 * (`DeadlinesRelationManagerTest.php`) đo nút "Đổi người phụ trách"; tệp này đo luật của chính
 * Action.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Vũ Khang']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
});

function makeChangeableDeadline(Matter $matter, array $attributes = []): Deadline
{
    return Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $matter->lead_lawyer_id,
        ...$attributes,
    ]);
}

it('changes the responsible person and writes an audit entry naming both sides', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $deadline = makeChangeableDeadline($this->matter, ['responsible_user_id' => $assistant->id]);

    $newResponsible = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($newResponsible, MatterRole::Assistant);

    $changed = app(ChangeDeadlineResponsible::class)->handle(
        deadline: $deadline,
        actor: $this->lawyer,
        newResponsible: $newResponsible,
    );

    expect($changed->responsible_user_id)->toBe($newResponsible->id)
        ->and($deadline->fresh()->responsible_user_id)->toBe($newResponsible->id);

    $activity = Activity::query()->where('event', 'deadline_responsible_changed')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($this->lawyer))->toBeTrue()
        ->and($activity->properties->get('from'))->toBe($assistant->id)
        ->and($activity->properties->get('to'))->toBe($newResponsible->id)
        ->and($activity->properties->get('matter_id'))->toBe($this->matter->id);
});

/**
 * Kế toán có `matter.viewAny` nhưng không có `matter.update` (SPEC §5, cùng cổng
 * `DeadlinePolicy::update`): họ không đổi được người phụ trách trên hồ sơ của người khác.
 */
it('refuses an actor who cannot write to the matter', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $deadline = makeChangeableDeadline($this->matter);

    expect(fn () => app(ChangeDeadlineResponsible::class)->handle(
        deadline: $deadline,
        actor: $accountant,
        newResponsible: $this->lawyer,
    ))->toThrow(AuthorizationException::class);

    expect($deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);
});

/**
 * Ruling fix round 1: người mới phải qua được `Gate::forUser($u)->allows('view', $matter)` —
 * một luật sư hoàn toàn NGOÀI đội ngũ, không có `matter.viewAny`, không mở được hồ sơ này chút
 * nào, nên không đứng tên được một mốc của nó.
 */
it('refuses a new responsible who cannot view the matter', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $deadline = makeChangeableDeadline($this->matter);

    expect(fn () => app(ChangeDeadlineResponsible::class)->handle(
        deadline: $deadline,
        actor: $this->lawyer,
        newResponsible: $outsider,
    ))->toThrow(ValidationException::class);

    expect($deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);
});

/** Vế dương của test trên: một thành viên đội ngũ (qua được `view`) nhận được mốc bình thường. */
it('accepts a new responsible who is a member of the matter team', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $deadline = makeChangeableDeadline($this->matter);

    $changed = app(ChangeDeadlineResponsible::class)->handle(
        deadline: $deadline,
        actor: $this->lawyer,
        newResponsible: $assistant,
    );

    expect($changed->responsible_user_id)->toBe($assistant->id);
});

it('refuses a new responsible whose account has been deactivated', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['is_active' => false]);
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $deadline = makeChangeableDeadline($this->matter);

    expect(fn () => app(ChangeDeadlineResponsible::class)->handle(
        deadline: $deadline,
        actor: $this->lawyer,
        newResponsible: $assistant,
    ))->toThrow(ValidationException::class);

    expect($deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);
});

it('refuses a new responsible whose account has been soft deleted', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $assistant->delete();
    $deadline = makeChangeableDeadline($this->matter);

    expect(fn () => app(ChangeDeadlineResponsible::class)->handle(
        deadline: $deadline,
        actor: $this->lawyer,
        newResponsible: $assistant,
    ))->toThrow(ValidationException::class);
});
