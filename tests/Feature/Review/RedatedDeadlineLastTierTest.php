<?php

use App\Actions\Deadline\UpdateDeadline;
use App\Actions\Schedule\CheckDeadlines;
use App\Enums\DeadlineSeverity;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn matter — dời hạn vào trong bậc nhắc CUỐI (1 ngày).
 *
 * `UpdateDeadline::clearedReminders()` giữ những bậc ngày MỚI "đã tới" (`daysLeft <= bậc`) — đúng cho
 * d7/d3 vì còn một bậc gần hơn sẽ nhắc trước ngày đến hạn. Bậc `d1` thì không còn bậc nào sau nó: giữ
 * lại `d1` đã gửi cho ngày CŨ nghĩa là ngày MỚI không bao giờ được nhắc trước khi tới hạn. Tình huống
 * thật: mốc đã quá hạn (hay đến hạn hôm nay), toà cho gia hạn tới ngày mai — luật sư sửa ngày, và không
 * ai nhận thư "khẩn" nào cho ngày mới tới khi nó lại quá hạn.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
});

function redatedLastTierRows(User $recipient, string $tierAtDate): int
{
    return OutboundMessage::query()
        ->withoutGlobalScopes()
        ->where('template', 'staff.deadline_reminder')
        ->where('recipient', $recipient->email)
        ->where('status', OutboundStatus::Sent)
        ->get()
        ->filter(fn ($row): bool => (string) ($row->payload['tier'] ?? '') === $tierAtDate)
        ->count();
}

function redate(Deadline $deadline, User $actor, string $date): void
{
    app(UpdateDeadline::class)->handle(
        deadline: $deadline->fresh(),
        actor: $actor,
        name: $deadline->name,
        dueDate: $date,
        severity: DeadlineSeverity::Normal,
    );
}

it('reminds about an overdue deadline that was extended to tomorrow before the new date arrives', function () {
    $deadline = Deadline::factory()->for($this->matter)->create([
        'name' => 'Nộp tạm ứng án phí',
        'due_date' => today()->subDays(2),
        'responsible_user_id' => $this->lawyer->id,
        'reminders_sent' => [],
    ]);

    (new CheckDeadlines)->handle();
    expect($deadline->fresh()->reminders_sent)->toContain('overdue', 'd1');

    $tomorrow = today()->addDay()->toDateString();
    redate($deadline, $this->lawyer, $tomorrow);

    // Lượt nhắc kế tiếp (30 phút sau) và sáng ngày đến hạn mới: phải có ÍT NHẤT một thư d1 cho ngày mới.
    (new CheckDeadlines)->handle();
    $this->travelTo(today()->addDay()->setTime(7, 0));
    (new CheckDeadlines)->handle();

    expect(redatedLastTierRows($this->lawyer, 'd1@'.$tomorrow))->toBe(1);
});

it('reminds again when a deadline due today is moved to tomorrow', function () {
    $deadline = Deadline::factory()->for($this->matter)->create([
        'name' => 'Phiên hoà giải',
        'due_date' => today(),
        'responsible_user_id' => $this->lawyer->id,
        'reminders_sent' => [],
    ]);

    (new CheckDeadlines)->handle();
    expect(redatedLastTierRows($this->lawyer, 'd1@'.today()->toDateString()))->toBe(1);

    $tomorrow = today()->addDay()->toDateString();
    redate($deadline, $this->lawyer, $tomorrow);

    (new CheckDeadlines)->handle();
    $this->travelTo(today()->addDay()->setTime(7, 0));
    (new CheckDeadlines)->handle();

    expect(redatedLastTierRows($this->lawyer, 'd1@'.$tomorrow))->toBe(1);
});

it('still keeps the seven-day tier when a deadline is pushed back but stays inside it', function () {
    $deadline = Deadline::factory()->for($this->matter)->create([
        'name' => 'Phiên hoà giải',
        'due_date' => today()->addDays(2),
        'responsible_user_id' => $this->lawyer->id,
        'reminders_sent' => ['d7', 'd3'],
    ]);

    redate($deadline, $this->lawyer, today()->addDays(5)->toDateString());

    expect($deadline->fresh()->reminders_sent)->toBe(['d7']);
});
