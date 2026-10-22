<?php

use App\Actions\Performance\BuildPerformanceReport;
use App\Enums\DeadlineOutcome;
use App\Enums\Role;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Performance\PerformancePeriod;
use App\Support\Performance\TeamRoster;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn perf2 — "Hiệu suất theo kỳ" mở lạnh một quý khoảng 1,3–1,4 giây trên dữ liệu
 * đo M13; phần lớn là dựng model (Laravel 13: khoảng 25 µs mỗi model), không phải SQL. P1 nạp vụ của mỗi mốc chỉ để
 * hỏi `closedOnOrBefore()` — chỉ vụ ĐÃ kết thúc mới đổi được đáp án — nên báo cáo không dựng model `Matter` nào cho
 * mốc của vụ còn mở, và con số không đổi (P1 trên vụ kết thúc trước hạn: `PerformanceFormulasTest`).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
});

it('builds P1 without loading the matters that are still open', function () {
    foreach (range(1, 4) as $n) {
        $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
        Deadline::factory()->create([
            'matter_id' => $matter->id, 'responsible_user_id' => $this->lawyer->id,
            'due_date' => "2026-09-0{$n}", 'is_completed' => false, 'created_at' => '2026-08-01 09:00:00',
        ]);
    }
    $closed = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id, 'closed_at' => '2026-09-02']);
    Deadline::factory()->create([
        'matter_id' => $closed->id, 'responsible_user_id' => $this->lawyer->id,
        'due_date' => '2026-09-05', 'is_completed' => false, 'created_at' => '2026-08-01 09:00:00',
    ]);

    $loaded = [];
    Event::listen('eloquent.retrieved: '.Matter::class, function (Matter $matter) use (&$loaded): void {
        $loaded[] = (int) $matter->getKey();
    });

    $viewer = $this->manager->fresh();
    $period = PerformancePeriod::fromFilters(['period' => 'last_month']);
    $report = app(BuildPerformanceReport::class)->handle($viewer, TeamRoster::subjectsForPeriod($viewer, $period), $period);

    // Bốn mốc lỡ trên vụ còn mở; mốc của vụ đã kết thúc trước hạn không tính.
    expect(array_values(array_unique($loaded)))->toBe([(int) $closed->id])
        ->and($report->rows[$this->lawyer->id]->deadlinesMissed)->toBe(4)
        ->and(Deadline::query()->with('matter')->where('matter_id', $closed->id)->first()->outcomeAt($period->cutoff()))->toBeNull()
        ->and(Deadline::query()->with('matter')->where('matter_id', '!=', $closed->id)->first()->outcomeAt($period->cutoff()))->toBe(DeadlineOutcome::Missed);
});
