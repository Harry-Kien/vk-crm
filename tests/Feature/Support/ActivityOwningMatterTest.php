<?php

use App\Enums\InstalmentStatus;
use App\Enums\IntakeStatus;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Instalment;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\Payment;
use App\Models\StageLog;
use App\Models\User;
use App\Support\ActivityOwningMatter;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
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

// -----------------------------------------------------------------------------------------
// Gộp M9: dòng nhật ký của TIỀN
// -----------------------------------------------------------------------------------------
//
// Các Action tiền của M9 ghi `Audit::record()` lên hợp đồng, đợt, khoản thu, phụ lục — bốn tên
// morph không nằm trong `MATTER_OWNED` và phần lớn không ghi `properties.matter_id`. Trước bản sửa
// này chúng rơi vào nhánh "dòng không thuộc vụ nào": một trưởng phòng đọc được `payment_recorded`
// (kèm số tiền) của một vụ `restricted` — đúng tiền mà SPEC §5 (bổ sung M9) nói họ không thấy, kể
// cả trong số liệu tổng hợp. Nay mỗi dòng tiền quy về vụ của hợp đồng, và chỉ người vừa thấy vụ
// vừa có `billing.view` mới thấy nó (cùng hai điều kiện `ChecksBillingAccess::canSeeBilling()`).

/** Một chủ thể tiền trên `$matter`, theo đúng tên morph của nó. */
function moneySubjectOn(Matter $matter, string $type, User $lead): Model
{
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Pending,
    ]);

    return match ($type) {
        'contract' => $contract,
        'instalment' => $instalment,
        'payment' => Payment::factory()->for($instalment)->create([
            'amount' => 4_000_000,
            'attributed_lawyer_id' => $lead->id,
        ]),
        'contract_amendment' => ContractAmendment::factory()->for($contract)->create(),
    };
}

it('resolves each money subject to the matter of its contract, and keeps a restricted one from a manager', function (string $type) {
    $subject = moneySubjectOn($this->restricted, $type, $this->lead);

    expect($subject->getMorphClass())->toBe($type);

    $row = Audit::record('payment_recorded', $subject, ['amount' => 4_000_000], $this->lead);

    expect(ActivityOwningMatter::owningMatterId($row))->toBe($this->restricted->id);
    expectVerdict($this->manager, $row, false);
    expectVerdict($this->lead, $row, true);
})->with(['contract', 'instalment', 'payment', 'contract_amendment']);

/** Cặp dương: tiền của một vụ thường vẫn hiện cho trưởng phòng (có `billing.view`). */
it('keeps the money rows of a normal matter visible to a manager', function (string $type) {
    $row = Audit::record('payment_recorded', moneySubjectOn($this->normal, $type, $this->lead), [], $this->lead);

    expectVerdict($this->manager, $row, true);
})->with(['contract', 'instalment', 'payment', 'contract_amendment']);

/**
 * Thấy vụ việc chưa phải thấy tiền: một trợ lý trong đội ngũ được cấp thẳng `auditLog.view` đọc
 * được dòng nhật ký của vụ, nhưng không có `billing.view` nên không đọc được dòng tiền của nó.
 */
it('keeps money rows from a viewer who may read the audit log and the matter but not its money', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $assistant->givePermissionTo(Permission::AuditLogView->value);
    $this->normal->addTeamMember($assistant, MatterRole::Assistant);

    $matterRow = Audit::record('matter_details_updated', $this->normal, [], $this->lead);
    $moneyRow = Audit::record('payment_recorded', moneySubjectOn($this->normal, 'payment', $this->lead), [], $this->lead);

    expectVerdict($assistant, $matterRow, true);
    expectVerdict($assistant, $moneyRow, false);
});

/*
 * Rà soát cuối M10, vòng sửa 1 (FI1): dòng chủ thể `intake_request` chỉ hiện cho người xem được CHÍNH bản
 * ghi đó (`IntakeRequest::scopeVisibleTo()`) — bản đã chuyển thành vụ `restricted` (`matter_id`), và bản đã
 * gộp vào nó (`merge_chain_matter_id`), biến mất với trưởng phòng không phụ trách vụ. Cả hai nửa của luật
 * (bảng SQL và modal PHP) — mỗi nửa một phép đột biến.
 */
it('shows the rows of an intake only to whoever can view that intake: converted into a restricted matter, merged into one, or still open', function () {
    $converted = IntakeRequest::factory()->create([
        'status' => IntakeStatus::Won,
        'matter_id' => $this->restricted->id,
        'client_id' => $this->restricted->client_id,
        'assigned_to' => $this->lead->id,
    ]);
    $mergedSource = IntakeRequest::factory()->create([
        'status' => IntakeStatus::Merged,
        'merged_into_id' => $converted->id,
        'assigned_to' => $this->lead->id,
    ]);
    $mergedSource->forceFill(['merge_chain_matter_id' => $this->restricted->id])->saveQuietly();
    $open = IntakeRequest::factory()->create();
    $admin = User::factory()->withRole(Role::Admin)->create();

    foreach ([$converted, $mergedSource] as $intake) {
        $row = Audit::record('conflict_check_run', $intake, ['matches' => [], 'incomplete_parties' => ['Ông Bên Kia']], $this->lead);

        expect(ActivityOwningMatter::owningMatterId($row))->toBeNull();
        expectVerdict($this->manager, $row, false);
        expectVerdict($this->lead, $row, true);
        expectVerdict($admin, $row, true);
    }

    expectVerdict($this->manager, Audit::record('intake_recorded', $open, [], $this->lead), true);

    // Một dòng nhật ký sống lâu hơn trạng thái của bản ghi: bản đã xoá mềm (không màn hình nào xoá, nhưng
    // `SoftDeletes` có mặt) vẫn là một bản ghi người này xem được, nên dòng của nó vẫn hiện.
    $trashed = IntakeRequest::factory()->create();
    $trashedRow = Audit::record('intake_recorded', $trashed, [], $this->lead);
    $trashed->delete();

    expectVerdict($this->manager, $trashedRow, true);

    // Dòng không có chủ thể (đăng nhập, tác vụ hệ thống): cổng của bản ghi tiếp nhận không đụng tới.
    expectVerdict($this->manager, Audit::record('system_event_without_subject', null, [], $this->lead), true);
});
