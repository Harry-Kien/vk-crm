<?php

use App\Actions\Deadline\SetDeadlineCompletion;
use App\Enums\Confidentiality;
use App\Enums\Role;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

/**
 * "Đánh dấu hoàn thành" của SPEC §7.2, và đường lùi của nó.
 *
 * Một mốc đã hoàn thành là một mốc `CheckDeadlines` (Task 6) thôi nhắc — nên một lần bấm nhầm là
 * một mốc tố tụng im lặng cho tới ngày nó quá hạn. Vì vậy Action nhận một cờ chứ không phải một
 * chiều: cùng hình dạng `SetMatterPortalPublication::handle()`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->deadline = Deadline::factory()->for($this->matter)->create([
        'name' => 'Nộp đơn kháng cáo',
        'responsible_user_id' => $this->lawyer->id,
    ]);
});

it('marks a deadline complete and records when', function () {
    $this->travelTo('2026-09-22 09:30:00');

    $result = app(SetDeadlineCompletion::class)->handle(
        deadline: $this->deadline,
        completed: true,
        actor: $this->lawyer,
    );

    expect($result->is_completed)->toBeTrue()
        ->and($result->completed_at?->format('Y-m-d H:i'))->toBe('2026-09-22 09:30')
        ->and($this->deadline->fresh()->is_completed)->toBeTrue();
});

/**
 * SPEC §4.13 KHÔNG có cột `completed_by`, nên "ai đánh dấu" sống ở hai chỗ yếu hơn một cột: cột
 * `updated_by` (người ghi GẦN NHẤT, đè đi ở lần sửa sau) và nhật ký — thứ append-only. Test này
 * ghim cả hai, và phiên đăng nhập CỐ Ý là người khác để một cài đặt đọc `auth()` không xanh nhờ
 * trùng đáp án.
 */
it('records who marked it complete, from the actor passed in and not from the session', function () {
    $someoneElse = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($someoneElse, 'web');

    app(SetDeadlineCompletion::class)->handle(
        deadline: $this->deadline,
        completed: true,
        actor: $this->lawyer,
    );

    $activity = Activity::query()->where('event', 'deadline_completion_set')->latest('id')->first();

    expect($this->deadline->fresh()->updated_by)->toBe($this->lawyer->id)
        ->and($activity)->not->toBeNull()
        ->and($activity->causer?->is($this->lawyer))->toBeTrue()
        ->and($activity->properties->get('completed'))->toBeTrue();
});

/**
 * Cron gọi trùng, hai tab, một cú bấm đúp: lần thứ hai không được dời mốc thời gian đã ghi, và
 * không được đẻ thêm một dòng nhật ký nói rằng có người vừa hoàn thành nó lần nữa.
 */
it('is a no-op when the deadline is already in the state asked for', function () {
    $this->travelTo('2026-09-22 09:30:00');
    app(SetDeadlineCompletion::class)->handle($this->deadline, true, $this->lawyer);

    $this->travelTo('2026-09-23 15:00:00');
    app(SetDeadlineCompletion::class)->handle($this->deadline, true, $this->lawyer);

    expect($this->deadline->fresh()->completed_at?->format('Y-m-d H:i'))->toBe('2026-09-22 09:30')
        ->and(Activity::query()->where('event', 'deadline_completion_set')->count())->toBe(1);
});

/**
 * Đường lùi. `reminders_sent` KHÔNG bị xoá: các thư đã gửi thì đã gửi thật, và xoá trí nhớ chống
 * gửi trùng (Phán quyết R3 của M6) sẽ bắn lại cả loạt nhắc cũ vào hộp thư người phụ trách.
 */
it('reopens a deadline, clears the completion timestamp and keeps the reminder memory', function () {
    $this->deadline->update(['reminders_sent' => ['d7', 'd3']]);
    app(SetDeadlineCompletion::class)->handle($this->deadline, true, $this->lawyer);

    app(SetDeadlineCompletion::class)->handle($this->deadline, false, $this->lawyer);

    $reopened = $this->deadline->fresh();

    expect($reopened->is_completed)->toBeFalse()
        ->and($reopened->completed_at)->toBeNull()
        ->and($reopened->reminders_sent)->toBe(['d7', 'd3']);
});

/**
 * **Action đọc lại hàng thật, không tin đối tượng caller cầm trong tay.** Đây là nửa ĐỌC LẠI của
 * `OpensDeadline` (nửa KHOÁ thì SQLite không đo được). Một màn hình Livewire giữ bản ghi của nó
 * qua nhiều request, nên "đối tượng trong tay đã cũ" là chuyện thường ngày chứ không phải một ca
 * dựng: ở đây hàng trong cơ sở dữ liệu đã hoàn thành, còn đối tượng trong tay thì chưa — và luật
 * "gọi trùng không ghi đè lịch sử" chỉ đúng nếu Action đọc hàng thật.
 */
it('reads the row back from the database instead of trusting the object it was handed', function () {
    $this->travelTo('2026-09-22 09:30:00');
    app(SetDeadlineCompletion::class)->handle($this->deadline, true, $this->lawyer);

    // Một bản sao CŨ, vẫn nghĩ rằng mốc chưa xong.
    $stale = $this->deadline;
    $stale->is_completed = false;
    $stale->completed_at = null;

    $this->travelTo('2026-09-25 17:00:00');
    app(SetDeadlineCompletion::class)->handle($stale, true, $this->lawyer);

    expect($this->deadline->fresh()->completed_at?->format('Y-m-d H:i'))->toBe('2026-09-22 09:30')
        ->and(Activity::query()->where('event', 'deadline_completion_set')->count())->toBe(1);
});

/**
 * Cổng hỏi trên hàng ĐÃ ĐỌC LẠI, không trên đối tượng được đưa vào: policy đọc `matter` của đối
 * tượng được hỏi, nên một `matter_id` bị sửa trong bộ nhớ sẽ trả lời thay cho dòng dữ liệu thật.
 * Ở đây người hỏi mở được hồ sơ B và không mở được hồ sơ A; mốc thì nằm ở A.
 */
it('asks the gate about the real row, not about a matter_id swapped in memory', function () {
    // `restricted` (SPEC §4.6) khép hồ sơ lại với mọi người trừ luật sư phụ trách và quản trị
    // viên. Ở đây nó là thắt lưng đi cùng dây đeo: một luật sư ngoài đội ngũ đã không thấy hồ sơ
    // thường (vai trò luật sư KHÔNG có `matter.viewAny` — xem `Role::permissions()`), nhưng test
    // này nói về một lời từ chối phải đứng vững, nên nó không dựa vào một mình việc thiếu tên
    // trong `matter_user`.
    $this->matter->update(['confidentiality' => Confidentiality::Restricted]);

    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $reachable = Matter::factory()->create(['lead_lawyer_id' => $outsider->id]);

    // Mốc thật nằm ở hồ sơ của người khác; đối tượng trong tay thì nói nó nằm ở hồ sơ mở được.
    $tampered = $this->deadline;
    $tampered->matter_id = $reachable->id;

    expect(fn () => app(SetDeadlineCompletion::class)->handle($tampered, true, $outsider))
        ->toThrow(AuthorizationException::class);

    expect($this->deadline->fresh()->is_completed)->toBeFalse();
});

it('refuses an actor who cannot write to the matter', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(fn () => app(SetDeadlineCompletion::class)->handle($this->deadline, true, $accountant))
        ->toThrow(AuthorizationException::class);

    expect($this->deadline->fresh()->is_completed)->toBeFalse();
});

it('refuses an actor whose account has been deactivated', function () {
    $this->lawyer->update(['is_active' => false]);

    expect(fn () => app(SetDeadlineCompletion::class)->handle($this->deadline, true, $this->lawyer))
        ->toThrow(AuthorizationException::class);
});

it('refuses to touch a deadline whose matter has been soft deleted', function () {
    $this->matter->delete();

    expect(fn () => app(SetDeadlineCompletion::class)->handle($this->deadline, true, $this->lawyer))
        ->toThrow(AuthorizationException::class);
});
