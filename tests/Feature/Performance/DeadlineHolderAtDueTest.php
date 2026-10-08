<?php

use App\Actions\Deadline\ChangeDeadlineResponsible;
use App\Actions\Deadline\SetDeadlineCompletion;
use App\Actions\Deadline\UpdateDeadline;
use App\Actions\Matter\ReassignMatter;
use App\Actions\Matter\ReassignMatters;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use App\Support\Performance\DeadlineHolderAtDue;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/**
 * M13 Task 3 — `DeadlineHolderAtDue` (R9): người giữ mốc VÀO NGÀY ĐẾN HẠN, dựng lại từ MỘT khoá sự
 * kiện `deadline_responsible_changed`, và năm đường đổi người giữ mốc đều ghi khoá đó.
 *
 * Mốc mặc định đến hạn 10/09/2026 (hết ngày đến hạn: 10/09 23:59:59). Mọi lần bàn giao "sau hạn"
 * xảy ra 15/09; "trước hạn" là 05/09. Hàm tiện ích mang tiền tố `m13bDh` (làn m13b, tệp này).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();

    $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $this->newLead = User::factory()->withRole(Role::Lawyer)->create();
    $this->associate = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id]);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);
});

/** Một mốc chưa xong, đến hạn `$due`, do `$holder` giữ. */
function m13bDhDeadline(Matter $matter, User $holder, string $due = '2026-09-10', array $attributes = []): Deadline
{
    return Deadline::factory()->for($matter)->create([
        'due_date' => $due,
        'responsible_user_id' => $holder->id,
        'is_completed' => false,
        'completed_at' => null,
        ...$attributes,
    ]);
}

/**
 * Người giữ vào ngày đến hạn của các mốc, đọc lại từ CSDL (không tin đối tượng trong bộ nhớ).
 *
 * @return array<int, ?int>
 */
function m13bDhHolders(Deadline ...$deadlines): array
{
    return DeadlineHolderAtDue::resolve(
        Deadline::query()->whereKey(array_map(fn (Deadline $deadline): int => $deadline->id, $deadlines))->get(),
    );
}

/** Bàn giao MỘT vụ qua đúng Action của nút "Bàn giao" (không thư: hàng đợi giả). */
function m13bDhReassign(Matter $matter, User $actor, User $newLead): void
{
    app(ReassignMatter::class)->handle(
        matter: $matter->fresh(),
        actor: $actor,
        newLead: $newLead,
        reason: 'Luật sư cũ nghỉ việc.',
        keepOldLeadAsAssociate: false,
    );
}

// =========================================================================================
// Lỡ rồi mới bàn giao — qua từng đường trong năm đường: người giữ vào ngày đến hạn là người TRƯỚC.
// =========================================================================================

it('keeps a missed deadline with the old lead when ReassignMatter hands the matter over after the due day', function () {
    $missed = m13bDhDeadline($this->matter, $this->oldLead);

    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
    m13bDhReassign($this->matter, $this->admin, $this->newLead);

    expect($missed->fresh()->responsible_user_id)->toBe($this->newLead->id)
        ->and(m13bDhHolders($missed))->toBe([$missed->id => $this->oldLead->id]);
});

it('keeps a missed deadline with the old lead when ReassignMatters hands a batch over after the due day', function () {
    $other = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id]);
    $missedHere = m13bDhDeadline($this->matter, $this->oldLead);
    $missedThere = m13bDhDeadline($other, $this->oldLead, '2026-09-12');

    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
    $results = app(ReassignMatters::class)->handle(
        matterIds: [$this->matter->id, $other->id],
        actor: $this->admin,
        newLead: $this->newLead,
        reason: 'Nghỉ việc, bàn giao cả lô.',
        keepOldLeadAsAssociate: false,
        expectedLeadId: $this->oldLead->id,
    );

    expect(collect($results)->every(fn ($result): bool => $result->success))->toBeTrue()
        ->and($missedHere->fresh()->responsible_user_id)->toBe($this->newLead->id)
        ->and(m13bDhHolders($missedHere, $missedThere))->toBe([
            $missedHere->id => $this->oldLead->id,
            $missedThere->id => $this->oldLead->id,
        ]);
});

it('keeps a missed deadline with the previous holder when ChangeDeadlineResponsible moves it after the due day', function () {
    $missed = m13bDhDeadline($this->matter, $this->associate);

    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
    app(ChangeDeadlineResponsible::class)->handle($missed->fresh(), $this->admin, $this->oldLead);

    expect($missed->fresh()->responsible_user_id)->toBe($this->oldLead->id)
        ->and(m13bDhHolders($missed))->toBe([$missed->id => $this->associate->id]);
});

it('keeps a missed deadline with the previous holder when UpdateDeadline changes the holder after the due day', function () {
    $missed = m13bDhDeadline($this->matter, $this->associate);

    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
    app(UpdateDeadline::class)->handle(
        deadline: $missed->fresh(),
        actor: $this->admin,
        name: $missed->name,
        dueDate: '2026-09-10',
        severity: $missed->severity,
        responsible: $this->oldLead,
    );

    expect($missed->fresh()->responsible_user_id)->toBe($this->oldLead->id)
        ->and(m13bDhHolders($missed))->toBe([$missed->id => $this->associate->id]);
});

it('keeps a deadline reopened after its due day with the holder who had it, when the reopen hands it to the lead', function () {
    $completed = m13bDhDeadline($this->matter, $this->associate, attributes: [
        'is_completed' => true,
        'completed_at' => '2026-09-09 16:00:00',
    ]);
    $this->associate->update(['is_active' => false]);

    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
    app(SetDeadlineCompletion::class)->handle($completed->fresh(), false, $this->oldLead);

    expect($completed->fresh()->responsible_user_id)->toBe($this->oldLead->id)
        ->and(m13bDhHolders($completed))->toBe([$completed->id => $this->associate->id]);
});

// =========================================================================================
// Bàn giao TRƯỚC ngày đến hạn: người SAU.
// =========================================================================================

it('gives a deadline handed over before its due day to the new holder, on every path', function () {
    $viaReassign = m13bDhDeadline($this->matter, $this->oldLead);
    $viaChange = m13bDhDeadline($this->matter, $this->associate);
    $viaUpdate = m13bDhDeadline($this->matter, $this->associate);

    $this->travelTo(Carbon::parse('2026-09-05 10:00:00'));
    app(ChangeDeadlineResponsible::class)->handle($viaChange->fresh(), $this->admin, $this->oldLead);
    app(UpdateDeadline::class)->handle(
        deadline: $viaUpdate->fresh(),
        actor: $this->admin,
        name: $viaUpdate->name,
        dueDate: '2026-09-10',
        severity: $viaUpdate->severity,
        responsible: $this->oldLead,
    );
    m13bDhReassign($this->matter, $this->admin, $this->newLead);

    expect(m13bDhHolders($viaReassign, $viaChange, $viaUpdate))->toBe([
        $viaReassign->id => $this->newLead->id,
        // `oldLead` nhận mốc lúc 10:00 rồi `ReassignMatter` cùng lúc chuyển tiếp sang `newLead`.
        $viaChange->id => $this->newLead->id,
        $viaUpdate->id => $this->newLead->id,
    ]);
});

it('keeps a reopen handover before the due day with the lead who received it', function () {
    $completed = m13bDhDeadline($this->matter, $this->associate, '2026-09-20', [
        'is_completed' => true,
        'completed_at' => '2026-09-02 16:00:00',
    ]);
    $this->associate->update(['is_active' => false]);

    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
    app(SetDeadlineCompletion::class)->handle($completed->fresh(), false, $this->oldLead);

    expect(m13bDhHolders($completed))->toBe([$completed->id => $this->oldLead->id]);
});

// =========================================================================================
// Hai lần đổi sau hạn, biên giây, dữ liệu cũ, dữ liệu hỏng.
// =========================================================================================

it('reads the from of the earliest change after the due day when there are two', function () {
    $missed = m13bDhDeadline($this->matter, $this->associate);

    $this->travelTo(Carbon::parse('2026-09-12 10:00:00'));
    app(ChangeDeadlineResponsible::class)->handle($missed->fresh(), $this->admin, $this->oldLead);

    $this->travelTo(Carbon::parse('2026-09-20 10:00:00'));
    m13bDhReassign($this->matter, $this->admin, $this->newLead);

    expect($missed->fresh()->responsible_user_id)->toBe($this->newLead->id)
        ->and(m13bDhHolders($missed))->toBe([$missed->id => $this->associate->id]);
});

it('counts a change at 23:59:59 of the due day as before the due day, and one at 00:00:01 the next day as after', function () {
    $changedOnTheDay = m13bDhDeadline($this->matter, $this->associate);
    $changedNextDay = m13bDhDeadline($this->matter, $this->associate);

    $this->travelTo(Carbon::parse('2026-09-10 23:59:59'));
    app(ChangeDeadlineResponsible::class)->handle($changedOnTheDay->fresh(), $this->admin, $this->oldLead);

    $this->travelTo(Carbon::parse('2026-09-11 00:00:01'));
    app(ChangeDeadlineResponsible::class)->handle($changedNextDay->fresh(), $this->admin, $this->oldLead);

    expect(m13bDhHolders($changedOnTheDay, $changedNextDay))->toBe([
        $changedOnTheDay->id => $this->oldLead->id,
        $changedNextDay->id => $this->associate->id,
    ]);
});

it('falls back to the current holder when a deadline has no history row (data before M13)', function () {
    $legacy = m13bDhDeadline($this->matter, $this->oldLead);

    // Một lần bàn giao kiểu cũ: cột đổi, không dòng lịch sử nào.
    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
    DB::table('deadlines')->where('id', $legacy->id)->update(['responsible_user_id' => $this->newLead->id]);

    expect(m13bDhHolders($legacy))->toBe([$legacy->id => $this->newLead->id]);
});

it('attributes a deadline to nobody when the history row after the due day has an empty or non-numeric from', function () {
    $emptyFrom = m13bDhDeadline($this->matter, $this->newLead);
    $textFrom = m13bDhDeadline($this->matter, $this->newLead);
    $healthy = m13bDhDeadline($this->matter, $this->newLead);

    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
    Audit::record(DeadlineHolderAtDue::EVENT, $emptyFrom, ['from' => null, 'to' => $this->newLead->id], $this->admin);
    Audit::record(DeadlineHolderAtDue::EVENT, $textFrom, ['from' => 'luật sư cũ', 'to' => $this->newLead->id], $this->admin);
    Audit::record(DeadlineHolderAtDue::EVENT, $healthy, ['from' => (string) $this->oldLead->id, 'to' => $this->newLead->id], $this->admin);

    expect(m13bDhHolders($emptyFrom, $textFrom, $healthy))->toBe([
        $emptyFrom->id => null,
        $textFrom->id => null,
        // Một id ghi dạng chuỗi số vẫn là một id.
        $healthy->id => $this->oldLead->id,
    ]);
});

it('reads only deadline_responsible_changed rows whose subject is that deadline', function () {
    $missed = m13bDhDeadline($this->matter, $this->newLead);

    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
    // Cùng id, khác loại chủ thể: dòng của một vụ việc có id trùng id của mốc.
    Activity::query()->create([
        'log_name' => 'default',
        'description' => DeadlineHolderAtDue::EVENT,
        'event' => DeadlineHolderAtDue::EVENT,
        'subject_type' => (new Matter)->getMorphClass(),
        'subject_id' => $missed->id,
        'properties' => ['from' => $this->associate->id, 'to' => $this->newLead->id],
    ]);
    // Cùng chủ thể, khác sự kiện.
    Audit::record('deadline_updated', $missed, ['from' => $this->associate->id, 'to' => $this->newLead->id], $this->admin);

    expect(m13bDhHolders($missed))->toBe([$missed->id => $this->newLead->id]);
});

/** R11: "một truy vấn cho cả lô, qua index morph `subject` của `activity_log`" — ghim hình dạng của nó. */
it('reads the batch through the subject morph index: event, subject type and exactly the batch ids', function () {
    $first = m13bDhDeadline($this->matter, $this->associate);
    $second = m13bDhDeadline($this->matter, $this->associate);
    $batch = Deadline::query()->whereKey([$first->id, $second->id])->orderBy('id')->get();

    DB::enableQueryLog();
    DeadlineHolderAtDue::resolve($batch);
    [$query] = DB::getQueryLog();

    expect($query['query'])->toMatch('/from ["`]activity_log["`] where ["`]event["`] = \? and ["`]subject_type["`] = \? and ["`]subject_id["`] in \('.$first->id.', '.$second->id.'\)/')
        ->and($query['bindings'])->toBe([DeadlineHolderAtDue::EVENT, 'deadline']);
});

it('returns an empty map for an empty batch without touching the database', function () {
    DB::enableQueryLog();

    expect(DeadlineHolderAtDue::resolve(collect()))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

it('reads the history of a whole batch in one query, whether it holds 3 deadlines or 30', function () {
    $queriesFor = function (int $count): int {
        $deadlines = collect(range(1, $count))->map(fn (): Deadline => m13bDhDeadline($this->matter, $this->associate));

        $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
        $deadlines->each(fn (Deadline $deadline) => app(ChangeDeadlineResponsible::class)->handle($deadline->fresh(), $this->admin, $this->oldLead));
        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));

        $batch = Deadline::query()->whereKey($deadlines->pluck('id'))->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $holders = DeadlineHolderAtDue::resolve($batch);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect(array_unique(array_values($holders)))->toBe([$this->associate->id]);

        return $queries;
    };

    expect($queriesFor(3))->toBe(1)
        ->and($queriesFor(30))->toBe(1);
});

// =========================================================================================
// Đường ghi: ReassignMatter bước 3 và UpdateDeadline.
// =========================================================================================

it('writes one deadline_responsible_changed row per moved deadline in ReassignMatter, and none for a deadline it does not move', function () {
    $movedOne = m13bDhDeadline($this->matter, $this->oldLead);
    $movedTwo = m13bDhDeadline($this->matter, $this->oldLead, '2026-09-20');
    $done = m13bDhDeadline($this->matter, $this->oldLead, attributes: ['is_completed' => true, 'completed_at' => '2026-09-09 10:00:00']);
    $someoneElses = m13bDhDeadline($this->matter, $this->associate);

    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
    m13bDhReassign($this->matter, $this->admin, $this->newLead);

    $rows = Activity::query()->where('event', DeadlineHolderAtDue::EVENT)->orderBy('subject_id')->get();

    expect($rows->pluck('subject_id')->all())->toBe([$movedOne->id, $movedTwo->id])
        ->and($rows->pluck('subject_type')->unique()->all())->toBe(['deadline'])
        ->and($rows->every(fn (Activity $row): bool => $row->causer?->is($this->admin) === true))->toBeTrue()
        ->and($rows->first()->properties->all())->toBe([
            'matter_id' => $this->matter->id,
            'client_id' => $this->matter->client_id,
            'from' => $this->oldLead->id,
            'to' => $this->newLead->id,
            'reason' => ReassignMatter::DEADLINE_HANDOVER_REASON,
        ])
        ->and(ReassignMatter::DEADLINE_HANDOVER_REASON)->toBe('matter_reassigned')
        // Dòng `matter_reassigned` (số lượng) vẫn còn nguyên.
        ->and(Activity::query()->where('event', 'matter_reassigned')->sole()->properties->get('deadlines_moved'))->toBe(2)
        ->and($done->fresh()->responsible_user_id)->toBe($this->oldLead->id)
        ->and($someoneElses->fresh()->responsible_user_id)->toBe($this->associate->id);
});

it('loads the moved deadlines for the history rows in one query, however many ReassignMatter moves', function () {
    $selectsOnDeadlines = function (int $count): int {
        $matter = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id]);
        collect(range(1, $count))->each(fn () => m13bDhDeadline($matter, $this->oldLead));

        DB::flushQueryLog();
        DB::enableQueryLog();
        m13bDhReassign($matter, $this->admin, $this->newLead);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return collect($log)
            ->filter(fn (array $query): bool => preg_match('/^select .* from ["`]deadlines["`]/i', $query['query']) === 1)
            ->count();
    };

    expect($selectsOnDeadlines(2))->toBe($selectsOnDeadlines(6));
});

it('makes UpdateDeadline write a deadline_responsible_changed row after deadline_updated when the holder really changes', function () {
    $deadline = m13bDhDeadline($this->matter, $this->associate);

    app(UpdateDeadline::class)->handle(
        deadline: $deadline->fresh(),
        actor: $this->admin,
        name: $deadline->name,
        dueDate: '2026-09-10',
        severity: $deadline->severity,
        responsible: $this->oldLead,
    );

    $rows = Activity::query()->where('subject_type', 'deadline')->where('subject_id', $deadline->id)->orderBy('id')->get();

    expect($rows->pluck('event')->all())->toBe(['deadline_updated', DeadlineHolderAtDue::EVENT])
        ->and($rows->last()->causer?->is($this->admin))->toBeTrue()
        ->and($rows->last()->properties->all())->toBe([
            'matter_id' => $this->matter->id,
            'client_id' => $this->matter->client_id,
            'from' => $this->associate->id,
            'to' => $this->oldLead->id,
            'reason' => UpdateDeadline::HANDOVER_REASON,
        ])
        ->and(UpdateDeadline::HANDOVER_REASON)->toBe('deadline_updated');
});

it('makes UpdateDeadline write no holder row when the holder stays the same', function () {
    $renamed = m13bDhDeadline($this->matter, $this->associate);
    $sameHolderResent = m13bDhDeadline($this->matter, $this->associate);

    app(UpdateDeadline::class)->handle(
        deadline: $renamed->fresh(),
        actor: $this->admin,
        name: 'Tên mới của mốc',
        dueDate: '2026-09-11',
        severity: $renamed->severity,
    );
    app(UpdateDeadline::class)->handle(
        deadline: $sameHolderResent->fresh(),
        actor: $this->admin,
        name: 'Tên mới của mốc thứ hai',
        dueDate: '2026-09-10',
        severity: $sameHolderResent->severity,
        responsible: $this->associate,
    );

    expect(Activity::query()->where('event', 'deadline_updated')->count())->toBe(2)
        ->and(Activity::query()->where('event', DeadlineHolderAtDue::EVENT)->exists())->toBeFalse();
});
