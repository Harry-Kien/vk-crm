<?php

use App\Enums\InstalmentState;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\AccountantBillingRow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Ai thấy và ghi TIỀN của vụ nào (M9 P3; SPEC §5, bổ sung 2026-09-19, sửa 2026-09-24).
 *
 * **Một định nghĩa:** có `billing.view` VÀ vụ nằm trong `Matter::listableBy($user)` (bản trong bộ
 * nhớ: `Matter::isListableBy()`), cài ở `ChecksBillingAccess::canSeeBilling()`. Không hỏi
 * `MatterPolicy::view` — kế toán không có `matter.view` mà vẫn phải thấy tiền.
 *
 * **Nhân chứng được cấp quyền thẳng** (`billingWitness()`, bài học M4 `RegroupDocument`): với mỗi
 * điều kiện có thể dựng được như vậy, test âm dùng một tài khoản KHÔNG vai trò, cấp đúng các quyền
 * cần để mọi điều kiện khác đều qua, và đặt cạnh nó một tài khoản chỉ khác đúng một quyền hoặc
 * đúng một chỗ trong đội ngũ làm cặp dương. Như vậy thứ chặn tài khoản âm chỉ có thể là đúng điều
 * kiện đang thử. Hai điều kiện dùng tài khoản theo vai trò vì chính vai trò đã cô lập đúng điều
 * kiện đó, có cặp dương trên cùng tài khoản: luật sư phụ trách cũ đổi chức danh sang kế toán
 * (`matter.view` trong nhánh `restricted`), và luật sư phụ trách ghi khoản thu trên vụ `restricted`
 * mà không có `payment.record`. Nhân chứng theo vai trò (kế toán, quản lý, trợ lý) còn đứng thêm
 * bên cạnh để ghim bảng quyền của SPEC, không thay thế nhân chứng cấp thẳng.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->teammate = User::factory()->withRole(Role::Lawyer)->create();
    $this->outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->teammate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);

    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->restricted->addTeamMember($this->teammate, MatterRole::Associate);

    $this->chain = billingAccessChain($this->matter);
    $this->restrictedChain = billingAccessChain($this->restricted);

    [$this->contract, $this->instalment, $this->payment] = $this->chain;
    [$this->restrictedContract, $this->restrictedInstalment, $this->restrictedPayment] = $this->restrictedChain;
});

/** @return array{0: Contract, 1: Instalment, 2: Payment, 3: ContractAmendment} */
function billingAccessChain(Matter $matter): array
{
    $contract = Contract::factory()->for($matter)->active()->create();
    // Một đợt bằng đúng giá trị hợp đồng: hợp đồng `active` phải khớp tổng (bất biến M9 Task 4).
    $instalment = Instalment::factory()->for($contract)->create(['amount' => $contract->total_amount]);
    $payment = Payment::factory()->for($instalment)->create();
    $amendment = ContractAmendment::factory()->for($contract)->create();

    return [$contract, $instalment, $payment, $amendment];
}

/**
 * Tài khoản KHÔNG vai trò (không `admin`, nên nhánh `hasRole(admin)` của `isListableBy` không bao
 * giờ giúp nó), chỉ có đúng các quyền được cấp.
 *
 * @param  list<Permission>  $permissions
 */
function billingWitness(array $permissions, ?Matter $onTeamOf = null): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(array_map(fn (Permission $permission) => $permission->value, $permissions));
    $onTeamOf?->addTeamMember($user, MatterRole::Associate);

    return $user;
}

/**
 * `view` trên cả bốn model tiền của một chuỗi, theo thứ tự hợp đồng, đợt, khoản thu, phụ lục —
 * để một policy đi lệch ba policy kia là một phần tử lệch trong mảng.
 *
 * @param  array<int, Contract|Instalment|Payment|ContractAmendment>  $chain
 * @return list<bool>
 */
function canReadMoney(User|ClientUser $user, array $chain): array
{
    return array_values(array_map(fn ($record) => $user->can('view', $record), $chain));
}

/** @return list<bool> `viewAny` không ngữ cảnh trên cả bốn model tiền. */
function canListMoney(User|ClientUser $user): array
{
    return array_map(
        fn (string $model) => $user->can('viewAny', $model),
        [Contract::class, Instalment::class, Payment::class, ContractAmendment::class],
    );
}

/**
 * Bốn câu hỏi `contract.manage`: soạn hợp đồng cho vụ, sửa/kích hoạt/phụ lục/huỷ, xoá, miễn một đợt.
 *
 * @param  array<int, Contract|Instalment|Payment|ContractAmendment>  $chain
 * @return list<bool>
 */
function canManageContract(User|ClientUser $user, Matter $matter, array $chain): array
{
    [$contract, $instalment] = $chain;

    return [
        $user->can('create', [Contract::class, $matter]),
        $user->can('update', $contract),
        $user->can('delete', $contract),
        $user->can('waive', $instalment),
    ];
}

/**
 * Ba câu hỏi `payment.record`: ghi khoản thu với ngữ cảnh ĐỢT (trang "Công nợ"), với ngữ cảnh
 * VỤ VIỆC (tab của vụ, thành ngữ `Gate::allows('create', [X::class, $this->getOwnerRecord()])`),
 * và huỷ một khoản thu.
 *
 * @param  array<int, Contract|Instalment|Payment|ContractAmendment>  $chain
 * @return list<bool>
 */
function canRecordMoney(User|ClientUser $user, Matter $matter, array $chain): array
{
    [, $instalment, $payment] = $chain;

    return [
        $user->can('create', [Payment::class, $instalment]),
        $user->can('create', [Payment::class, $matter]),
        $user->can('void', $payment),
    ];
}

// ── Đọc: một định nghĩa ─────────────────────────────────────────────────────────────────────────

it('lets the accountant read the money of a matter whose file it cannot open', function () {
    expect($this->accountant->can('view', $this->matter))->toBeFalse()
        ->and(canReadMoney($this->accountant, $this->chain))->toBe([true, true, true, true])
        ->and($this->accountant->can('viewAny', [Contract::class, $this->matter]))->toBeTrue();
});

it('refuses the money to an account that opens the matter but lacks billing.view', function () {
    $seer = billingWitness([Permission::MatterView], $this->matter);
    $payer = billingWitness([Permission::MatterView, Permission::BillingView], $this->matter);

    expect($seer->can('view', $this->matter))->toBeTrue()
        ->and(canReadMoney($seer, $this->chain))->toBe([false, false, false, false])
        ->and($seer->can('viewAny', [Contract::class, $this->matter]))->toBeFalse()
        ->and(canReadMoney($payer, $this->chain))->toBe([true, true, true, true])
        ->and($payer->can('viewAny', [Contract::class, $this->matter]))->toBeTrue()
        // Vai trò trợ lý: đứng trong đội ngũ, mở được hồ sơ, không thấy tiền (SPEC §5 bổ sung M9).
        ->and($this->assistant->can('view', $this->matter))->toBeTrue()
        ->and(canReadMoney($this->assistant, $this->chain))->toBe([false, false, false, false]);
});

it('refuses the money to an account holding billing.view on a matter it is not listed for', function () {
    $stranger = billingWitness([Permission::MatterView, Permission::BillingView]);
    $member = billingWitness([Permission::MatterView, Permission::BillingView], $this->matter);

    expect(canReadMoney($stranger, $this->chain))->toBe([false, false, false, false])
        ->and($stranger->can('viewAny', [Contract::class, $this->matter]))->toBeFalse()
        ->and(canReadMoney($member, $this->chain))->toBe([true, true, true, true])
        ->and(canReadMoney($this->outsider, $this->chain))->toBe([false, false, false, false])
        ->and(canReadMoney($this->teammate, $this->chain))->toBe([true, true, true, true]);
});

// ── Vụ `restricted` (P3, SPEC §4.6): mỗi vế một test ─────────────────────────────────────────────

it('shows the money of a restricted matter to its lead lawyer and to the admin', function () {
    expect(canReadMoney($this->lead, $this->restrictedChain))->toBe([true, true, true, true])
        ->and(canReadMoney($this->admin, $this->restrictedChain))->toBe([true, true, true, true]);
});

it('hides the money of a restricted matter from the accountant and the manager, who both read it on an ordinary matter', function () {
    expect(canReadMoney($this->accountant, $this->restrictedChain))->toBe([false, false, false, false])
        ->and(canReadMoney($this->accountant, $this->chain))->toBe([true, true, true, true])
        ->and(canReadMoney($this->manager, $this->restrictedChain))->toBe([false, false, false, false])
        ->and(canReadMoney($this->manager, $this->chain))->toBe([true, true, true, true]);
});

it('hides the money of a restricted matter from a team member who is not the lead', function () {
    $member = billingWitness([Permission::MatterView, Permission::BillingView], $this->restricted);
    $this->matter->addTeamMember($member, MatterRole::Associate);

    expect(canReadMoney($member, $this->restrictedChain))->toBe([false, false, false, false])
        ->and(canReadMoney($member, $this->chain))->toBe([true, true, true, true])
        ->and(canReadMoney($this->teammate, $this->restrictedChain))->toBe([false, false, false, false])
        ->and(canReadMoney($this->teammate, $this->chain))->toBe([true, true, true, true]);
});

/*
 * `isListableBy` đòi luật sư phụ trách vụ `restricted` vẫn còn `matter.view`: một người đổi chức
 * danh sang kế toán vẫn giữ `lead_lawyer_id` trên các vụ cũ, và `billing.view` của vai kế toán
 * không được biến vị trí "lead" cũ ấy thành một cửa sau vào tiền của vụ hạn chế.
 */
it('hides restricted money from a lead lawyer whose title changed to accountant', function () {
    $former = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $former->id]);
    $chain = billingAccessChain($matter);

    expect(canReadMoney($former, $chain))->toBe([false, false, false, false])
        ->and(canReadMoney($former, $this->chain))->toBe([true, true, true, true]);
});

/*
 * "Provably matches": câu trả lời trong bộ nhớ (policy) và câu trả lời bằng SQL (thứ trang "Công
 * nợ" và trang doanh thu sẽ dùng: `billing.view` + `Matter::listableBy`) phải trùng nhau cho MỌI
 * tài khoản trên MỌI vụ — nếu không, một người thấy một con số trên biểu đồ mà không mở được dòng
 * của nó, hoặc ngược lại.
 */
it('answers the money question in memory exactly as the listable query does, for every account on every matter', function () {
    $accounts = [
        $this->admin, $this->manager, $this->lead, $this->teammate, $this->outsider,
        $this->assistant, $this->accountant,
    ];

    foreach ($accounts as $account) {
        foreach ([$this->matter, $this->restricted] as $matter) {
            $byQuery = $account->can(Permission::BillingView->value)
                && Matter::query()->listableBy($account)->whereKey($matter->getKey())->exists();

            expect($account->can('viewAny', [Contract::class, $matter]))
                ->toBe($byQuery, "user {$account->id} / matter {$matter->id}");
        }
    }
});

// ── `viewAny`: không ngữ cảnh là câu hỏi giao diện, có ngữ cảnh là định nghĩa ───────────────────

it('asks billing.view for the money screens without a matter, and the one definition with a matter', function () {
    $seer = billingWitness([Permission::MatterView], $this->matter);

    expect(canListMoney($this->outsider))->toBe([true, true, true, true])
        ->and(canListMoney($this->accountant))->toBe([true, true, true, true])
        ->and(canListMoney($this->assistant))->toBe([false, false, false, false])
        ->and(canListMoney($seer))->toBe([false, false, false, false])
        // Với ngữ cảnh: đúng định nghĩa, không còn là "có billing.view".
        ->and($this->outsider->can('viewAny', [Contract::class, $this->matter]))->toBeFalse()
        ->and($this->accountant->can('viewAny', [Contract::class, $this->restricted]))->toBeFalse()
        ->and($this->lead->can('viewAny', [Contract::class, $this->restricted]))->toBeTrue()
        ->and($this->accountant->can('viewAny', [Instalment::class, $this->restricted]))->toBeFalse()
        ->and($this->accountant->can('viewAny', [Payment::class, $this->restricted]))->toBeFalse()
        ->and($this->accountant->can('viewAny', [ContractAmendment::class, $this->restricted]))->toBeFalse()
        ->and($this->accountant->can('viewAny', [Payment::class, $this->matter]))->toBeTrue();
});

it('refuses a money list question whose context is not a matter', function () {
    expect($this->admin->can('viewAny', [Contract::class, $this->matter]))->toBeTrue()
        ->and($this->admin->can('viewAny', [Contract::class, $this->contract]))->toBeFalse()
        ->and($this->admin->can('viewAny', [Contract::class, 'VK-2026-DD-0001']))->toBeFalse();
});

// ── Vụ đã xoá mềm: không nằm trong `listableBy`, nên không có tiền ─────────────────────────────

it('refuses the money of a soft deleted matter instead of failing', function () {
    expect($this->admin->can('view', $this->contract))->toBeTrue();

    $this->matter->delete();

    expect(canReadMoney($this->admin, [
        $this->contract->fresh(), $this->instalment->fresh(), $this->payment->fresh(), $this->chain[3]->fresh(),
    ]))->toBe([false, false, false, false]);
});

it('refuses the money of a soft deleted matter even when the caller loaded the trashed matter', function () {
    $this->matter->delete();

    $contract = Contract::query()
        ->with(['matter' => fn ($query) => $query->withTrashed()])
        ->findOrFail($this->contract->id);

    expect($contract->matter)->not->toBeNull()
        ->and($this->admin->can('view', $contract))->toBeFalse()
        ->and($this->admin->can('viewAny', [Contract::class, $contract->matter]))->toBeFalse();
});

// ── `contract.manage` ───────────────────────────────────────────────────────────────────────────

it('lets the lawyers on the team, the manager and the admin manage the contract of an ordinary matter', function () {
    expect(canManageContract($this->lead, $this->matter, $this->chain))->toBe([true, true, true, true])
        ->and(canManageContract($this->teammate, $this->matter, $this->chain))->toBe([true, true, true, true])
        ->and(canManageContract($this->manager, $this->matter, $this->chain))->toBe([true, true, true, true])
        ->and(canManageContract($this->admin, $this->matter, $this->chain))->toBe([true, true, true, true]);
});

it('refuses contract management to an account that reads the money but lacks contract.manage', function () {
    $reader = billingWitness([Permission::MatterView, Permission::BillingView], $this->matter);
    $manager = billingWitness([Permission::MatterView, Permission::BillingView, Permission::ContractManage], $this->matter);

    expect(canReadMoney($reader, $this->chain))->toBe([true, true, true, true])
        ->and(canManageContract($reader, $this->matter, $this->chain))->toBe([false, false, false, false])
        ->and(canManageContract($manager, $this->matter, $this->chain))->toBe([true, true, true, true])
        // Vai kế toán: đọc được, không soạn/sửa/xoá/miễn (SPEC §5 bổ sung M9).
        ->and(canReadMoney($this->accountant, $this->chain))->toBe([true, true, true, true])
        ->and(canManageContract($this->accountant, $this->matter, $this->chain))->toBe([false, false, false, false]);
});

it('refuses contract management on a matter whose money the account cannot see, contract.manage or not', function () {
    $stranger = billingWitness([Permission::MatterView, Permission::BillingView, Permission::ContractManage]);
    $member = billingWitness([Permission::MatterView, Permission::BillingView, Permission::ContractManage], $this->matter);

    expect(canManageContract($stranger, $this->matter, $this->chain))->toBe([false, false, false, false])
        ->and(canManageContract($member, $this->matter, $this->chain))->toBe([true, true, true, true])
        // Vai quản lý có `contract.manage` nhưng không thấy vụ `restricted`.
        ->and(canManageContract($this->manager, $this->restricted, $this->restrictedChain))->toBe([false, false, false, false])
        ->and(canManageContract($this->lead, $this->restricted, $this->restrictedChain))->toBe([true, true, true, true])
        ->and(canManageContract($this->teammate, $this->restricted, $this->restrictedChain))->toBe([false, false, false, false]);
});

it('refuses to draft a contract when the question carries no matter', function () {
    expect($this->admin->can('create', [Contract::class, $this->matter]))->toBeTrue()
        ->and($this->admin->can('create', Contract::class))->toBeFalse()
        ->and($this->admin->can('create', [Contract::class, $this->instalment]))->toBeFalse();
});

// ── `payment.record` ────────────────────────────────────────────────────────────────────────────

it('lets the manager read a payment but never record or void one', function () {
    $reader = billingWitness([Permission::MatterView, Permission::BillingView], $this->matter);
    $recorder = billingWitness([Permission::MatterView, Permission::BillingView, Permission::PaymentRecord], $this->matter);

    expect(canReadMoney($this->manager, $this->chain))->toBe([true, true, true, true])
        ->and(canRecordMoney($this->manager, $this->matter, $this->chain))->toBe([false, false, false])
        ->and(canReadMoney($reader, $this->chain))->toBe([true, true, true, true])
        ->and(canRecordMoney($reader, $this->matter, $this->chain))->toBe([false, false, false])
        ->and(canRecordMoney($recorder, $this->matter, $this->chain))->toBe([true, true, true]);
});

it('lets the accountant and the admin record and void on an ordinary matter, but not its lawyers', function () {
    expect(canRecordMoney($this->accountant, $this->matter, $this->chain))->toBe([true, true, true])
        ->and(canRecordMoney($this->admin, $this->matter, $this->chain))->toBe([true, true, true])
        ->and(canRecordMoney($this->lead, $this->matter, $this->chain))->toBe([false, false, false])
        ->and(canRecordMoney($this->teammate, $this->matter, $this->chain))->toBe([false, false, false]);
});

it('lets the lead lawyer of a restricted matter record and void there without payment.record', function () {
    expect($this->lead->can(Permission::PaymentRecord->value))->toBeFalse()
        ->and(canRecordMoney($this->lead, $this->restricted, $this->restrictedChain))->toBe([true, true, true])
        ->and(canRecordMoney($this->lead, $this->matter, $this->chain))->toBe([false, false, false])
        ->and(canRecordMoney($this->admin, $this->restricted, $this->restrictedChain))->toBe([true, true, true]);
});

it('refuses to record on a restricted matter to anyone who cannot see its money, payment.record or not', function () {
    $member = billingWitness([Permission::MatterView, Permission::BillingView, Permission::PaymentRecord], $this->restricted);
    $this->matter->addTeamMember($member, MatterRole::Associate);

    expect(canRecordMoney($member, $this->restricted, $this->restrictedChain))->toBe([false, false, false])
        ->and(canRecordMoney($member, $this->matter, $this->chain))->toBe([true, true, true])
        ->and(canRecordMoney($this->accountant, $this->restricted, $this->restrictedChain))->toBe([false, false, false])
        ->and(canRecordMoney($this->accountant, $this->matter, $this->chain))->toBe([true, true, true])
        ->and(canRecordMoney($this->teammate, $this->restricted, $this->restrictedChain))->toBe([false, false, false]);
});

it('refuses a payment question that carries neither an instalment nor a matter', function () {
    expect($this->admin->can('create', [Payment::class, $this->instalment]))->toBeTrue()
        ->and($this->admin->can('create', Payment::class))->toBeFalse()
        ->and($this->admin->can('create', [Payment::class, $this->contract]))->toBeFalse()
        ->and($this->accountant->can('create', Payment::class))->toBeFalse();
});

// ── Khách hàng: chưa bao giờ, cho tới Task 10 ──────────────────────────────────────────────────

it('never lets a client user list, manage, record or void money, even on their own restricted matter', function () {
    $clientUser = ClientUser::factory()->create(['client_id' => $this->restricted->client_id]);

    expect($clientUser->can('viewAny', [Contract::class, $this->restricted]))->toBeFalse()
        ->and(canListMoney($clientUser))->toBe([false, false, false, false])
        ->and(canReadMoney($clientUser, $this->restrictedChain))->toBe([false, false, false, false])
        ->and(canManageContract($clientUser, $this->restricted, $this->restrictedChain))->toBe([false, false, false, false])
        ->and(canRecordMoney($clientUser, $this->restricted, $this->restrictedChain))->toBe([false, false, false]);
});

// ── Ranh giới của kế toán: `AccountantBillingRow` (tiền lệ `ConflictMatch`, SPEC §6.10) ─────────

it('carries only the matter code, type, client, instalment name, numbers, due date and state', function () {
    $secretTitle = 'TIEU DE TUYET MAT KHONG DUOC LO XXQ123';
    $client = Client::factory()->create(['name' => 'Công ty TNHH Thương mại Hải Đăng']);
    $matter = Matter::factory()->for($client)->create([
        'title' => $secretTitle,
        'description_internal' => 'Noi dung noi bo tuyet mat',
        'summary_for_client' => 'Tom tat rieng tu cho khach',
    ]);
    $contract = Contract::factory()->for($matter)->active()->create(['note' => 'Ghi chu hop dong KHONGLO1', 'total_amount' => 30_000_000]);
    $instalment = Instalment::factory()->for($contract)->create([
        'name' => 'Thanh toán đợt 2 khi nộp đơn khởi kiện',
        'amount' => 30_000_000,
        'due_date' => '2026-10-15',
        'note' => 'Ghi chu dot thanh toan KHONGLO2',
    ]);

    $row = AccountantBillingRow::fromInstalment(
        $instalment,
        collected: 10_000_000,
        outstanding: 20_000_000,
        state: InstalmentState::PartiallyPaid,
    );

    $serialized = json_encode($row->toArray());

    expect($row->toArray())->toBe([
        'matter_code' => $matter->code,
        'matter_type_name' => $matter->matterType->name,
        'client_name' => 'Công ty TNHH Thương mại Hải Đăng',
        'instalment_name' => 'Thanh toán đợt 2 khi nộp đơn khởi kiện',
        'amount' => 30_000_000,
        'collected' => 10_000_000,
        'outstanding' => 20_000_000,
        'due_date' => '2026-10-15',
        'state' => 'partially_paid',
    ])
        ->and($serialized)->not->toContain($secretTitle)
        ->and($serialized)->not->toContain('tuyet mat')
        ->and($serialized)->not->toContain('rieng tu')
        ->and($serialized)->not->toContain('KHONGLO');
});

it('is a final readonly class with exactly the nine fields of the SPEC amendment', function () {
    $class = new ReflectionClass(AccountantBillingRow::class);

    expect($class->isFinal())->toBeTrue()
        ->and($class->isReadOnly())->toBeTrue()
        ->and(array_map(fn (ReflectionProperty $property) => $property->getName(), $class->getProperties()))->toBe([
            'matterCode', 'matterTypeName', 'clientName', 'instalmentName',
            'amount', 'collected', 'outstanding', 'dueDate', 'state',
        ]);
});

/*
 * Khách hàng và loại vụ việc đều xoá mềm được. Kế toán vẫn phải lập được phiếu thu cho một khoản
 * của khách đã lưu hồ sơ — lý do duy nhất tên khách đi qua ranh giới này (SPEC §5 bổ sung M9).
 */
it('still names a client and a matter type that were soft deleted', function () {
    $client = $this->matter->client;
    $type = $this->matter->matterType;

    $client->delete();
    $type->delete();

    $row = AccountantBillingRow::fromInstalment(
        $this->instalment->fresh(),
        collected: 0,
        outstanding: $this->instalment->amount,
        state: InstalmentState::Due,
    );

    expect($row->clientName)->toBe($client->name)
        ->and($row->matterTypeName)->toBe($type->name)
        ->and($row->matterCode)->toBe($this->matter->code);
});

it('reads parents the caller already loaded without a query of its own', function () {
    $instalment = Instalment::query()
        ->with(['contract.matter' => fn ($query) => $query->with(['matterType', 'client'])])
        ->findOrFail($this->instalment->id);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $row = AccountantBillingRow::fromInstalment($instalment, collected: 0, outstanding: 0, state: InstalmentState::Due);

    expect(DB::getQueryLog())->toBe([])
        ->and($row->matterCode)->toBe($this->matter->code);
});

// ── Vụ việc nạp thiếu cột (fix round 1, I1) ────────────────────────────────────────────────────
//
// `Matter::isListableBy()` đọc thuộc tính trong bộ nhớ. Một vụ nạp bằng
// `with('matter:id,code,lead_lawyer_id')` — đúng kiểu nạp mà test đếm truy vấn của trang tiền sẽ
// đẩy người viết tới — không có `confidentiality` (thành `null`, tức nhánh vụ THƯỜNG, nơi mọi người
// có `matter.viewAny` đều qua) và không có `deleted_at` (thành "chưa xoá mềm"). Bản SQL thì đóng
// trên `NULL`; bản trong bộ nhớ mở. Các test dưới đây dựng lại đúng những lần nạp đó.

/**
 * Nạp lại một chuỗi tiền với vụ việc CHỈ có các cột đã cho, qua đúng đường quan hệ mà mỗi policy
 * đọc (`contract.matter`, `instalment.contract.matter`, …).
 *
 * @param  array<int, Contract|Instalment|Payment|ContractAmendment>  $chain
 * @param  list<string>  $columns
 * @return array{0: Contract, 1: Instalment, 2: Payment, 3: ContractAmendment}
 */
function billingChainLoadedWith(array $chain, array $columns, bool $withTrashed = false): array
{
    [$contract, $instalment, $payment, $amendment] = $chain;

    $matter = fn ($query) => $query->select($columns)->when($withTrashed, fn ($query) => $query->withTrashed());

    return [
        Contract::query()->with(['matter' => $matter])->findOrFail($contract->id),
        Instalment::query()->with(['contract.matter' => $matter])->findOrFail($instalment->id),
        Payment::query()->with(['instalment.contract.matter' => $matter])->findOrFail($payment->id),
        ContractAmendment::query()->with(['contract.matter' => $matter])->findOrFail($amendment->id),
    ];
}

it('refuses the accountant and the manager the money of a restricted matter loaded without its confidentiality', function () {
    $chain = billingChainLoadedWith($this->restrictedChain, ['id', 'code', 'lead_lawyer_id']);
    $matter = $chain[0]->matter;

    // Dạng nạp đúng như người rà soát nêu, viết bằng chuỗi `relation:cột`.
    $literal = Contract::query()->with('matter:id,code,lead_lawyer_id')->findOrFail($this->restrictedContract->id);

    expect(array_key_exists('confidentiality', $matter->getAttributes()))->toBeFalse()
        ->and(array_key_exists('confidentiality', $literal->matter->getAttributes()))->toBeFalse()
        ->and($this->accountant->can('view', $literal))->toBeFalse()
        ->and(canReadMoney($this->accountant, $chain))->toBe([false, false, false, false])
        ->and(canRecordMoney($this->accountant, $matter, $chain))->toBe([false, false, false])
        ->and($this->accountant->can('viewAny', [Contract::class, $matter]))->toBeFalse()
        ->and(canReadMoney($this->manager, $chain))->toBe([false, false, false, false])
        ->and(canRecordMoney($this->manager, $matter, $chain))->toBe([false, false, false])
        ->and(canManageContract($this->manager, $matter, $chain))->toBe([false, false, false, false]);
});

it('still gives the lead lawyer and the admin their money on the same partially loaded restricted chain', function () {
    $chain = billingChainLoadedWith($this->restrictedChain, ['id', 'code', 'lead_lawyer_id']);
    $matter = $chain[0]->matter;

    expect(canReadMoney($this->lead, $chain))->toBe([true, true, true, true])
        ->and(canRecordMoney($this->lead, $matter, $chain))->toBe([true, true, true])
        ->and(canManageContract($this->lead, $matter, $chain))->toBe([true, true, true, true])
        ->and(canReadMoney($this->admin, $chain))->toBe([true, true, true, true])
        ->and(canRecordMoney($this->admin, $matter, $chain))->toBe([true, true, true]);
});

/* Mỗi cột trong ba cột một test riêng: thiếu ĐÚNG cột đó, hai cột kia có mặt. */

it('refuses the accountant a restricted matter loaded without only its confidentiality', function () {
    $chain = billingChainLoadedWith($this->restrictedChain, ['id', 'code', 'lead_lawyer_id', 'deleted_at']);

    expect(canReadMoney($this->accountant, $chain))->toBe([false, false, false, false])
        ->and(canRecordMoney($this->accountant, $chain[0]->matter, $chain))->toBe([false, false, false])
        // Cặp dương trên cùng dạng nạp: vụ thường vẫn mở cho kế toán.
        ->and(canReadMoney($this->accountant, billingChainLoadedWith($this->chain, ['id', 'code', 'lead_lawyer_id', 'deleted_at'])))
        ->toBe([true, true, true, true]);
});

it('gives the lead lawyer a restricted matter loaded without only its lead_lawyer_id', function () {
    $chain = billingChainLoadedWith($this->restrictedChain, ['id', 'code', 'confidentiality', 'deleted_at']);

    expect(array_key_exists('lead_lawyer_id', $chain[0]->matter->getAttributes()))->toBeFalse()
        ->and(canReadMoney($this->lead, $chain))->toBe([true, true, true, true])
        ->and(canRecordMoney($this->lead, $chain[0]->matter, $chain))->toBe([true, true, true])
        ->and(canReadMoney($this->teammate, $chain))->toBe([false, false, false, false]);
});

it('refuses the money of a soft deleted matter loaded without its deleted_at', function () {
    $columns = ['id', 'code', 'lead_lawyer_id', 'confidentiality'];

    // Cặp dương: cùng dạng nạp, vụ chưa xoá mềm → admin thấy.
    expect(canReadMoney($this->admin, billingChainLoadedWith($this->chain, $columns, withTrashed: true)))
        ->toBe([true, true, true, true]);

    $this->matter->delete();
    $chain = billingChainLoadedWith($this->chain, $columns, withTrashed: true);

    expect($chain[0]->matter)->not->toBeNull()
        ->and(array_key_exists('deleted_at', $chain[0]->matter->getAttributes()))->toBeFalse()
        ->and(canReadMoney($this->admin, $chain))->toBe([false, false, false, false])
        ->and(canRecordMoney($this->admin, $chain[0]->matter, $chain))->toBe([false, false, false])
        ->and($this->admin->can('viewAny', [Contract::class, $chain[0]->matter]))->toBeFalse();
});

/*
 * Lần nạp lại chạy một truy vấn `Matter`, và `Matter` mang `ClientPortalScope`: khi một phiên cổng
 * khách đang mở mà guard `web` thì chưa (một job, một Action gọi `Gate::forUser($staff)`), truy vấn
 * đó bị cắt theo KHÁCH của phiên kia. Câu trả lời về nhân sự không được đổi theo chuyện đó — cùng
 * thiết bị với đường truy vấn của `MatterPolicy::view`.
 */
it('answers a partially loaded matter the same while an unrelated client portal session is open', function () {
    $chain = billingChainLoadedWith($this->restrictedChain, ['id', 'code', 'lead_lawyer_id']);

    $this->actingAs(ClientUser::factory()->create(), 'client');

    expect(canReadMoney($this->lead, $chain))->toBe([true, true, true, true])
        ->and(canRecordMoney($this->lead, $chain[0]->matter, $chain))->toBe([true, true, true])
        ->and(canReadMoney($this->accountant, $chain))->toBe([false, false, false, false]);
});
