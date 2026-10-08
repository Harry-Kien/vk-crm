<?php

use App\Actions\Deadline\SetDeadlineCompletion;
use App\Enums\DeadlineOutcome;
use App\Enums\Role;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

/**
 * M13 Task 2 — `Deadline::outcomeAt(CarbonInterface $cutoff): ?DeadlineOutcome`, bảng ca biên của
 * P1 (kế hoạch M13, "Định nghĩa các con số"; SPEC §6.14). Mỗi ca một `it()`, kèm cặp dương/âm ngay
 * trong ca, để một mutation probe bỏ một điều kiện làm đúng ca đó đỏ.
 *
 * Mốc cắt mặc định là của kỳ ĐÃ ĐÓNG "tháng 9/2026" (`PerformancePeriod::cutoff()` của Task 6 =
 * `min(23:59:59 ngày cuối kỳ, now())`); hôm nay đứng ở 15/10/2026, nên mọi việc làm sau 30/09 là
 * "sau kỳ" (R19).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->cutoff = Carbon::parse('2026-09-30 23:59:59');
});

/** Một mốc đến hạn `$due`, ghi vào hệ thống lúc `$createdAt` (mặc định một tuần trước hạn). */
function m13t2Deadline(Matter $matter, string $due, array $attributes = []): Deadline
{
    $deadline = Deadline::factory()->for($matter)->create([
        'due_date' => $due,
        'responsible_user_id' => $matter->lead_lawyer_id,
        'is_completed' => false,
        'completed_at' => null,
        'created_at' => Carbon::parse($due)->subWeek()->setTime(9, 0),
        ...$attributes,
    ]);

    return Deadline::query()->with('matter')->findOrFail($deadline->getKey());
}

function m13t2Done(string $at): array
{
    return ['is_completed' => true, 'completed_at' => $at];
}

it('ca 1 — leaves out a deadline written into the system after its due day, and keeps one written on the due day itself', function () {
    $writtenLate = m13t2Deadline($this->matter, '2026-09-10', ['created_at' => '2026-09-11 08:00:00']);
    $writtenLateDone = m13t2Deadline($this->matter, '2026-09-10', ['created_at' => '2026-09-11 08:00:00', ...m13t2Done('2026-09-11 08:05:00')]);
    $writtenOnTheDay = m13t2Deadline($this->matter, '2026-09-10', ['created_at' => '2026-09-10 23:59:59']);

    expect($writtenLate->outcomeAt($this->cutoff))->toBeNull()
        ->and($writtenLateDone->outcomeAt($this->cutoff))->toBeNull()
        ->and($writtenOnTheDay->outcomeAt($this->cutoff))->toBe(DeadlineOutcome::Missed);
});

it('ca 2 — does not guess when a deadline marked done has no completion time', function () {
    $noTime = m13t2Deadline($this->matter, '2026-09-10', ['is_completed' => true, 'completed_at' => null]);
    $withTime = m13t2Deadline($this->matter, '2026-09-10', m13t2Done('2026-09-09 16:00:00'));

    expect($noTime->outcomeAt($this->cutoff))->toBeNull()
        ->and($withTime->outcomeAt($this->cutoff))->toBe(DeadlineOutcome::OnTime);
});

it('ca 3 — calls a deadline done at 23:59:59 on its due day on time', function () {
    $lastSecond = m13t2Deadline($this->matter, '2026-09-10', m13t2Done('2026-09-10 23:59:59'));
    $early = m13t2Deadline($this->matter, '2026-09-10', m13t2Done('2026-09-02 08:00:00'));

    expect($lastSecond->outcomeAt($this->cutoff))->toBe(DeadlineOutcome::OnTime)
        ->and($early->outcomeAt($this->cutoff))->toBe(DeadlineOutcome::OnTime);
});

it('ca 4 — calls a deadline done one second into the next day late, as long as it was done by the cutoff', function () {
    $nextDay = m13t2Deadline($this->matter, '2026-09-10', m13t2Done('2026-09-11 00:00:01'));
    $atCutoff = m13t2Deadline($this->matter, '2026-09-10', m13t2Done('2026-09-30 23:59:59'));
    $afterCutoff = m13t2Deadline($this->matter, '2026-09-10', m13t2Done('2026-10-01 00:00:00'));

    expect($nextDay->outcomeAt($this->cutoff))->toBe(DeadlineOutcome::Late)
        ->and($atCutoff->outcomeAt($this->cutoff))->toBe(DeadlineOutcome::Late)
        // R19: xong sau khi hết kỳ vẫn là "lỡ" của kỳ đó — kỳ đã đóng không trôi.
        ->and($afterCutoff->outcomeAt($this->cutoff))->toBe(DeadlineOutcome::Missed);
});

it('ca 5 — leaves out a deadline due today in a running period until its day is over', function () {
    $runningCutoff = now();

    $dueToday = m13t2Deadline($this->matter, '2026-10-15');
    $dueYesterday = m13t2Deadline($this->matter, '2026-10-14');
    $dueTodayDone = m13t2Deadline($this->matter, '2026-10-15', m13t2Done('2026-10-15 09:00:00'));

    expect($dueToday->outcomeAt($runningCutoff))->toBeNull()
        ->and($dueYesterday->outcomeAt($runningCutoff))->toBe(DeadlineOutcome::Missed)
        ->and($dueTodayDone->outcomeAt($runningCutoff))->toBe(DeadlineOutcome::OnTime);

    // Mốc đến hạn ngày cuối kỳ đã đóng chỉ có thể đúng hạn hoặc lỡ (R19).
    $dueOnLastDay = m13t2Deadline($this->matter, '2026-09-30');

    expect($dueOnLastDay->outcomeAt($this->cutoff))->toBe(DeadlineOutcome::Missed);
});

it('ca 6 — leaves out a deadline of a matter that closed on or before its due day, but not one that closed the day after', function () {
    $closedOnTheDay = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $closedOnTheDay->forceFill(['closed_at' => Carbon::parse('2026-09-10 10:00:00')])->save();

    $closedBefore = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $closedBefore->forceFill(['closed_at' => Carbon::parse('2026-09-05 10:00:00')])->save();

    $closedNextDay = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $closedNextDay->forceFill(['closed_at' => Carbon::parse('2026-09-11 00:00:01')])->save();

    expect(m13t2Deadline($closedOnTheDay, '2026-09-10')->outcomeAt($this->cutoff))->toBeNull()
        ->and(m13t2Deadline($closedBefore, '2026-09-10')->outcomeAt($this->cutoff))->toBeNull()
        ->and(m13t2Deadline($closedNextDay, '2026-09-10')->outcomeAt($this->cutoff))->toBe(DeadlineOutcome::Missed)
        // Mốc đã xong vẫn được xếp, dù vụ đã kết thúc: ca 3 đứng trước ca 6.
        ->and(m13t2Deadline($closedOnTheDay, '2026-09-10', m13t2Done('2026-09-09 10:00:00'))->outcomeAt($this->cutoff))->toBe(DeadlineOutcome::OnTime);
});

it('closedOnOrBefore() — a matter closed at 10:00 on the day counts, one closed at 00:00:01 the next day does not', function () {
    $day = Carbon::parse('2026-09-10');

    $closedOnTheDay = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $closedOnTheDay->forceFill(['closed_at' => Carbon::parse('2026-09-10 10:00:00')])->save();

    $closedNextDay = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $closedNextDay->forceFill(['closed_at' => Carbon::parse('2026-09-11 00:00:01')])->save();

    $open = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $cancelled = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $cancelled->forceFill(['closed_at' => Carbon::parse('2026-09-05 10:00:00')])->save();
    $cancelled->delete();

    expect($closedOnTheDay->fresh()->closedOnOrBefore($day))->toBeTrue()
        ->and($closedOnTheDay->fresh()->closedOnOrBefore(Carbon::parse('2026-09-09 23:59:59')))->toBeFalse()
        ->and($closedNextDay->fresh()->closedOnOrBefore($day))->toBeFalse()
        ->and($open->fresh()->closedOnOrBefore($day))->toBeFalse()
        // Một vụ đã huỷ không "đã kết thúc" (`isClosed()`), cùng luật với `scopeClosed()`.
        ->and(Matter::withTrashed()->findOrFail($cancelled->getKey())->closedOnOrBefore($day))->toBeFalse();
});

it('ca 7 — calls an open deadline past its due day in a matter still open missed', function () {
    $missed = m13t2Deadline($this->matter, '2026-09-10');

    expect($missed->outcomeAt($this->cutoff))->toBe(DeadlineOutcome::Missed);
});

/**
 * Ca 8: mở lại là văn phòng nói mốc đó CHƯA xong; con số đi theo lời đó (R19 — một trong các thao
 * tác được phép làm đổi kỳ đã đóng). Đi qua đúng Action mở lại (`SetDeadlineCompletion`).
 */
it('ca 8 — follows a deadline reopened after its due day: missed, or late once done again before the cutoff', function () {
    $this->travelTo(Carbon::parse('2026-09-10 15:00:00'));
    $deadline = m13t2Deadline($this->matter, '2026-09-10');
    app(SetDeadlineCompletion::class)->handle($deadline, true, $this->lawyer);

    expect($deadline->fresh()->load('matter')->outcomeAt($this->cutoff))->toBe(DeadlineOutcome::OnTime);

    $this->travelTo(Carbon::parse('2026-09-14 09:00:00'));
    app(SetDeadlineCompletion::class)->handle($deadline->fresh(), false, $this->lawyer);

    expect($deadline->fresh()->load('matter')->outcomeAt($this->cutoff))->toBe(DeadlineOutcome::Missed);

    $this->travelTo(Carbon::parse('2026-09-20 11:00:00'));
    app(SetDeadlineCompletion::class)->handle($deadline->fresh(), true, $this->lawyer);

    expect($deadline->fresh()->load('matter')->outcomeAt($this->cutoff))->toBe(DeadlineOutcome::Late);
});

/**
 * Ca 9 thuộc `DeadlineHolderAtDue` (Task 3): dòng lịch sử người giữ có `from` rỗng làm mốc không quy
 * về ai, nhưng PHÂN LOẠI giữ nguyên — `outcomeAt()` không đọc lịch sử người giữ.
 */
it('ca 9 — classifies the same whatever the holder history says', function () {
    $deadline = m13t2Deadline($this->matter, '2026-09-10');

    $before = $deadline->outcomeAt($this->cutoff);

    Audit::record('deadline_responsible_changed', $deadline, ['matter_id' => $this->matter->id, 'from' => null, 'to' => $this->lawyer->id], causer: $this->lawyer);

    expect($deadline->fresh()->load('matter')->outcomeAt($this->cutoff))->toBe($before)
        ->and($before)->toBe(DeadlineOutcome::Missed);
});

it('labels every outcome in vietnamese', function () {
    expect(DeadlineOutcome::cases())->toHaveCount(3)
        ->and(collect(DeadlineOutcome::cases())->map(fn (DeadlineOutcome $outcome): string => $outcome->value)->all())->toBe(['on_time', 'late', 'missed']);

    foreach (DeadlineOutcome::cases() as $outcome) {
        expect($outcome->label())->not->toBeEmpty()->not->toStartWith('performance.');
    }
});
