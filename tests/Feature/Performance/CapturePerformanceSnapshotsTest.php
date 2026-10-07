<?php

use App\Actions\Performance\BuildTeamWorkload;
use App\Actions\Schedule\CapturePerformanceSnapshots;
use App\Enums\ChecklistItemStatus;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\PerformanceSnapshot;
use App\Models\User;
use App\Support\Performance\TeamWorkloadRow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M13 Task 7 — tác vụ chụp hằng ngày (R10): `CapturePerformanceSnapshots` gọi ĐÚNG các luật N1, N4, N5,
 * N10 trên `Matter::query()` (không `listableBy`: không người đăng nhập), tách theo `confidentiality` qua
 * `Matter::scopeOfConfidentiality()`, ghi một dòng `normal` cho MỌI người đang được theo dõi và một dòng
 * `restricted` khi có số > 0, `upsert` theo (ngày, người, loại), xoá dòng quá 25 tháng.
 *
 * Test đồng nhất: chạy tác vụ ở một thời điểm đóng băng rồi so với số TRỰC TIẾP (`BuildTeamWorkload`) ở
 * cùng thời điểm — dòng `normal` của X bằng số trưởng phòng đọc về X; `normal` + `restricted` bằng số X và
 * admin đọc.
 *
 * Hàm toàn cục mang tiền tố `m13t7Cap`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon::parse('2026-10-14 23:50:00'));

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản Trị']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng']);
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư X']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Z']);
});

/**
 * Một vụ có việc cho MỌI số được chụp: đang mở, đã bật cổng, quá hạn cập nhật (20 ngày), hai mốc quá
 * hạn (một của `$holder`), một mốc chưa tới hạn, ba đầu mục (một đã duyệt, hai còn thiếu).
 */
function m13t7CapMatter(User $lead, ?Confidentiality $level = null, ?User $holder = null): Matter
{
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lead->id,
        'confidentiality' => $level ?? Confidentiality::Normal,
        'last_client_update_at' => now()->subDays(20),
    ]);

    if ($holder !== null) {
        $matter->addTeamMember($holder, MatterRole::Assistant);
    }

    Deadline::factory()->for($matter)->create(['responsible_user_id' => $lead->id, 'due_date' => today()->subDays(3)->toDateString()]);
    Deadline::factory()->for($matter)->create(['responsible_user_id' => ($holder ?? $lead)->id, 'due_date' => today()->subDay()->toDateString()]);
    Deadline::factory()->for($matter)->create(['responsible_user_id' => $lead->id, 'due_date' => today()->addDays(2)->toDateString()]);

    MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Accepted)->create(['is_required' => true]);
    MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Missing)->create(['is_required' => true]);
    MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Missing)->create(['is_required' => true]);

    return $matter;
}

/** Thế giới cho test đồng nhất: X có vụ thường VÀ vụ restricted; một vụ đã kết thúc và một vụ đã huỷ không tính. */
function m13t7CapWorld(User $lawyer, User $assistant): void
{
    m13t7CapMatter($lawyer, Confidentiality::Normal, $assistant);
    m13t7CapMatter($lawyer, Confidentiality::Normal);
    m13t7CapMatter($lawyer, Confidentiality::Restricted);
    m13t7CapMatter($lawyer)->forceFill(['closed_at' => today()->subDays(2)])->save();
    m13t7CapMatter($lawyer, Confidentiality::Restricted)->delete();
    Matter::factory()->unpublished()->create(['lead_lawyer_id' => $lawyer->id]);
}

/** @return array{open_lead_matters: int, stale_matters: int, overdue_deadlines: int, checklist_settled: int, checklist_total: int} */
function m13t7CapLive(User $viewer, User $subject): array
{
    /** @var TeamWorkloadRow $row */
    $row = app(BuildTeamWorkload::class)->handle($viewer->fresh(), collect([$subject->fresh()->load(['roles.permissions', 'permissions'])]))[$subject->id];

    return [
        'open_lead_matters' => (int) $row->leadOpen,
        'stale_matters' => (int) $row->stale,
        'overdue_deadlines' => $row->overdueDeadlines,
        'checklist_settled' => (int) $row->checklistSettled,
        'checklist_total' => (int) $row->checklistTotal,
    ];
}

/** @return array<string, int>|null năm số của một dòng, hoặc null khi dòng không có */
function m13t7CapRow(User $person, Confidentiality $level, string $day = '2026-10-14'): ?array
{
    $row = PerformanceSnapshot::query()
        ->where('user_id', $person->id)
        ->where('confidentiality', $level->value)
        ->whereDate('captured_on', $day)
        ->first();

    return $row?->only(['open_lead_matters', 'stale_matters', 'overdue_deadlines', 'checklist_settled', 'checklist_total']);
}

/** @param  array<string, int>  ...$rows */
function m13t7CapSum(array ...$rows): array
{
    $sum = [];

    foreach ($rows as $row) {
        foreach ($row as $key => $value) {
            $sum[$key] = ($sum[$key] ?? 0) + $value;
        }
    }

    return $sum;
}

it('writes for X a normal row equal to what the manager reads live, and normal plus restricted equal to what X and the admin read', function () {
    m13t7CapWorld($this->lawyer, $this->assistant);

    app(CapturePerformanceSnapshots::class)->handle();

    $normal = m13t7CapRow($this->lawyer, Confidentiality::Normal);
    $restricted = m13t7CapRow($this->lawyer, Confidentiality::Restricted);

    expect($normal)->toBe(m13t7CapLive($this->manager, $this->lawyer))
        ->and(m13t7CapSum($normal, $restricted))->toBe(m13t7CapLive($this->lawyer, $this->lawyer))
        ->and(m13t7CapSum($normal, $restricted))->toBe(m13t7CapLive($this->admin, $this->lawyer))
        // Không phải "bằng nhau vì cùng 0": mỗi dòng mang số thật ở mọi cột.
        // N1 thường = hai vụ đã bật cổng + vụ chưa bật cổng; vụ đã kết thúc và vụ đã huỷ không tính.
        ->and($normal)->toBe(['open_lead_matters' => 3, 'stale_matters' => 2, 'overdue_deadlines' => 3, 'checklist_settled' => 2, 'checklist_total' => 6])
        ->and($restricted)->toBe(['open_lead_matters' => 1, 'stale_matters' => 1, 'overdue_deadlines' => 2, 'checklist_settled' => 1, 'checklist_total' => 3]);
});

it('writes for an assistant the deadlines they hold, the same number the manager reads, and zero on the lead-only counts', function () {
    m13t7CapWorld($this->lawyer, $this->assistant);

    app(CapturePerformanceSnapshots::class)->handle();

    expect(m13t7CapRow($this->assistant, Confidentiality::Normal))->toBe([
        'open_lead_matters' => 0,
        'stale_matters' => 0,
        'overdue_deadlines' => m13t7CapLive($this->manager, $this->assistant)['overdue_deadlines'],
        'checklist_settled' => 0,
        'checklist_total' => 0,
    ])
        ->and(m13t7CapRow($this->assistant, Confidentiality::Normal)['overdue_deadlines'])->toBe(1)
        ->and(m13t7CapRow($this->assistant, Confidentiality::Restricted))->toBeNull();
});

it('always writes the normal row, zeros included, and the restricted row only when one of its numbers is above zero', function () {
    $idle = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Rảnh']);
    $normalOnly = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Thường']);
    m13t7CapMatter($normalOnly);
    // Một vụ restricted đang mở mà mọi số khác 0 chỉ ở N1: vẫn là "có số > 0".
    Matter::factory()->restricted()->unpublished()->create(['lead_lawyer_id' => $idle->id]);

    $written = app(CapturePerformanceSnapshots::class)->handle();

    expect(m13t7CapRow($idle, Confidentiality::Normal))->toBe(['open_lead_matters' => 0, 'stale_matters' => 0, 'overdue_deadlines' => 0, 'checklist_settled' => 0, 'checklist_total' => 0])
        ->and(m13t7CapRow($idle, Confidentiality::Restricted))->toBe(['open_lead_matters' => 1, 'stale_matters' => 0, 'overdue_deadlines' => 0, 'checklist_settled' => 0, 'checklist_total' => 0])
        ->and(m13t7CapRow($normalOnly, Confidentiality::Restricted))->toBeNull()
        ->and(m13t7CapRow($this->manager, Confidentiality::Normal))->not->toBeNull()
        // Người được theo dõi: quản lý, luật sư X, trợ lý Z, hai luật sư mới — năm dòng normal, một restricted.
        ->and($written)->toBe(6)
        ->and(PerformanceSnapshot::query()->count())->toBe(6);
});

it('captures only the people on the roster: no admin, no accountant, no deactivated or soft-deleted person', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $left = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $deleted = User::factory()->withRole(Role::Lawyer)->create();
    m13t7CapMatter($left);
    m13t7CapMatter($deleted);
    m13t7CapMatter($this->admin);
    $deleted->delete();

    app(CapturePerformanceSnapshots::class)->handle();

    expect(PerformanceSnapshot::query()->pluck('user_id')->unique()->sort()->values()->all())
        ->toBe(collect([$this->manager->id, $this->lawyer->id, $this->assistant->id])->sort()->values()->all())
        ->and(PerformanceSnapshot::query()->whereIn('user_id', [$accountant->id, $left->id, $deleted->id, $this->admin->id])->count())->toBe(0);
});

it('keeps the old rows of someone who left, and writes them no new row', function () {
    m13t7CapMatter($this->lawyer);
    app(CapturePerformanceSnapshots::class)->handle();

    $this->lawyer->forceFill(['is_active' => false])->save();
    $this->travelTo(Carbon::parse('2026-10-15 23:50:00'));
    app(CapturePerformanceSnapshots::class)->handle();

    expect(m13t7CapRow($this->lawyer, Confidentiality::Normal, '2026-10-14'))->not->toBeNull()
        ->and(m13t7CapRow($this->lawyer, Confidentiality::Normal, '2026-10-15'))->toBeNull()
        ->and(m13t7CapRow($this->assistant, Confidentiality::Normal, '2026-10-15'))->not->toBeNull();
});

it('keeps one row per day, person and level when run twice the same day, and the later numbers win', function () {
    $matter = m13t7CapMatter($this->lawyer);
    $secret = m13t7CapMatter($this->lawyer, Confidentiality::Restricted);

    $first = app(CapturePerformanceSnapshots::class)->handle();
    $rows = PerformanceSnapshot::query()->count();

    m13t7CapMatter($this->lawyer);
    $this->travelTo(Carbon::parse('2026-10-14 23:55:00'));
    $second = app(CapturePerformanceSnapshots::class)->handle();

    expect(PerformanceSnapshot::query()->count())->toBe($rows)
        ->and($second)->toBe($first)
        ->and(m13t7CapRow($this->lawyer, Confidentiality::Normal)['open_lead_matters'])->toBe(2)
        ->and(m13t7CapRow($this->lawyer, Confidentiality::Restricted)['open_lead_matters'])->toBe(1);

    // Cùng ngày, vụ restricted nay đã kết thúc và mọi số restricted về 0: dòng restricted của ngày đó đi
    // theo số mới — không còn — chứ không giữ số của lần chụp trước.
    $secret->forceFill(['closed_at' => today()])->save();
    $this->travelTo(Carbon::parse('2026-10-14 23:58:00'));
    app(CapturePerformanceSnapshots::class)->handle();

    expect(m13t7CapRow($this->lawyer, Confidentiality::Restricted))->toBeNull()
        ->and(m13t7CapRow($this->lawyer, Confidentiality::Normal)['open_lead_matters'])->toBe(2)
        ->and($matter->exists)->toBeTrue();
});

it('dates the capture by the office time zone: 23:50 on D is D, and so is 06:00 on D (still D − 1 in UTC)', function () {
    expect(config('app.timezone'))->toBe('Asia/Ho_Chi_Minh');

    $this->travelTo(Carbon::parse('2026-10-20 23:50:00', 'Asia/Ho_Chi_Minh'));
    app(CapturePerformanceSnapshots::class)->handle();

    $this->travelTo(Carbon::parse('2026-10-22 06:00:00', 'Asia/Ho_Chi_Minh'));
    app(CapturePerformanceSnapshots::class)->handle();

    expect(PerformanceSnapshot::query()->where('user_id', $this->lawyer->id)->get()->map(fn (PerformanceSnapshot $row): string => $row->captured_on->toDateString())->sort()->values()->all())
        ->toBe(['2026-10-20', '2026-10-22']);
});

it('deletes rows older than 25 months in the same run, and keeps the row of exactly 25 months ago', function () {
    $old = fn (string $day, User $person) => PerformanceSnapshot::query()->create([
        'captured_on' => $day, 'user_id' => $person->id, 'confidentiality' => Confidentiality::Normal,
        'open_lead_matters' => 1, 'stale_matters' => 0, 'overdue_deadlines' => 0, 'checklist_settled' => 0, 'checklist_total' => 0,
    ]);
    $left = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);

    $tooOld = $old('2024-09-13', $this->lawyer);
    $tooOldOfSomeoneWhoLeft = $old('2024-09-13', $left);
    $kept = $old('2024-09-14', $this->lawyer);

    expect(PerformanceSnapshot::KEEP_MONTHS)->toBe(25)
        ->and(PerformanceSnapshot::keptFrom()->toDateString())->toBe('2024-09-14');

    app(CapturePerformanceSnapshots::class)->handle();

    expect(PerformanceSnapshot::query()->whereKey($tooOld->id)->exists())->toBeFalse()
        ->and(PerformanceSnapshot::query()->whereKey($tooOldOfSomeoneWhoLeft->id)->exists())->toBeFalse()
        ->and(PerformanceSnapshot::query()->whereKey($kept->id)->exists())->toBeTrue();
});

it('runs the same number of queries for 3 and for 12 people on the roster', function () {
    $count = function (): int {
        app(CapturePerformanceSnapshots::class)->handle();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(CapturePerformanceSnapshots::class)->handle();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    m13t7CapMatter($this->lawyer, Confidentiality::Restricted);
    $three = $count();

    foreach (range(1, 9) as $i) {
        m13t7CapMatter(User::factory()->withRole(Role::Lawyer)->create(['name' => "Luật Sư {$i}"]), $i % 2 === 0 ? Confidentiality::Restricted : null);
    }

    expect($count())->toBe($three);
});

it('is the scheduled callable: invoking it captures', function () {
    (new CapturePerformanceSnapshots)();

    expect(PerformanceSnapshot::query()->count())->toBe(3);
});
