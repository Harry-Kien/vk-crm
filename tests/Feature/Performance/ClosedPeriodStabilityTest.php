<?php

use App\Actions\Deadline\SetDeadlineCompletion;
use App\Actions\Document\ReviewChecklistItem;
use App\Actions\Matter\ReassignMatter;
use App\Actions\Matter\ReassignMatters;
use App\Actions\Portal\ReplyToClientRequest;
use App\Actions\Portal\TriageClientRequest;
use App\Actions\TransitionMatterStage;
use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\Performance;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Deadline;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\Payment;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * M13 Task 6 — kỳ đã đóng không trôi (R19, Review Focus 5). Tính "tháng trước" (tháng 9/2026) lúc
 * 15/10; rồi hôm sau hoàn thành các mốc đã lỡ, trả lời các luồng tồn, bàn giao vụ (một vụ đang mở và
 * một vụ đã kết thúc qua `ReassignMatter`, một vụ qua `ReassignMatters` hàng loạt); tính lại: mọi
 * trường của mọi dòng (cả dòng "Chung") không đổi, cho trưởng phòng và cho admin.
 *
 * Đọc qua trang (Livewire). Chạy cả dưới `test:mariadb` (tuần tự). Hàm toàn cục mang tiền tố `m13bCs`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Queue::fake();
    Mail::fake();
    Notification::fake();

    $this->travelTo(Carbon::parse('2026-09-01 08:00:00'));

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản Trị Viên']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng']);
    $this->lawyerA = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư A']);
    $this->lawyerB = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư B']);
    $this->assistantC = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý C']);

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
});

function m13bCsAt(string $at): void
{
    test()->travelTo(Carbon::parse($at));
}

function m13bCsMatter(User $lead, array $attributes = []): Matter
{
    return Matter::factory()->for(test()->client)->create(['lead_lawyer_id' => $lead->id, 'is_published_to_portal' => true, ...$attributes]);
}

function m13bCsDeadline(Matter $matter, User $holder, string $due): Deadline
{
    return Deadline::factory()->for($matter)->create([
        'due_date' => $due,
        'responsible_user_id' => $holder->id,
        'is_completed' => false,
        'completed_at' => null,
        'created_at' => Carbon::parse($due)->subWeek()->setTime(9, 0),
    ]);
}

function m13bCsThread(Matter $matter, string $at): ClientRequest
{
    return ClientRequest::factory()->for($matter)->create([
        'client_user_id' => test()->clientUser->id,
        'status' => ClientRequestStatus::New,
        'created_at' => $at,
        'last_activity_at' => $at,
    ]);
}

function m13bCsReply(ClientRequest $thread, User $actor): void
{
    app(ReplyToClientRequest::class)->handle($thread->fresh(), $actor, 'Văn phòng đã nhận và trả lời anh/chị như sau.');
}

/** @return array<int|string, array<string, mixed>> mọi dòng của "tháng trước" như `$viewer` đọc */
function m13bCsRows(User $viewer): array
{
    test()->actingAs($viewer, 'web');

    return Livewire::test(Performance::class)->instance()->getTableRecords()->all();
}

it('keeps every field of every row of a closed period after late completions, late answers and handovers', function () {
    // Tháng 9 — vụ M1 (đang mở), M2 (kết thúc trong kỳ), M3 (đang mở, bàn giao hàng loạt), M4 (của B).
    $m1 = m13bCsMatter($this->lawyerA);
    $m1->addTeamMember($this->assistantC, MatterRole::Assistant);
    $m2 = m13bCsMatter($this->lawyerA, ['stage' => 'enforcement']);
    $m3 = m13bCsMatter($this->lawyerA);
    $m3->addTeamMember($this->assistantC, MatterRole::Assistant);
    $m4 = m13bCsMatter($this->lawyerB);

    $missedByA = m13bCsDeadline($m1, $this->lawyerA, '2026-09-10');
    $doneOnTime = m13bCsDeadline($m1, $this->lawyerA, '2026-09-12');
    $missedByC = m13bCsDeadline($m3, $this->assistantC, '2026-09-25');
    m13bCsDeadline($m4, $this->lawyerB, '2026-09-18');

    $unansweredUnassigned = m13bCsThread($m1, '2026-09-05 09:00:00');
    $answered = m13bCsThread($m1, '2026-09-06 09:00:00');
    $assignedToA = m13bCsThread($m1, '2026-09-07 09:00:00');
    $assignedToC = m13bCsThread($m3, '2026-09-08 09:00:00');

    m13bCsAt('2026-09-06 11:00:00');
    m13bCsReply($answered, $this->lawyerA);
    m13bCsAt('2026-09-07 10:00:00');
    app(TriageClientRequest::class)->assign($assignedToA->fresh(), $this->lawyerA, $this->lawyerA);
    m13bCsAt('2026-09-08 10:00:00');
    app(TriageClientRequest::class)->assign($assignedToC->fresh(), $this->lawyerA, $this->assistantC);
    m13bCsAt('2026-09-12 15:00:00');
    app(SetDeadlineCompletion::class)->handle($doneOnTime->fresh(), true, $this->lawyerA);

    $entry = StageLog::factory()->for($m1)->make(['occurred_at' => '2026-09-15', 'from_stage' => 'intake', 'to_stage' => 'collecting_documents']);
    $entry->blameOn($this->lawyerA);
    $entry->save();

    m13bCsAt('2026-09-16 10:00:00');
    app(ReviewChecklistItem::class)->handle(
        MatterChecklistItem::factory()->for($m1)->status(ChecklistItemStatus::PendingReview)->create(),
        $this->lawyerA,
        ChecklistItemStatus::Accepted,
    );

    $contract = Contract::factory()->for($m1)->active()->create(['total_amount' => 5_000_000, 'signed_at' => '2026-08-01']);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 5_000_000, 'due_date' => '2026-09-15']);
    Payment::factory()->for($instalment)->create(['amount' => 5_000_000, 'paid_on' => '2026-09-17', 'attributed_lawyer_id' => $this->lawyerA->id]);

    m13bCsAt('2026-09-20 10:00:00');
    app(TransitionMatterStage::class)->handle($m2->fresh(), $this->lawyerA, 'closed', '2026-09-20', null, null, null, null, null, false);

    // 15/10 — tính "tháng trước".
    m13bCsAt('2026-10-15 10:00:00');
    $before = ['manager' => m13bCsRows($this->manager), 'admin' => m13bCsRows($this->admin)];

    // 16/10 — làm nốt việc tồn, rồi bàn giao.
    m13bCsAt('2026-10-16 09:00:00');
    app(SetDeadlineCompletion::class)->handle($missedByA->fresh(), true, $this->lawyerA);
    app(SetDeadlineCompletion::class)->handle($missedByC->fresh(), true, $this->assistantC);
    m13bCsReply($unansweredUnassigned, $this->lawyerA);
    m13bCsReply($assignedToA, $this->lawyerA);

    m13bCsAt('2026-10-16 14:00:00');
    foreach ([$m1, $m2] as $matter) {
        app(ReassignMatter::class)->handle(
            matter: $matter->fresh(),
            actor: $this->admin,
            newLead: $this->lawyerB,
            reason: 'Luật sư cũ chuyển công tác.',
            keepOldLeadAsAssociate: false,
        );
    }
    $bulk = app(ReassignMatters::class)->handle(
        matterIds: [$m3->id],
        actor: $this->admin,
        newLead: $this->lawyerB,
        reason: 'Nghỉ việc, bàn giao cả lô.',
        keepOldLeadAsAssociate: false,
        expectedLeadId: $this->lawyerA->id,
    );

    m13bCsAt('2026-10-16 16:00:00');
    $after = ['manager' => m13bCsRows($this->manager), 'admin' => m13bCsRows($this->admin)];

    // Tiền điều kiện: các thao tác sau kỳ đã thật sự xảy ra, và tháng 9 có số để giữ.
    expect($bulk[0]->success)->toBeTrue()
        ->and($m1->fresh()->lead_lawyer_id)->toBe($this->lawyerB->id)
        ->and($m2->fresh()->lead_lawyer_id)->toBe($this->lawyerB->id)
        ->and($missedByA->fresh()->is_completed)->toBeTrue()
        ->and($unansweredUnassigned->fresh()->answered_at)->not->toBeNull()
        ->and($assignedToA->fresh()->assigned_to)->toBe($this->lawyerB->id)
        ->and($before['manager'][$this->lawyerA->id]['deadlinesMissed'])->toBe(1)
        ->and($before['manager'][$this->assistantC->id]['deadlinesMissed'])->toBe(1)
        ->and($before['manager'][$this->lawyerA->id]['requestsReceived'])->toBe(3)
        ->and($before['manager'][$this->lawyerA->id]['requestsAnswered'])->toBe(1)
        ->and($before['manager'][$this->assistantC->id]['requestsReceived'])->toBe(1)
        ->and($before['manager'][$this->lawyerA->id]['mattersClosed'])->toBe(1)
        ->and($before['manager'][$this->lawyerA->id]['revenueCollected'])->toBe(5_000_000)
        ->and($before['manager'][$this->lawyerB->id]['requestsReceived'])->toBe(0);

    foreach (['manager', 'admin'] as $viewer) {
        expect(array_keys($after[$viewer]))->toBe(array_keys($before[$viewer]));

        foreach ($before[$viewer] as $key => $row) {
            foreach ($row as $field => $value) {
                expect($after[$viewer][$key][$field])->toEqual($value, "{$viewer}: dòng {$key}, trường {$field} đã trôi sau kỳ.");
            }
        }
    }
});
