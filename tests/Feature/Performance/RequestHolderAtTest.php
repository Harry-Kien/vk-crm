<?php

use App\Actions\Matter\ReassignMatter;
use App\Actions\Matter\ReassignMatters;
use App\Actions\Portal\ReplyToClientRequest;
use App\Actions\Portal\TriageClientRequest;
use App\Enums\ClientRequestStatus;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use App\Support\Performance\DeadlineHolderAtDue;
use App\Support\Performance\LeadAt;
use App\Support\Performance\RequestHolderAt;
use App\Support\Scopes\ClientPortalScope;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/**
 * M13 Task 3 — `RequestHolderAt` và `LeadAt` (R18): người giữ luồng yêu cầu của khách và luật sư phụ
 * trách vụ TẠI MỘT THỜI ĐIỂM, dựng lại từ nhật ký (`client_request_assigned`, `matter_reassigned`),
 * không từ người giữ hiện tại. Review Focus 3 của kế hoạch M13.
 *
 * Mặc định: luật sư A phụ trách vụ từ 01/09/2026; B là luật sư nhận bàn giao; C và D là trợ lý trong
 * đội ngũ. Hàm tiện ích mang tiền tố `m13bRh` (làn m13b, tệp này).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Queue::fake();
    Mail::fake();
    Notification::fake();

    $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lawyerA = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư A']);
    $this->lawyerB = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư B']);
    $this->assistantC = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý C']);
    $this->assistantD = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý D']);

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $this->lawyerA->id,
    ]);
    $this->matter->addTeamMember($this->assistantC, MatterRole::Assistant);
    $this->matter->addTeamMember($this->assistantD, MatterRole::Assistant);
});

/** Một luồng khách gửi lúc `$at` (mặc định 02/09 09:00), chưa giao ai. */
function m13bRhThread(Matter $matter, ClientUser $clientUser, string $at = '2026-09-02 09:00:00'): ClientRequest
{
    return ClientRequest::factory()->for($matter)->create([
        'client_user_id' => $clientUser->id,
        'status' => ClientRequestStatus::New,
        'created_at' => $at,
        'last_activity_at' => $at,
    ]);
}

/** Đọc lại luồng từ CSDL, kèm vụ việc (tiền điều kiện của `RequestHolderAt`). */
function m13bRhFresh(ClientRequest $thread): ClientRequest
{
    return ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->with('matter')->findOrFail($thread->getKey());
}

/** Người giữ `$thread` tại `$at`. */
function m13bRhHolderAt(ClientRequest $thread, string|CarbonInterface $at): ?int
{
    $at = $at instanceof CarbonInterface ? $at : Carbon::parse($at);

    return RequestHolderAt::resolve(collect([m13bRhFresh($thread)]), fn (): CarbonInterface => $at)[$thread->getKey()];
}

/** Luật sư phụ trách `$matter` tại `$at`. */
function m13bRhLeadAt(Matter $matter, string $at): ?int
{
    return LeadAt::resolve(collect([$matter->fresh()]), fn (): CarbonInterface => Carbon::parse($at))[$matter->getKey()];
}

/** `$at` dịch chuyển thời gian rồi bàn giao một vụ qua đúng Action của nút "Bàn giao". */
function m13bRhReassignAt(string $at, Matter $matter, User $actor, User $newLead): void
{
    test()->travelTo(Carbon::parse($at));

    app(ReassignMatter::class)->handle(
        matter: $matter->fresh(),
        actor: $actor,
        newLead: $newLead,
        reason: 'Luật sư cũ chuyển công tác.',
        keepOldLeadAsAssociate: false,
    );
}

function m13bRhAssignAt(string $at, ClientRequest $thread, User $actor, ?User $assignee): void
{
    test()->travelTo(Carbon::parse($at));

    app(TriageClientRequest::class)->assign(m13bRhFresh($thread), $actor, $assignee);
}

// =========================================================================================
// Luồng CHƯA GIAO AI (ca thường gặp nhất) — người giữ đi theo người phụ trách vụ TẠI thời điểm hỏi.
// =========================================================================================

it('gives an unassigned thread answered while A led the matter to A, after ReassignMatter moves the matter to B', function () {
    $thread = m13bRhThread($this->matter, $this->clientUser);

    $this->travelTo(Carbon::parse('2026-09-03 10:00:00'));
    app(ReplyToClientRequest::class)->handle(m13bRhFresh($thread), $this->lawyerA, 'Văn phòng đã nhận và trả lời.');

    m13bRhReassignAt('2026-10-05 10:00:00', $this->matter, $this->admin, $this->lawyerB);

    $answeredAt = m13bRhFresh($thread)->answered_at;

    expect($answeredAt?->toDateTimeString())->toBe('2026-09-03 10:00:00')
        ->and(m13bRhHolderAt($thread, $answeredAt))->toBe($this->lawyerA->id)
        ->and(m13bRhHolderAt($thread, now()))->toBe($this->lawyerB->id)
        // Bước 4 chỉ chuyển luồng giao đích danh cho luật sư cũ: luồng chưa giao không có dòng riêng.
        ->and(Activity::query()->where('event', RequestHolderAt::EVENT)->exists())->toBeFalse();
});

it('gives an unassigned thread answered while A led the matter to A, after ReassignMatters moves a batch to B', function () {
    $thread = m13bRhThread($this->matter, $this->clientUser);

    $this->travelTo(Carbon::parse('2026-09-03 10:00:00'));
    app(TriageClientRequest::class)->setStatus(m13bRhFresh($thread), $this->lawyerA, ClientRequestStatus::Answered);

    $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
    $results = app(ReassignMatters::class)->handle(
        matterIds: [$this->matter->id],
        actor: $this->admin,
        newLead: $this->lawyerB,
        reason: 'Nghỉ việc, bàn giao cả lô.',
        keepOldLeadAsAssociate: false,
        expectedLeadId: $this->lawyerA->id,
    );

    expect($results[0]->success)->toBeTrue()
        ->and(m13bRhHolderAt($thread, m13bRhFresh($thread)->answered_at))->toBe($this->lawyerA->id);
});

it('gives an unassigned, unanswered thread to A before the handover and to B after it', function () {
    $thread = m13bRhThread($this->matter, $this->clientUser);

    m13bRhReassignAt('2026-10-05 10:00:00', $this->matter, $this->admin, $this->lawyerB);

    expect(m13bRhHolderAt($thread, '2026-09-30 23:59:59'))->toBe($this->lawyerA->id)
        ->and(m13bRhHolderAt($thread, '2026-10-31 23:59:59'))->toBe($this->lawyerB->id);
});

// =========================================================================================
// Luồng GIAO ĐÍCH DANH — cho luật sư phụ trách, cho trợ lý.
// =========================================================================================

it('gives a thread assigned by name to A, then moved to B by ReassignMatter step 4, to A before the move and to B after', function () {
    $thread = m13bRhThread($this->matter, $this->clientUser);
    m13bRhAssignAt('2026-09-02 10:00:00', $thread, $this->lawyerA, $this->lawyerA);

    m13bRhReassignAt('2026-10-05 10:00:00', $this->matter, $this->admin, $this->lawyerB);

    expect(m13bRhFresh($thread)->assigned_to)->toBe($this->lawyerB->id)
        ->and(m13bRhHolderAt($thread, '2026-09-30 23:59:59'))->toBe($this->lawyerA->id)
        ->and(m13bRhHolderAt($thread, '2026-10-31 23:59:59'))->toBe($this->lawyerB->id);
});

it('writes one client_request_assigned row per thread ReassignMatter step 4 moves, and none for a thread it leaves', function () {
    $assignedToA = m13bRhThread($this->matter, $this->clientUser);
    $alsoAssignedToA = m13bRhThread($this->matter, $this->clientUser);
    $assignedToC = m13bRhThread($this->matter, $this->clientUser);
    $closedOfA = m13bRhThread($this->matter, $this->clientUser);
    $unassigned = m13bRhThread($this->matter, $this->clientUser);
    m13bRhAssignAt('2026-09-02 10:00:00', $assignedToA, $this->lawyerA, $this->lawyerA);
    m13bRhAssignAt('2026-09-02 10:00:00', $alsoAssignedToA, $this->lawyerA, $this->lawyerA);
    m13bRhAssignAt('2026-09-02 10:00:00', $assignedToC, $this->lawyerA, $this->assistantC);
    m13bRhAssignAt('2026-09-02 10:00:00', $closedOfA, $this->lawyerA, $this->lawyerA);
    app(TriageClientRequest::class)->setStatus(m13bRhFresh($closedOfA), $this->lawyerA, ClientRequestStatus::Closed);

    $before = Activity::query()->where('event', RequestHolderAt::EVENT)->pluck('id');
    m13bRhReassignAt('2026-10-05 10:00:00', $this->matter, $this->admin, $this->lawyerB);

    $rows = Activity::query()->where('event', RequestHolderAt::EVENT)->whereKeyNot($before)->orderBy('subject_id')->get();

    expect($rows->pluck('subject_id')->all())->toBe([$assignedToA->id, $alsoAssignedToA->id])
        ->and($rows->pluck('subject_type')->unique()->all())->toBe(['client_request'])
        ->and($rows->every(fn (Activity $row): bool => $row->causer?->is($this->admin) === true))->toBeTrue()
        ->and($rows->first()->properties->all())->toBe([
            'matter_id' => $this->matter->id,
            'client_id' => $this->matter->client_id,
            'from' => $this->lawyerA->id,
            'to' => $this->lawyerB->id,
            'reason' => ReassignMatter::REQUEST_HANDOVER_REASON,
        ])
        ->and(ReassignMatter::REQUEST_HANDOVER_REASON)->toBe('matter_reassigned')
        // Dòng `matter_reassigned` (số lượng) vẫn còn nguyên.
        ->and(Activity::query()->where('event', LeadAt::EVENT)->sole()->properties->get('client_requests_moved'))->toBe(2)
        ->and(m13bRhFresh($unassigned)->assigned_to)->toBeNull()
        ->and(m13bRhFresh($closedOfA)->assigned_to)->toBe($this->lawyerA->id);
});

it('keeps a thread assigned to assistant C with C through a matter handover', function () {
    $thread = m13bRhThread($this->matter, $this->clientUser);
    m13bRhAssignAt('2026-09-02 10:00:00', $thread, $this->lawyerA, $this->assistantC);

    m13bRhReassignAt('2026-10-05 10:00:00', $this->matter, $this->admin, $this->lawyerB);

    expect(m13bRhHolderAt($thread, '2026-09-30 23:59:59'))->toBe($this->assistantC->id)
        ->and(m13bRhHolderAt($thread, '2026-10-31 23:59:59'))->toBe($this->assistantC->id);
});

it('follows TriageClientRequest::assign from C to D to nobody, and the last stretch to the lead of that moment', function () {
    $thread = m13bRhThread($this->matter, $this->clientUser);
    m13bRhAssignAt('2026-09-02 10:00:00', $thread, $this->lawyerA, $this->assistantC);
    m13bRhAssignAt('2026-09-10 10:00:00', $thread, $this->lawyerA, $this->assistantD);
    m13bRhAssignAt('2026-09-20 10:00:00', $thread, $this->lawyerA, null);
    m13bRhReassignAt('2026-10-05 10:00:00', $this->matter, $this->admin, $this->lawyerB);

    expect(m13bRhHolderAt($thread, '2026-09-02 09:30:00'))->toBe($this->lawyerA->id)
        ->and(m13bRhHolderAt($thread, '2026-09-05 00:00:00'))->toBe($this->assistantC->id)
        ->and(m13bRhHolderAt($thread, '2026-09-15 00:00:00'))->toBe($this->assistantD->id)
        ->and(m13bRhHolderAt($thread, '2026-09-25 00:00:00'))->toBe($this->lawyerA->id)
        ->and(m13bRhHolderAt($thread, '2026-10-10 00:00:00'))->toBe($this->lawyerB->id);
});

it('reads the unassignment that a reopen after closing writes (client_request_assigned with to = null)', function () {
    $thread = m13bRhThread($this->matter, $this->clientUser);
    m13bRhAssignAt('2026-09-02 10:00:00', $thread, $this->lawyerA, $this->assistantC);

    $this->travelTo(Carbon::parse('2026-09-05 10:00:00'));
    app(TriageClientRequest::class)->setStatus(m13bRhFresh($thread), $this->lawyerA, ClientRequestStatus::Closed);
    $this->assistantC->update(['is_active' => false]);

    $this->travelTo(Carbon::parse('2026-09-10 10:00:00'));
    $result = app(TriageClientRequest::class)->setStatus(m13bRhFresh($thread), $this->lawyerA, ClientRequestStatus::InProgress);

    expect($result->unassignedAssignee?->is($this->assistantC))->toBeTrue()
        ->and(m13bRhHolderAt($thread, '2026-09-08 00:00:00'))->toBe($this->assistantC->id)
        ->and(m13bRhHolderAt($thread, '2026-09-12 00:00:00'))->toBe($this->lawyerA->id);
});

/**
 * Hai cách đọc người giữ luồng, có chủ đích (R18): "bây giờ" (`holderId()`, N9) bỏ người được giao đã
 * xoá mềm như đường thông báo; "trong kỳ" (`RequestHolderAt`) giữ người đó, vì đó là lịch sử.
 */
it('keeps a soft-deleted assignee as the holder (history) while holderId() falls back to the lead', function () {
    $thread = m13bRhThread($this->matter, $this->clientUser);
    m13bRhAssignAt('2026-09-02 10:00:00', $thread, $this->lawyerA, $this->assistantC);

    $this->travelTo(Carbon::parse('2026-09-05 10:00:00'));
    $this->assistantC->delete();

    expect(m13bRhHolderAt($thread, now()))->toBe($this->assistantC->id)
        ->and(m13bRhFresh($thread)->holderId())->toBe($this->lawyerA->id);
});

/**
 * Dòng lịch sử mới của `ReassignMatter` mang chủ thể là mốc/luồng của vụ — trang Nhật ký hệ thống
 * quy chúng về vụ qua `ActivityOwningMatter` như mọi dòng con, nên vụ `restricted` không lộ ra.
 */
it('keeps the new handover rows of a restricted matter off the activity log of a manager, and shows them to an admin', function () {
    $restricted = Matter::factory()->for($this->client)->create([
        'lead_lawyer_id' => $this->lawyerA->id,
        'confidentiality' => Confidentiality::Restricted,
    ]);
    Deadline::factory()->for($restricted)->create(['responsible_user_id' => $this->lawyerA->id, 'is_completed' => false]);
    $thread = m13bRhThread($restricted, $this->clientUser);
    m13bRhAssignAt('2026-09-02 10:00:00', $thread, $this->lawyerA, $this->lawyerA);
    m13bRhReassignAt('2026-09-15 10:00:00', $restricted, $this->admin, $this->lawyerB);

    $rows = Activity::query()
        ->whereIn('event', [DeadlineHolderAtDue::EVENT, RequestHolderAt::EVENT])
        ->where('properties->reason', ReassignMatter::DEADLINE_HANDOVER_REASON)
        ->get();
    $manager = User::factory()->withRole(Role::Manager)->create();

    expect($rows->pluck('event')->sort()->values()->all())->toBe([RequestHolderAt::EVENT, DeadlineHolderAtDue::EVENT]);

    $this->actingAs($manager, 'web');
    $this->livewire(ActivityLogPage::class)->assertCanNotSeeTableRecords($rows);

    $this->actingAs($this->admin, 'web');
    $this->livewire(ActivityLogPage::class)->assertCanSeeTableRecords($rows);
});

// =========================================================================================
// Đồng nhất tại now().
// =========================================================================================

it('agrees with holderId() at now() for every thread whose assignee still has an account, and LeadAt with lead_lawyer_id', function () {
    $otherMatter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyerB->id]);
    $quietMatter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyerA->id]);

    $unassigned = m13bRhThread($this->matter, $this->clientUser);
    $toC = m13bRhThread($this->matter, $this->clientUser);
    $toA = m13bRhThread($this->matter, $this->clientUser);
    $onOther = m13bRhThread($otherMatter, $this->clientUser);
    m13bRhAssignAt('2026-09-02 10:00:00', $toC, $this->lawyerA, $this->assistantC);
    m13bRhAssignAt('2026-09-02 10:00:00', $toA, $this->lawyerA, $this->lawyerA);
    m13bRhReassignAt('2026-09-10 10:00:00', $this->matter, $this->admin, $this->lawyerB);
    m13bRhReassignAt('2026-09-20 10:00:00', $this->matter, $this->admin, $this->lawyerA);
    m13bRhReassignAt('2026-09-25 10:00:00', $otherMatter, $this->admin, $this->lawyerA);
    $this->travelTo(Carbon::parse('2026-10-01 08:00:00'));

    $threads = ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->with('matter')
        ->whereKey([$unassigned->id, $toC->id, $toA->id, $onOther->id])->get();
    $matters = Matter::query()->whereKey([$this->matter->id, $otherMatter->id, $quietMatter->id])->get();

    expect(RequestHolderAt::resolve($threads, fn (): CarbonInterface => now()))
        ->toBe($threads->mapWithKeys(fn (ClientRequest $thread): array => [$thread->id => $thread->holderId()])->all())
        ->and(LeadAt::resolve($matters, fn (): CarbonInterface => now()))
        ->toBe($matters->mapWithKeys(fn (Matter $matter): array => [$matter->id => $matter->lead_lawyer_id])->all());
});

// =========================================================================================
// LeadAt — luật sư phụ trách tại một thời điểm.
// =========================================================================================

it('gives the lead at closed_at of a matter that closed and was then handed over from the matter page', function () {
    // Vụ kết thúc 05/09 (cột `date`). Ghi thẳng cột, không qua `TransitionMatterStage`: thứ được đo
    // ở đây là lần bàn giao SAU khi kết thúc, không phải lần kết thúc.
    DB::table('matters')->where('id', $this->matter->id)->update(['closed_at' => '2026-09-05']);

    $this->travelTo(Carbon::parse('2026-09-20 10:00:00'));
    $this->actingAs($this->lawyerA, 'web');

    $this->livewire(ViewMatter::class, ['record' => $this->matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $this->lawyerB->id,
            'keep_old_lead_as_associate' => false,
            'reason' => 'Bàn giao hồ sơ đã kết thúc để lưu trữ.',
        ])
        ->assertHasNoActionErrors();

    $closed = $this->matter->fresh();

    expect($closed->isClosed())->toBeTrue()
        ->and($closed->lead_lawyer_id)->toBe($this->lawyerB->id)
        ->and(LeadAt::resolve(collect([$closed]), fn (Matter $matter): CarbonInterface => $matter->closed_at))
        ->toBe([$closed->id => $this->lawyerA->id]);
});

it('walks two handovers: A before the first, B between them, the current lead after the second', function () {
    $lawyerE = User::factory()->withRole(Role::Lawyer)->create();
    m13bRhReassignAt('2026-09-10 10:00:00', $this->matter, $this->admin, $this->lawyerB);
    m13bRhReassignAt('2026-09-20 10:00:00', $this->matter, $this->admin, $lawyerE);

    expect(m13bRhLeadAt($this->matter, '2026-09-05 00:00:00'))->toBe($this->lawyerA->id)
        ->and(m13bRhLeadAt($this->matter, '2026-09-15 00:00:00'))->toBe($this->lawyerB->id)
        ->and(m13bRhLeadAt($this->matter, '2026-09-25 00:00:00'))->toBe($lawyerE->id);
});

it('treats a history row written at exactly the asked second as already in effect, for LeadAt and RequestHolderAt', function () {
    $thread = m13bRhThread($this->matter, $this->clientUser);
    m13bRhAssignAt('2026-09-02 10:00:00', $thread, $this->lawyerA, $this->assistantC);
    m13bRhReassignAt('2026-10-05 10:00:00', $this->matter, $this->admin, $this->lawyerB);

    expect(m13bRhLeadAt($this->matter, '2026-10-05 09:59:59'))->toBe($this->lawyerA->id)
        ->and(m13bRhLeadAt($this->matter, '2026-10-05 10:00:00'))->toBe($this->lawyerB->id)
        ->and(m13bRhHolderAt($thread, '2026-09-02 09:59:59'))->toBe($this->lawyerA->id)
        ->and(m13bRhHolderAt($thread, '2026-09-02 10:00:00'))->toBe($this->assistantC->id);
});

// =========================================================================================
// Dữ liệu hỏng, và chỉ đúng khoá sự kiện của đúng chủ thể.
// =========================================================================================

it('attributes a matter to nobody when the matter_reassigned row after the moment has an empty or non-numeric from_user_id', function () {
    $emptyFrom = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyerB->id]);
    $textFrom = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyerB->id]);
    $numericText = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyerB->id]);

    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
    Audit::record(LeadAt::EVENT, $emptyFrom, ['from_user_id' => null, 'to_user_id' => $this->lawyerB->id], $this->admin);
    Audit::record(LeadAt::EVENT, $textFrom, ['from_user_id' => 'luật sư A', 'to_user_id' => $this->lawyerB->id], $this->admin);
    Audit::record(LeadAt::EVENT, $numericText, ['from_user_id' => (string) $this->lawyerA->id, 'to_user_id' => $this->lawyerB->id], $this->admin);

    expect(LeadAt::resolve(Matter::query()->whereKey([$emptyFrom->id, $textFrom->id, $numericText->id])->get(), fn (): CarbonInterface => Carbon::parse('2026-09-10')))
        ->toBe([
            $emptyFrom->id => null,
            $textFrom->id => null,
            $numericText->id => $this->lawyerA->id,
        ]);
});

it('attributes a thread to nobody on a non-numeric or missing from, and to the lead of the moment on an empty from', function () {
    $textFrom = m13bRhThread($this->matter, $this->clientUser);
    $missingFrom = m13bRhThread($this->matter, $this->clientUser);
    $emptyFrom = m13bRhThread($this->matter, $this->clientUser);

    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
    Audit::record(RequestHolderAt::EVENT, $textFrom, ['from' => 'trợ lý C', 'to' => $this->assistantD->id], $this->admin);
    Audit::record(RequestHolderAt::EVENT, $missingFrom, ['to' => $this->assistantD->id], $this->admin);
    Audit::record(RequestHolderAt::EVENT, $emptyFrom, ['from' => null, 'to' => $this->assistantD->id], $this->admin);

    expect(m13bRhHolderAt($textFrom, '2026-09-10 00:00:00'))->toBeNull()
        ->and(m13bRhHolderAt($missingFrom, '2026-09-10 00:00:00'))->toBeNull()
        ->and(m13bRhHolderAt($emptyFrom, '2026-09-10 00:00:00'))->toBe($this->lawyerA->id);
});

it('reads only client_request_assigned rows of that thread and matter_reassigned rows of that matter', function () {
    $thread = m13bRhThread($this->matter, $this->clientUser);

    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
    foreach ([
        // Đúng sự kiện, khác loại chủ thể, cùng id.
        [RequestHolderAt::EVENT, 'deadline', $thread->id, ['from' => $this->assistantC->id, 'to' => null]],
        [LeadAt::EVENT, 'deadline', $this->matter->id, ['from_user_id' => $this->lawyerB->id, 'to_user_id' => $this->lawyerA->id]],
        // Đúng chủ thể, khác sự kiện.
        ['client_request_status_changed', 'client_request', $thread->id, ['from' => $this->assistantC->id, 'to' => null]],
        ['matter_details_updated', 'matter', $this->matter->id, ['from_user_id' => $this->lawyerB->id, 'to_user_id' => $this->lawyerA->id]],
    ] as [$event, $subjectType, $subjectId, $properties]) {
        Activity::query()->create([
            'log_name' => 'default',
            'description' => $event,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'properties' => $properties,
        ]);
    }
    // Đúng sự kiện, đúng loại chủ thể, khác id.
    $neighbour = m13bRhThread($this->matter, $this->clientUser);
    Audit::record(RequestHolderAt::EVENT, $neighbour, ['from' => $this->assistantD->id, 'to' => null], $this->admin);

    expect(m13bRhHolderAt($thread, '2026-09-10 00:00:00'))->toBe($this->lawyerA->id)
        ->and(m13bRhLeadAt($this->matter, '2026-09-10 00:00:00'))->toBe($this->lawyerA->id);
});

it('attributes an unassigned thread of a cancelled matter to nobody, and an assigned one to its assignee', function () {
    $unassigned = m13bRhThread($this->matter, $this->clientUser);
    $assigned = m13bRhThread($this->matter, $this->clientUser);
    m13bRhAssignAt('2026-09-02 10:00:00', $assigned, $this->lawyerA, $this->assistantC);

    $this->travelTo(Carbon::parse('2026-09-05 10:00:00'));
    DB::table('matters')->where('id', $this->matter->id)->update(['deleted_at' => now()]);

    $threads = ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->with('matter')
        ->whereKey([$unassigned->id, $assigned->id])->orderBy('id')->get();

    expect($threads->every(fn (ClientRequest $thread): bool => $thread->matter === null))->toBeTrue()
        ->and(RequestHolderAt::resolve($threads, fn (): CarbonInterface => now()))->toBe([
            $unassigned->id => null,
            $assigned->id => $this->assistantC->id,
        ]);
});

// =========================================================================================
// Số truy vấn: không đổi theo số phần tử, và đi qua index morph `subject`.
// =========================================================================================

/** R11: "một truy vấn cho cả lô, qua index morph `subject` của `activity_log`" — ghim hình dạng của nó. */
it('reads both histories through the subject morph index: event, subject type and exactly the batch ids', function () {
    $first = m13bRhThread($this->matter, $this->clientUser);
    $second = m13bRhThread($this->matter, $this->clientUser);
    $batch = ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->with('matter')
        ->whereKey([$first->id, $second->id])->orderBy('id')->get();

    DB::enableQueryLog();
    RequestHolderAt::resolve($batch, fn (): CarbonInterface => now());
    [$assignments, $leads] = DB::getQueryLog();

    $shape = fn (string $ids): string => '/from ["`]activity_log["`] where ["`]event["`] = \? and ["`]subject_type["`] = \? and ["`]subject_id["`] in \('.$ids.'\)/';

    expect($assignments['query'])->toMatch($shape($first->id.', '.$second->id))
        ->and($assignments['bindings'])->toBe([RequestHolderAt::EVENT, 'client_request'])
        ->and($leads['query'])->toMatch($shape((string) $this->matter->id))
        ->and($leads['bindings'])->toBe([LeadAt::EVENT, 'matter']);
});

it('returns an empty map for an empty batch without touching the database', function () {
    DB::enableQueryLog();

    expect(LeadAt::resolve(collect(), fn (): CarbonInterface => now()))->toBe([])
        ->and(RequestHolderAt::resolve(collect(), fn (): CarbonInterface => now()))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

it('reads the lead history of 3 matters or 30 in one query', function () {
    $queriesFor = function (int $count): int {
        $matters = collect(range(1, $count))->map(fn (): Matter => Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyerA->id]));
        $matters->each(fn (Matter $matter) => m13bRhReassignAt('2026-09-15 10:00:00', $matter, $this->admin, $this->lawyerB));

        $batch = Matter::query()->whereKey($matters->pluck('id'))->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $leads = LeadAt::resolve($batch, fn (): CarbonInterface => Carbon::parse('2026-09-10'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect(array_unique(array_values($leads)))->toBe([$this->lawyerA->id]);

        return $queries;
    };

    expect($queriesFor(3))->toBe(1)
        ->and($queriesFor(30))->toBe(1);
});

it('resolves 3 threads or 30 in two queries when their matters are loaded, and in a constant number when they are not', function () {
    $queriesFor = function (int $count, bool $preload): int {
        $matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyerA->id]);
        $matter->addTeamMember($this->assistantC, MatterRole::Assistant);
        $threads = collect(range(1, $count))->map(fn (): ClientRequest => m13bRhThread($matter, $this->clientUser));
        // Một nửa giao cho C, một nửa chưa giao ai: cả hai truy vấn đều có việc.
        $threads->each(fn (ClientRequest $thread, int $i) => $i % 2 === 0
            ? m13bRhAssignAt('2026-09-03 10:00:00', $thread, $this->lawyerA, $this->assistantC)
            : null);
        m13bRhReassignAt('2026-09-15 10:00:00', $matter, $this->admin, $this->lawyerB);

        $query = ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->whereKey($threads->pluck('id'));
        $batch = $preload ? $query->with('matter')->get() : $query->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $holders = RequestHolderAt::resolve($batch, fn (): CarbonInterface => Carbon::parse('2026-09-10'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect(collect($holders)->countBy()->all())->toBe([
            $this->assistantC->id => intdiv($count + 1, 2),
            $this->lawyerA->id => intdiv($count, 2),
        ]);

        return $queries;
    };

    expect($queriesFor(3, true))->toBe(2)
        ->and($queriesFor(30, true))->toBe(2)
        ->and($queriesFor(3, false))->toBe($queriesFor(30, false));
});
