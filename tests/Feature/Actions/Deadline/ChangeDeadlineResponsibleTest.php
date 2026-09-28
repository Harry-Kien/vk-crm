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

/**
 * M6.5 Task 14: luật chung `ChecksDeadlineHolder::canHoldDeadline()` thêm vế "còn trong đội ngũ".
 * Trưởng phòng XEM được vụ thường (`matter.viewAny`) nhưng không ở trong đội ngũ — không nhận được
 * mốc, dù qua được `view`. Cặp dương là test ngay dưới.
 */
it('refuses a new responsible who can view the matter but is outside its team', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $deadline = makeChangeableDeadline($this->matter);

    expect(fn () => app(ChangeDeadlineResponsible::class)->handle(
        deadline: $deadline,
        actor: $this->lawyer,
        newResponsible: $manager,
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

/**
 * Minor (fix round 2): một mốc ĐÃ HOÀN THÀNH không còn "việc" nào để đổi người phụ trách nữa —
 * Action tự chặn (lớp phòng thủ THẬT), không chỉ ẩn nút ở tầng UI
 * (`DeadlinesRelationManagerTest`'s "hides the change-responsible button on a completed
 * deadline"). Đọc `is_completed` từ `$fresh` (đã khoá dòng), không phải `$deadline` caller đưa
 * vào — cùng kỷ luật đọc mọi điều kiện từ bản ghi đã khoá của Action này.
 */
it('refuses to change the responsible person on a deadline that is already completed', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $deadline = makeChangeableDeadline($this->matter, ['is_completed' => true, 'completed_at' => now()]);

    expect(fn () => app(ChangeDeadlineResponsible::class)->handle(
        deadline: $deadline,
        actor: $this->lawyer,
        newResponsible: $assistant,
    ))->toThrow(ValidationException::class);

    expect($deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);
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

/**
 * Minor (fix round 2): `canHoldTheDeadline()` đọc `$newResponsible->is_active` — đối tượng caller
 * đưa vào, không khoá/đọc lại gì. Mô phỏng đúng cuộc đua: form mở ra lúc người mới còn hoạt động
 * (đối tượng trong tay test VẪN `is_active = true`), một request khác vô hiệu hoá họ thẳng trên
 * CSDL (không qua đối tượng này) NGAY TRƯỚC khi lượt đổi người phụ trách này bấm lưu.
 */
it('refuses a new responsible deactivated between when the form opened and when it was submitted', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $deadline = makeChangeableDeadline($this->matter);

    User::query()->whereKey($assistant->id)->update(['is_active' => false]);

    expect(fn () => app(ChangeDeadlineResponsible::class)->handle(
        deadline: $deadline,
        actor: $this->lawyer,
        newResponsible: $assistant,
    ))->toThrow(ValidationException::class);

    expect($deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);
});

/**
 * Minor (fix round 2): điều kiện actor còn hiệu lực (`ChangeDeadlineResponsible.php:70`) chưa có
 * test riêng — mọi test khác đo bằng một actor không đủ quyền `matter.update`
 * (`Gate::forUser()->denied()`), một nhánh KHÁC. Actor đã bị vô hiệu hoá chặn TRƯỚC CẢ khi chạm
 * Gate — đối tượng `$actor` truyền vào đã `is_active = false` NGAY TỪ ĐẦU (không cần mô phỏng cuộc
 * đua: đây là actor tự thao tác khi tài khoản CHÍNH HỌ đã bị khoá, ví dụ một phiên cũ còn sống sau
 * khi bị vô hiệu hoá). **Actor phải là LEAD của vụ việc** (qua được `DeadlinePolicy::update`) — nếu
 * không, một mutation probe xoá điều kiện `accountIsActive` đi vẫn xanh vì actor đã bị chặn ở Gate
 * TRƯỚC (một nhánh KHÁC, không phải nhánh đang đo) — `MatterPolicy::update` không tự hỏi
 * `is_active` (xem docblock `ChecksAccountActive`), nên một lead đã vô hiệu hoá vẫn qua được Gate.
 */
it('refuses an actor whose own account has been deactivated', function () {
    $inactiveActor = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $inactiveActor->id]);
    $deadline = makeChangeableDeadline($matter);
    // Người nhận việc phải là một lựa chọn hợp lệ RIÊNG trên chính $matter này (khác đội ngũ của
    // $this->matter) — để một lần RED giả (thất bại vì "người mới không mở được hồ sơ", một nhánh
    // KHÁC) không nguỵ trang thành bằng chứng cho nhánh đang đo.
    $newResponsible = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($newResponsible, MatterRole::Assistant);

    expect(fn () => app(ChangeDeadlineResponsible::class)->handle(
        deadline: $deadline,
        actor: $inactiveActor,
        newResponsible: $newResponsible,
    ))->toThrow(AuthorizationException::class);

    expect($deadline->fresh()->responsible_user_id)->toBe($inactiveActor->id);
});
