<?php

use App\Enums\Role;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\StageLog;
use App\Models\User;
use App\Support\ActivityOwningMatter;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Final review X1: từng nhánh của luật "dòng nhật ký thuộc vụ nào / ai thấy" — mỗi nhánh một test
 * để một phép đột biến bỏ nhánh đó đỏ ở đúng chỗ. Hành vi trên màn hình (bảng + modal) đo ở
 * `ActivityLogPageTest`; ở đây đo lớp luật, và đo rằng bảng (SQL) và modal (PHP) trả CÙNG một
 * câu trả lời cho cùng một dòng.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->normal = Matter::factory()->create();
});

function visibleInTable(User $viewer, Activity $activity): bool
{
    $query = Activity::query();
    ActivityOwningMatter::scopeVisibleTo($query, $viewer);

    return $query->whereKey($activity->id)->exists();
}

function expectVerdict(User $viewer, Activity $activity, bool $allowed): void
{
    expect(ActivityOwningMatter::canView($viewer, $activity))->toBe($allowed)
        ->and(visibleInTable($viewer, $activity))->toBe($allowed);
}

it('resolves a matter subject to that matter', function () {
    $row = Audit::record('matter_details_updated', $this->restricted, [], $this->lead);

    expect(ActivityOwningMatter::owningMatterId($row))->toBe($this->restricted->id);
    expectVerdict($this->manager, $row, false);
    expectVerdict($this->lead, $row, true);
});

it('resolves each matter-owned child subject through its matter_id', function (string $modelClass) {
    $child = $modelClass::factory()->create(['matter_id' => $this->restricted->id]);
    $row = Audit::record('child_event', $child, [], $this->lead);

    expect(ActivityOwningMatter::owningMatterId($row))->toBe($this->restricted->id);
    expectVerdict($this->manager, $row, false);
    expectVerdict($this->lead, $row, true);
})->with([
    'matter party' => MatterParty::class,
    'stage log' => StageLog::class,
    'client request' => ClientRequest::class,
    'checklist item' => MatterChecklistItem::class,
]);

it('resolves a client request reply through its request', function () {
    $request = ClientRequest::factory()->create(['matter_id' => $this->restricted->id]);
    $reply = ClientRequestReply::factory()->create(['request_id' => $request->id]);
    $row = Audit::record('client_request_answered_by_staff', $reply, [], $this->lead);

    expect(ActivityOwningMatter::owningMatterId($row))->toBe($this->restricted->id);
    expectVerdict($this->manager, $row, false);
    expectVerdict($this->lead, $row, true);
});

it('falls back to properties.matter_id when the subject is not matter-owned', function () {
    $row = Audit::record('client_lookup', null, ['matter_id' => $this->restricted->id], $this->lead);

    expect(ActivityOwningMatter::owningMatterId($row))->toBe($this->restricted->id);
    expectVerdict($this->manager, $row, false);
    expectVerdict($this->lead, $row, true);
});

it('refuses a non-admin a matter-owned row whose subject no longer resolves, and lets an admin through', function () {
    $party = MatterParty::factory()->create(['matter_id' => $this->normal->id]);
    $row = Audit::record('matter_party_updated', $party, [], $this->lead);
    DB::table('matter_parties')->where('id', $party->id)->delete();

    expect(ActivityOwningMatter::owningMatterId($row))->toBeNull();
    expectVerdict($this->manager, $row, false);
    expectVerdict(User::factory()->withRole(Role::Admin)->create(), $row, true);
});

it('refuses a non-admin a row whose properties.matter_id points at a matter that no longer exists', function () {
    $row = Audit::record('client_lookup', null, ['matter_id' => 999999], $this->lead);

    expectVerdict($this->manager, $row, false);
});

it('keeps rows with no matter at all, and rows of a normal matter, visible to a manager', function () {
    $noMatter = Audit::record('login_success', $this->manager, ['guard' => 'web'], $this->manager);
    $normal = Audit::record('matter_details_updated', $this->normal, [], $this->manager);

    expect(ActivityOwningMatter::owningMatterId($noMatter))->toBeNull();
    expectVerdict($this->manager, $noMatter, true);
    expectVerdict($this->manager, $normal, true);
});

it('lets a manager see a soft-deleted normal matter\'s rows, exactly like MatterPolicy::view does', function () {
    $row = Audit::record('matter_details_updated', $this->normal, [], $this->manager);
    $this->normal->delete();

    expectVerdict($this->manager, $row, true);
});
