<?php

use App\Actions\Deadline\DeleteDeadline;
use App\Enums\Role;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Gỡ một mốc thời hạn (M6.5 Task 14, `deadlines/F7`; R14) — nửa NGHIỆP VỤ, độc lập với màn hình.
 * Xem `tests/Feature/Filament/DeadlinesRelationManagerTest.php` cho hành vi qua Livewire (nút
 * "Xoá").
 *
 * **Vì sao tệp này gọi thẳng Action, không qua Livewire, cho câu "lý do bắt buộc".** Ô `reason`
 * trên màn hình CŨNG mang `->required()` (để người dùng thấy lỗi ngay, không cần round-trip máy
 * chủ) — nên một `callTableAction(..., data: ['reason' => ''])` bị CHÍNH Filament chặn trước khi
 * chạm tới Action, và một mutation xoá hẳn cổng của `DeleteDeadline::handle()` vẫn để test Livewire
 * xanh (đã đo: xem báo cáo). Test dưới đây gọi Action trực tiếp, nên nó đo đúng cổng của TẦNG
 * ACTION — lớp phòng thủ thật, cùng kỷ luật "màn hình không phải cổng, Action mới là cổng".
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Vũ Khang']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->deadline = Deadline::factory()->for($this->matter)->create([
        'name' => 'Mốc gõ nhầm',
        'due_date' => today()->addDays(5),
        'responsible_user_id' => $this->lawyer->id,
    ]);
});

it('soft-deletes the deadline and writes an audit row with the reason', function () {
    app(DeleteDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $this->lawyer,
        reason: 'Ghi nhầm sang vụ khác, chưa từng là mốc của hồ sơ này.',
    );

    expect(Deadline::query()->whereKey($this->deadline->id)->exists())->toBeFalse()
        ->and(Deadline::withTrashed()->whereKey($this->deadline->id)->exists())->toBeTrue();

    $activity = Activity::query()->where('event', 'deadline_deleted')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($this->lawyer))->toBeTrue()
        ->and($activity->properties->get('reason'))->toBe('Ghi nhầm sang vụ khác, chưa từng là mốc của hồ sơ này.')
        ->and($activity->properties->get('name'))->toBe('Mốc gõ nhầm');
});

/**
 * R14: "gỡ một bên/mốc là xoá mềm kèm lý do bắt buộc" — cổng thật nằm Ở TẦNG ACTION, không chỉ ở
 * `->required()` của form. Mutation probe: xoá hẳn điều kiện `$reason === ''` (đổi thành
 * `if (false)`) làm đúng khẳng định dưới đây đỏ — dán trong báo cáo, đã khôi phục.
 */
it('refuses a blank reason and deletes nothing', function () {
    expect(fn () => app(DeleteDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $this->lawyer,
        reason: '   ',
    ))->toThrow(ValidationException::class);

    expect(Deadline::query()->whereKey($this->deadline->id)->exists())->toBeTrue();
});

it('refuses an actor who cannot write to the matter', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(fn () => app(DeleteDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $accountant,
        reason: 'Nhập trùng.',
    ))->toThrow(AuthorizationException::class);

    expect(Deadline::query()->whereKey($this->deadline->id)->exists())->toBeTrue();
});

it('refuses an actor whose account has been deactivated', function () {
    $this->lawyer->update(['is_active' => false]);

    expect(fn () => app(DeleteDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $this->lawyer,
        reason: 'Nhập trùng.',
    ))->toThrow(AuthorizationException::class);
});

it('refuses to delete a deadline that has already been removed', function () {
    $this->deadline->delete();

    expect(fn () => app(DeleteDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $this->lawyer,
        reason: 'Nhập trùng.',
    ))->toThrow(AuthorizationException::class);
});

it('refuses to delete a deadline on a soft deleted matter', function () {
    $this->matter->delete();

    expect(fn () => app(DeleteDeadline::class)->handle(
        deadline: $this->deadline,
        actor: $this->lawyer,
        reason: 'Nhập trùng.',
    ))->toThrow(AuthorizationException::class);
});
