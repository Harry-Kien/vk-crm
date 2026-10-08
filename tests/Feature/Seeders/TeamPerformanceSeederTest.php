<?php

use App\Actions\Matter\ReassignMatter;
use App\Enums\ClientRequestStatus;
use App\Enums\Confidentiality;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\PerformanceSnapshot;
use App\Models\StageLog;
use App\Models\User;
use App\Support\MatterStaleness;
use App\Support\Performance\PerformancePeriod;
use App\Support\Performance\TeamRoster;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\TeamPerformanceSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * M13 Task 8 — dữ liệu mẫu theo dõi đội ngũ (`TeamPerformanceSeeder`, gọi từ `DemoDataSeeder`, không bao
 * giờ ở production). Mỗi tình huống của kế hoạch (Task 8, "Dữ liệu mẫu") một khẳng định: luật sư nghỉ việc
 * bàn giao qua `ReassignMatter` thật, không thư (dòng lịch sử cho từng mốc và từng vụ), một vụ `restricted` của
 * luật sư A quá hạn cập nhật và có mốc quá hạn, yêu cầu nhanh/chậm/đóng không trả lời, dòng tiến độ trải
 * ba tháng, 90 ngày ảnh chụp giả. Số của từng trang trên dữ liệu này đo ở
 * `tests/Feature/Performance/DemoWalkthroughTest.php`. Hàm toàn cục mang tiền tố `m13t8Seed`.
 */
beforeEach(function () {
    // MatterSeeder ghi tệp PDF thật (xem DemoDataSeederTest).
    Storage::fake('private');

    $this->seed(DatabaseSeeder::class);
});

function m13t8SeedUser(string $email): User
{
    return User::query()->where('email', $email)->firstOrFail();
}

it('hands the matters of a lawyer who left after last month to another lawyer through ReassignMatter, then deactivates the account', function () {
    $departed = m13t8SeedUser(TeamPerformanceSeeder::DEPARTED_EMAIL);
    $receiver = m13t8SeedUser(TeamPerformanceSeeder::RECEIVER_EMAIL);

    $handovers = Activity::query()->where('event', 'matter_reassigned')
        ->where('properties->from_user_id', $departed->id)
        ->get();

    expect($departed->is_active)->toBeFalse()
        ->and(TeamRoster::isTrackable($departed))->toBeTrue()
        ->and($handovers)->toHaveCount(1)
        ->and($handovers->pluck('properties')->map(fn ($p) => $p['to_user_id'])->unique()->all())->toBe([$receiver->id])
        ->and(Matter::query()->where('lead_lawyer_id', $departed->id)->count())->toBe(0)
        // R9: một dòng cho từng mốc đã chuyển (mốc lỡ tháng trước và mốc tuần tới), cùng lý do của ReassignMatter.
        ->and(Activity::query()->where('event', 'deadline_responsible_changed')
            ->where('properties->reason', ReassignMatter::DEADLINE_HANDOVER_REASON)
            ->where('properties->from', $departed->id)->count())->toBe(2)
        // R3: lần vô hiệu hoá nằm trong nhật ký, sau tháng trước — người đó vẫn có dòng của tháng trước.
        ->and(Activity::query()->where('subject_type', $departed->getMorphClass())->where('subject_id', $departed->id)
            ->where('event', 'updated')->where('properties->attributes->is_active', false)->count())->toBe(1)
        ->and(TeamRoster::subjectsForPeriod(m13t8SeedUser('quanly@luatvukhang.com'), PerformancePeriod::fromFilters(['period' => 'last_month']))
            ->pluck('id'))->toContain($departed->id);
});

// Lượt quét trước bản 1.0 (rà soát gộp M13 vào main): dữ liệu mẫu không gửi thư thật. Bàn giao qua
// `ReassignMatters` (cách cũ của seeder) luôn xếp `SendReassignmentDigest` ở `finally{}`; hàng đợi của bộ
// test là `sync`, nên job chạy ngay trong lúc seed và để lại dòng nhật ký thư `staff.matter_reassigned`
// (cùng điều `BillingSeeder` tránh bằng `sendDigest: false`).
it('hands over the departed lawyer\'s matter without queueing a real reassignment mail', function () {
    $receiver = m13t8SeedUser(TeamPerformanceSeeder::RECEIVER_EMAIL);

    expect(Activity::query()->where('event', 'matter_reassigned')->where('properties->to_user_id', $receiver->id)->exists())->toBeTrue()
        ->and(OutboundMessage::query()->withoutGlobalScopes()->where('template', 'staff.matter_reassigned')->count())->toBe(0);
});

it('leaves the departed lawyer one missed deadline of last month, now held by the receiver, and unassigned threads he answered', function () {
    $departed = m13t8SeedUser(TeamPerformanceSeeder::DEPARTED_EMAIL);
    $receiver = m13t8SeedUser(TeamPerformanceSeeder::RECEIVER_EMAIL);
    $bounds = PerformancePeriod::fromFilters(['period' => 'last_month'])->bounds();
    $handedOver = Activity::query()->where('event', 'matter_reassigned')->where('properties->from_user_id', $departed->id)->pluck('subject_id');

    $missed = Deadline::query()->whereIn('matter_id', $handedOver)->whereBetween('due_date', $bounds)->where('is_completed', false)->get();
    $threads = ClientRequest::query()->whereIn('matter_id', $handedOver)->whereBetween('created_at', $bounds)->get();

    expect($missed)->toHaveCount(1)
        ->and($missed->first()->responsible_user_id)->toBe($receiver->id)
        ->and($threads->whereNull('assigned_to'))->toHaveCount($threads->count())
        ->and($threads->whereNotNull('answered_at')->count())->toBe(2)
        ->and($threads->whereNull('answered_at')->count())->toBe(1);
});

it('gives lawyer A a restricted matter, published to the portal, overdue for a client update and holding an overdue deadline', function () {
    $lawyerA = m13t8SeedUser(TeamPerformanceSeeder::LAWYER_A_EMAIL);

    $restricted = Matter::query()->where('confidentiality', Confidentiality::Restricted->value)
        ->where('lead_lawyer_id', $lawyerA->id)->where('is_published_to_portal', true)->sole();

    expect(MatterStaleness::scopeStale(Matter::query())->whereKey($restricted->id)->exists())->toBeTrue()
        ->and(Deadline::query()->overdue()->where('matter_id', $restricted->id)->where('responsible_user_id', $lawyerA->id)->exists())->toBeTrue()
        // A có đúng 2 vụ THƯỜNG quá hạn cập nhật (vụ 1 của MatterSeeder và vụ mẫu M13): trưởng phòng thấy 2, A thấy 3.
        ->and(MatterStaleness::scopeStale(Matter::query())->where('lead_lawyer_id', $lawyerA->id)
            ->where('confidentiality', Confidentiality::Normal->value)->count())->toBe(2);
});

it('answers client requests fast and slow, and closes one without an answer', function () {
    $bounds = PerformancePeriod::fromFilters(['period' => 'last_month'])->bounds();
    $lastMonth = ClientRequest::query()->whereBetween('created_at', $bounds)->get();

    $hours = $lastMonth->whereNotNull('answered_at')
        ->map(fn (ClientRequest $r): float => $r->created_at->diffInMinutes($r->answered_at) / 60);

    expect($lastMonth->filter(fn (ClientRequest $r): bool => $r->isClosedWithoutAnswer()))->toHaveCount(1)
        ->and($lastMonth->firstWhere(fn (ClientRequest $r): bool => $r->isClosedWithoutAnswer())->status)->toBe(ClientRequestStatus::Closed)
        ->and($hours->min())->toBeLessThan(2.0)
        ->and($hours->max())->toBeGreaterThan(24.0)
        // Gửi và trả lời trong ngày làm việc: thời gian phản hồi đo bằng giờ làm việc (R17), một yêu cầu gửi Chủ nhật
        // và trả lời ngay Chủ nhật là 0 giờ — không phải "trả lời nhanh".
        ->and($lastMonth->every(fn (ClientRequest $r): bool => ! $r->created_at->isWeekend() && ($r->answered_at === null || ! $r->answered_at->isWeekend())))->toBeTrue();
});

it('spreads stage entries written by the lawyers over three calendar months', function () {
    $months = StageLog::query()->entries()
        ->whereIn('created_by', User::query()->whereIn('email', TeamPerformanceSeeder::lawyerEmails())->select('id'))
        ->pluck('occurred_at')
        ->map(fn ($d) => $d->format('Y-m'))
        ->unique()->sort()->values();

    expect($months->all())->toContain(today()->format('Y-m'))
        ->toContain(today()->startOfMonth()->subMonthNoOverflow()->format('Y-m'))
        ->toContain(today()->startOfMonth()->subMonthsNoOverflow(2)->format('Y-m'));
});

it('writes ninety days of sample snapshots ending yesterday: a normal row for every tracked member, the departed lawyer included, restricted rows only for the restricted lead', function () {
    $members = TeamRoster::members(includeInactive: true);
    $lawyerA = m13t8SeedUser(TeamPerformanceSeeder::LAWYER_A_EMAIL);
    $normal = PerformanceSnapshot::query()->where('confidentiality', Confidentiality::Normal->value)->get();
    $restricted = PerformanceSnapshot::query()->where('confidentiality', Confidentiality::Restricted->value)->get();

    expect($members->pluck('email'))->toContain(TeamPerformanceSeeder::DEPARTED_EMAIL)
        ->and($normal->pluck('user_id')->unique()->sort()->values()->all())->toBe(collect($members->modelKeys())->sort()->values()->all())
        ->and($normal->groupBy('user_id')->map->count()->unique()->values()->all())->toBe([90])
        ->and($normal->max('captured_on')->toDateString())->toBe(today()->subDay()->toDateString())
        ->and($normal->min('captured_on')->toDateString())->toBe(today()->subDays(90)->toDateString())
        ->and($restricted->pluck('user_id')->unique()->all())->toBe([$lawyerA->id])
        ->and($restricted->every(fn (PerformanceSnapshot $s): bool => $s->stale_matters + $s->overdue_deadlines + $s->open_lead_matters + $s->checklist_total > 0))->toBeTrue();
});

it('adds nothing when the demo seeder runs again', function () {
    $count = fn (): array => [User::withTrashed()->count(), Matter::withTrashed()->count(), Deadline::withTrashed()->count(),
        ClientRequest::query()->count(), StageLog::query()->count(), PerformanceSnapshot::query()->count(), Activity::query()->where('event', 'matter_reassigned')->count()];
    $before = $count();

    $this->seed(DemoDataSeeder::class);

    expect($count())->toBe($before);
});
