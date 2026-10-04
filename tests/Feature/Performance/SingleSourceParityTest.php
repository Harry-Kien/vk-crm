<?php

use App\Actions\Document\ChecklistProgress;
use App\Actions\Document\ReviewChecklistItem;
use App\Actions\Matter\ReassignMatter;
use App\Actions\Portal\ReplyToClientRequest;
use App\Actions\Portal\TriageClientRequest;
use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\Confidentiality;
use App\Enums\DocumentGroup;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Widgets\PendingChecklistReviewsWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueOverTimeWidget;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\Payment;
use App\Models\StageLog;
use App\Models\User;
use App\Notifications\Staff\ClientRequestFollowUpAlert;
use App\Support\ActivityOwningMatter;
use App\Support\Audit;
use App\Support\Billing\CollectedRevenue;
use App\Support\MatterStaleness;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * M13 Task 2 — "không định nghĩa thứ hai": mỗi scope mới đặt CẠNH luật đang có, và tệp này ghim nó
 * vào đúng nơi đang dùng luật đó (widget trang chủ, đường thông báo, trang Doanh thu, `X/Y` của
 * từng vụ, trang nhật ký). Màn hình được đọc qua Livewire.
 *
 * Chạy trên SQLite (bộ thường) VÀ `test:mariadb` (tuần tự): các scope mới nối bảng
 * (`ClientRequest::scopeWithHolder()`, `Matter::scopeWithSupportingMember()`,
 * `ChecklistProgress::totalsByLead()`), và MariaDB strict báo cột mơ hồ hay `GROUP BY` thiếu cột
 * mà SQLite bỏ qua.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
});

/** Hai cận đủ giờ của một khoảng ngày — cùng hình dạng `RevenueFilters::bounds()`. */
function m13t2Bounds(string $from, string $to): array
{
    return [Carbon::parse($from)->startOfDay()->toDateTimeString(), Carbon::parse($to)->endOfDay()->toDateTimeString()];
}

/** @return list<int> khoá đã sắp, để so tập không phụ thuộc thứ tự */
function m13t2Keys(iterable $models): array
{
    return collect($models)->map(fn (mixed $model): int => (int) (is_object($model) ? $model->getKey() : $model))->sort()->values()->all();
}

// =================================================================================================
// Matter — closedWithin(), withSupportingMember(), ledBy(), workedOnBy(), ofConfidentiality()
// =================================================================================================

it('takes a matter closed on the first and on the last day of the period in closedWithin(), and never a cancelled one', function () {
    $closeAt = function (string $at): Matter {
        $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
        // Như `TransitionMatterStage` ghi: `now()` có giờ; SQLite giữ giờ, MariaDB cắt về ngày.
        $matter->forceFill(['closed_at' => Carbon::parse($at)])->save();

        return $matter;
    };

    $firstDay = $closeAt('2026-09-01 00:00:00');
    $lastDay = $closeAt('2026-09-30 22:15:00');
    $before = $closeAt('2026-08-31 23:00:00');
    $after = $closeAt('2026-10-01 00:00:00');
    $cancelled = $closeAt('2026-09-15 10:00:00');
    $cancelled->delete();
    $open = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    // `$to` mang giờ sáng: scope tự nâng nó lên 23:59:59, không để giờ của người gọi cắt ngày cuối.
    $within = fn (Builder $query): array => m13t2Keys($query->closedWithin(Carbon::parse('2026-09-01 09:00'), Carbon::parse('2026-09-30 08:00'))->get());

    expect($within(Matter::query()))->toBe(m13t2Keys([$firstDay, $lastDay]))
        ->and($within(Matter::query()->withTrashed()))->toBe(m13t2Keys([$firstDay, $lastDay]))
        ->and($within(Matter::query()))->not->toContain($before->getKey())
        ->and($within(Matter::query()))->not->toContain($after->getKey())
        ->and($within(Matter::query()))->not->toContain($open->getKey());
});

it('lists exactly the associate and assistant seats in withSupportingMember(), the seats the team relation holds', function () {
    $associate = User::factory()->withRole(Role::Lawyer)->create();
    $observer = User::factory()->withRole(Role::Lawyer)->create();

    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $matter->addTeamMember($associate, MatterRole::Associate);
    $matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $matter->addTeamMember($observer, MatterRole::Observer);

    $other = Matter::factory()->create(['lead_lawyer_id' => $associate->id]);
    $other->addTeamMember($this->assistant, MatterRole::Assistant);

    $seats = fn (Builder $query): array => $query->withSupportingMember()->get()
        ->map(fn (Matter $row): string => $row->getKey().'-'.$row->member_id)->sort()->values()->all();

    $fromRelation = Matter::query()->with('team')->get()
        ->flatMap(fn (Matter $row) => $row->team
            ->filter(fn (User $member): bool => in_array($member->pivot->role_in_matter, MatterRole::supporting(), true))
            ->map(fn (User $member): string => $row->getKey().'-'.$member->getKey()))
        ->sort()->values()->all();

    expect($seats(Matter::query()))->toBe($fromRelation)
        ->and($seats(Matter::query()))->toBe(collect([
            $matter->id.'-'.$associate->id,
            $matter->id.'-'.$this->assistant->id,
            $other->id.'-'.$this->assistant->id,
        ])->sort()->values()->all())
        ->and(MatterRole::supporting())->toBe([MatterRole::Associate, MatterRole::Assistant])
        // Nối bảng không vấp `listableBy()` của người KHÔNG có `matter.viewAny` (EXISTS trên đội ngũ).
        ->and($seats(Matter::query()->listableBy($associate)->open()))->toBe(collect([
            $matter->id.'-'.$associate->id,
            $matter->id.'-'.$this->assistant->id,
            $other->id.'-'.$this->assistant->id,
        ])->sort()->values()->all());

    // Hình dạng đếm theo người (N2, Task 4) trong docblock của scope: một `GROUP BY`, chạy được trên
    // MariaDB strict.
    $perMember = Matter::query()->listableBy($this->manager)->open()->withSupportingMember()
        ->select('supporting_seat.user_id as member_id')
        ->selectRaw('COUNT(*) as aggregate')
        ->groupBy('supporting_seat.user_id')
        ->toBase()
        ->get()
        ->mapWithKeys(fn (object $row): array => [(int) $row->member_id => (int) $row->aggregate])
        ->sortKeys()
        ->all();

    expect($perMember)->toBe(collect([$associate->id => 1, $this->assistant->id => 2])->sortKeys()->all());
});

it('reads workedOnBy() as led by the person or seated as associate or assistant, never as observer', function () {
    $person = User::factory()->withRole(Role::Lawyer)->create();

    $led = Matter::factory()->create(['lead_lawyer_id' => $person->id]);
    $asAssociate = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $asAssociate->addTeamMember($person, MatterRole::Associate);
    $asObserver = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $asObserver->addTeamMember($person, MatterRole::Observer);
    $notOnIt = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $assistantSeat = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $assistantSeat->addTeamMember($this->assistant, MatterRole::Assistant);

    expect(m13t2Keys(Matter::query()->workedOnBy($person)->get()))->toBe(m13t2Keys([$led, $asAssociate]))
        ->and(m13t2Keys(Matter::query()->ledBy($person)->get()))->toBe(m13t2Keys([$led]))
        ->and(m13t2Keys(Matter::query()->workedOnBy($this->assistant)->get()))->toBe(m13t2Keys([$assistantSeat]))
        ->and(m13t2Keys(Matter::query()->ledBy($this->lawyer)->get()))->toBe(m13t2Keys([$asAssociate, $asObserver, $notOnIt, $assistantSeat]))
        // Ghép với phạm vi xem: `workedOnBy()` chỉ thu hẹp, không nới.
        ->and(m13t2Keys(Matter::query()->listableBy($this->manager)->workedOnBy($person)->get()))->toBe(m13t2Keys([$led, $asAssociate]));
});

it('splits every matter into its two confidentiality levels with ofConfidentiality()', function () {
    $normal = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);

    expect(m13t2Keys(Matter::query()->ofConfidentiality(Confidentiality::Normal)->get()))->toBe([$normal->getKey()])
        ->and(m13t2Keys(Matter::query()->ofConfidentiality(Confidentiality::Restricted)->get()))->toBe([$restricted->getKey()]);
});

// =================================================================================================
// MatterStaleness::scopeNotMeasurable()
// =================================================================================================

it('splits every open matter into stale, not measurable, and published but fresh, with no overlap', function () {
    $this->travelTo(Carbon::parse('2026-10-10 09:00:00'));

    $make = fn (bool $published, int $daysSinceUpdate): Matter => Matter::factory()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'is_published_to_portal' => $published,
        'last_client_update_at' => now()->subDays($daysSinceUpdate),
    ]);

    $stale = $make(true, 20);
    $fresh = $make(true, 2);
    $unpublishedOld = $make(false, 30);
    $unpublishedNew = $make(false, 1);
    $closedUnpublished = $make(false, 30);
    $closedUnpublished->forceFill(['closed_at' => now()])->save();
    $cancelledUnpublished = $make(false, 30);
    $cancelledUnpublished->delete();

    $staleIds = m13t2Keys(MatterStaleness::scopeStale(Matter::query())->get());
    $notMeasurableIds = m13t2Keys(MatterStaleness::scopeNotMeasurable(Matter::query())->get());
    $publishedFreshIds = m13t2Keys(Matter::query()->open()->where('is_published_to_portal', true)->whereKeyNot($staleIds)->get());

    expect($staleIds)->toBe([$stale->getKey()])
        ->and($notMeasurableIds)->toBe(m13t2Keys([$unpublishedOld, $unpublishedNew]))
        ->and(array_intersect($staleIds, $notMeasurableIds))->toBe([])
        ->and(m13t2Keys([...$staleIds, ...$notMeasurableIds, ...$publishedFreshIds]))->toBe(m13t2Keys(Matter::query()->open()->get()))
        ->and($publishedFreshIds)->toBe([$fresh->getKey()]);
});

// =================================================================================================
// MatterChecklistItem::scopeAwaitingReview() ↔ PendingChecklistReviewsWidget
// =================================================================================================

it('keeps awaitingReview() and the pending reviews widget on the same checklist items', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $closed = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $closed->forceFill(['closed_at' => now()])->save();

    $items = collect(ChecklistItemStatus::cases())->mapWithKeys(fn (ChecklistItemStatus $status): array => [
        $status->value => MatterChecklistItem::factory()->for($matter)->status($status)->create(['rejection_reason' => $status === ChecklistItemStatus::Rejected ? str_repeat('a', 25) : null]),
    ]);
    // Widget không lọc `open()` (docblock của widget): một đầu mục chờ duyệt trên vụ đã đóng vẫn hiện.
    $onClosed = MatterChecklistItem::factory()->for($closed)->status(ChecklistItemStatus::PendingReview)->create();

    $awaiting = m13t2Keys(MatterChecklistItem::query()->awaitingReview()->get());

    expect($awaiting)->toBe(m13t2Keys([$items[ChecklistItemStatus::PendingReview->value], $onClosed]))
        ->and(m13t2Keys(PendingChecklistReviewsWidget::rowsFor($this->lawyer)->get()))->toBe($awaiting);

    $this->actingAs($this->lawyer, 'web');

    $this->livewire(PendingChecklistReviewsWidget::class)
        ->assertCanSeeTableRecords([$items[ChecklistItemStatus::PendingReview->value], $onClosed])
        ->assertCanNotSeeTableRecords($items->except(ChecklistItemStatus::PendingReview->value)->values()->all());
});

// =================================================================================================
// ChecklistProgress::totalsByLead() ↔ ChecklistProgress::handle()
// =================================================================================================

/** Một đầu mục, kèm (tuỳ chọn) một tài liệu nhóm `$group`; `$documentTrashed` xoá mềm tài liệu đó. */
function m13t2Item(Matter $matter, bool $required, ChecklistItemStatus $status, ?DocumentGroup $group = null, bool $documentTrashed = false): MatterChecklistItem
{
    $item = MatterChecklistItem::factory()->for($matter)->status($status)->create([
        'is_required' => $required,
        'rejection_reason' => $status === ChecklistItemStatus::Rejected ? str_repeat('a', 25) : null,
    ]);

    if ($group !== null) {
        $document = Document::factory()->group($group)->create([
            'matter_id' => $matter->id,
            'matter_checklist_item_id' => $item->id,
        ]);

        if ($documentTrashed) {
            $document->delete();
        }
    }

    return $item;
}

/** Mọi trường hợp mẫu số `Y` của SPEC §4.10 phải phân biệt, trên một vụ. */
function m13t2FullChecklist(Matter $matter): void
{
    m13t2Item($matter, true, ChecklistItemStatus::Accepted);
    m13t2Item($matter, true, ChecklistItemStatus::Missing);
    m13t2Item($matter, true, ChecklistItemStatus::PendingReview, DocumentGroup::ClientProvided);
    m13t2Item($matter, true, ChecklistItemStatus::Rejected);
    m13t2Item($matter, true, ChecklistItemStatus::NotApplicable);
    // Không bắt buộc, có tài liệu nhóm A: vào `Y`.
    m13t2Item($matter, false, ChecklistItemStatus::Missing, DocumentGroup::ClientProvided);
    m13t2Item($matter, false, ChecklistItemStatus::Accepted, DocumentGroup::ClientProvided);
    // Không bắt buộc, chỉ có tài liệu nhóm B: KHÔNG vào `Y`.
    m13t2Item($matter, false, ChecklistItemStatus::Accepted, DocumentGroup::Issued);
    // Không bắt buộc, tài liệu nhóm A đã xoá mềm: KHÔNG vào `Y`.
    m13t2Item($matter, false, ChecklistItemStatus::Missing, DocumentGroup::ClientProvided, documentTrashed: true);
    // Không bắt buộc, "không cần nộp", không tài liệu: KHÔNG vào `Y`.
    m13t2Item($matter, false, ChecklistItemStatus::NotApplicable);
    // Đầu mục đã gỡ khỏi danh mục: không có mặt ở vế nào.
    m13t2Item($matter, true, ChecklistItemStatus::Accepted)->delete();
}

/** @return array<int, array{settled: int, total: int}> tổng `handle()` của từng vụ, theo người phụ trách */
function m13t2HandleTotals(Builder $matters): array
{
    return $matters->get()
        ->groupBy('lead_lawyer_id')
        ->map(function ($group): array {
            $totals = $group->map(fn (Matter $matter): array => app(ChecklistProgress::class)->handle($matter));

            return ['settled' => $totals->sum('submitted'), 'total' => $totals->sum('total')];
        })
        ->filter(fn (array $totals): bool => $totals['total'] > 0)
        ->sortKeys()
        ->all();
}

it('adds totalsByLead() up to the X/Y of every matter, lead by lead, and only over the matters it is handed', function () {
    $otherLead = User::factory()->withRole(Role::Lawyer)->create();
    $quietLead = User::factory()->withRole(Role::Lawyer)->create();

    m13t2FullChecklist(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]));
    m13t2FullChecklist(Matter::factory()->unpublished()->create(['lead_lawyer_id' => $this->lawyer->id]));

    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $otherLead->id]);
    m13t2Item($otherMatter, true, ChecklistItemStatus::Accepted);
    m13t2Item($otherMatter, true, ChecklistItemStatus::Missing);
    m13t2Item($otherMatter, false, ChecklistItemStatus::Accepted, DocumentGroup::ClientProvided);

    // Vụ `restricted` của người phụ trách khác: trưởng phòng không thấy, nên tổng của trưởng phòng
    // không được mang nó.
    m13t2FullChecklist(Matter::factory()->restricted()->create(['lead_lawyer_id' => $otherLead->id]));

    // Người phụ trách chỉ có đầu mục ngoài `Y`: không có dòng (0/0 là "chưa có gì để đếm").
    m13t2Item(Matter::factory()->create(['lead_lawyer_id' => $quietLead->id]), false, ChecklistItemStatus::NotApplicable);

    $all = ChecklistProgress::totalsByLead(Matter::query());
    ksort($all);

    $asManager = ChecklistProgress::totalsByLead(Matter::query()->listableBy($this->manager));
    ksort($asManager);

    expect($all)->toBe(m13t2HandleTotals(Matter::query()))
        ->and($asManager)->toBe(m13t2HandleTotals(Matter::query()->listableBy($this->manager)))
        ->and($all[$this->lawyer->id])->toBe(['settled' => 6, 'total' => 14])
        ->and($all[$otherLead->id])->toBe(['settled' => 5, 'total' => 10])
        ->and($asManager[$otherLead->id])->toBe(['settled' => 2, 'total' => 3])
        ->and($all)->not->toHaveKey($quietLead->id);
});

// =================================================================================================
// CollectedRevenue ↔ RevenueOverTimeWidget
// =================================================================================================

function m13t2Payment(User $lead, int $amount, string $paidOn, bool $restricted = false, bool $voided = false): Payment
{
    $matter = ($restricted ? Matter::factory()->restricted() : Matter::factory())->create(['lead_lawyer_id' => $lead->id]);
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => $amount, 'signed_at' => '2026-08-01']);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => $amount, 'due_date' => '2026-09-15']);

    return ($voided ? Payment::factory()->voided() : Payment::factory())->for($instalment)->create([
        'amount' => $amount,
        'paid_on' => $paidOn,
        'attributed_lawyer_id' => $lead->id,
    ]);
}

/** Tổng các cột của `RevenueOverTimeWidget` đã mount thật qua Livewire, kỳ tuỳ chọn tháng 9/2026. */
function m13t2RevenueWidgetTotal(array $extraFilters = []): int
{
    $instance = Livewire::test(RevenueOverTimeWidget::class, ['pageFilters' => [
        'period' => 'custom',
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
        ...$extraFilters,
    ]])->instance();

    $getData = new ReflectionMethod($instance, 'getData');

    return (int) array_sum($getData->invoke($instance)['datasets'][0]['data']);
}

it('sums CollectedRevenue::query() to the over-time widget for every viewer, a payment on the last day of the period included', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $colleague = User::factory()->withRole(Role::Lawyer)->create();

    m13t2Payment($this->lawyer, 1_000_000, '2026-09-30');
    m13t2Payment($this->lawyer, 2_000_000, '2026-09-01');
    m13t2Payment($this->lawyer, 4_000_000, '2026-10-01');
    m13t2Payment($this->lawyer, 8_000_000, '2026-08-31');
    m13t2Payment($this->lawyer, 16_000_000, '2026-09-15', voided: true);
    m13t2Payment($this->lawyer, 32_000_000, '2026-09-10', restricted: true);
    m13t2Payment($colleague, 64_000_000, '2026-09-20');

    $bounds = m13t2Bounds('2026-09-01', '2026-09-30');

    foreach ([
        [$this->admin, 99_000_000],
        [$this->manager, 67_000_000],
        [$this->lawyer, 35_000_000],
    ] as [$viewer, $expected]) {
        $this->actingAs($viewer, 'web');

        expect((int) CollectedRevenue::query($viewer, $bounds)->sum('amount'))->toBe($expected)
            ->and(m13t2RevenueWidgetTotal())->toBe($expected);
    }

    // Bộ lọc luật sư vẫn ở widget, gắn THÊM vào truy vấn trả về.
    $this->actingAs($this->admin, 'web');

    expect(m13t2RevenueWidgetTotal(['lawyer_id' => $this->lawyer->id]))->toBe(35_000_000)
        ->and((int) CollectedRevenue::query($this->admin, $bounds)->where('attributed_lawyer_id', $this->lawyer->id)->sum('amount'))->toBe(35_000_000);
});

// =================================================================================================
// ClientRequest — người giữ luồng "bây giờ" ↔ ReplyToClientRequest::notifyHolderOfFollowUp()
// =================================================================================================

it('names the same holder in holderId(), withHolder() and the follow-up notification, on four kinds of thread', function () {
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->create(['lead_lawyer_id' => $this->lawyer->id, 'is_published_to_portal' => true]);

    $departing = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $matter->addTeamMember($departing, MatterRole::Assistant);

    $thread = fn (Matter $on): ClientRequest => ClientRequest::factory()->for($on)->create(['client_user_id' => $clientUser->id, 'status' => ClientRequestStatus::New]);

    // 1. chưa giao ai.
    $unassigned = $thread($matter);
    // 2. giao cho trợ lý.
    $toAssistant = $thread($matter);
    app(TriageClientRequest::class)->assign($toAssistant, $this->lawyer, $this->assistant);
    // 3. giao cho người sau đó bị xoá mềm.
    $toDeparted = $thread($matter);
    app(TriageClientRequest::class)->assign($toDeparted, $this->lawyer, $departing);
    $departing->delete();
    // 4. luật sư phụ trách đã đổi.
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $handedOver = Matter::factory()->for($client)->create(['lead_lawyer_id' => $this->lawyer->id, 'is_published_to_portal' => true]);
    $afterHandover = $thread($handedOver);
    app(ReassignMatter::class)->handle($handedOver, $this->admin, $newLead, 'Bàn giao vụ để kiểm người giữ luồng.', false, sendDigest: false);

    $expected = [
        $unassigned->id => $this->lawyer->id,
        $toAssistant->id => $this->assistant->id,
        $toDeparted->id => $this->lawyer->id,
        $afterHandover->id => $newLead->id,
    ];

    $fromSql = ClientRequest::query()->withHolder()->get()
        ->mapWithKeys(fn (ClientRequest $request): array => [$request->getKey() => (int) $request->holder_id])
        ->all();

    $candidates = collect([$this->lawyer, $this->assistant, $newLead, User::withTrashed()->findOrFail($departing->id)]);

    foreach ($expected as $requestId => $holderId) {
        $request = ClientRequest::query()->findOrFail($requestId);

        Notification::fake();
        app(ReplyToClientRequest::class)->handle($request, $clientUser, 'Tôi hỏi thêm một ý nữa ạ.');

        $notified = $candidates
            ->filter(fn (User $user): bool => Notification::sent($user, ClientRequestFollowUpAlert::class)->isNotEmpty())
            ->map(fn (User $user): int => $user->getKey())
            ->values()
            ->all();

        expect($request->fresh()->holderId())->toBe($holderId)
            ->and($fromSql[$requestId])->toBe($holderId)
            ->and($notified)->toBe([$holderId]);
    }

    expect(m13t2Keys(ClientRequest::query()->heldBy($this->lawyer)->get()))->toBe(m13t2Keys([$unassigned, $toDeparted]))
        ->and(m13t2Keys(ClientRequest::query()->heldBy($this->assistant)->get()))->toBe([$toAssistant->getKey()])
        ->and(m13t2Keys(ClientRequest::query()->heldBy($newLead)->get()))->toBe([$afterHandover->getKey()])
        ->and(ClientRequest::query()->heldBy($departing)->count())->toBe(0);
});

/**
 * Vụ đã huỷ: quan hệ `matter` (và đường thông báo, đọc vụ qua truy vấn có `SoftDeletes`) không thấy
 * vụ, nên không có luật sư phụ trách nào để rơi về — hai hình dạng cùng nói "không ai", trừ khi
 * người được giao còn tài khoản.
 */
it('names nobody as the holder of an unassigned thread on a cancelled matter, in both shapes', function () {
    $cancelled = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $cancelled->addTeamMember($this->assistant, MatterRole::Assistant);

    $unassigned = ClientRequest::factory()->for($cancelled)->create(['status' => ClientRequestStatus::New]);
    $assigned = ClientRequest::factory()->for($cancelled)->create(['status' => ClientRequestStatus::InProgress, 'assigned_to' => $this->assistant->id]);
    $cancelled->delete();

    $fromSql = ClientRequest::query()->withHolder()->get()
        ->mapWithKeys(fn (ClientRequest $request): array => [$request->getKey() => $request->holder_id === null ? null : (int) $request->holder_id])
        ->all();

    expect($unassigned->fresh()->holderId())->toBeNull()
        ->and($fromSql[$unassigned->id])->toBeNull()
        ->and($assigned->fresh()->holderId())->toBe($this->assistant->id)
        ->and($fromSql[$assigned->id])->toBe($this->assistant->id);
});

it('reads awaitingOffice() as new or in progress', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $threads = collect(ClientRequestStatus::cases())->mapWithKeys(fn (ClientRequestStatus $status): array => [
        $status->value => ClientRequest::factory()->for($matter)->create(['status' => $status]),
    ]);

    expect(m13t2Keys(ClientRequest::query()->awaitingOffice()->get()))->toBe(m13t2Keys([
        $threads[ClientRequestStatus::New->value],
        $threads[ClientRequestStatus::InProgress->value],
    ]));
});

/**
 * `withHolder()` nối `users` và `matters`: mọi cột của các scope đi cùng phải viết đủ tên bảng, không
 * thì MariaDB báo cột mơ hồ (`created_at`, `deleted_at`, `id` có ở cả ba bảng), và một `GROUP BY` thiếu
 * cột thì MariaDB strict từ chối. Cùng tệp chạy dưới `test:mariadb`.
 */
it('runs withHolder() with createdBetween(), awaitingOffice() and the listable matter filter, and groups by holder', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $make = fn (Matter $on, string $createdAt, ClientRequestStatus $status = ClientRequestStatus::New, ?User $assignee = null): ClientRequest => ClientRequest::factory()->for($on)->create([
        'status' => $status,
        'created_at' => $createdAt,
        'assigned_to' => $assignee?->getKey(),
    ]);

    $firstSecond = $make($matter, '2026-09-01 00:00:00');
    $lastSecond = $make($matter, '2026-09-30 23:59:59', ClientRequestStatus::InProgress, $this->assistant);
    $answered = $make($matter, '2026-09-15 10:00:00', ClientRequestStatus::Answered);
    $nextMonth = $make($matter, '2026-10-01 00:00:00');
    $previousMonth = $make($matter, '2026-08-31 23:59:59');
    $onRestricted = $make($restricted, '2026-09-10 10:00:00');

    $scoped = fn (User $viewer): Builder => ClientRequest::query()
        ->createdBetween(m13t2Bounds('2026-09-01', '2026-09-30'))
        ->awaitingOffice()
        ->whereHas('matter', fn (Builder $matters): Builder => $matters->open()->listableBy($viewer))
        ->withHolder();

    expect(m13t2Keys($scoped($this->manager)->get()))->toBe(m13t2Keys([$firstSecond, $lastSecond]))
        ->and(m13t2Keys($scoped($this->lawyer)->get()))->toBe(m13t2Keys([$firstSecond, $lastSecond, $onRestricted]))
        ->and(m13t2Keys(ClientRequest::query()->createdBetween(m13t2Bounds('2026-09-01', '2026-09-30'))->get()))->toBe(m13t2Keys([$firstSecond, $lastSecond, $answered, $onRestricted]));

    // Hình dạng gộp theo người giữ (N9, Task 4): một truy vấn GROUP BY trên biểu thức người giữ.
    $perHolder = $scoped($this->lawyer)
        ->select(DB::raw(ClientRequest::holderIdSql().' as holder_id'))
        ->selectRaw('COUNT(*) as aggregate')
        ->groupBy(DB::raw(ClientRequest::holderIdSql()))
        ->toBase()
        ->get()
        ->mapWithKeys(fn (object $row): array => [(int) $row->holder_id => (int) $row->aggregate])
        ->sortKeys()
        ->all();

    expect($perHolder)->toBe(collect([$this->lawyer->id => 2, $this->assistant->id => 1])->sortKeys()->all())
        ->and(m13t2Keys($scoped($this->admin)->get()))->not->toContain($nextMonth->getKey())
        ->and(m13t2Keys($scoped($this->admin)->get()))->not->toContain($previousMonth->getKey());
});

it('calls a thread closed straight from new closed without an answer, and not one answered before it was closed', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $triage = app(TriageClientRequest::class);

    $closedUnanswered = ClientRequest::factory()->for($matter)->create(['status' => ClientRequestStatus::New]);
    $triage->setStatus($closedUnanswered, $this->lawyer, ClientRequestStatus::Closed);

    $answeredThenClosed = ClientRequest::factory()->for($matter)->create(['status' => ClientRequestStatus::New]);
    app(ReplyToClientRequest::class)->handle($answeredThenClosed, $this->lawyer, 'Văn phòng trả lời anh/chị như sau.');
    $triage->setStatus($answeredThenClosed->fresh(), $this->lawyer, ClientRequestStatus::Closed);

    $answeredByPhoneThenClosed = ClientRequest::factory()->for($matter)->create(['status' => ClientRequestStatus::New]);
    $triage->setStatus($answeredByPhoneThenClosed, $this->lawyer, ClientRequestStatus::Answered);
    $triage->setStatus($answeredByPhoneThenClosed->fresh(), $this->lawyer, ClientRequestStatus::Closed);

    $stillOpen = ClientRequest::factory()->for($matter)->create(['status' => ClientRequestStatus::New]);

    expect($closedUnanswered->fresh()->isClosedWithoutAnswer())->toBeTrue()
        ->and($answeredThenClosed->fresh()->isClosedWithoutAnswer())->toBeFalse()
        ->and($answeredByPhoneThenClosed->fresh()->isClosedWithoutAnswer())->toBeFalse()
        ->and($stillOpen->fresh()->isClosedWithoutAnswer())->toBeFalse();
});

it('counts a thread answered at 23:59:59 on the last day as answered by the cutoff, and not by a cutoff one second earlier', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $answered = ClientRequest::factory()->for($matter)->create([
        'status' => ClientRequestStatus::Answered,
        'answered_at' => '2026-09-30 23:59:59',
    ]);
    $never = ClientRequest::factory()->for($matter)->create(['status' => ClientRequestStatus::InProgress, 'answered_at' => null]);

    expect($answered->fresh()->answeredBy(Carbon::parse('2026-09-30 23:59:59')))->toBeTrue()
        ->and($answered->fresh()->answeredBy(Carbon::parse('2026-09-30 23:59:58')))->toBeFalse()
        ->and($never->fresh()->answeredBy(Carbon::parse('2026-12-31 23:59:59')))->toBeFalse();
});

// =================================================================================================
// StageLog::scopeOccurredBetween()
// =================================================================================================

it('takes a stage log dated on the first and on the last day in occurredBetween(), and composes with entries()', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $log = fn (string $occurredAt, ?string $from = 'intake', ?string $to = 'filed'): StageLog => StageLog::factory()->for($matter)->create([
        'occurred_at' => $occurredAt,
        'from_stage' => $from,
        'to_stage' => $to,
    ]);

    $firstDay = $log('2026-09-01 00:00:00');
    $lastDay = $log('2026-09-30 00:00:00');
    $lastSecond = $log('2026-09-30 23:59:59');
    $sameStage = $log('2026-09-15 00:00:00', 'filed', 'filed');
    $before = $log('2026-08-31 23:59:59');
    $after = $log('2026-10-01 00:00:00');

    $bounds = m13t2Bounds('2026-09-01', '2026-09-30');

    expect(m13t2Keys(StageLog::query()->occurredBetween($bounds)->get()))->toBe(m13t2Keys([$firstDay, $lastDay, $lastSecond, $sameStage]))
        ->and(m13t2Keys(StageLog::query()->entries()->occurredBetween($bounds)->get()))->toBe(m13t2Keys([$firstDay, $lastDay, $lastSecond]))
        ->and(m13t2Keys(StageLog::query()->occurredBetween($bounds)->get()))->not->toContain($before->getKey())
        ->and(m13t2Keys(StageLog::query()->occurredBetween($bounds)->get()))->not->toContain($after->getKey());
});

// =================================================================================================
// ActivityOwningMatter::scopeOwnedByVisibleMatters(), scopeEventsWithin(); ReviewChecklistItem::AUDIT_EVENT
// =================================================================================================

/** @return list<int> trong `$among`, những dòng `scopeOwnedByVisibleMatters()` thả cho `$viewer` */
function m13t2OwnedRows(User $viewer, array $among): array
{
    $query = Activity::query()->whereKey($among);
    ActivityOwningMatter::scopeOwnedByVisibleMatters($query, $viewer);

    return m13t2Keys($query->pluck('id'));
}

/** @return list<int> trong `$among`, những dòng trang Nhật ký hệ thống thả cho `$viewer` */
function m13t2LogPageRows(User $viewer, array $among): array
{
    $query = Activity::query()->whereKey($among);
    ActivityOwningMatter::scopeVisibleTo($query, $viewer);

    return m13t2Keys($query->pluck('id'));
}

it('keeps login rows and restricted matter rows out of scopeOwnedByVisibleMatters() for a manager, and gives the lead and the admin the restricted ones', function () {
    $normal = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $normal->addTeamMember($this->assistant, MatterRole::Assistant);
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $cancelled = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $login = Audit::record('login_success', null, ['guard' => 'web'], causer: $this->lawyer)->getKey();
    $onNormal = Audit::record('deadline_added', Deadline::factory()->for($normal)->create(), ['matter_id' => $normal->id], causer: $this->lawyer)->getKey();
    $onMatter = Audit::record('matter_reassigned', $normal, [], causer: $this->lawyer)->getKey();
    $byProperty = Audit::record('conflict_check_run', null, ['matter_id' => $normal->id], causer: $this->lawyer)->getKey();
    $onRestricted = Audit::record('deadline_added', Deadline::factory()->for($restricted)->create(), ['matter_id' => $restricted->id], causer: $this->lawyer)->getKey();
    $onCancelled = Audit::record('deadline_added', Deadline::factory()->for($cancelled)->create(), ['matter_id' => $cancelled->id], causer: $this->lawyer)->getKey();
    $cancelled->delete();

    $contract = Contract::factory()->for($normal)->active()->create(['total_amount' => 5_000_000, 'signed_at' => '2026-08-01']);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 5_000_000]);
    $money = Audit::record('payment_recorded', Payment::factory()->for($instalment)->create(['amount' => 5_000_000, 'attributed_lawyer_id' => $this->lawyer->id]), [], causer: $this->lawyer)->getKey();

    $among = [$login, $onNormal, $onMatter, $byProperty, $onRestricted, $onCancelled, $money];

    expect(m13t2OwnedRows($this->manager, $among))->toBe(m13t2Keys([$onNormal, $onMatter, $byProperty, $money]))
        ->and(m13t2OwnedRows($this->lawyer, $among))->toBe(m13t2Keys([$onNormal, $onMatter, $byProperty, $money, $onRestricted]))
        // Admin KHÔNG được lối tắt của `scopeVisibleTo()`: dòng đăng nhập không thuộc vụ nào nên không tính.
        ->and(m13t2OwnedRows($this->admin, $among))->toBe(m13t2Keys([$onNormal, $onMatter, $byProperty, $money, $onRestricted]))
        // Dòng tiền chỉ cho người có `billing.view`: trợ lý trong đội không có.
        ->and(m13t2OwnedRows($this->assistant, $among))->toBe(m13t2Keys([$onNormal, $onMatter, $byProperty]));

    // Lựa chọn của Task 2, ghim lại: con số M13 bỏ vụ ĐÃ HUỶ (như mọi con số khác, tập gốc là
    // `listableBy()` không `withTrashed()`), trong khi trang Nhật ký hệ thống vẫn hiện dòng đó.
    expect(m13t2LogPageRows($this->manager, $among))->toContain($onCancelled)
        ->and(m13t2LogPageRows($this->manager, $among))->toContain($login)
        ->and(m13t2OwnedRows($this->manager, $among))->not->toContain($onCancelled);
});

/**
 * Nhánh "M10 chưa gộp" của kế hoạch (Task 2, bước `scopeOwnedByVisibleMatters()`): khi M10 gộp,
 * `ActivityOwningMatter::scopeVisibleTo()` có thêm cổng bản ghi tiếp nhận (`INTAKE_REQUEST`,
 * `visibleIntakes()`). Người gộp tách lớp chồng đó thành một hàm `private` dùng chung và gọi ở CẢ
 * `scopeVisibleTo()` lẫn `scopeOwnedByVisibleMatters()`, rồi viết test này: một dòng chủ thể
 * `intake_request` mang `properties.matter_id` của một vụ trưởng phòng XEM ĐƯỢC, nhưng bản ghi tiếp
 * nhận đó trưởng phòng KHÔNG xem được — dòng không được tính vào N11/P6 của trưởng phòng; cặp dương:
 * người xem được bản ghi tiếp nhận thì dòng được tính.
 */
it('drops an intake_request row whose matter the manager can see but whose intake record they cannot (M10 gate)')->todo();

it('counts an event logged at 23:59:59 on the last day in scopeEventsWithin(), and not one logged at midnight after it', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->create();

    $at = function (string $when, string $event = ReviewChecklistItem::AUDIT_EVENT) use ($item): int {
        $this->travelTo(Carbon::parse($when));

        return Audit::record($event, $item, ['matter_id' => $item->matter_id, 'status' => 'accepted'], causer: $this->lawyer)->getKey();
    };

    $firstSecond = $at('2026-09-01 00:00:00');
    $lastSecond = $at('2026-09-30 23:59:59');
    $nextMidnight = $at('2026-10-01 00:00:00');
    $before = $at('2026-08-31 23:59:59');
    $otherEvent = $at('2026-09-15 10:00:00', 'checklist_item_added');

    $query = Activity::query()->whereKey([$firstSecond, $lastSecond, $nextMidnight, $before, $otherEvent]);
    ActivityOwningMatter::scopeEventsWithin($query, ReviewChecklistItem::AUDIT_EVENT, m13t2Bounds('2026-09-01', '2026-09-30'));

    expect(m13t2Keys($query->pluck('id')))->toBe(m13t2Keys([$firstSecond, $lastSecond]));
});

/**
 * `ActivityLogEventTranslationsTest` chỉ quét LITERAL `Audit::record('…')`; từ Task 2 tên sự kiện
 * duyệt đầu mục đi qua hằng số, nên test đó không còn thấy nó — ghim nhãn và đường ghi ở đây.
 */
it('logs checklist reviews under ReviewChecklistItem::AUDIT_EVENT, an event with a vietnamese label', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create();

    app(ReviewChecklistItem::class)->handle($item, $this->lawyer, ChecklistItemStatus::Accepted);

    expect(ReviewChecklistItem::AUDIT_EVENT)->toBe('checklist_item_reviewed')
        ->and(__('activity.events.'.ReviewChecklistItem::AUDIT_EVENT))->not->toStartWith('activity.')
        ->and(Activity::query()->where('event', ReviewChecklistItem::AUDIT_EVENT)->where('subject_id', $item->id)->count())->toBe(1);
});
