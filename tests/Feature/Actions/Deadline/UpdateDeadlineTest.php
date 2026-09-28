<?php

use App\Actions\Deadline\UpdateDeadline;
use App\Enums\Confidentiality;
use App\Enums\DeadlineSeverity;
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
 * Sửa một mốc thời hạn (M6.5 Task 14, `deadlines/F7`) — nửa NGHIỆP VỤ, độc lập với màn hình. Xem
 * `tests/Feature/Filament/DeadlinesRelationManagerTest.php` cho hành vi qua Livewire (nút "Sửa"),
 * và cho hai test dựng SCENARIO của brief ("hoãn phiên toà từ còn 2 ngày sang còn 20 ngày").
 *
 * Tệp này gọi thẳng Action để đo đúng cổng validation của CHÍNH NÓ, độc lập với `->required()` của
 * form — cùng lý do `AddMatterDeadlineTest.php` tồn tại cạnh `DeadlinesRelationManagerTest.php`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Vũ Khang']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->deadline = Deadline::factory()->for($this->matter)->create([
        'name' => 'Phiên hoà giải lần 1',
        'due_date' => today()->addDays(9),
        'severity' => DeadlineSeverity::Normal,
        'responsible_user_id' => $this->lawyer->id,
    ]);
});

it('updates the name, due date and severity, blaming the actor', function () {
    $updated = app(UpdateDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $this->lawyer,
        name: 'Phiên hoà giải lần 1 (hoãn)',
        dueDate: today()->addDays(15)->toDateString(),
        severity: DeadlineSeverity::Critical,
    );

    expect($updated->name)->toBe('Phiên hoà giải lần 1 (hoãn)')
        ->and($updated->due_date->toDateString())->toBe(today()->addDays(15)->toDateString())
        ->and($updated->severity)->toBe(DeadlineSeverity::Critical)
        ->and($updated->fresh()->updated_by)->toBe($this->lawyer->id);
});

it('records an audit row naming the changed fields and the before/after values', function () {
    app(UpdateDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $this->lawyer,
        name: 'Tên mới',
        dueDate: today()->addDays(9)->toDateString(),
        severity: DeadlineSeverity::Normal,
    );

    $activity = Activity::query()->where('event', 'deadline_updated')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($this->lawyer))->toBeTrue()
        ->and($activity->properties->get('changed_fields'))->toContain('name')
        ->and($activity->properties->get('before')['name'])->toBe('Phiên hoà giải lần 1')
        ->and($activity->properties->get('after')['name'])->toBe('Tên mới');
});

/** Gửi lại y hệt dữ liệu cũ: không ghi cột, không ghi nhật ký — cùng kỷ luật `SetDeadlineCompletion`. */
it('writes nothing when the submitted data is identical to what is already stored', function () {
    app(UpdateDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $this->lawyer,
        name: $this->deadline->name,
        dueDate: $this->deadline->due_date->toDateString(),
        severity: $this->deadline->severity,
    );

    expect(Activity::query()->where('event', 'deadline_updated')->count())->toBe(0)
        ->and($this->deadline->fresh()->updated_by)->toBeNull();
});

it('refuses an actor who cannot write to the matter', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(fn () => app(UpdateDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $accountant,
        name: 'Tên mới',
        dueDate: today()->addDays(9)->toDateString(),
        severity: DeadlineSeverity::Normal,
    ))->toThrow(AuthorizationException::class);

    expect($this->deadline->fresh()->name)->toBe('Phiên hoà giải lần 1');
});

it('refuses an actor whose account has been deactivated', function () {
    $this->lawyer->update(['is_active' => false]);

    expect(fn () => app(UpdateDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $this->lawyer,
        name: 'Tên mới',
        dueDate: today()->addDays(9)->toDateString(),
        severity: DeadlineSeverity::Normal,
    ))->toThrow(AuthorizationException::class);
});

it('refuses a blank name', function () {
    expect(fn () => app(UpdateDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $this->lawyer,
        name: '   ',
        dueDate: today()->addDays(9)->toDateString(),
        severity: DeadlineSeverity::Normal,
    ))->toThrow(ValidationException::class);

    expect($this->deadline->fresh()->name)->toBe('Phiên hoà giải lần 1');
});

it('refuses a name longer than the DB column', function () {
    expect(fn () => app(UpdateDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $this->lawyer,
        name: str_repeat('a', UpdateDeadline::NAME_MAX_LENGTH + 1),
        dueDate: today()->addDays(9)->toDateString(),
        severity: DeadlineSeverity::Normal,
    ))->toThrow(ValidationException::class);
});

it('refuses a blank due date', function () {
    expect(fn () => app(UpdateDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $this->lawyer,
        name: 'Tên mới',
        dueDate: '',
        severity: DeadlineSeverity::Normal,
    ))->toThrow(ValidationException::class);
});

it('refuses to update a deadline on a soft deleted matter', function () {
    $this->matter->delete();

    expect(fn () => app(UpdateDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $this->lawyer,
        name: 'Tên mới',
        dueDate: today()->addDays(9)->toDateString(),
        severity: DeadlineSeverity::Normal,
    ))->toThrow(AuthorizationException::class);
});

// ---------------------------------------------------------------------------------------------
// Đổi người phụ trách qua "Sửa" — cùng luật `ChecksDeadlineHolder::canHoldDeadline()` mà
// `ChangeDeadlineResponsible` và lần mở lại của `SetDeadlineCompletion` hỏi: còn đi làm, còn trong
// đội ngũ (hoặc là luật sư phụ trách hồ sơ), và xem được hồ sơ. Màn hình chỉ BÀY RA đội ngũ qua
// `responsibleOptions()`; các test dưới đây đo cổng thật ở tầng Action, nơi một payload dàn dựng
// hay một người vừa bị vô hiệu hoá giữa lúc mở form và lúc bấm lưu vẫn phải bị chặn.
// ---------------------------------------------------------------------------------------------

function updateDeadlineHandingTo(Deadline $deadline, User $actor, User $responsible): Deadline
{
    return app(UpdateDeadline::class)->handle(
        deadline: $deadline,
        actor: $actor,
        name: $deadline->name,
        dueDate: $deadline->due_date->toDateString(),
        severity: $deadline->severity,
        responsible: $responsible,
    );
}

it('hands the deadline to a qualified team member and names the change in the audit row', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    updateDeadlineHandingTo($this->deadline, $this->lawyer, $assistant);

    $activity = Activity::query()->where('event', 'deadline_updated')->sole();

    expect($this->deadline->fresh()->responsible_user_id)->toBe($assistant->id)
        ->and($activity->properties->get('changed_fields'))->toContain('responsible_user_id')
        ->and($activity->properties->get('before')['responsible_user_id'])->toBe($this->lawyer->id)
        ->and($activity->properties->get('after')['responsible_user_id'])->toBe($assistant->id);
});

it('refuses to hand the deadline to someone whose account is deactivated', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['is_active' => false]);
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    expect(fn () => updateDeadlineHandingTo($this->deadline, $this->lawyer, $assistant))
        ->toThrow(ValidationException::class);

    expect($this->deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);
});

/** Trưởng phòng XEM được vụ thường (`matter.viewAny`) nhưng không nằm trong đội ngũ — vế "còn trong đội ngũ" của luật. */
it('refuses to hand the deadline to someone outside the matter team, even one who can view the matter', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();

    expect(fn () => updateDeadlineHandingTo($this->deadline, $this->lawyer, $manager))
        ->toThrow(ValidationException::class);

    expect($this->deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);
});

/** Vụ `restricted`: một cộng sự trong đội ngũ không còn `Gate::view()` — vế "xem được hồ sơ" của luật. */
it('refuses to hand the deadline of a restricted matter to a team member who cannot view it', function () {
    $associate = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($associate, MatterRole::Associate);
    $this->matter->update(['confidentiality' => Confidentiality::Restricted]);

    expect(fn () => updateDeadlineHandingTo($this->deadline, $this->lawyer, $associate))
        ->toThrow(ValidationException::class);

    expect($this->deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);
});

/** Cùng kỷ luật `ChangeDeadlineResponsible`: một mốc ĐÃ XONG không còn việc gì để giao. */
it('refuses to change who holds a completed deadline', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $this->deadline->update(['is_completed' => true, 'completed_at' => now()]);

    expect(fn () => updateDeadlineHandingTo($this->deadline->fresh(), $this->lawyer, $assistant))
        ->toThrow(ValidationException::class);

    expect($this->deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);
});

/** Gửi lại đúng người đang giữ mốc (một mốc đã xong, form ẩn ô này): không phải một lần "đổi người", không bị chặn. */
it('accepts the current holder resubmitted unchanged on a completed deadline', function () {
    $this->deadline->update(['is_completed' => true, 'completed_at' => now()]);

    app(UpdateDeadline::class)->handle(
        deadline: $this->deadline->fresh(),
        actor: $this->lawyer,
        name: 'Tên mới cho mốc đã xong',
        dueDate: $this->deadline->due_date->toDateString(),
        severity: DeadlineSeverity::Normal,
        responsible: $this->lawyer,
    );

    expect($this->deadline->fresh()->name)->toBe('Tên mới cho mốc đã xong');
});
