<?php

use App\Actions\Document\ChecklistProgress;
use App\Actions\Performance\BuildTeamWorkload;
use App\Actions\Schedule\CheckDeadlines;
use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\DeadlineSeverity;
use App\Enums\DocumentGroup;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Filament\Admin\Widgets\MattersMissingDocumentsWidget;
use App\Filament\Admin\Widgets\PendingChecklistReviewsWidget;
use App\Filament\Admin\Widgets\Revenue\LoadPerLawyerWidget;
use App\Filament\Admin\Widgets\StaleMattersWidget;
use App\Filament\Admin\Widgets\UpcomingDeadlinesWidget;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use App\Support\Performance\TeamRoster;
use App\Support\Performance\TeamWorkloadRow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * M13 Task 4 — `BuildTeamWorkload` (cột N1–N11; N1–N10 trên "Theo dõi đội ngũ", N11 khi được hỏi):
 * mỗi cột một fixture có ca biên, và mỗi cột "bây giờ" bằng ĐÚNG con số của widget trang chủ tương ứng (hoặc của
 * `LoadPerLawyerWidget`, của tab Danh mục), lọc theo người, cho CÙNG người xem — trưởng phòng và
 * luật sư (Review Focus 2). Widget được đọc qua Livewire, hoặc qua đúng `rowsFor()` mà bảng của
 * widget dùng.
 *
 * Hàm toàn cục mang tiền tố `m13t4` (kế hoạch M13, "Tên hàm Pest toàn cục"; rà soát Task 1 m5): tệp
 * này không gọi hàm của tệp khác.
 *
 * Chạy trên SQLite (bộ thường) VÀ `test:mariadb` (tuần tự): các truy vấn gộp nối bảng và `GROUP BY`
 * một biểu thức, thứ MariaDB strict kiểm chặt hơn SQLite.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản Trị Hệ Thống']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng Một']);
});

/** Trường chỉ dành cho người phụ trách vụ (R6): `null` = "Không áp dụng". */
const M13T4_LEAD_ONLY_FIELDS = [
    'leadOpen', 'leadClosed', 'stale', 'notMeasurable', 'awaitingClientMatters', 'awaitingClientStuck',
    'awaitingReviewItems', 'checklistSettled', 'checklistTotal',
];

/** Trường áp dụng cho mọi người được theo dõi, trợ lý cũng vậy (R6). */
const M13T4_EVERYONE_FIELDS = ['teamOpen', 'overdueDeadlines', 'deadlinesDueSoon', 'awaitingOfficeRequests'];

function m13t4Person(string $name, Role $role = Role::Lawyer): User
{
    return User::factory()->withRole($role)->create(['name' => $name]);
}

function m13t4Matter(User $lead, array $attributes = [], bool $restricted = false): Matter
{
    return ($restricted ? Matter::factory()->restricted() : Matter::factory())
        ->create(['lead_lawyer_id' => $lead->id] + $attributes);
}

function m13t4Closed(Matter $matter): Matter
{
    $matter->forceFill(['closed_at' => now()])->save();

    return $matter;
}

/** @return array<int, TeamWorkloadRow> đúng như trang gọi: người của `TeamRoster::subjectsFor()` */
function m13t4Workload(User $viewer, bool $includeInactive = false, bool $withLastActivity = false): array
{
    return app(BuildTeamWorkload::class)->handle($viewer, TeamRoster::subjectsFor($viewer, $includeInactive), $withLastActivity);
}

function m13t4Deadline(Matter $matter, User $holder, int $dueInDays, array $attributes = []): Deadline
{
    return Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $holder->id,
        'due_date' => today()->addDays($dueInDays)->toDateString(),
    ] + $attributes);
}

function m13t4Item(Matter $matter, ChecklistItemStatus $status, int $daysOld = 0, bool $required = true): MatterChecklistItem
{
    $item = MatterChecklistItem::factory()->for($matter)->status($status)->create([
        'is_required' => $required,
        'rejection_reason' => $status === ChecklistItemStatus::Rejected ? str_repeat('a', 25) : null,
    ]);
    $item->forceFill(['created_at' => now()->subDays($daysOld)])->save();

    return $item;
}

/** @return array<int, int> số dòng theo một khoá người */
function m13t4CountBy(iterable $models, string $key): array
{
    return collect($models)->countBy(fn (mixed $model): int => (int) data_get($model, $key))->sortKeys()->all();
}

// =================================================================================================
// N1 ↔ LoadPerLawyerWidget
// =================================================================================================

/**
 * `LoadPerLawyerWidget::canView()` đòi `revenue.viewAny`, nhưng Filament chỉ hỏi nó ở
 * `hydrateCanAuthorizeAccess()` (request cập nhật), không ở lần mount của `Livewire::test()`: nên
 * vế "với luật sư" đọc widget bằng chính một luật sư thật, tập vụ của widget là đúng
 * `listableBy(luật sư)`.
 */
it('counts N1 per lead exactly as the load per lawyer widget does, for the manager and for a lawyer', function () {
    $an = m13t4Person('Luật Sư An');
    $binh = m13t4Person('Luật Sư Bình');
    $idle = m13t4Person('Luật Sư Chưa Có Vụ');

    m13t4Matter($an);
    m13t4Matter($an);
    m13t4Closed(m13t4Matter($an));
    m13t4Matter($an)->delete();
    m13t4Matter($binh);
    m13t4Matter($binh, restricted: true);

    $widget = function (User $viewer): array {
        $this->actingAs($viewer, 'web');

        return collect(Livewire::test(LoadPerLawyerWidget::class, ['pageFilters' => []])->instance()->numberTableRows())
            ->mapWithKeys(fn (array $row): array => [$row['label'] => (int) $row['value']])
            ->all();
    };

    $asManager = m13t4Workload($this->manager);
    $asBinh = m13t4Workload($binh);

    expect($widget($this->manager))->toBe(['Luật Sư An' => 2, 'Luật Sư Bình' => 1])
        ->and($widget($binh))->toBe(['Luật Sư Bình' => 2]);

    foreach ([[$this->manager, $asManager], [$binh, $asBinh]] as [$viewer, $rows]) {
        $fromWidget = $widget($viewer);

        foreach ($rows as $row) {
            expect($row->leadOpen)->toBe($fromWidget[$row->name] ?? 0);
        }
    }

    expect($asManager[$an->id]->leadOpen)->toBe(2)
        ->and($asManager[$binh->id]->leadOpen)->toBe(1)
        ->and($asManager[$idle->id]->leadOpen)->toBe(0)
        ->and(array_keys($asBinh))->toBe([$binh->id])
        ->and($asBinh[$binh->id]->leadOpen)->toBe(2);
});

// =================================================================================================
// N2, N3
// =================================================================================================

it('counts N2 over associate and assistant seats of open matters, never an observer seat and never the lead seat', function () {
    $lead = m13t4Person('Luật Sư Phụ Trách');
    $associate = m13t4Person('Luật Sư Cộng Sự');
    $assistant = m13t4Person('Trợ Lý Hồ Sơ', Role::Assistant);

    $open = m13t4Matter($lead);
    $open->addTeamMember($associate, MatterRole::Associate);
    $open->addTeamMember($assistant, MatterRole::Assistant);

    m13t4Matter($lead)->addTeamMember($associate, MatterRole::Observer);
    m13t4Closed(m13t4Matter($lead))->addTeamMember($associate, MatterRole::Associate);

    $cancelled = m13t4Matter($lead);
    $cancelled->addTeamMember($assistant, MatterRole::Assistant);
    $cancelled->delete();

    $restricted = m13t4Matter($lead, restricted: true);
    $restricted->addTeamMember($associate, MatterRole::Associate);

    // Vụ người cộng sự phụ trách: đếm ở N1, không ở N2.
    m13t4Matter($associate);

    $rows = m13t4Workload($this->manager);
    $asAdmin = m13t4Workload($this->admin);

    expect($rows[$associate->id]->teamOpen)->toBe(1)
        ->and($rows[$assistant->id]->teamOpen)->toBe(1)
        ->and($rows[$lead->id]->teamOpen)->toBe(0)
        ->and($rows[$associate->id]->leadOpen)->toBe(1)
        ->and($rows[$lead->id]->leadOpen)->toBe(2)
        ->and($asAdmin[$associate->id]->teamOpen)->toBe(2);
});

it('counts N3 as the closed matters standing in the lead\'s name, and never a cancelled one', function () {
    $lead = m13t4Person('Luật Sư Kết Thúc');

    m13t4Closed(m13t4Matter($lead));
    m13t4Closed(m13t4Matter($lead));
    m13t4Closed(m13t4Matter($lead))->delete();
    m13t4Matter($lead);
    m13t4Matter($lead)->delete();

    expect(m13t4Workload($this->manager)[$lead->id]->leadClosed)->toBe(2)
        ->and(m13t4Workload($this->manager)[$lead->id]->leadOpen)->toBe(1);
});

// =================================================================================================
// N4 ↔ StaleMattersWidget
// =================================================================================================

/**
 * Đồng nhất qua Livewire: bản ghi của bảng `StaleMattersWidget` (truy vấn đã lọc của chính widget,
 * không phân trang) đếm theo `lead_lawyer_id`.
 */
it('counts N4 as the stale matters widget does, lead by lead, and the matters the rule cannot measure on the side', function () {
    $an = m13t4Person('Luật Sư An');
    $binh = m13t4Person('Luật Sư Bình');

    $updatedAgo = fn (User $lead, int $minutesPastFourteenDays, array $attributes = [], bool $restricted = false): Matter => m13t4Matter(
        $lead,
        ['last_client_update_at' => now()->subDays(14)->subMinutes($minutesPastFourteenDays)] + $attributes,
        $restricted,
    );

    $updatedAgo($an, 1);
    $updatedAgo($an, -1);
    $updatedAgo($an, 60 * 24 * 30, ['is_published_to_portal' => false]);
    m13t4Matter($an, ['is_published_to_portal' => false]);
    m13t4Closed($updatedAgo($an, 60));
    $updatedAgo($binh, 60);
    $updatedAgo($binh, 60, restricted: true);
    m13t4Matter($binh, ['is_published_to_portal' => false]);
    m13t4Closed(m13t4Matter($binh, ['is_published_to_portal' => false]));

    $asManager = m13t4Workload($this->manager);
    $asBinh = m13t4Workload($binh);

    expect($asManager[$an->id]->stale)->toBe(1)
        ->and($asManager[$an->id]->notMeasurable)->toBe(2)
        ->and($asManager[$binh->id]->stale)->toBe(1)
        ->and($asManager[$binh->id]->notMeasurable)->toBe(1)
        ->and($asBinh[$binh->id]->stale)->toBe(2);

    foreach ([[$this->manager, $asManager], [$binh, $asBinh]] as [$viewer, $rows]) {
        $this->actingAs($viewer, 'web');
        $fromWidget = m13t4CountBy(Livewire::test(StaleMattersWidget::class)->instance()->getFilteredTableQuery()->get(), 'lead_lawyer_id');

        foreach ($rows as $row) {
            if ($row->leadsMatters) {
                expect($row->stale)->toBe($fromWidget[$row->userId] ?? 0);
            }
        }
    }
});

// =================================================================================================
// N5, N6 ↔ CheckDeadlines::tierFor() và UpcomingDeadlinesWidget::rowsFor()
// =================================================================================================

it('counts N5 and N6 on day 0 and day +7 as CheckDeadlines and the upcoming deadlines widget do', function () {
    $lead = m13t4Person('Luật Sư Giữ Mốc');
    $assistant = m13t4Person('Trợ Lý Giữ Mốc', Role::Assistant);

    $open = m13t4Matter($lead);
    $open->addTeamMember($assistant, MatterRole::Assistant);
    $closed = m13t4Closed(m13t4Matter($lead));
    $restricted = m13t4Matter($lead, restricted: true);

    m13t4Deadline($open, $lead, -1);
    m13t4Deadline($open, $lead, -30);
    m13t4Deadline($open, $lead, 0);
    m13t4Deadline($open, $lead, 7);
    m13t4Deadline($open, $lead, 8);
    m13t4Deadline($open, $lead, 10, ['severity' => DeadlineSeverity::Critical]);
    m13t4Deadline($open, $lead, -2, ['is_completed' => true, 'completed_at' => now()]);
    m13t4Deadline($closed, $lead, -2);
    m13t4Deadline($open, $lead, -2)->delete();
    m13t4Deadline($restricted, $lead, -2);
    m13t4Deadline($restricted, $lead, 2);
    m13t4Deadline($open, $assistant, -3);
    m13t4Deadline($open, $assistant, 3);

    $asManager = m13t4Workload($this->manager);
    $asLead = m13t4Workload($lead);

    expect($asManager[$lead->id]->overdueDeadlines)->toBe(2)
        ->and($asManager[$lead->id]->deadlinesDueSoon)->toBe(2)
        ->and($asManager[$assistant->id]->overdueDeadlines)->toBe(1)
        ->and($asManager[$assistant->id]->deadlinesDueSoon)->toBe(1)
        ->and($asLead[$lead->id]->overdueDeadlines)->toBe(3)
        ->and($asLead[$lead->id]->deadlinesDueSoon)->toBe(3);

    $checker = app(CheckDeadlines::class);

    foreach ([[$this->manager, $asManager], [$lead, $asLead]] as [$viewer, $rows]) {
        $widgetRows = UpcomingDeadlinesWidget::rowsFor($viewer)->get();
        $overdue = m13t4CountBy($widgetRows->filter(fn (Deadline $d): bool => $checker->tierFor($d) === CheckDeadlines::OVERDUE_KEY), 'responsible_user_id');
        $withinSeven = m13t4CountBy($widgetRows->filter(fn (Deadline $d): bool => in_array($checker->tierFor($d), ['d1', 'd3', 'd7'], true)), 'responsible_user_id');
        $all = m13t4CountBy($widgetRows, 'responsible_user_id');

        foreach ($rows as $row) {
            expect($row->overdueDeadlines)->toBe($overdue[$row->userId] ?? 0)
                ->and($row->deadlinesDueSoon)->toBe($withinSeven[$row->userId] ?? 0)
                ->and($row->overdueDeadlines + $row->deadlinesDueSoon)->toBe($all[$row->userId] ?? 0);
        }
    }
});

// =================================================================================================
// N7 ↔ ChecklistProgress::mattersAwaitingClient() và MattersMissingDocumentsWidget::rowsFor()
// =================================================================================================

it('counts N7 as the matters awaiting the client, and in brackets the stuck ones the missing documents widget lists', function () {
    $lead = m13t4Person('Luật Sư Chờ Giấy Tờ');

    m13t4Item(m13t4Matter($lead), ChecklistItemStatus::Missing, daysOld: 20);
    m13t4Item(m13t4Matter($lead), ChecklistItemStatus::Missing, daysOld: 3);
    m13t4Item(m13t4Matter($lead, ['is_published_to_portal' => false]), ChecklistItemStatus::Missing, daysOld: 20);
    m13t4Item(m13t4Matter($lead), ChecklistItemStatus::PendingReview, daysOld: 20);
    m13t4Item(m13t4Matter($lead), ChecklistItemStatus::Missing, daysOld: 20, required: false);
    m13t4Item(m13t4Closed(m13t4Matter($lead)), ChecklistItemStatus::Missing, daysOld: 20);
    m13t4Item(m13t4Matter($lead, restricted: true), ChecklistItemStatus::Missing, daysOld: 20);

    $asManager = m13t4Workload($this->manager);
    $asLead = m13t4Workload($lead);

    expect($asManager[$lead->id]->awaitingClientMatters)->toBe(2)
        ->and($asManager[$lead->id]->awaitingClientStuck)->toBe(1)
        ->and($asLead[$lead->id]->awaitingClientMatters)->toBe(3)
        ->and($asLead[$lead->id]->awaitingClientStuck)->toBe(2);

    foreach ([[$this->manager, $asManager], [$lead, $asLead]] as [$viewer, $rows]) {
        $stuck = m13t4CountBy(MattersMissingDocumentsWidget::rowsFor($viewer)->get(), 'lead_lawyer_id');
        $awaiting = m13t4CountBy(ChecklistProgress::mattersAwaitingClient(Matter::query()->listableBy($viewer))->get(), 'lead_lawyer_id');

        foreach ($rows as $row) {
            if ($row->leadsMatters) {
                expect($row->awaitingClientStuck)->toBe($stuck[$row->userId] ?? 0)
                    ->and($row->awaitingClientMatters)->toBe($awaiting[$row->userId] ?? 0);
            }
        }
    }
});

// =================================================================================================
// N8 ↔ PendingChecklistReviewsWidget::rowsFor()
// =================================================================================================

it('counts N8 as the pending reviews widget does, by the lead of the matter, closed matters included', function () {
    $lead = m13t4Person('Luật Sư Duyệt');
    $other = m13t4Person('Luật Sư Khác');

    m13t4Item(m13t4Matter($lead), ChecklistItemStatus::PendingReview);
    m13t4Item(m13t4Closed(m13t4Matter($lead)), ChecklistItemStatus::PendingReview);
    m13t4Item(m13t4Matter($lead, restricted: true), ChecklistItemStatus::PendingReview);
    m13t4Item(m13t4Matter($lead), ChecklistItemStatus::Accepted);
    m13t4Item(m13t4Matter($lead), ChecklistItemStatus::PendingReview)->delete();
    $cancelled = m13t4Matter($lead);
    m13t4Item($cancelled, ChecklistItemStatus::PendingReview);
    $cancelled->delete();
    m13t4Item(m13t4Matter($other), ChecklistItemStatus::PendingReview);

    $asManager = m13t4Workload($this->manager);
    $asLead = m13t4Workload($lead);

    expect($asManager[$lead->id]->awaitingReviewItems)->toBe(2)
        ->and($asManager[$other->id]->awaitingReviewItems)->toBe(1)
        ->and($asLead[$lead->id]->awaitingReviewItems)->toBe(3);

    foreach ([[$this->manager, $asManager], [$lead, $asLead]] as [$viewer, $rows]) {
        $fromWidget = m13t4CountBy(PendingChecklistReviewsWidget::rowsFor($viewer)->get(), 'matter.lead_lawyer_id');

        foreach ($rows as $row) {
            if ($row->leadsMatters) {
                expect($row->awaitingReviewItems)->toBe($fromWidget[$row->userId] ?? 0);
            }
        }
    }
});

// =================================================================================================
// N9 — người giữ luồng như đường thông báo
// =================================================================================================

it('counts N9 for the lead when nobody is assigned, for the assignee when there is one, and for the lead again when the assignee was soft-deleted', function () {
    $lead = m13t4Person('Luật Sư Giữ Luồng');
    $assistant = m13t4Person('Trợ Lý Giữ Luồng', Role::Assistant);
    $departed = m13t4Person('Trợ Lý Đã Xoá', Role::Assistant);

    $matter = m13t4Matter($lead);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    $matter->addTeamMember($departed, MatterRole::Assistant);

    $thread = fn (Matter $on, ClientRequestStatus $status, ?User $assignee = null): ClientRequest => ClientRequest::factory()->for($on)->create([
        'status' => $status,
        'assigned_to' => $assignee?->getKey(),
    ]);

    $thread($matter, ClientRequestStatus::New);
    $thread($matter, ClientRequestStatus::InProgress, $assistant);
    $thread($matter, ClientRequestStatus::New, $departed);
    $thread($matter, ClientRequestStatus::Answered);
    $thread($matter, ClientRequestStatus::Closed);
    $thread(m13t4Closed(m13t4Matter($lead)), ClientRequestStatus::New);
    $thread(m13t4Matter($lead, restricted: true), ClientRequestStatus::New);
    $departed->delete();

    $asManager = m13t4Workload($this->manager);

    expect($asManager[$lead->id]->awaitingOfficeRequests)->toBe(2)
        ->and($asManager[$assistant->id]->awaitingOfficeRequests)->toBe(1)
        ->and($asManager)->not->toHaveKey($departed->id)
        ->and(m13t4Workload($lead)[$lead->id]->awaitingOfficeRequests)->toBe(3);
});

// =================================================================================================
// N10 ↔ "Đã nộp X/Y" của tab Danh mục
// =================================================================================================

it('adds N10 up to the X/Y the checklist tab shows on every open matter the person leads', function () {
    $lead = m13t4Person('Luật Sư Danh Mục');

    $first = m13t4Matter($lead);
    m13t4Item($first, ChecklistItemStatus::Accepted);
    m13t4Item($first, ChecklistItemStatus::Missing);
    m13t4Item($first, ChecklistItemStatus::NotApplicable);
    m13t4Item($first, ChecklistItemStatus::Accepted, required: false);

    $second = m13t4Matter($lead);
    m13t4Item($second, ChecklistItemStatus::Rejected);
    m13t4Item($second, ChecklistItemStatus::PendingReview);
    $optionalWithDocument = m13t4Item($second, ChecklistItemStatus::Accepted, required: false);
    Document::factory()->group(DocumentGroup::ClientProvided)->create(['matter_id' => $second->id, 'matter_checklist_item_id' => $optionalWithDocument->id]);

    $closed = m13t4Closed(m13t4Matter($lead));
    m13t4Item($closed, ChecklistItemStatus::Accepted);

    $restricted = m13t4Matter($lead, restricted: true);
    m13t4Item($restricted, ChecklistItemStatus::Accepted);

    $tab = function (User $viewer, Matter $matter): array {
        $this->actingAs($viewer, 'web');
        $html = Livewire::test(ChecklistRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])->html();
        $pattern = '/'.str_replace(['\:submitted', '\:total'], ['(\d+)', '(\d+)'], preg_quote(__('checklist.tab.progress'), '/')).'/u';
        preg_match($pattern, $html, $match);

        return [(int) $match[1], (int) $match[2]];
    };

    foreach ([[$this->manager, [$first, $second]], [$lead, [$first, $second, $restricted]]] as [$viewer, $matters]) {
        $sums = collect($matters)->map(fn (Matter $matter): array => $tab($viewer, $matter));
        $row = m13t4Workload($viewer)[$lead->id];

        expect([$row->checklistSettled, $row->checklistTotal])->toBe([$sums->sum(0), $sums->sum(1)]);
    }

    expect([m13t4Workload($this->manager)[$lead->id]->checklistSettled, m13t4Workload($this->manager)[$lead->id]->checklistTotal])->toBe([3, 6]);
});

// =================================================================================================
// N11 — thao tác hồ sơ gần nhất
// =================================================================================================

it('reads N11 as the latest matter row the person caused, never a login row, even for the admin, and a money row only for a viewer with billing.view', function () {
    $lead = m13t4Person('Luật Sư Thao Tác');
    $matter = m13t4Matter($lead);
    $deadline = m13t4Deadline($matter, $lead, 5);
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 5_000_000, 'signed_at' => '2026-08-01']);
    $payment = Payment::factory()->for(Instalment::factory()->for($contract)->create(['amount' => 5_000_000]))->create([
        'amount' => 5_000_000,
        'attributed_lawyer_id' => $lead->id,
    ]);

    $this->travelTo(Carbon::parse('2026-10-13 09:00:00'));
    Audit::record('deadline_added', $deadline, ['matter_id' => $matter->id], causer: $lead);
    $this->travelTo(Carbon::parse('2026-10-13 11:00:00'));
    Audit::record('payment_recorded', $payment, [], causer: $lead);
    $this->travelTo(Carbon::parse('2026-10-13 15:00:00'));
    Audit::record('login_success', null, ['guard' => 'web'], causer: $lead);
    // Một tài khoản khách TRÙNG id với luật sư, ghi lên chính vụ đó: `causer_id` giống, `causer_type`
    // khác — không phải thao tác của luật sư.
    $this->travelTo(Carbon::parse('2026-10-13 16:00:00'));
    $twin = ClientUser::factory()->create();
    DB::table('client_users')->where('id', $twin->id)->update(['id' => $lead->id]);
    Audit::record('client_request_opened', $matter, ['matter_id' => $matter->id], causer: ClientUser::query()->findOrFail($lead->id));
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));

    $noMoney = User::factory()->create(['name' => 'Người Xem Không Thấy Tiền']);
    $noMoney->givePermissionTo([Permission::PerformanceViewAny->value, Permission::MatterViewAny->value, Permission::MatterView->value]);

    $at = fn (User $viewer): ?string => m13t4Workload($viewer->fresh(), withLastActivity: true)[$lead->id]->lastMatterActivityAt?->toDateTimeString();

    expect($at($this->manager))->toBe('2026-10-13 11:00:00')
        ->and($at($this->admin))->toBe('2026-10-13 11:00:00')
        ->and($at($lead))->toBe('2026-10-13 11:00:00')
        ->and($at($noMoney))->toBe('2026-10-13 09:00:00')
        ->and(m13t4Workload($this->manager, withLastActivity: true)[$this->manager->id]->lastMatterActivityAt)->toBeNull();
});

/**
 * Phán quyết N11 của Task 4 (docblock `BuildTeamWorkload`): truy vấn gộp N11 cho mọi người vượt
 * ngưỡng 150 ms của kế hoạch trên dữ liệu benchmark, nên N11 chỉ tính khi được hỏi (trang của một
 * người), bằng một truy vấn nhật ký giới hạn ở đúng những người được hỏi — không phải nhật ký của cả
 * văn phòng rồi lọc bằng PHP.
 */
it('reads no activity log for N11 unless asked, and then one query limited to the people asked about', function () {
    $an = m13t4Person('Luật Sư An');
    $binh = m13t4Person('Luật Sư Bình');
    $matter = m13t4Matter($an);
    $matter->addTeamMember($binh, MatterRole::Associate);

    $this->travelTo(Carbon::parse('2026-10-13 09:00:00'));
    Audit::record('matter_reassigned', $matter, [], causer: $an);
    $this->travelTo(Carbon::parse('2026-10-13 10:00:00'));
    Audit::record('matter_reassigned', $matter, [], causer: $binh);
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));

    $activityQueries = function (callable $run): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $run();
        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'activity_log'))->values()->all();
        DB::disableQueryLog();

        return [$rows, $queries];
    };

    [$overview, $none] = $activityQueries(fn (): array => m13t4Workload($this->manager));
    [$onePerson, $one] = $activityQueries(fn (): array => app(BuildTeamWorkload::class)->handle($this->manager, new EloquentCollection([$an]), withLastMatterActivity: true));

    expect($none)->toBe([])
        ->and(collect($overview)->map->lastMatterActivityAt->filter()->all())->toBe([])
        ->and($one)->toHaveCount(1)
        ->and($one[0]['bindings'])->toContain($an->id)
        ->and($one[0]['bindings'])->not->toContain($binh->id)
        ->and($onePerson[$an->id]->lastMatterActivityAt?->toDateTimeString())->toBe('2026-10-13 09:00:00');
});

// =================================================================================================
// R6 — "Không áp dụng" theo quyền, không bao giờ theo vụ
// =================================================================================================

it('leaves every lead-only field of an assistant null and gives numbers, zero included, everywhere else', function () {
    $lead = m13t4Person('Luật Sư Có Trợ Lý');
    $assistant = m13t4Person('Trợ Lý Không Phụ Trách', Role::Assistant);
    $matter = m13t4Matter($lead);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    m13t4Deadline($matter, $assistant, -1);

    $row = m13t4Workload($this->manager)[$assistant->id];

    expect($row->leadsMatters)->toBeFalse();

    foreach (M13T4_LEAD_ONLY_FIELDS as $field) {
        expect($row->{$field})->toBeNull();
    }

    expect($row->teamOpen)->toBe(1)
        ->and($row->overdueDeadlines)->toBe(1)
        ->and($row->deadlinesDueSoon)->toBe(0)
        ->and($row->awaitingOfficeRequests)->toBe(0);
});

it('gives a lawyer with no matter at all zero in every lead-only field, never null', function () {
    $idle = m13t4Person('Luật Sư Chưa Có Vụ');

    $row = m13t4Workload($this->manager)[$idle->id];

    expect($row->leadsMatters)->toBeTrue();

    foreach ([...M13T4_LEAD_ONLY_FIELDS, ...M13T4_EVERYONE_FIELDS] as $field) {
        expect($row->{$field})->toBe(0);
    }
});

/**
 * R4 + R6: "Không áp dụng" suy từ QUYỀN. Một luật sư chỉ phụ trách vụ `restricted` hiện 0 với
 * trưởng phòng — nếu `leadsMatters()` đọc "có vụ đang phụ trách" thì dòng này đổi sang `null`, tức
 * lộ rằng có vụ trưởng phòng không thấy.
 */
it('gives a lawyer who only leads restricted matters zero, not null, in every lead-only field the manager reads', function () {
    $secret = m13t4Person('Luật Sư Vụ Hạn Chế');
    $matter = m13t4Matter($secret, ['last_client_update_at' => now()->subDays(30)], restricted: true);
    m13t4Item($matter, ChecklistItemStatus::Missing, daysOld: 20);
    m13t4Item($matter, ChecklistItemStatus::PendingReview);
    m13t4Closed(m13t4Matter($secret, restricted: true));

    $asManager = m13t4Workload($this->manager)[$secret->id];
    $asSelf = m13t4Workload($secret)[$secret->id];

    foreach (M13T4_LEAD_ONLY_FIELDS as $field) {
        expect($asManager->{$field})->toBe(0);
    }

    expect($asSelf->leadOpen)->toBe(1)
        ->and($asSelf->leadClosed)->toBe(1)
        ->and($asSelf->stale)->toBe(1);
});

// =================================================================================================
// R11, phòng thủ, thứ tự
// =================================================================================================

/** R11: mỗi chỉ số một truy vấn gộp, không một truy vấn cho mỗi người. */
it('runs the same number of queries for 3 people and for 12', function () {
    $populate = function (int $people): void {
        foreach (range(1, $people) as $i) {
            $role = $i % 3 === 0 ? Role::Assistant : Role::Lawyer;
            $person = User::factory()->withRole($role)->create();

            if ($role === Role::Lawyer) {
                $matter = m13t4Matter($person, ['last_client_update_at' => now()->subDays(20)]);
                m13t4Deadline($matter, $person, -1);
                m13t4Item($matter, ChecklistItemStatus::PendingReview);
                ClientRequest::factory()->for($matter)->create(['status' => ClientRequestStatus::New]);
                Audit::record('matter_reassigned', $matter, [], causer: $person);
            }
        }
    };

    $count = function (): int {
        $viewer = $this->manager->fresh();
        $subjects = TeamRoster::subjectsFor($viewer);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(BuildTeamWorkload::class)->handle($viewer, $subjects);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $populate(2);
    expect(TeamRoster::subjectsFor($this->manager))->toHaveCount(3);
    $withThree = $count();

    $populate(9);
    expect(TeamRoster::subjectsFor($this->manager))->toHaveCount(12);
    $withTwelve = $count();

    expect($withTwelve)->toBe($withThree);
});

it('refuses, as a defence, a subject the viewer may not see', function () {
    $lawyer = m13t4Person('Luật Sư Một');
    $colleague = m13t4Person('Luật Sư Hai');

    expect(fn () => app(BuildTeamWorkload::class)->handle($lawyer, new EloquentCollection([$colleague])))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(BuildTeamWorkload::class)->handle($this->manager, new EloquentCollection([$this->admin])))
        ->toThrow(AuthorizationException::class);
});

it('returns one row per subject, keyed by user id, in the order of the subjects', function () {
    $zed = m13t4Person('Zê Cuối');
    $an = m13t4Person('An Đầu');

    $subjects = new EloquentCollection([$zed, $an, $this->manager]);
    $rows = app(BuildTeamWorkload::class)->handle($this->manager, $subjects);

    expect(array_keys($rows))->toBe([$zed->id, $an->id, $this->manager->id])
        ->and($rows[$zed->id]->name)->toBe('Zê Cuối')
        ->and($rows[$zed->id]->isActive)->toBeTrue()
        ->and(app(BuildTeamWorkload::class)->handle($this->manager, new EloquentCollection))->toBe([]);
});
