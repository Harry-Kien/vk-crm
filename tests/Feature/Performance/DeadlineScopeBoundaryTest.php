<?php

use App\Actions\Schedule\CheckDeadlines;
use App\Enums\Role;
use App\Filament\Admin\Widgets\UpcomingDeadlinesWidget;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * M13 Task 2 — cận ngày của các scope mốc thời hạn: "bây giờ" (`overdue()`, `dueWithin()`,
 * `upcoming()`) và "trong kỳ" (`dueBetween()`, `removedBetween()`).
 *
 * `due_date` là cột `date`, nhưng cast `date` ghi theo định dạng ngày-giờ của kết nối: SQLite lưu
 * `2026-11-07 00:00:00`, lớn hơn chuỗi ngày trần `2026-11-07` khi so chuỗi. Trước Task 2,
 * `scopeUpcoming()` so `due_date <= 'Y-m-d'` trần, nên mốc ngày +7 rơi khỏi widget trang chủ trên
 * SQLite mà vẫn có mặt trên MariaDB — và `CheckDeadlines::tierFor()` trả `d7` cho đúng ngày đó.
 * Một test so `upcoming(7) = overdue() ∪ dueWithin(7)` mà cả hai vế cùng sai vẫn xanh, nên tệp này
 * khẳng định ngày +7 TƯỜNG MINH và đối chiếu với `tierFor()`.
 *
 * Chạy trên SQLite (bộ thường) VÀ `test:mariadb` (tuần tự).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->travelTo(Carbon::parse('2026-10-31 15:30:00'));

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
});

/**
 * Một mốc chưa xong cho mỗi ngày từ `$from` tới `$to` (lệch so với hôm nay), khoá theo độ lệch.
 *
 * @return array<int, Deadline>
 */
function m13t2DeadlineRange(Matter $matter, int $from, int $to, array $attributes = []): array
{
    $deadlines = [];

    for ($offset = $from; $offset <= $to; $offset++) {
        $deadlines[$offset] = Deadline::factory()->for($matter)->create([
            'due_date' => today()->addDays($offset)->toDateString(),
            'responsible_user_id' => $matter->lead_lawyer_id,
            'is_completed' => false,
            ...$attributes,
        ]);
    }

    return $deadlines;
}

/** @return list<int> id đã sắp tăng dần */
function m13t2DeadlineIds(Builder $query): array
{
    return $query->pluck('deadlines.id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
}

/**
 * @param  array<int, Deadline>  $range
 * @return list<int>
 */
function m13t2RangeIds(array $range, int $from, int $to): array
{
    return collect($range)
        ->filter(fn (Deadline $deadline, int $offset): bool => $offset >= $from && $offset <= $to)
        ->map(fn (Deadline $deadline): int => $deadline->getKey())
        ->sort()
        ->values()
        ->all();
}

it('keeps a deadline due on day +7 in upcoming(7) and dueWithin(7), and drops the one due on day +8', function () {
    $range = m13t2DeadlineRange($this->matter, -3, 8);

    expect(m13t2DeadlineIds(Deadline::query()->upcoming(7)))->toBe(m13t2RangeIds($range, -3, 7))
        ->and(m13t2DeadlineIds(Deadline::query()->dueWithin(7)))->toBe(m13t2RangeIds($range, 0, 7))
        ->and(m13t2DeadlineIds(Deadline::query()->overdue()))->toBe(m13t2RangeIds($range, -3, -1))
        ->and(m13t2DeadlineIds(Deadline::query()->dueWithin(7)))->toContain($range[7]->getKey())
        ->and(m13t2DeadlineIds(Deadline::query()->upcoming(7)))->toContain($range[7]->getKey())
        ->and(m13t2DeadlineIds(Deadline::query()->upcoming(7)))->not->toContain($range[8]->getKey())
        ->and(Deadline::UPCOMING_WINDOW_DAYS)->toBe(7);
});

it('splits upcoming(7) into overdue() and dueWithin(7), two sets that never overlap', function () {
    $range = m13t2DeadlineRange($this->matter, -3, 8);

    // Mốc đã xong và mốc đã gỡ không thuộc vế nào.
    $doneOverdue = Deadline::factory()->for($this->matter)->create(['due_date' => today()->subDays(2)->toDateString(), 'is_completed' => true, 'completed_at' => now()]);
    $doneSoon = Deadline::factory()->for($this->matter)->create(['due_date' => today()->addDays(2)->toDateString(), 'is_completed' => true, 'completed_at' => now()]);
    $removed = Deadline::factory()->for($this->matter)->create(['due_date' => today()->subDays(1)->toDateString()]);
    $removed->delete();

    $upcoming = m13t2DeadlineIds(Deadline::query()->upcoming(7));
    $overdue = m13t2DeadlineIds(Deadline::query()->overdue());
    $dueWithin = m13t2DeadlineIds(Deadline::query()->dueWithin(7));

    $union = collect([...$overdue, ...$dueWithin])->sort()->values()->all();

    expect($union)->toBe($upcoming)
        ->and(array_intersect($overdue, $dueWithin))->toBe([])
        ->and([...$upcoming, ...$overdue, ...$dueWithin])->not->toContain($doneOverdue->getKey())
        ->and([...$upcoming, ...$overdue, ...$dueWithin])->not->toContain($doneSoon->getKey())
        ->and([...$upcoming, ...$overdue, ...$dueWithin])->not->toContain($removed->getKey())
        ->and($upcoming)->toBe(m13t2RangeIds($range, -3, 7));
});

/**
 * Review Focus 2: `overdue()` ↔ `tierFor() === OVERDUE_KEY`; `dueWithin(7)` ↔ `tierFor()` trả
 * `d1`/`d3`/`d7` (mốc hôm nay là `d1`). Chỉ đúng với mốc THƯỜNG: mốc `critical` có thêm bậc `d14`,
 * nên một mốc critical +10 có bậc mà không thuộc `dueWithin(7)` — ghim điều đó để không ai "sửa"
 * `dueWithin()` theo bậc của `tierFor()`.
 */
it('agrees with CheckDeadlines::tierFor() from three days overdue to eight days ahead', function () {
    $range = m13t2DeadlineRange($this->matter, -3, 8);
    $criticalFar = Deadline::factory()->for($this->matter)->critical()->create([
        'due_date' => today()->addDays(10)->toDateString(),
        'is_completed' => false,
    ]);

    $checker = app(CheckDeadlines::class);
    $all = Deadline::query()->get();

    $overdueByTier = $all->filter(fn (Deadline $deadline): bool => $checker->tierFor($deadline) === CheckDeadlines::OVERDUE_KEY)
        ->map(fn (Deadline $deadline): int => $deadline->getKey())->sort()->values()->all();
    $withinSevenByTier = $all->filter(fn (Deadline $deadline): bool => in_array($checker->tierFor($deadline), ['d1', 'd3', 'd7'], true))
        ->map(fn (Deadline $deadline): int => $deadline->getKey())->sort()->values()->all();

    expect(m13t2DeadlineIds(Deadline::query()->overdue()))->toBe($overdueByTier)
        ->and(m13t2DeadlineIds(Deadline::query()->dueWithin(7)))->toBe($withinSevenByTier)
        ->and($checker->tierFor($range[0]))->toBe('d1')
        ->and($checker->tierFor($range[7]))->toBe('d7')
        ->and($checker->tierFor($range[8]))->toBeNull()
        ->and($checker->tierFor($criticalFar))->toBe('d14')
        ->and(m13t2DeadlineIds(Deadline::query()->dueWithin(7)))->not->toContain($criticalFar->getKey());
});

/** Hai đầu của một ngày: 00:00:00 và 23:59:59 — mốc hôm nay không bao giờ là "quá hạn". */
it('moves a deadline from dueWithin() to overdue() exactly at midnight', function () {
    $this->travelTo(Carbon::parse('2026-10-31 23:59:59'));

    $today = Deadline::factory()->for($this->matter)->create(['due_date' => '2026-10-31']);
    $dayPlusSeven = Deadline::factory()->for($this->matter)->create(['due_date' => '2026-11-07']);
    $dayPlusEight = Deadline::factory()->for($this->matter)->create(['due_date' => '2026-11-08']);

    expect(m13t2DeadlineIds(Deadline::query()->overdue()))->not->toContain($today->getKey())
        ->and(m13t2DeadlineIds(Deadline::query()->dueWithin(7)))->toBe([$today->getKey(), $dayPlusSeven->getKey()]);

    $this->travelTo(Carbon::parse('2026-11-01 00:00:00'));

    expect(m13t2DeadlineIds(Deadline::query()->overdue()))->toBe([$today->getKey()])
        ->and(m13t2DeadlineIds(Deadline::query()->dueWithin(7)))->toBe([$dayPlusSeven->getKey(), $dayPlusEight->getKey()])
        ->and(m13t2DeadlineIds(Deadline::query()->upcoming(7)))->toBe([$today->getKey(), $dayPlusSeven->getKey(), $dayPlusEight->getKey()]);
});

/**
 * Bản sửa lỗi có chủ đích (SPEC §7.1, đính chính 2026-10-04): widget "Mốc thời hạn 7 ngày tới"
 * gồm mốc ngày +7 trên MỌI CSDL — đọc qua Livewire, không chỉ qua `rowsFor()`.
 */
it('shows the deadline due on day +7 on the upcoming deadlines widget, and not the one on day +8', function () {
    $range = m13t2DeadlineRange($this->matter, 6, 8);

    $this->actingAs($this->lawyer, 'web');

    $this->livewire(UpcomingDeadlinesWidget::class)
        ->assertCanSeeTableRecords([$range[6], $range[7]])
        ->assertCanNotSeeTableRecords([$range[8]]);

    expect(m13t2DeadlineIds(UpcomingDeadlinesWidget::rowsFor($this->lawyer)))->toBe(m13t2RangeIds($range, 6, 7))
        ->and(UpcomingDeadlinesWidget::WINDOW_DAYS)->toBe(Deadline::UPCOMING_WINDOW_DAYS);
});

it('takes a deadline due on the first and on the last day of the bounds in dueBetween(), and never a removed one', function () {
    $bounds = [Carbon::parse('2026-10-01')->startOfDay()->toDateTimeString(), Carbon::parse('2026-10-31')->endOfDay()->toDateTimeString()];

    $first = Deadline::factory()->for($this->matter)->create(['due_date' => '2026-10-01']);
    $last = Deadline::factory()->for($this->matter)->create(['due_date' => '2026-10-31']);
    $completedLast = Deadline::factory()->for($this->matter)->create(['due_date' => '2026-10-31', 'is_completed' => true, 'completed_at' => now()]);
    $before = Deadline::factory()->for($this->matter)->create(['due_date' => '2026-09-30']);
    $after = Deadline::factory()->for($this->matter)->create(['due_date' => '2026-11-01']);
    $removed = Deadline::factory()->for($this->matter)->create(['due_date' => '2026-10-15']);
    $removed->delete();

    $expected = [$first->getKey(), $last->getKey(), $completedLast->getKey()];

    expect(m13t2DeadlineIds(Deadline::query()->dueBetween($bounds)))->toBe($expected)
        // Gỡ `SoftDeletingScope` phía trên không kéo mốc đã gỡ vào kỳ: "đã gỡ" là P2, không phải P1.
        ->and(m13t2DeadlineIds(Deadline::query()->withTrashed()->dueBetween($bounds)))->toBe($expected)
        ->and(m13t2DeadlineIds(Deadline::query()->dueBetween($bounds)))->not->toContain($before->getKey())
        ->and(m13t2DeadlineIds(Deadline::query()->dueBetween($bounds)))->not->toContain($after->getKey());
});

it('takes a deadline removed at 23:59:59 on the last day in removedBetween(), and not one removed at midnight after it', function () {
    $bounds = [Carbon::parse('2026-10-01')->startOfDay()->toDateTimeString(), Carbon::parse('2026-10-31')->endOfDay()->toDateTimeString()];

    $kept = Deadline::factory()->for($this->matter)->create(['due_date' => '2026-10-20']);
    $removedFirstSecond = Deadline::factory()->for($this->matter)->create(['due_date' => '2026-10-20']);
    $removedLastSecond = Deadline::factory()->for($this->matter)->create(['due_date' => '2026-10-20']);
    $removedBefore = Deadline::factory()->for($this->matter)->create(['due_date' => '2026-10-20']);
    $removedAfter = Deadline::factory()->for($this->matter)->create(['due_date' => '2026-10-20']);

    $this->travelTo(Carbon::parse('2026-10-01 00:00:00'));
    $removedFirstSecond->delete();
    $this->travelTo(Carbon::parse('2026-10-31 23:59:59'));
    $removedLastSecond->delete();
    $this->travelTo(Carbon::parse('2026-09-30 23:59:59'));
    $removedBefore->delete();
    $this->travelTo(Carbon::parse('2026-11-01 00:00:00'));
    $removedAfter->delete();

    expect(m13t2DeadlineIds(Deadline::query()->removedBetween($bounds)))->toBe([$removedFirstSecond->getKey(), $removedLastSecond->getKey()])
        ->and(m13t2DeadlineIds(Deadline::query()->removedBetween($bounds)))->not->toContain($kept->getKey());
});

it('reads heldBy() as the person holding the deadline now', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();

    $held = Deadline::factory()->for($this->matter)->create(['responsible_user_id' => $assistant->id]);
    $notHeld = Deadline::factory()->for($this->matter)->create(['responsible_user_id' => $this->lawyer->id]);

    expect(m13t2DeadlineIds(Deadline::query()->heldBy($assistant)))->toBe([$held->getKey()])
        ->and(m13t2DeadlineIds(Deadline::query()->heldBy($this->lawyer)))->toBe([$notHeld->getKey()]);
});
