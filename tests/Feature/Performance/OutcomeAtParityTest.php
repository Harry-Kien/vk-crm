<?php

use App\Enums\DeadlineOutcome;
use App\Enums\Role;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Performance\DeadlineHolderAtDue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn ops — `Deadline::outcomeAt()` và `DeadlineHolderAtDue` so bằng dấu
 * thời gian (giây) thay vì dựng Carbon cho mỗi cột của mỗi mốc (trang "Hiệu suất theo kỳ": khoảng 4.650 mốc
 * một quý trên dữ liệu đo M13, phần lớn thời gian báo cáo nằm ở các Carbon đó). Tệp này ghim rằng đáp án
 * KHÔNG đổi: trên một lưới mốc (ngày ghi, ngày xong, vụ kết thúc) × mốc cắt (cả mốc cắt mang micro giây như
 * `now()`), kết quả bằng đúng bản so Carbon của M13 chép nguyên văn dưới đây ({@see opsCarbonOutcome()}).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
});

/** Bản so Carbon của `outcomeAt()` trước lần tối ưu (M13 Task 2) — đáp án tham chiếu. */
function opsCarbonOutcome(Deadline $deadline, CarbonInterface $cutoff): ?DeadlineOutcome
{
    $dueEnd = $deadline->due_date->copy()->setTime(23, 59, 59);
    $createdAt = $deadline->created_at;

    if ($createdAt !== null && $createdAt->gt($dueEnd)) {
        return null;
    }

    if ($deadline->is_completed) {
        $completedAt = $deadline->completed_at;

        if ($completedAt === null) {
            return null;
        }

        if ($completedAt->lte($dueEnd)) {
            return DeadlineOutcome::OnTime;
        }

        if ($completedAt->lte($cutoff)) {
            return DeadlineOutcome::Late;
        }
    }

    if ($dueEnd->gt($cutoff)) {
        return null;
    }

    if ($deadline->matter?->closedOnOrBefore($dueEnd) === true) {
        return null;
    }

    return DeadlineOutcome::Missed;
}

it('classifies every deadline of the grid exactly as the Carbon comparison did, whatever the cutoff', function () {
    $due = '2026-09-10';
    $createdAts = ['2026-09-01 09:00:00', '2026-09-10 23:59:59', '2026-09-11 00:00:00', null];
    $completions = [
        [false, null],
        [true, null],
        [true, '2026-09-10 23:59:59'],
        [true, '2026-09-11 00:00:00'],
        [true, '2026-09-20 12:00:00'],
        [true, '2026-09-30 23:59:59'],
        [true, '2026-10-01 00:00:00'],
    ];
    $closings = [null, '2026-09-09', '2026-09-10', '2026-09-11'];
    $cutoffs = [
        CarbonImmutable::parse('2026-09-30 23:59:59'),
        CarbonImmutable::parse('2026-09-10 23:59:58.700000'),
        CarbonImmutable::parse('2026-09-10 23:59:59.400000'),
        CarbonImmutable::parse('2026-09-11 00:00:00.000001'),
        CarbonImmutable::parse('2026-09-20 11:59:59.999999'),
        CarbonImmutable::parse('2026-09-20 12:00:00.250000'),
        now()->toImmutable(),
    ];

    $checked = 0;
    $seen = [];

    foreach ($closings as $closedAt) {
        $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id, 'closed_at' => $closedAt]);

        foreach ($createdAts as $createdAt) {
            foreach ($completions as [$done, $completedAt]) {
                $created = Deadline::factory()->for($matter)->create([
                    'due_date' => $due,
                    'responsible_user_id' => $this->lawyer->id,
                    'is_completed' => $done,
                    'completed_at' => $completedAt,
                ]);
                $created->forceFill(['created_at' => $createdAt])->saveQuietly();

                // Đọc lại từ CSDL (chuỗi lưu thật), như báo cáo nạp mốc.
                $deadline = Deadline::query()->with('matter:id,closed_at,deleted_at')->findOrFail($created->getKey());

                foreach ($cutoffs as $cutoff) {
                    $expected = opsCarbonOutcome($deadline, $cutoff);
                    expect($deadline->outcomeAt($cutoff))->toBe($expected);
                    $seen[$expected?->value ?? 'none'] = true;
                    $checked++;
                }
            }
        }
    }

    // Lưới chạm đủ bốn đáp án, để phép so không xanh nhờ một nhánh duy nhất.
    expect($checked)->toBe(4 * 4 * 7 * 7)
        ->and(array_keys($seen))->toEqualCanonicalizing(['none', 'on_time', 'late', 'missed']);
});

it('puts the end of the due day at the same second as dueEnd(), read from the database or set in memory', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $stored = Deadline::factory()->for($matter)->create(['due_date' => '2026-12-31']);

    $inMemory = [
        new Deadline(['due_date' => '2026-03-01']),
        new Deadline(['due_date' => Carbon::parse('2026-07-15 14:30:00')]),
        new Deadline(['due_date' => CarbonImmutable::parse('2027-01-01')]),
    ];

    foreach ([Deadline::query()->findOrFail($stored->getKey()), $stored, ...$inMemory] as $deadline) {
        expect($deadline->dueEndTimestamp())->toBe($deadline->dueEnd()->getTimestamp());
    }

    expect(date('Y-m-d H:i:s', $stored->dueEndTimestamp()))->toBe('2026-12-31 23:59:59');
});

it('still hands a deadline to whoever held it at 23:59:59 of its due day', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $other = User::factory()->withRole(Role::Lawyer)->create();
    $deadline = Deadline::factory()->for($matter)->create(['due_date' => '2026-09-10', 'responsible_user_id' => $other->id]);

    // Đổi người giữ: lần đổi đúng 23:59:59 ngày đến hạn chưa "sau" hạn; lần đổi 00:00:00 hôm sau là sau.
    activity()->performedOn($deadline)->event(DeadlineHolderAtDue::EVENT)
        ->withProperties(['from' => $this->lawyer->id])->createdAt(Carbon::parse('2026-09-11 00:00:00'))->log('x');

    expect(DeadlineHolderAtDue::resolve(collect([$deadline->fresh()])))->toBe([$deadline->getKey() => $this->lawyer->id]);
});
