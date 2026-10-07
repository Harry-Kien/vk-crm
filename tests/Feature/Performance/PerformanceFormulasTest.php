<?php

use App\Actions\Deadline\ChangeDeadlineResponsible;
use App\Actions\Deadline\DeleteDeadline;
use App\Actions\Deadline\SetDeadlineCompletion;
use App\Actions\Document\ReviewChecklistItem;
use App\Actions\Document\SubmitClientDocument;
use App\Actions\Document\UploadStaffDocument;
use App\Actions\Matter\ReassignMatter;
use App\Actions\Matter\ReassignMatters;
use App\Actions\Performance\BuildPerformanceReport;
use App\Actions\Portal\ReplyToClientRequest;
use App\Actions\Portal\TriageClientRequest;
use App\Actions\TransitionMatterStage;
use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\DocumentGroup;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\Performance;
use App\Filament\Admin\Widgets\Revenue\RevenueOverTimeWidget;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Deadline;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterType;
use App\Models\Payment;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Audit;
use App\Support\Performance\DeadlineHolderAtDue;
use App\Support\Performance\PerformancePeriod;
use App\Support\Performance\Ratio;
use App\Support\Performance\ResponseTime;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * M13 Task 6 — các con số của trang "Hiệu suất theo kỳ" (P1–P7, P9, P10, "Lĩnh vực chính", dòng
 * "Chung"), đọc QUA TRANG: mỗi số lấy từ bảng `Table::records()` của `Performance` đã mount thật qua
 * Livewire (cùng đường trang dùng: `TeamRoster::subjectsForPeriod()` → `BuildPerformanceReport`), không
 * gọi thẳng Action. Bảng ca biên của `Deadline::outcomeAt()` đã test từng ca ở Task 2; ở đây đối chiếu
 * qua trang.
 *
 * Mặc định: dữ liệu ghi trong tháng 9/2026, xem lúc 15/10/2026 10:00 với kỳ mặc định "tháng trước"
 * (kỳ ĐÃ ĐÓNG tháng 9, mốc cắt 30/09 23:59:59). Hàm toàn cục mang tiền tố `m13bPf` (làn m13b, tệp này).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Queue::fake();
    Mail::fake();
    Notification::fake();
    Storage::fake('private');

    $this->travelTo(Carbon::parse('2026-09-01 08:00:00'));

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản Trị Viên']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng']);
    $this->lawyerA = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư A']);
    $this->lawyerB = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư B']);
    $this->assistantC = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý C']);

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
});

function m13bPfMatter(User $lead, array $attributes = [], ?MatterType $type = null): Matter
{
    $factory = Matter::factory()->for(test()->client);

    if ($type !== null) {
        $factory = $factory->for($type);
    }

    return $factory->create(['lead_lawyer_id' => $lead->id, 'is_published_to_portal' => true, ...$attributes]);
}

/** Một mốc đến hạn `$due`, ghi vào hệ thống một tuần trước hạn. */
function m13bPfDeadline(Matter $matter, User $holder, string $due, array $attributes = []): Deadline
{
    return Deadline::factory()->for($matter)->create([
        'due_date' => $due,
        'responsible_user_id' => $holder->id,
        'is_completed' => false,
        'completed_at' => null,
        'created_at' => Carbon::parse($due)->subWeek()->setTime(9, 0),
        ...$attributes,
    ]);
}

function m13bPfDone(string $at): array
{
    return ['is_completed' => true, 'completed_at' => $at];
}

/** Một luồng khách gửi lúc `$at`, chưa giao ai. */
function m13bPfThread(Matter $matter, string $at, array $attributes = []): ClientRequest
{
    return ClientRequest::factory()->for($matter)->create([
        'client_user_id' => test()->clientUser->id,
        'status' => ClientRequestStatus::New,
        'created_at' => $at,
        'last_activity_at' => $at,
        ...$attributes,
    ]);
}

/** Văn phòng trả lời lần đầu lúc `$at`, qua đúng Action của ô trả lời. */
function m13bPfReply(ClientRequest $thread, User $actor, string $at): void
{
    test()->travelTo(Carbon::parse($at));

    app(ReplyToClientRequest::class)->handle($thread->fresh(), $actor, 'Văn phòng đã nhận và trả lời anh/chị như sau.');
}

/** Bàn giao một vụ lúc `$at` qua đúng Action của nút "Bàn giao" (admin bấm). */
function m13bPfReassign(Matter $matter, User $newLead, string $at, bool $keepOldLead = false): void
{
    test()->travelTo(Carbon::parse($at));

    app(ReassignMatter::class)->handle(
        matter: $matter->fresh(),
        actor: test()->admin,
        newLead: $newLead,
        reason: 'Luật sư cũ chuyển công tác.',
        keepOldLeadAsAssociate: $keepOldLead,
    );
}

/** Đưa vụ vào giai đoạn kết thúc lúc `$at` qua `TransitionMatterStage` (ghi `closed_at = now()`). */
function m13bPfClose(Matter $matter, User $actor, string $at, string $toStage = 'closed'): void
{
    test()->travelTo(Carbon::parse($at));

    app(TransitionMatterStage::class)->handle(
        $matter->fresh(), $actor, $toStage, Carbon::parse($at)->toDateString(), null, null, null, null, null, false,
    );
}

/** Ngày xem mặc định: 15/10/2026 10:00 — kỳ "tháng trước" là tháng 9, đã đóng. */
function m13bPfView(): void
{
    test()->travelTo(Carbon::parse('2026-10-15 10:00:00'));
}

function m13bPfPage(User $viewer, ?array $period = null): Testable
{
    test()->actingAs($viewer, 'web');

    $page = Livewire::test(Performance::class);

    if ($period !== null) {
        $page->set('data', ['period' => null, 'date_from' => null, 'date_to' => null, ...$period])
            ->call('applyPeriod')
            ->assertHasNoErrors();
    }

    return $page;
}

/** @return array<int|string, array<string, mixed>> dòng của bảng, khoá là id người (dòng "Chung": `Performance::REFERENCE_KEY`) */
function m13bPfRows(User $viewer, ?array $period = null): array
{
    return m13bPfPage($viewer, $period)->instance()->getTableRecords()->all();
}

/** @return array<string, mixed> dòng của `$subject` như `$viewer` đọc */
function m13bPfRow(User $viewer, User $subject, ?array $period = null): array
{
    $rows = m13bPfRows($viewer, $period);

    expect($rows)->toHaveKey($subject->getKey());

    return $rows[$subject->getKey()];
}

/** @return array{0: int, 1: int, 2: int} đúng hạn, trễ, lỡ */
function m13bPfOutcomes(array $row): array
{
    return [$row['deadlinesOnTime'], $row['deadlinesLate'], $row['deadlinesMissed']];
}

// =================================================================================================
// P1 — ranh giới thời gian
// =================================================================================================

it('P1 — calls a deadline done at 23:59:59 on its due day on time, and one done at 00:00:01 the next day late', function () {
    $matter = m13bPfMatter($this->lawyerA);
    m13bPfDeadline($matter, $this->lawyerA, '2026-09-10', m13bPfDone('2026-09-10 23:59:59'));
    m13bPfDeadline($matter, $this->lawyerA, '2026-09-10', m13bPfDone('2026-09-11 00:00:01'));

    m13bPfView();
    $row = m13bPfRow($this->manager, $this->lawyerA);

    expect(m13bPfOutcomes($row))->toBe([1, 1, 0])
        ->and($row['onTimeRatio'])->toEqual(new Ratio(1, 2));
});

it('P1 — leaves a deadline due today and not done out of a running period, and takes one due today and done, on time', function () {
    $matter = m13bPfMatter($this->lawyerA);
    m13bPfDeadline($matter, $this->lawyerA, '2026-10-15');
    m13bPfDeadline($matter, $this->lawyerA, '2026-10-15', m13bPfDone('2026-10-15 09:00:00'));

    $this->travelTo(Carbon::parse('2026-10-15 15:00:00'));
    $row = m13bPfRow($this->manager, $this->lawyerA, ['period' => 'this_month']);

    expect(m13bPfOutcomes($row))->toBe([1, 0, 0])
        ->and($row['onTimeRatio'])->toEqual(new Ratio(1, 1));
});

it('P1 — calls a deadline of a closed period done after 23:59:59 of its last day missed, not late', function () {
    $matter = m13bPfMatter($this->lawyerA);
    m13bPfDeadline($matter, $this->lawyerA, '2026-09-30', m13bPfDone('2026-10-01 00:00:01'));
    m13bPfDeadline($matter, $this->lawyerA, '2026-09-29', m13bPfDone('2026-09-30 23:59:59'));

    m13bPfView();

    expect(m13bPfOutcomes(m13bPfRow($this->manager, $this->lawyerA)))->toBe([0, 1, 1]);
});

// =================================================================================================
// P1 — vụ và mốc đặc biệt (bảng ca biên của Task 2, qua trang)
// =================================================================================================

it('P1 — leaves out an open deadline of a matter that closed before or on its due day', function () {
    $closedBefore = m13bPfMatter($this->lawyerA, ['stage' => 'enforcement']);
    $closedOnTheDay = m13bPfMatter($this->lawyerA, ['stage' => 'enforcement']);
    m13bPfDeadline($closedBefore, $this->lawyerA, '2026-09-10');
    m13bPfDeadline($closedOnTheDay, $this->lawyerA, '2026-09-10');

    m13bPfClose($closedBefore, $this->lawyerA, '2026-09-05 10:00:00');
    m13bPfClose($closedOnTheDay, $this->lawyerA, '2026-09-10 15:00:00');

    m13bPfView();
    $row = m13bPfRow($this->manager, $this->lawyerA);

    expect(m13bPfOutcomes($row))->toBe([0, 0, 0])
        ->and($row['onTimeRatio'])->toEqual(new Ratio(0, 0));
});

it('P1 — calls an open deadline of a matter that closed after its due day missed', function () {
    $closedAfter = m13bPfMatter($this->lawyerA, ['stage' => 'enforcement']);
    m13bPfDeadline($closedAfter, $this->lawyerA, '2026-09-10');

    m13bPfClose($closedAfter, $this->lawyerA, '2026-09-11 09:00:00');

    m13bPfView();

    expect(m13bPfOutcomes(m13bPfRow($this->manager, $this->lawyerA)))->toBe([0, 0, 1]);
});

it('P1 — leaves out a deadline written into the system after its due day, and keeps one written on the day', function () {
    $matter = m13bPfMatter($this->lawyerA);
    m13bPfDeadline($matter, $this->lawyerA, '2026-09-10', ['created_at' => '2026-09-11 08:00:00']);
    m13bPfDeadline($matter, $this->lawyerA, '2026-09-10', ['created_at' => '2026-09-10 16:00:00']);

    m13bPfView();

    expect(m13bPfOutcomes(m13bPfRow($this->manager, $this->lawyerA)))->toBe([0, 0, 1]);
});

it('P1 — calls a deadline reopened after its due day missed, for the person who held it on the due day', function () {
    $matter = m13bPfMatter($this->lawyerA);
    $matter->addTeamMember($this->lawyerB, MatterRole::Associate);
    $deadline = m13bPfDeadline($matter, $this->lawyerA, '2026-09-10');

    $this->travelTo(Carbon::parse('2026-09-09 16:00:00'));
    app(SetDeadlineCompletion::class)->handle($deadline->fresh(), true, $this->lawyerA);

    // Mở lại sau ngày đến hạn, rồi giao cho B: mốc là "lỡ" của A, người giữ nó vào ngày đến hạn.
    $this->travelTo(Carbon::parse('2026-09-15 09:00:00'));
    app(SetDeadlineCompletion::class)->handle($deadline->fresh(), false, $this->lawyerA);

    $this->travelTo(Carbon::parse('2026-09-16 09:00:00'));
    app(ChangeDeadlineResponsible::class)->handle($deadline->fresh(), $this->lawyerA, $this->lawyerB);

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect($deadline->fresh()->responsible_user_id)->toBe($this->lawyerB->id)
        ->and(m13bPfOutcomes($rows[$this->lawyerA->id]))->toBe([0, 0, 1])
        ->and(m13bPfOutcomes($rows[$this->lawyerB->id]))->toBe([0, 0, 0]);
});

it('P1, P2 — keeps a removed deadline out of the ratio and counts it as removed in the period it was removed', function () {
    $matter = m13bPfMatter($this->lawyerA);
    $removedInSeptember = m13bPfDeadline($matter, $this->lawyerA, '2026-09-10');
    $removedInOctober = m13bPfDeadline($matter, $this->lawyerA, '2026-09-12');
    m13bPfDeadline($matter, $this->lawyerA, '2026-09-14', m13bPfDone('2026-09-14 10:00:00'));

    $this->travelTo(Carbon::parse('2026-09-12 10:00:00'));
    app(DeleteDeadline::class)->handle($removedInSeptember->fresh(), $this->lawyerA, 'Toà huỷ phiên, mốc không còn hiệu lực.');

    $this->travelTo(Carbon::parse('2026-10-02 10:00:00'));
    app(DeleteDeadline::class)->handle($removedInOctober->fresh(), $this->lawyerA, 'Toà huỷ phiên, mốc không còn hiệu lực.');

    m13bPfView();
    $row = m13bPfRow($this->manager, $this->lawyerA);

    expect(m13bPfOutcomes($row))->toBe([1, 0, 0])
        ->and($row['deadlinesRemoved'])->toBe(1)
        ->and($row['onTimeRatio'])->toEqual(new Ratio(1, 1));
});

it('P1, P2 — counts nothing of a cancelled matter, anywhere', function () {
    $matter = m13bPfMatter($this->lawyerA);
    m13bPfDeadline($matter, $this->lawyerA, '2026-09-10');
    $removed = m13bPfDeadline($matter, $this->lawyerA, '2026-09-12');

    $this->travelTo(Carbon::parse('2026-09-12 10:00:00'));
    app(DeleteDeadline::class)->handle($removed->fresh(), $this->lawyerA, 'Toà huỷ phiên, mốc không còn hiệu lực.');
    $matter->fresh()->delete();

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect(m13bPfOutcomes($rows[$this->lawyerA->id]))->toBe([0, 0, 0])
        ->and($rows[$this->lawyerA->id]['deadlinesRemoved'])->toBe(0)
        ->and(m13bPfOutcomes($rows[Performance::REFERENCE_KEY]))->toBe([0, 0, 0])
        ->and($rows[Performance::REFERENCE_KEY]['deadlinesRemoved'])->toBe(0);
});

// =================================================================================================
// P1 — quy người qua bàn giao (Review Focus 3)
// =================================================================================================

/**
 * Luật sư xem dòng của CHÍNH MÌNH: tập người là một người, nhưng tập mốc của kỳ phải nạp KHÔNG lọc
 * người (R11). Nạp bằng `responsible_user_id IN (tập người)` thì mốc A đã lỡ, nay do B giữ, không bao
 * giờ tới được dòng của A — mutation probe của R11 làm đúng test này đỏ.
 */
it('P1 — keeps a missed deadline in the own row of the lawyer who missed it, after it was handed over', function () {
    $matter = m13bPfMatter($this->lawyerA);
    $deadline = m13bPfDeadline($matter, $this->lawyerA, '2026-09-10');

    m13bPfReassign($matter, $this->lawyerB, '2026-10-05 10:00:00', keepOldLead: true);

    m13bPfView();
    $rows = m13bPfRows($this->lawyerA);

    expect($deadline->fresh()->responsible_user_id)->toBe($this->lawyerB->id)
        ->and(array_keys($rows))->toBe([$this->lawyerA->id])
        ->and(m13bPfOutcomes($rows[$this->lawyerA->id]))->toBe([0, 0, 1])
        ->and(m13bPfOutcomes(m13bPfRow($this->lawyerB, $this->lawyerB)))->toBe([0, 0, 0]);
});

it('P1 — gives a deadline handed over before its due day to the person who received it', function () {
    $matter = m13bPfMatter($this->lawyerA);
    m13bPfDeadline($matter, $this->lawyerA, '2026-09-20');

    m13bPfReassign($matter, $this->lawyerB, '2026-09-10 10:00:00');

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect(m13bPfOutcomes($rows[$this->lawyerA->id]))->toBe([0, 0, 0])
        ->and(m13bPfOutcomes($rows[$this->lawyerB->id]))->toBe([0, 0, 1]);
});

// =================================================================================================
// P3 — trả lời yêu cầu của khách
// =================================================================================================

it('P3 — gives the median and the mean response time in business hours, for an odd and an even count', function () {
    $matterA = m13bPfMatter($this->lawyerA);
    foreach (['2026-09-02 10:00:00', '2026-09-02 11:00:00', '2026-09-02 15:00:00'] as $answeredAt) {
        m13bPfReply(m13bPfThread($matterA, '2026-09-02 09:00:00'), $this->lawyerA, $answeredAt);
    }

    $matterB = m13bPfMatter($this->lawyerB);
    foreach (['2026-09-03 10:00:00', '2026-09-03 11:00:00', '2026-09-03 13:00:00', '2026-09-03 18:00:00'] as $answeredAt) {
        m13bPfReply(m13bPfThread($matterB, '2026-09-03 09:00:00'), $this->lawyerB, $answeredAt);
    }

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    // Thứ Tư và Thứ Năm, giờ làm việc 08:00–17:30 (R17 — giờ làm việc của M10, Task 7 làn A). A: 1, 2, 6
    // giờ. B: 1, 2, 4, và 8,5 giờ — lần trả lời 18:00 đến sau giờ đóng cửa, nửa giờ sau 17:30 không tính.
    expect($rows[$this->lawyerA->id]['responseMedianHours'])->toBe(2.0)
        ->and($rows[$this->lawyerA->id]['responseMeanHours'])->toBe(3.0)
        ->and($rows[$this->lawyerB->id]['responseMedianHours'])->toBe(3.0)
        ->and($rows[$this->lawyerB->id]['responseMeanHours'])->toBe(3.875)
        ->and($rows[$this->lawyerA->id]['requestsAnswered'])->toBe(3)
        ->and($rows[$this->lawyerB->id]['requestsReceived'])->toBe(4);
});

it('P3 — puts an unanswered request in the P9 denominator and leaves it out of the response time', function () {
    $matter = m13bPfMatter($this->lawyerA);
    m13bPfReply(m13bPfThread($matter, '2026-09-02 09:00:00'), $this->lawyerA, '2026-09-02 10:30:00');
    m13bPfThread($matter, '2026-09-03 09:00:00');

    m13bPfView();
    $row = m13bPfRow($this->manager, $this->lawyerA);

    expect($row['requestsReceived'])->toBe(2)
        ->and($row['requestsAnswered'])->toBe(1)
        ->and($row['responseMedianHours'])->toBe(1.5)
        ->and($row['responseMeanHours'])->toBe(1.5)
        ->and($row['completionRatio'])->toEqual(new Ratio(1, 2));
});

it('P3 — counts a request marked answered by phone through TriageClientRequest', function () {
    $matter = m13bPfMatter($this->lawyerA);
    $thread = m13bPfThread($matter, '2026-09-02 09:00:00');

    $this->travelTo(Carbon::parse('2026-09-03 10:00:00'));
    app(TriageClientRequest::class)->setStatus($thread->fresh(), $this->lawyerA, ClientRequestStatus::Answered);

    m13bPfView();
    $row = m13bPfRow($this->manager, $this->lawyerA);

    // Thứ Tư 09:00 → Thứ Năm 10:00: 8,5 giờ làm việc (09:00–17:30) + 2 giờ (08:00–10:00); 25 giờ lịch.
    expect($row['requestsAnswered'])->toBe(1)
        ->and($row['responseMedianHours'])->toBe(10.5);
});

/**
 * R17 — đo bằng ĐÚNG `App\Support\BusinessHours` (M10), không định nghĩa "giờ làm việc" thứ hai: đêm và
 * cuối tuần không tính; đổi lịch làm việc trong cấu hình (`vkcrm.business_hours`) thì con số đổi theo.
 */
it('P3 — measures a request sent on a Friday afternoon and answered on Monday morning in business hours, through the configured schedule', function () {
    $matter = m13bPfMatter($this->lawyerA);
    // Thứ Sáu 04/09 16:30 → Thứ Hai 07/09 09:30: 1 giờ thứ Sáu + 1,5 giờ thứ Hai (65 giờ lịch).
    m13bPfReply(m13bPfThread($matter, '2026-09-04 16:30:00'), $this->lawyerA, '2026-09-07 09:30:00');

    m13bPfView();

    expect(m13bPfRow($this->manager, $this->lawyerA)['responseMedianHours'])->toBe(2.5);

    // Văn phòng làm thêm Thứ Bảy (cùng khung 08:00–17:30 — một khung cho mọi ngày): Thứ Bảy 05/09 cộng 9,5 giờ.
    config(['vkcrm.business_hours.days' => [1, 2, 3, 4, 5, 6]]);

    expect(m13bPfRow($this->manager, $this->lawyerA)['responseMedianHours'])->toBe(12.0);
});

it('P3 — gives a request sent and answered outside business hours a response time of zero', function () {
    $matter = m13bPfMatter($this->lawyerA);
    // Thứ Bảy 05/09 10:00 → Thứ Bảy 15:00.
    m13bPfReply(m13bPfThread($matter, '2026-09-05 10:00:00'), $this->lawyerA, '2026-09-05 15:00:00');

    m13bPfView();
    $row = m13bPfRow($this->manager, $this->lawyerA);

    expect($row['requestsAnswered'])->toBe(1)
        ->and($row['responseMedianHours'])->toBe(0.0);
});

it('P3 — treats a request of a closed period answered after its cutoff as unanswered in that period', function () {
    $matter = m13bPfMatter($this->lawyerA);
    m13bPfReply(m13bPfThread($matter, '2026-09-29 09:00:00'), $this->lawyerA, '2026-10-01 00:00:01');

    m13bPfView();
    $row = m13bPfRow($this->manager, $this->lawyerA);

    expect($row['requestsReceived'])->toBe(1)
        ->and($row['requestsAnswered'])->toBe(0)
        ->and($row['responseMedianHours'])->toBeNull()
        ->and($row['responseMeanHours'])->toBeNull();
});

it('P3 — keeps an unassigned request answered while A led the matter with A after ReassignMatter moves the matter to B', function () {
    $matter = m13bPfMatter($this->lawyerA);
    m13bPfReply(m13bPfThread($matter, '2026-09-02 09:00:00'), $this->lawyerA, '2026-09-03 10:00:00');

    m13bPfReassign($matter, $this->lawyerB, '2026-10-05 10:00:00');

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect($rows[$this->lawyerA->id]['requestsAnswered'])->toBe(1)
        ->and($rows[$this->lawyerA->id]['requestsReceived'])->toBe(1)
        ->and($rows[$this->lawyerB->id]['requestsReceived'])->toBe(0)
        ->and($rows[$this->lawyerA->id]['completionRatio'])->toEqual(new Ratio(1, 1));
});

it('P3 — keeps an unassigned request still unanswered at the end of September with A, who led the matter then', function () {
    $matter = m13bPfMatter($this->lawyerA);
    m13bPfThread($matter, '2026-09-20 09:00:00');

    m13bPfReassign($matter, $this->lawyerB, '2026-10-05 10:00:00');

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect($rows[$this->lawyerA->id]['requestsReceived'])->toBe(1)
        ->and($rows[$this->lawyerA->id]['requestsAnswered'])->toBe(0)
        ->and($rows[$this->lawyerB->id]['requestsReceived'])->toBe(0);
});

it('P3 — keeps an unassigned request answered while A led the matter with A after ReassignMatters moves a batch to B', function () {
    $matter = m13bPfMatter($this->lawyerA);
    m13bPfReply(m13bPfThread($matter, '2026-09-02 09:00:00'), $this->lawyerA, '2026-09-03 10:00:00');

    $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
    $results = app(ReassignMatters::class)->handle(
        matterIds: [$matter->id],
        actor: $this->admin,
        newLead: $this->lawyerB,
        reason: 'Nghỉ việc, bàn giao cả lô.',
        keepOldLeadAsAssociate: false,
        expectedLeadId: $this->lawyerA->id,
    );

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect($results[0]->success)->toBeTrue()
        ->and($rows[$this->lawyerA->id]['requestsAnswered'])->toBe(1)
        ->and($rows[$this->lawyerB->id]['requestsReceived'])->toBe(0);
});

it('P3 — gives a request assigned by name to A, then moved to B with the matter, to A before the move and to B after it', function () {
    $matter = m13bPfMatter($this->lawyerA);
    $thread = m13bPfThread($matter, '2026-09-02 09:00:00');

    $this->travelTo(Carbon::parse('2026-09-02 10:00:00'));
    app(TriageClientRequest::class)->assign($thread->fresh(), $this->lawyerA, $this->lawyerA);

    m13bPfReassign($matter, $this->lawyerB, '2026-10-05 10:00:00');

    m13bPfView();
    $september = m13bPfRows($this->manager);
    $sinceSeptember = m13bPfRows($this->manager, ['period' => 'custom', 'date_from' => '2026-09-01', 'date_to' => '2026-10-15']);

    expect($thread->fresh()->assigned_to)->toBe($this->lawyerB->id)
        ->and($september[$this->lawyerA->id]['requestsReceived'])->toBe(1)
        ->and($september[$this->lawyerB->id]['requestsReceived'])->toBe(0)
        ->and($sinceSeptember[$this->lawyerA->id]['requestsReceived'])->toBe(0)
        ->and($sinceSeptember[$this->lawyerB->id]['requestsReceived'])->toBe(1);
});

it('P3 — keeps a request assigned to assistant C with C through a handover of the matter', function () {
    $matter = m13bPfMatter($this->lawyerA);
    $matter->addTeamMember($this->assistantC, MatterRole::Assistant);
    $thread = m13bPfThread($matter, '2026-09-02 09:00:00');

    $this->travelTo(Carbon::parse('2026-09-02 10:00:00'));
    app(TriageClientRequest::class)->assign($thread->fresh(), $this->lawyerA, $this->assistantC);
    m13bPfReply($thread, $this->assistantC, '2026-09-02 12:00:00');

    m13bPfReassign($matter, $this->lawyerB, '2026-09-20 10:00:00');

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect($rows[$this->assistantC->id]['requestsAnswered'])->toBe(1)
        ->and($rows[$this->assistantC->id]['responseMedianHours'])->toBe(3.0)
        ->and($rows[$this->lawyerA->id]['requestsReceived'])->toBe(0)
        ->and($rows[$this->lawyerB->id]['requestsReceived'])->toBe(0);
});

/**
 * Thời điểm quy người của một luồng đã trả lời là LÚC TRẢ LỜI, không phải mốc cắt: bàn giao giữa kỳ sau
 * khi A đã trả lời không chuyển luồng sang B. Luồng chưa trả lời tới hết kỳ thì thuộc người giữ lúc mốc cắt.
 */
it('P3 — keeps a request answered before a handover inside the period with the person who held it when it was answered', function () {
    $matter = m13bPfMatter($this->lawyerA);
    m13bPfReply(m13bPfThread($matter, '2026-09-02 09:00:00'), $this->lawyerA, '2026-09-03 10:00:00');
    $unanswered = m13bPfThread($matter, '2026-09-04 09:00:00');

    m13bPfReassign($matter, $this->lawyerB, '2026-09-20 10:00:00');

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect($unanswered->fresh()->answered_at)->toBeNull()
        ->and($rows[$this->lawyerA->id]['requestsAnswered'])->toBe(1)
        ->and($rows[$this->lawyerA->id]['requestsReceived'])->toBe(1)
        ->and($rows[$this->lawyerB->id]['requestsAnswered'])->toBe(0)
        ->and($rows[$this->lawyerB->id]['requestsReceived'])->toBe(1);
});

/**
 * Giờ LÀM VIỆC (R17) thì không gộp thành "ngày": một ngày làm việc không phải 24 giờ, và "2 ngày 4 giờ" theo
 * 24 giờ sẽ đọc thành hai ngày làm việc trong khi chỉ là chừng năm ngày rưỡi làm việc. In giờ, dấu phẩy thập
 * phân, một chữ số sau dấu phẩy.
 */
it('P3 — prints a response time in business hours with a decimal comma, never folded into 24-hour days', function () {
    expect(ResponseTime::label(3.5))->toBe('3,5 giờ')
        ->and(ResponseTime::label(3.0))->toBe('3 giờ')
        ->and(ResponseTime::label(0.25))->toBe('0,3 giờ')
        ->and(ResponseTime::label(23.96))->toBe('24 giờ')
        ->and(ResponseTime::label(52.0))->toBe('52 giờ')
        ->and(ResponseTime::label(48.2))->toBe('48,2 giờ')
        ->and(ResponseTime::label(1234.5))->toBe('1.234,5 giờ')
        ->and(ResponseTime::median([]))->toBeNull()
        ->and(ResponseTime::mean([]))->toBeNull()
        ->and(ResponseTime::median([6.0, 1.0, 2.0]))->toBe(2.0)
        ->and(ResponseTime::median([9.0, 1.0, 4.0, 2.0]))->toBe(3.0)
        ->and(ResponseTime::mean([9.0, 1.0, 4.0, 2.0]))->toBe(4.0);
});

// =================================================================================================
// P10 — yêu cầu đóng không trả lời
// =================================================================================================

it('P10 — takes a request closed without an answer out of the P3 and P9 denominator into P10, and keeps one answered then closed answered', function () {
    $matter = m13bPfMatter($this->lawyerA);
    $closedUnanswered = m13bPfThread($matter, '2026-09-02 09:00:00');
    $answeredThenClosed = m13bPfThread($matter, '2026-09-02 09:00:00');

    $this->travelTo(Carbon::parse('2026-09-03 09:00:00'));
    app(TriageClientRequest::class)->setStatus($closedUnanswered->fresh(), $this->lawyerA, ClientRequestStatus::Closed);

    m13bPfReply($answeredThenClosed, $this->lawyerA, '2026-09-02 13:00:00');
    $this->travelTo(Carbon::parse('2026-09-04 09:00:00'));
    app(TriageClientRequest::class)->setStatus($answeredThenClosed->fresh(), $this->lawyerA, ClientRequestStatus::Closed);

    m13bPfView();
    $row = m13bPfRow($this->manager, $this->lawyerA);

    expect($row['requestsClosedUnanswered'])->toBe(1)
        ->and($row['requestsReceived'])->toBe(1)
        ->and($row['requestsAnswered'])->toBe(1)
        ->and($row['completionRatio'])->toEqual(new Ratio(1, 1));
});

// =================================================================================================
// P4 — chuyển giai đoạn
// =================================================================================================

function m13bPfStage(Matter $matter, User $by, string $occurredAt, ?string $from, ?string $to): StageLog
{
    return StageLog::factory()->for($matter)->create([
        'occurred_at' => $occurredAt,
        'from_stage' => $from,
        'to_stage' => $to,
        'created_by' => $by->id,
        'updated_by' => $by->id,
    ]);
}

it('P4 — counts moves into a new stage by occurred_at, leaves out same-stage updates and the internal handover line, and backdated lines count', function () {
    $first = m13bPfMatter($this->lawyerA);
    $second = m13bPfMatter($this->lawyerA);
    $handedOver = m13bPfMatter($this->lawyerA);

    m13bPfStage($first, $this->lawyerA, '2026-09-10', 'intake', 'collecting_documents');
    m13bPfStage($first, $this->lawyerA, '2026-09-12', 'collecting_documents', 'collecting_documents');
    m13bPfStage($second, $this->lawyerA, '2026-09-25', 'intake', 'collecting_documents');
    m13bPfStage($second, $this->lawyerA, '2026-10-02', 'collecting_documents', 'drafting');

    // Dòng bàn giao nội bộ của ReassignMatter: cùng giai đoạn, người ghi là admin.
    m13bPfReassign($handedOver, $this->lawyerB, '2026-09-15 10:00:00');

    // Ghi hôm 03/10 cho ngày 20/09: tính vào tháng 9.
    $this->travelTo(Carbon::parse('2026-10-03 10:00:00'));
    m13bPfStage($first, $this->lawyerA, '2026-09-20', 'collecting_documents', 'drafting');

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect(StageLog::query()->where('matter_id', $handedOver->id)->count())->toBeGreaterThan(0)
        ->and($rows[$this->lawyerA->id]['stageEntries'])->toBe(3)
        ->and($rows[$this->lawyerA->id]['mattersMoved'])->toBe(2)
        ->and($rows[$this->manager->id]['stageEntries'])->toBe(0)
        ->and($rows[Performance::REFERENCE_KEY]['stageEntries'])->toBe(3)
        ->and($rows[Performance::REFERENCE_KEY]['mattersMoved'])->toBe(2);
});

it('P4, P5 — shows an assistant "not applicable" by permission, even with a stage line in their name', function () {
    $matter = m13bPfMatter($this->lawyerA);
    $matter->addTeamMember($this->assistantC, MatterRole::Assistant);
    m13bPfStage($matter, $this->assistantC, '2026-09-10', 'intake', 'collecting_documents');

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect($rows[$this->assistantC->id]['stageEntries'])->toBeNull()
        ->and($rows[$this->assistantC->id]['mattersMoved'])->toBeNull()
        ->and($rows[$this->assistantC->id]['mattersClosed'])->toBeNull()
        ->and($rows[$this->lawyerB->id]['stageEntries'])->toBe(0)
        ->and($rows[$this->lawyerB->id]['mattersClosed'])->toBe(0)
        ->and($rows[Performance::REFERENCE_KEY]['stageEntries'])->toBe(1);
});

// =================================================================================================
// P5 — vụ kết thúc trong kỳ
// =================================================================================================

it('P5 — stops counting a matter an admin reopened after it closed', function () {
    $reopened = m13bPfMatter($this->lawyerA, ['stage' => 'enforcement']);
    $stillClosed = m13bPfMatter($this->lawyerA, ['stage' => 'enforcement']);

    m13bPfClose($reopened, $this->lawyerA, '2026-09-10 10:00:00');
    m13bPfClose($stillClosed, $this->lawyerA, '2026-09-11 10:00:00');
    m13bPfClose($reopened, $this->admin, '2026-10-02 10:00:00', toStage: 'enforcement');

    m13bPfView();

    expect($reopened->fresh()->closed_at)->toBeNull()
        ->and(m13bPfRow($this->manager, $this->lawyerA)['mattersClosed'])->toBe(1);
});

it('P5 — keeps a matter that closed and was handed over later with the lead it had when it closed', function () {
    $matter = m13bPfMatter($this->lawyerA, ['stage' => 'enforcement']);

    m13bPfClose($matter, $this->lawyerA, '2026-09-12 10:00:00');
    m13bPfReassign($matter, $this->lawyerB, '2026-10-05 10:00:00');

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect($matter->fresh()->lead_lawyer_id)->toBe($this->lawyerB->id)
        ->and($rows[$this->lawyerA->id]['mattersClosed'])->toBe(1)
        ->and($rows[$this->lawyerB->id]['mattersClosed'])->toBe(0);
});

/**
 * `closed_at` là cột `date`: cast đọc về 00:00 của ngày kết thúc, trên SQLite lẫn MariaDB. Thời điểm hỏi
 * `LeadAt` của P5 là HẾT ngày kết thúc (23:59:59, như "người giữ mốc vào ngày đến hạn"), nên lần bàn
 * giao sáng hôm đó rồi người nhận đóng vụ buổi chiều tính cho người nhận. Chạy cả dưới `test:mariadb`.
 */
it('P5 — gives a matter handed over in the morning and closed by the new lead that afternoon to the new lead', function () {
    $matter = m13bPfMatter($this->lawyerA, ['stage' => 'enforcement']);

    m13bPfReassign($matter, $this->lawyerB, '2026-09-14 10:00:00');
    m13bPfClose($matter, $this->lawyerB, '2026-09-14 15:00:00');

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect($rows[$this->lawyerB->id]['mattersClosed'])->toBe(1)
        ->and($rows[$this->lawyerA->id]['mattersClosed'])->toBe(0);
});

// =================================================================================================
// P6 — giấy tờ đã duyệt
// =================================================================================================

function m13bPfPendingItem(Matter $matter): MatterChecklistItem
{
    return MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create();
}

function m13bPfReview(MatterChecklistItem $item, User $actor, string $at, ChecklistItemStatus $decision = ChecklistItemStatus::Accepted): void
{
    test()->travelTo(Carbon::parse($at));

    app(ReviewChecklistItem::class)->handle(
        $item->fresh(),
        $actor,
        $decision,
        $decision === ChecklistItemStatus::Rejected ? 'Bản chụp bị mờ, đề nghị nộp lại bản rõ hơn.' : null,
    );
}

function m13bPfPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('giay-to.pdf', "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<< /Type /Catalog >>\nendobj\n");
}

it('P6 — counts one approval and one rejection in the period as two, for the person who clicked', function () {
    $matter = m13bPfMatter($this->lawyerA);
    $matter->addTeamMember($this->assistantC, MatterRole::Assistant);

    m13bPfReview(m13bPfPendingItem($matter), $this->lawyerA, '2026-09-05 10:00:00');
    m13bPfReview(m13bPfPendingItem($matter), $this->lawyerA, '2026-09-06 10:00:00', ChecklistItemStatus::Rejected);
    m13bPfReview(m13bPfPendingItem($matter), $this->assistantC, '2026-09-07 10:00:00');
    m13bPfReview(m13bPfPendingItem($matter), $this->lawyerA, '2026-10-01 00:00:00');

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect($rows[$this->lawyerA->id]['itemsReviewed'])->toBe(2)
        ->and($rows[$this->assistantC->id]['itemsReviewed'])->toBe(1)
        ->and($rows[Performance::REFERENCE_KEY]['itemsReviewed'])->toBe(3);
});

it('P6 — still counts a rejection after the client submits again and the item loses its reviewer', function () {
    $matter = m13bPfMatter($this->lawyerA);
    $item = m13bPfPendingItem($matter);

    m13bPfReview($item, $this->lawyerA, '2026-09-06 10:00:00', ChecklistItemStatus::Rejected);

    $this->travelTo(Carbon::parse('2026-09-08 10:00:00'));
    app(SubmitClientDocument::class)->handle($item->fresh(), $this->clientUser, [m13bPfPdf()]);

    m13bPfView();

    expect($item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview)
        ->and($item->fresh()->reviewed_by)->toBeNull()
        ->and(m13bPfRow($this->manager, $this->lawyerA)['itemsReviewed'])->toBe(1);
});

it('P6 — does not count the office uploading a document on the client\'s behalf as a review', function () {
    $matter = m13bPfMatter($this->lawyerA);
    $item = MatterChecklistItem::factory()->for($matter)->create();

    $this->travelTo(Carbon::parse('2026-09-08 10:00:00'));
    app(UploadStaffDocument::class)->handle(
        matter: $matter->fresh(),
        actor: $this->lawyerA,
        file: m13bPfPdf(),
        group: DocumentGroup::ClientProvided,
        title: 'Bản sao giấy tờ khách đưa trực tiếp',
        checklistItem: $item->fresh(),
    );

    m13bPfView();

    expect($item->fresh()->status)->toBe(ChecklistItemStatus::Accepted)
        ->and(m13bPfRow($this->manager, $this->lawyerA)['itemsReviewed'])->toBe(0);
});

/**
 * Chỉ người dùng nội bộ duyệt giấy tờ (`ReviewChecklistItem` nhận một `User`). Một dòng nhật ký mà người
 * gây ra là một tài khoản KHÁCH trùng số id với một nhân sự không được tính cho nhân sự đó; dòng "Chung"
 * vẫn đếm nó (việc không quy được về ai).
 */
it('P6 — does not credit a staff member with a review row caused by a client account that shares their id', function () {
    $matter = m13bPfMatter($this->lawyerA);
    $item = m13bPfPendingItem($matter);
    $sameId = ClientUser::factory()->activated()->create(['id' => $this->lawyerA->id, 'client_id' => $this->client->id]);

    $this->travelTo(Carbon::parse('2026-09-05 10:00:00'));
    Audit::record(ReviewChecklistItem::AUDIT_EVENT, $item, ['matter_id' => $matter->id, 'status' => 'accepted'], causer: $sameId);

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect($sameId->getKey())->toBe($this->lawyerA->id)
        ->and($rows[$this->lawyerA->id]['itemsReviewed'])->toBe(0)
        ->and($rows[Performance::REFERENCE_KEY]['itemsReviewed'])->toBe(1);
});

it('P6 — leaves a review on a restricted matter out of the manager\'s numbers, and counts it for its lead and the admin', function () {
    $restricted = m13bPfMatter($this->lawyerA, ['confidentiality' => 'restricted']);
    m13bPfReview(m13bPfPendingItem($restricted), $this->lawyerA, '2026-09-05 10:00:00');

    m13bPfView();

    expect(m13bPfRow($this->manager, $this->lawyerA)['itemsReviewed'])->toBe(0)
        ->and(m13bPfRows($this->manager)[Performance::REFERENCE_KEY]['itemsReviewed'])->toBe(0)
        ->and(m13bPfRow($this->admin, $this->lawyerA)['itemsReviewed'])->toBe(1)
        ->and(m13bPfRow($this->lawyerA, $this->lawyerA)['itemsReviewed'])->toBe(1);
});

// =================================================================================================
// P7 — doanh thu đã thu
// =================================================================================================

/**
 * Một khoản thu `$amount` ngày `$paidOn`, tính cho `$attributed`. Mỗi khoản một vụ, một hợp đồng, một đợt
 * (`contracts.matter_id` là duy nhất, và tổng các đợt phải bằng giá trị hợp đồng); `$matter` cho sẵn thì
 * dùng vụ đó — chưa có hợp đồng.
 */
function m13bPfPayment(User $attributed, int $amount, string $paidOn, bool $voided = false, ?Matter $matter = null): Payment
{
    $matter ??= m13bPfMatter($attributed);
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => $amount, 'signed_at' => '2026-08-01']);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => $amount, 'due_date' => '2026-09-15']);

    return ($voided ? Payment::factory()->voided() : Payment::factory())->for($instalment)->create([
        'amount' => $amount,
        'paid_on' => $paidOn,
        'attributed_lawyer_id' => $attributed->id,
    ]);
}

/** Tổng các cột của `RevenueOverTimeWidget` mount thật qua Livewire, kỳ tuỳ chọn tháng 9/2026, như `$viewer` đọc. */
function m13bPfRevenueWidgetTotal(User $viewer, array $extraFilters = []): int
{
    test()->actingAs($viewer, 'web');

    $instance = Livewire::test(RevenueOverTimeWidget::class, ['pageFilters' => [
        'period' => 'custom',
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
        ...$extraFilters,
    ]])->instance();

    $getData = new ReflectionMethod($instance, 'getData');

    return (int) array_sum($getData->invoke($instance)['datasets'][0]['data']);
}

it('P7 — equals the over-time revenue widget with the lawyer filter for each lawyer, and the unfiltered widget on the reference row', function () {
    m13bPfPayment($this->lawyerA, 1_000_000, '2026-09-30');
    m13bPfPayment($this->lawyerA, 2_000_000, '2026-09-01');
    m13bPfPayment($this->lawyerA, 4_000_000, '2026-10-01');
    m13bPfPayment($this->lawyerB, 8_000_000, '2026-09-10');
    // Thu trước khi bàn giao: vẫn của A dù vụ nay do B phụ trách (M9 P2).
    $handedOver = m13bPfMatter($this->lawyerA);
    m13bPfPayment($this->lawyerA, 16_000_000, '2026-09-12', matter: $handedOver);
    m13bPfReassign($handedOver, $this->lawyerB, '2026-09-20 10:00:00');

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect($rows[$this->lawyerA->id]['revenueCollected'])->toBe(19_000_000)
        ->and($rows[$this->lawyerA->id]['revenueCollected'])->toBe(m13bPfRevenueWidgetTotal($this->manager, ['lawyer_id' => $this->lawyerA->id]))
        ->and($rows[$this->lawyerB->id]['revenueCollected'])->toBe(8_000_000)
        ->and($rows[$this->lawyerB->id]['revenueCollected'])->toBe(m13bPfRevenueWidgetTotal($this->manager, ['lawyer_id' => $this->lawyerB->id]))
        ->and($rows[Performance::REFERENCE_KEY]['revenueCollected'])->toBe(27_000_000)
        ->and($rows[Performance::REFERENCE_KEY]['revenueCollected'])->toBe(m13bPfRevenueWidgetTotal($this->manager));
});

it('P7 — shows an assistant "not applicable" when the viewer sees the column, and gives an assistant viewer no column at all', function () {
    m13bPfView();

    $managerPage = m13bPfPage($this->manager);
    $assistantPage = m13bPfPage($this->assistantC);

    expect($managerPage->instance()->getTableRecords()[$this->assistantC->id]['revenueCollected'])->toBeNull()
        ->and($managerPage->html())->toContain(e(__('performance.columns.p7')))
        ->and($assistantPage->html())->not->toContain(e(__('performance.columns.p7')))
        ->and($assistantPage->instance()->getTableRecords()[$this->assistantC->id]['revenueCollected'])->toBeNull();
});

it('P7 — lets a lawyer see the revenue column on their own row only', function () {
    m13bPfPayment($this->lawyerA, 5_000_000, '2026-09-10');
    m13bPfPayment($this->lawyerB, 7_000_000, '2026-09-10');

    m13bPfView();
    $page = m13bPfPage($this->lawyerA);
    $rows = $page->instance()->getTableRecords()->all();

    expect(array_keys($rows))->toBe([$this->lawyerA->id])
        ->and($rows[$this->lawyerA->id]['revenueCollected'])->toBe(5_000_000)
        ->and($page->html())->toContain(e(__('performance.columns.p7')));
});

it('P7 — does not count a voided payment', function () {
    m13bPfPayment($this->lawyerA, 3_000_000, '2026-09-10');
    m13bPfPayment($this->lawyerA, 9_000_000, '2026-09-11', voided: true);

    m13bPfView();

    expect(m13bPfRow($this->manager, $this->lawyerA)['revenueCollected'])->toBe(3_000_000);
});

// =================================================================================================
// Dòng "Chung" (R8)
// =================================================================================================

it('counts on the reference row the work of the admin, of a deleted account and of nobody, so it is not the sum of the rows', function () {
    $matter = m13bPfMatter($this->lawyerA);
    $gone = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Đã Xoá']);
    $matter->addTeamMember($this->lawyerB, MatterRole::Associate);

    m13bPfDeadline($matter, $this->lawyerA, '2026-09-10');
    m13bPfDeadline($matter, $this->admin, '2026-09-11');
    m13bPfDeadline($matter, $gone, '2026-09-12');
    $nobodys = m13bPfDeadline($matter, $this->lawyerB, '2026-09-13');

    // Dòng lịch sử hỏng (from rỗng) sau ngày đến hạn: mốc không quy về ai (R9), chỉ vào dòng "Chung".
    $this->travelTo(Carbon::parse('2026-09-20 10:00:00'));
    Audit::record(DeadlineHolderAtDue::EVENT, $nobodys, ['from' => null, 'to' => $this->lawyerB->id], causer: $this->admin);
    $gone->delete();

    m13bPfView();
    $rows = m13bPfRows($this->manager);
    $people = collect($rows)->except(Performance::REFERENCE_KEY);

    expect(array_key_first($rows))->toBe(Performance::REFERENCE_KEY)
        ->and($rows)->not->toHaveKey($gone->id)
        ->and($rows)->not->toHaveKey($this->admin->id)
        ->and($rows[Performance::REFERENCE_KEY]['deadlinesMissed'])->toBe(4)
        ->and($people->sum('deadlinesMissed'))->toBe(1)
        ->and($rows[$this->lawyerB->id]['deadlinesMissed'])->toBe(0)
        ->and($rows[Performance::REFERENCE_KEY]['name'])->toBe(__('performance.period_page.reference_name'))
        ->and($rows[Performance::REFERENCE_KEY]['userId'])->toBeNull();
});

/**
 * Phòng thủ (như `BuildTeamWorkload`): trang chỉ đưa vào người của `TeamRoster::subjectsForPeriod()`, nhưng
 * Action không tin điều đó — một người mà người xem không được xem làm cả lần tính dừng lại.
 */
it('refuses a subject the viewer may not see, even when the page is bypassed', function () {
    m13bPfView();

    expect(fn () => app(BuildPerformanceReport::class)->handle(
        $this->lawyerA,
        collect([$this->lawyerA, $this->lawyerB]),
        PerformancePeriod::fromFilters(null),
    ))->toThrow(AuthorizationException::class)
        ->and(app(BuildPerformanceReport::class)->handle($this->lawyerA, collect([$this->lawyerA]), PerformancePeriod::fromFilters(null))->rows)
        ->toHaveKey($this->lawyerA->id);
});

it('gives the reference row only to a performance.viewAny holder', function () {
    m13bPfView();

    expect(m13bPfRows($this->manager))->toHaveKey(Performance::REFERENCE_KEY)
        ->and(m13bPfRows($this->admin))->toHaveKey(Performance::REFERENCE_KEY)
        ->and(m13bPfRows($this->lawyerA))->not->toHaveKey(Performance::REFERENCE_KEY)
        ->and(m13bPfRows($this->assistantC))->not->toHaveKey(Performance::REFERENCE_KEY);
});

// =================================================================================================
// "Lĩnh vực chính"
// =================================================================================================

it('names the practice areas of the matters a person worked on in the period, not of the matters they hold now', function () {
    $criminal = MatterType::factory()->withStages()->create(['code' => 'HS', 'name' => 'Hình sự']);
    $civil = MatterType::factory()->withStages()->create(['code' => 'DS', 'name' => 'Dân sự']);

    foreach (['2026-09-05', '2026-09-12', '2026-09-25'] as $day) {
        $closed = m13bPfMatter($this->lawyerA, [], $criminal);
        // Như TransitionMatterStage ghi: `now()` của ngày kết thúc.
        $closed->forceFill(['closed_at' => Carbon::parse("{$day} 10:00:00")])->save();
    }
    m13bPfMatter($this->lawyerA, [], $civil);

    m13bPfView();
    $rows = m13bPfRows($this->manager);

    expect($rows[$this->lawyerA->id]['mattersClosed'])->toBe(3)
        ->and($rows[$this->lawyerA->id]['mainPracticeAreas'])->toBe([['name' => 'Hình sự', 'matters' => 3]])
        ->and($rows[Performance::REFERENCE_KEY]['mainPracticeAreas'])->toBe([['name' => 'Hình sự', 'matters' => 3]])
        ->and($rows[$this->lawyerB->id]['mainPracticeAreas'])->toBe([]);
});

it('keeps the two practice areas with the most matters, each matter once, from deadlines, requests, stage lines and closings', function () {
    $criminal = MatterType::factory()->withStages()->create(['code' => 'HS', 'name' => 'Hình sự']);
    $civil = MatterType::factory()->withStages()->create(['code' => 'DS', 'name' => 'Dân sự']);
    $corporate = MatterType::factory()->withStages()->create(['code' => 'DN', 'name' => 'Doanh nghiệp']);

    $c1 = m13bPfMatter($this->lawyerA, [], $criminal);
    $c2 = m13bPfMatter($this->lawyerA, [], $criminal);
    $c3 = m13bPfMatter($this->lawyerA, [], $criminal);
    $d1 = m13bPfMatter($this->lawyerA, [], $civil);
    $d2 = m13bPfMatter($this->lawyerA, [], $civil);
    $n1 = m13bPfMatter($this->lawyerA, [], $corporate);

    m13bPfDeadline($c1, $this->lawyerA, '2026-09-10');
    m13bPfDeadline($c1, $this->lawyerA, '2026-09-11');
    m13bPfThread($c2, '2026-09-03 09:00:00');
    m13bPfStage($c3, $this->lawyerA, '2026-09-10', 'intake', 'investigation');
    m13bPfDeadline($d1, $this->lawyerA, '2026-09-10');
    m13bPfThread($d2, '2026-09-03 09:00:00');
    m13bPfDeadline($n1, $this->lawyerA, '2026-09-10');

    m13bPfView();

    expect(m13bPfRow($this->manager, $this->lawyerA)['mainPracticeAreas'])->toBe([
        ['name' => 'Hình sự', 'matters' => 3],
        ['name' => 'Dân sự', 'matters' => 2],
    ]);
});

// =================================================================================================
// P9 và Ratio (R7)
// =================================================================================================

it('P9 — gives no ratio below five pieces of work, and one from five on', function () {
    $matter = m13bPfMatter($this->lawyerA);
    foreach (['2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13'] as $due) {
        m13bPfDeadline($matter, $this->lawyerA, $due, m13bPfDone("{$due} 09:00:00"));
    }

    m13bPfView();
    $four = m13bPfRow($this->manager, $this->lawyerA)['completionRatio'];

    m13bPfReply(m13bPfThread($matter, '2026-09-20 09:00:00'), $this->lawyerA, '2026-09-20 10:00:00');
    m13bPfView();
    $five = m13bPfRow($this->manager, $this->lawyerA)['completionRatio'];

    expect($four)->toEqual(new Ratio(4, 4))
        ->and($four->isMeasurable())->toBeFalse()
        ->and($four->label())->toBe(__('performance.ratio.insufficient', ['n' => 4]))
        ->and($four->label())->toBe('Chưa đủ dữ liệu (n = 4)')
        ->and($five)->toEqual(new Ratio(5, 5))
        ->and($five->isMeasurable())->toBeTrue()
        ->and($five->label())->toBe('100% (5/5)');
});

it('P9 — counts deadlines done by the cutoff, late ones included, and answered requests, over all of them', function () {
    $matter = m13bPfMatter($this->lawyerA);
    m13bPfDeadline($matter, $this->lawyerA, '2026-09-10', m13bPfDone('2026-09-10 09:00:00'));
    m13bPfDeadline($matter, $this->lawyerA, '2026-09-11', m13bPfDone('2026-09-15 09:00:00'));
    m13bPfDeadline($matter, $this->lawyerA, '2026-09-12');
    m13bPfReply(m13bPfThread($matter, '2026-09-20 09:00:00'), $this->lawyerA, '2026-09-20 10:00:00');
    m13bPfThread($matter, '2026-09-21 09:00:00');

    m13bPfView();

    expect(m13bPfRow($this->manager, $this->lawyerA)['completionRatio'])->toEqual(new Ratio(3, 5));
});

it('labels a ratio with a decimal comma and its numerator and denominator, and never a ratio under the minimum sample', function () {
    expect(Ratio::MIN_SAMPLE)->toBe(5)
        ->and((new Ratio(35, 40))->label())->toBe('87,5% (35/40)')
        ->and((new Ratio(4, 5))->label())->toBe('80% (4/5)')
        ->and((new Ratio(2, 3))->label())->toBe('Chưa đủ dữ liệu (n = 3)')
        ->and((new Ratio(0, 0))->label())->toBe('Chưa đủ dữ liệu (n = 0)')
        ->and((new Ratio(2, 6))->label())->toBe('33,3% (2/6)')
        ->and((new Ratio(0, 5))->label())->toBe('0% (0/5)')
        ->and((new Ratio(5, 5))->isMeasurable())->toBeTrue()
        ->and((new Ratio(4, 4))->isMeasurable())->toBeFalse();
});
