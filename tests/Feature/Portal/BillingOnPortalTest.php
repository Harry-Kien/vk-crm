<?php

use App\Actions\Matter\BuildHandoverPackage;
use App\Actions\Matter\RenderHandoverIndex;
use App\Enums\ContractStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\HandoverPackageStatus;
use App\Enums\InstalmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Filament\Portal\Pages\MatterProgress;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\Payment;
use App\Models\User;
use App\Support\OfficeProfile;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\PdfText;

/**
 * M9 Task 10 (P1) — khách xem hợp đồng và lịch thu của CHÍNH MÌNH trên cổng, và bảng kê thanh toán
 * trong `MUC-LUC.pdf` của gói bàn giao.
 *
 * Ba tầng của cổng (luật M5) được nới có chủ đích, và mỗi tầng được đo ĐỘC LẬP:
 *
 *  1. **Truy vấn** — `applyClientPortalConstraints()` của bốn model tiền, đo bằng
 *     `ClientPortalScope::actingAs()` thuần, không qua policy.
 *  2. **Quyền** — nhánh `ClientUser` của bốn policy, đo khi CẢ năm scope cổng (vụ việc + bốn model
 *     tiền) đã bị thay bằng một scope rỗng ("ai đó quên một câu `where`"). Tầng này đo ở tệp này
 *     thay vì thêm bốn model vào danh sách liệt kê tay của `PortalIsolationSweepTest` (lựa chọn ghi
 *     trong báo cáo Task 10): fixture tiền cần lịch thu cân bằng với tổng hợp đồng, thứ tệp kia
 *     không có.
 *  3. **Serialize** — cột nội bộ ẩn khỏi `toArray()` của từng model, VÀ hình chiếu hẹp của trang
 *     (`MatterProgress::billing()`), đo bằng chuỗi đánh dấu đặt trong MỌI cột nội bộ.
 *
 * Mọi khẳng định âm mang vế dương của nó trong cùng test: một trang trắng xanh y hệt một trang
 * đúng ở mọi câu "không thấy".
 *
 * Giờ làm việc (khung của M9 Task 12) vẫn đóng kín với khách sau Task 10 — test ở tệp policy của
 * chính model đó (`tests/Feature/Authorization/`), không ở đây: phép quét cấu trúc của Task 12 chỉ
 * cho đúng các tệp của nó nhắc tên model ấy.
 *
 * Mốc thời gian cố định 20/10/2026: đợt đến hạn 01/10/2026 chưa thu đủ là "quá hạn".
 */
const BOP_MARKER = 'TIEN-NOI-BO-8V3K';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));

    $this->staff = User::factory()->create(['name' => 'Kế toán '.BOP_MARKER]);

    $this->clientA = Client::factory()->create(['name' => 'Khách hàng A']);
    $this->userA = ClientUser::factory()->activated()->create(['client_id' => $this->clientA->id]);

    $this->clientB = Client::factory()->create(['name' => 'Khách hàng B']);
    $this->userB = ClientUser::factory()->activated()->create(['client_id' => $this->clientB->id]);

    $this->matterA = Matter::factory()->for($this->clientA)->create([
        'is_published_to_portal' => true,
        'title' => 'Tranh chấp hợp đồng thuê nhà',
        'stage' => 'collecting_documents',
    ]);

    $this->schedule = bopSchedule($this->matterA, $this->staff);
});

/**
 * Hợp đồng `active` của `$matter`, đủ mọi hình dạng một khách có thể gặp, và một chuỗi đánh dấu
 * trong MỌI cột nội bộ (P1, kế hoạch Task 10 điểm 3).
 *
 * Tổng các đợt chưa huỷ (20 + 15 + 10 + 5 triệu) bằng đúng `total_amount` — hợp đồng được dựng ở
 * `draft` rồi mới chuyển `active`, để hook bất biến tổng của `Contract::updating` đứng gác như ở
 * sản phẩm.
 *
 * @return array{contract: Contract, paid: Instalment, overdue: Instalment, stage: Instalment, waived: Instalment, cancelled: Instalment, receipt: Payment, partial: Payment, voided: Payment, amendment: ContractAmendment, receiptScan: Document, amendmentScan: Document}
 */
function bopSchedule(Matter $matter, User $staff, string $code = 'HD-2026-0042'): array
{
    $contract = Contract::factory()->for($matter)->create([
        'code' => $code,
        'total_amount' => 50_000_000,
        'vat_rate_percent' => 10,
        'note' => 'Ghi chu hop dong '.BOP_MARKER,
        'ended_reason' => 'Ly do ket thuc '.BOP_MARKER,
        'activated_by' => $staff->id,
    ]);

    $paid = Instalment::factory()->for($contract)->create([
        'sequence' => 1,
        'name' => 'Tạm ứng khi ký hợp đồng',
        'amount' => 20_000_000,
        'due_date' => '2026-09-15',
        'status' => InstalmentStatus::Paid,
        'percent_basis' => 40,
        'note' => 'Ghi chu dot 1 '.BOP_MARKER,
    ]);
    $overdue = Instalment::factory()->for($contract)->create([
        'sequence' => 2,
        'name' => 'Thanh toán đợt 2 khi nộp hồ sơ',
        'amount' => 15_000_000,
        'due_date' => '2026-10-01',
        'percent_basis' => 30,
        'note' => 'Ghi chu dot 2 '.BOP_MARKER,
    ]);
    $stage = Instalment::factory()->for($contract)->onStage('court_accepted')->create([
        'sequence' => 3,
        'name' => 'Thanh toán khi toà thụ lý',
        'amount' => 10_000_000,
        'due_days_after_trigger' => 15,
    ]);
    $waived = Instalment::factory()->for($contract)->waived('Ly do mien '.BOP_MARKER)->create([
        'sequence' => 4,
        'name' => 'Phí đi lại phát sinh',
        'amount' => 5_000_000,
        'waived_by' => $staff->id,
    ]);
    $cancelled = Instalment::factory()->for($contract)->cancelled()->create([
        'sequence' => 5,
        'name' => 'Đợt đã bỏ theo phụ lục BOPHUY',
        'amount' => 7_777_000,
    ]);

    $contract->update(['status' => ContractStatus::Active, 'signed_at' => '2026-09-15']);

    $lawyer = User::factory()->create(['name' => 'Luật sư '.BOP_MARKER]);
    $receiptScan = Document::factory()->for($matter)->group(DocumentGroup::Internal)->create([
        'title' => 'Bien lai scan '.BOP_MARKER,
    ]);
    $amendmentScan = Document::factory()->for($matter)->group(DocumentGroup::Internal)->create([
        'title' => 'Phu luc scan '.BOP_MARKER,
    ]);

    $receipt = Payment::factory()->for($paid)->create([
        'amount' => 20_000_000,
        'paid_on' => '2026-09-16',
        'method' => PaymentMethod::BankTransfer,
        'reference' => 'UNC-'.BOP_MARKER,
        'receipt_document_id' => $receiptScan->id,
        'attributed_lawyer_id' => $lawyer->id,
        'note' => 'Ghi chu khoan thu '.BOP_MARKER,
        // Cột này chỉ có nghĩa ở khoản thu ĐÃ huỷ; đặt ở đây để đo tầng serialize trên một dòng
        // khách thật sự nhận được.
        'void_reason' => 'Ly do huy '.BOP_MARKER,
    ]);
    $partial = Payment::factory()->for($overdue)->create([
        'amount' => 5_000_000,
        'paid_on' => '2026-10-02',
        'method' => PaymentMethod::Cash,
        'attributed_lawyer_id' => $lawyer->id,
    ]);
    $voided = Payment::factory()->for($overdue)->voided('Ghi nham so tien '.BOP_MARKER)->create([
        'amount' => 3_333_000,
        'paid_on' => '2026-10-03',
        'method' => PaymentMethod::Card,
        'attributed_lawyer_id' => $lawyer->id,
    ]);

    $amendment = ContractAmendment::factory()->for($contract)->create([
        'sequence' => 1,
        'previous_total_amount' => 45_000_000,
        'new_total_amount' => 50_000_000,
        'reason' => 'Ly do phu luc '.BOP_MARKER,
        'document_id' => $amendmentScan->id,
    ]);

    return compact('contract', 'paid', 'overdue', 'stage', 'waived', 'cancelled', 'receipt', 'partial', 'voided', 'amendment', 'receiptScan', 'amendmentScan');
}

/** Một hợp đồng ở `$status` với đúng một đợt và một khoản thu — cho những vụ việc chỉ cần "có hợp đồng". */
function bopSimpleContract(Matter $matter, ContractStatus $status, string $name): Contract
{
    $contract = Contract::factory()->for($matter)->create(['total_amount' => 9_000_000]);
    $instalment = Instalment::factory()->for($contract)->create([
        'name' => $name,
        'amount' => 9_000_000,
        'due_date' => '2026-09-01',
    ]);

    $contract->update(match ($status) {
        ContractStatus::Draft => [],
        ContractStatus::Active => ['status' => ContractStatus::Active, 'signed_at' => '2026-08-20'],
        ContractStatus::Completed => ['status' => ContractStatus::Completed, 'signed_at' => '2026-08-20', 'ended_at' => '2026-10-05'],
        ContractStatus::Cancelled => ['status' => ContractStatus::Cancelled, 'signed_at' => '2026-08-20', 'ended_at' => '2026-10-05', 'ended_reason' => 'Khách hàng chấm dứt hợp đồng trước hạn theo thoả thuận.'],
    });

    if ($status === ContractStatus::Completed) {
        $instalment->update(['status' => InstalmentStatus::Paid]);
    }

    Payment::factory()->for($instalment)->create(['amount' => 9_000_000, 'paid_on' => '2026-09-01']);

    return $contract->refresh();
}

function bopUrl(Matter $matter): string
{
    return MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal');
}

function bopHtml(ClientUser $user, Matter $matter): string
{
    return test()->actingAs($user, 'client')->get(bopUrl($matter))->assertOk()->getContent();
}

/** Khối tiền, cắt từ mốc của nó tới mốc khối 7. Không có khối thì trả `null`. */
function bopBlock(string $html): ?string
{
    $start = strpos($html, 'data-portal-block="billing"');

    if ($start === false) {
        return null;
    }

    $end = strpos($html, 'data-portal-block="7"', $start);

    expect($end)->not->toBeFalse();

    return substr($html, $start, (int) $end - $start);
}

/** @return list<string> */
function bopBlocksInOrder(string $html): array
{
    preg_match_all('/data-portal-block="([^"]+)"/', $html, $matches);

    return $matches[1];
}

/**
 * @param  list<class-string<Model>>  $models
 */
function bopWithEmptyPortalScope(array $models, Closure $callback): mixed
{
    foreach ($models as $model) {
        $model::addGlobalScope(ClientPortalScope::class, function (): void {});
    }

    try {
        return $callback();
    } finally {
        foreach ($models as $model) {
            $model::addGlobalScope(new ClientPortalScope);
        }
    }
}

const BOP_MONEY_MODELS = [Contract::class, Instalment::class, Payment::class, ContractAmendment::class];

/** Mọi chuỗi đánh dấu trong cột nội bộ, cùng những tên cột nội bộ không được lọt ra dưới dạng khoá. */
function bopInternalNeedles(): array
{
    return [
        BOP_MARKER,
        'Ly do',
        'Ghi chu',
        'Bien lai',
        'Phu luc scan',
        'UNC-',
        '"note"',
        'ended_reason',
        'waived_reason',
        'void_reason',
        'activated_by',
        'waived_by',
        'voided_by',
        'created_by',
        'updated_by',
        'attributed_lawyer_id',
        'receipt_document_id',
        'document_id',
        'percent_basis',
        'triggered_by_stage_log_id',
    ];
}

// =========================================================================================
// TẦNG 1 — TRUY VẤN: scope cổng của bốn model tiền, không qua policy
// =========================================================================================

it('lets the portal query return the signed contracts of the client and nothing else', function () {
    $completedMatter = Matter::factory()->for($this->clientA)->create();
    $completed = bopSimpleContract($completedMatter, ContractStatus::Completed, 'Trọn gói đã xong');

    $draft = bopSimpleContract(Matter::factory()->for($this->clientA)->create(), ContractStatus::Draft, 'Bản nháp');
    $cancelled = bopSimpleContract(Matter::factory()->for($this->clientA)->create(), ContractStatus::Cancelled, 'Đã huỷ');

    $ids = ClientPortalScope::actingAs($this->userA, fn () => Contract::query()->orderBy('id')->pluck('id')->all());

    expect($ids)->toBe([$this->schedule['contract']->id, $completed->id])
        ->and(Contract::query()->whereKey([$draft->id, $cancelled->id])->count())->toBe(2);
});

it('lets the portal query return every instalment of a visible contract except a cancelled one', function () {
    bopSimpleContract(Matter::factory()->for($this->clientA)->create(), ContractStatus::Draft, 'Đợt của bản nháp');

    $ids = ClientPortalScope::actingAs($this->userA, fn () => Instalment::query()->orderBy('id')->pluck('id')->all());

    expect($ids)->toBe([
        $this->schedule['paid']->id,
        $this->schedule['overdue']->id,
        $this->schedule['stage']->id,
        $this->schedule['waived']->id,
    ]);
});

it('lets the portal query return every payment of a visible instalment except a voided one', function () {
    bopSimpleContract(Matter::factory()->for($this->clientA)->create(), ContractStatus::Cancelled, 'Đợt của hợp đồng đã huỷ');

    $ids = ClientPortalScope::actingAs($this->userA, fn () => Payment::query()->orderBy('id')->pluck('id')->all());

    expect($ids)->toBe([$this->schedule['receipt']->id, $this->schedule['partial']->id]);
});

it('lets the portal query return the amendments of a visible contract only', function () {
    $draft = bopSimpleContract(Matter::factory()->for($this->clientA)->create(), ContractStatus::Draft, 'Bản nháp có phụ lục');
    ContractAmendment::factory()->for($draft)->create();

    $ids = ClientPortalScope::actingAs($this->userA, fn () => ContractAmendment::query()->pluck('id')->all());

    expect($ids)->toBe([$this->schedule['amendment']->id]);
});

it('keeps the money of client A out of the portal query of client B', function () {
    $matterB = Matter::factory()->for($this->clientB)->create();
    $contractB = bopSimpleContract($matterB, ContractStatus::Active, 'Đợt của khách B');

    $seen = ClientPortalScope::actingAs($this->userB, fn () => [
        Contract::query()->pluck('id')->all(),
        Instalment::query()->pluck('contract_id')->unique()->values()->all(),
        Payment::query()->count(),
        ContractAmendment::query()->count(),
    ]);

    // Vế dương: khách B vẫn thấy tiền của CHÍNH MÌNH qua cùng đường đó.
    expect($seen)->toBe([[$contractB->id], [$contractB->id], 1, 0]);
});

it('keeps the money of a matter that is not on the portal out of the portal query', function () {
    $hidden = Matter::factory()->for($this->clientA)->unpublished()->create();
    bopSimpleContract($hidden, ContractStatus::Active, 'Đợt của vụ chưa công bố');

    $ids = ClientPortalScope::actingAs($this->userA, fn () => Contract::query()->pluck('id')->all());

    expect($ids)->toBe([$this->schedule['contract']->id]);
});

it('drops the money of a matter whose client access has expired from the portal query', function () {
    MatterArchive::factory()->create(['matter_id' => $this->matterA->id, 'client_access_until' => '2026-10-20']);

    $before = ClientPortalScope::actingAs($this->userA, fn () => [Contract::query()->count(), Instalment::query()->count(), Payment::query()->count()]);

    $this->travelTo(Carbon::parse('2026-10-21 00:00:00'));

    $after = ClientPortalScope::actingAs($this->userA, fn () => [Contract::query()->count(), Instalment::query()->count(), Payment::query()->count()]);

    expect($before)->toBe([1, 4, 2])->and($after)->toBe([0, 0, 0]);
});

it('drops all money from the portal query once the client itself is soft deleted', function () {
    $before = ClientPortalScope::actingAs($this->userA, fn () => Contract::query()->count());

    $this->clientA->delete();

    $after = ClientPortalScope::actingAs($this->userA, fn () => [
        Contract::query()->count(), Instalment::query()->count(), Payment::query()->count(), ContractAmendment::query()->count(),
    ]);

    expect($before)->toBe(1)->and($after)->toBe([0, 0, 0, 0]);
});

// =========================================================================================
// TẦNG 2 — QUYỀN: mọi scope cổng đã quên luật của nó, policy vẫn phải tự trả lời
// =========================================================================================

it('still answers the money question on the policy layer when every portal scope forgets its rule', function () {
    $draft = bopSimpleContract(Matter::factory()->for($this->clientA)->create(), ContractStatus::Draft, 'Bản nháp');
    $cancelled = bopSimpleContract(Matter::factory()->for($this->clientA)->create(), ContractStatus::Cancelled, 'Đã huỷ');
    $completed = bopSimpleContract(Matter::factory()->for($this->clientA)->create(), ContractStatus::Completed, 'Đã xong');
    $draftAmendment = ContractAmendment::factory()->for($draft)->create();

    bopWithEmptyPortalScope([Matter::class, ...BOP_MONEY_MODELS], function () use ($draft, $cancelled, $completed, $draftAmendment) {
        $this->actingAs($this->userA, 'client');

        // Tầng truy vấn đã thủng — nếu không thì khẳng định bên dưới không đo tầng quyền.
        expect(Contract::query()->whereKey($draft->id)->exists())->toBeTrue()
            ->and(Instalment::query()->whereKey($this->schedule['cancelled']->id)->exists())->toBeTrue()
            ->and(Payment::query()->whereKey($this->schedule['voided']->id)->exists())->toBeTrue();

        $s = $this->schedule;

        expect($this->userA->can('view', $draft))->toBeFalse()
            ->and($this->userA->can('view', $cancelled))->toBeFalse()
            ->and($this->userA->can('view', $draft->instalments()->first()))->toBeFalse()
            ->and($this->userA->can('view', $s['cancelled']))->toBeFalse()
            ->and($this->userA->can('view', $s['voided']))->toBeFalse()
            ->and($this->userA->can('view', $draftAmendment))->toBeFalse()
            // Vế dương trong CÙNG ngữ cảnh thủng.
            ->and($this->userA->can('view', $s['contract']))->toBeTrue()
            ->and($this->userA->can('view', $completed))->toBeTrue()
            ->and($this->userA->can('view', $s['overdue']))->toBeTrue()
            ->and($this->userA->can('view', $s['waived']))->toBeTrue()
            ->and($this->userA->can('view', $s['partial']))->toBeTrue()
            ->and($this->userA->can('view', $s['amendment']))->toBeTrue();
    });
});

it('refuses client B every piece of the money of client A on the policy layer, scopes emptied', function () {
    $contractB = bopSimpleContract(Matter::factory()->for($this->clientB)->create(), ContractStatus::Active, 'Đợt của khách B');

    bopWithEmptyPortalScope([Matter::class, ...BOP_MONEY_MODELS], function () use ($contractB) {
        $this->actingAs($this->userB, 'client');

        expect(Contract::query()->whereKey($this->schedule['contract']->id)->exists())->toBeTrue();

        $s = $this->schedule;

        expect($this->userB->can('view', $s['contract']))->toBeFalse()
            ->and($this->userB->can('view', $s['overdue']))->toBeFalse()
            ->and($this->userB->can('view', $s['receipt']))->toBeFalse()
            ->and($this->userB->can('view', $s['amendment']))->toBeFalse()
            ->and($this->userB->can('view', $contractB))->toBeTrue()
            ->and($this->userB->can('view', $contractB->instalments()->first()))->toBeTrue();
    });
});

it('refuses on the policy layer the money of a matter that left the portal, scopes emptied', function () {
    $hidden = Matter::factory()->for($this->clientA)->unpublished()->create();
    $hiddenContract = bopSimpleContract($hidden, ContractStatus::Active, 'Đợt của vụ chưa công bố');
    MatterArchive::factory()->create(['matter_id' => $this->matterA->id, 'client_access_until' => '2026-10-19']);

    bopWithEmptyPortalScope([Matter::class, ...BOP_MONEY_MODELS], function () use ($hiddenContract) {
        $this->actingAs($this->userA, 'client');

        expect($this->userA->can('view', $hiddenContract))->toBeFalse()
            ->and($this->userA->can('view', $this->schedule['contract']))->toBeFalse()
            ->and($this->userA->can('view', $this->schedule['receipt']))->toBeFalse();
    });

    // Vế dương: mở lại tra cứu thì chính hợp đồng đó đọc được. Phiên cổng của khách A vẫn mở, và
    // `MatterArchive` đóng kín với cổng — câu cập nhật phải gỡ scope đó, nếu không nó sửa 0 dòng.
    MatterArchive::query()->withoutGlobalScope(ClientPortalScope::class)
        ->where('matter_id', $this->matterA->id)
        ->update(['client_access_until' => null]);

    expect($this->userA->can('view', $this->schedule['contract']->fresh()))->toBeTrue();
});

it('lets the client read its money but never list, manage, record, void or waive it', function () {
    $s = $this->schedule;

    expect($this->userA->can('view', $s['contract']))->toBeTrue()
        ->and($this->userA->can('viewAny', Contract::class))->toBeFalse()
        ->and($this->userA->can('viewAny', [Contract::class, $this->matterA]))->toBeFalse()
        ->and($this->userA->can('create', [Contract::class, $this->matterA]))->toBeFalse()
        ->and($this->userA->can('update', $s['contract']))->toBeFalse()
        ->and($this->userA->can('delete', $s['contract']))->toBeFalse()
        ->and($this->userA->can('waive', $s['overdue']))->toBeFalse()
        ->and($this->userA->can('create', [Payment::class, $s['overdue']]))->toBeFalse()
        ->and($this->userA->can('void', $s['partial']))->toBeFalse();
});

// =========================================================================================
// TẦNG 3 — SERIALIZE: cột nội bộ không ra khỏi model dưới phiên cổng
// =========================================================================================

dataset('bop internal columns', [
    'contract' => ['contract', ['ended_reason', 'note', 'activated_by', 'created_by', 'updated_by'], 'code'],
    'instalment' => ['overdue', ['waived_reason', 'note', 'waived_by', 'percent_basis', 'triggered_by_stage_log_id', 'created_by', 'updated_by'], 'name'],
    'payment' => ['receipt', ['note', 'void_reason', 'voided_by', 'receipt_document_id', 'attributed_lawyer_id', 'created_by', 'updated_by'], 'amount'],
    'amendment' => ['amendment', ['reason', 'document_id', 'created_by', 'updated_by'], 'new_total_amount'],
]);

it('hides every internal column of a money row from serialization under the portal scope', function (string $key, array $internal, string $public) {
    $record = $this->schedule[$key]->fresh();

    $inPortal = ClientPortalScope::actingAs($this->userA, fn () => $record->toArray());
    $inOffice = $record->toArray();

    foreach ($internal as $column) {
        expect($inPortal)->not->toHaveKey($column)
            ->and($inOffice)->toHaveKey($column);
    }

    expect($inPortal)->toHaveKey($public);
})->with('bop internal columns');

// =========================================================================================
// TRANG TIẾN ĐỘ — khối "Hợp đồng và thanh toán", qua HTTP và Livewire
// =========================================================================================

it('shows the client its own contract and payment schedule on the progress page', function () {
    $block = bopBlock(bopHtml($this->userA, $this->matterA));

    expect($block)->not->toBeNull()
        ->toContain(e(__('portal_progress.blocks.billing.heading')))
        ->toContain('HD-2026-0042')
        ->toContain('50.000.000 ₫')
        ->toContain(e(__('portal_progress.billing.vat', ['rate' => 10])))
        ->toContain('15/09/2026')
        // Bốn đợt khách được thấy, đúng thứ tự, với số tiền.
        ->toContain('Tạm ứng khi ký hợp đồng')
        ->toContain('Thanh toán đợt 2 khi nộp hồ sơ')
        ->toContain('Thanh toán khi toà thụ lý')
        ->toContain('Phí đi lại phát sinh')
        ->toContain('20.000.000 ₫')
        ->toContain('15.000.000 ₫')
        ->toContain('10.000.000 ₫')
        // "Đến hạn ngày …" và "đến hạn khi vụ việc tới bước: <client_label>".
        ->toContain(e(__('portal_progress.billing.due.on', ['date' => '01/10/2026'])))
        ->toContain(e(__('portal_progress.billing.due.stage_after', ['days' => 15, 'stage' => 'Toà đã nhận giải quyết'])))
        // Đã thanh toán và còn lại của đợt thu một phần đã quá hạn.
        ->toContain(e(__('portal_progress.billing.collected', ['amount' => '5.000.000 ₫'])))
        ->toContain(e(__('portal_progress.billing.outstanding', ['amount' => '10.000.000 ₫'])))
        // Quá hạn bằng CHỮ và bằng MÀU; đợt miễn chỉ một câu, không lý do.
        ->toContain(e(__('portal_progress.billing.state.overdue')))
        ->toContain('var(--danger-600)')
        ->toContain(e(__('portal_progress.billing.state.waived')))
        ->toContain(e(__('portal_progress.billing.state.paid')))
        // Các khoản đã nhận: ngày, số tiền, cách trả.
        ->toContain('16/09/2026')
        ->toContain('02/10/2026')
        ->toContain(e(__('portal_progress.billing.method.bank_transfer')))
        ->toContain(e(__('portal_progress.billing.method.cash')))
        // Nhãn NỘI BỘ của giai đoạn và khoá thô không bao giờ ra trước mặt khách.
        ->not->toContain('Toà thụ lý')
        ->not->toContain('court_accepted');
});

it('places the money block after the deadlines and before the request block', function () {
    expect(bopBlocksInOrder(bopHtml($this->userA, $this->matterA)))->toBe(['1', '3', '4', '5', '6', 'billing', '7']);
});

it('leaves out a cancelled instalment and a voided payment, keeping the rest', function () {
    $block = bopBlock(bopHtml($this->userA, $this->matterA));

    expect($block)->not->toContain('BOPHUY')
        ->not->toContain('7.777.000 ₫')
        ->not->toContain('3.333.000 ₫')
        ->not->toContain('03/10/2026')
        ->not->toContain(e(__('portal_progress.billing.method.card')))
        ->toContain('Thanh toán đợt 2 khi nộp hồ sơ')
        ->toContain('5.000.000 ₫');
});

it('shows no money block for a matter whose only contract is a draft', function () {
    $matter = Matter::factory()->for($this->clientA)->create();
    bopSimpleContract($matter, ContractStatus::Draft, 'Đợt của bản nháp');

    $html = bopHtml($this->userA, $matter);

    expect(bopBlock($html))->toBeNull()
        ->and($html)->not->toContain('Đợt của bản nháp')
        ->and(bopBlocksInOrder($html))->toBe(['1', '3', '4', '5', '6', '7']);
});

it('shows no money block for a matter whose contract was cancelled', function () {
    $matter = Matter::factory()->for($this->clientA)->create();
    bopSimpleContract($matter, ContractStatus::Cancelled, 'Đợt của hợp đồng đã huỷ');

    $html = bopHtml($this->userA, $matter);

    expect(bopBlock($html))->toBeNull()
        ->and($html)->not->toContain('Đợt của hợp đồng đã huỷ');
});

it('shows no money block for a matter without any contract', function () {
    $matter = Matter::factory()->for($this->clientA)->create();

    $html = bopHtml($this->userA, $matter);

    expect(bopBlock($html))->toBeNull()
        ->and($html)->not->toContain(e(__('portal_progress.blocks.billing.heading')));
});

it('shows a completed contract as completed, with nothing overdue and nothing left to pay', function () {
    $matter = Matter::factory()->for($this->clientA)->create();
    $contract = bopSimpleContract($matter, ContractStatus::Completed, 'Trọn gói đã thanh toán');

    $block = bopBlock(bopHtml($this->userA, $matter));

    expect($block)->not->toBeNull()
        ->toContain($contract->code)
        ->toContain('Trọn gói đã thanh toán')
        ->toContain(e(__('portal_progress.billing.completed', ['date' => '05/10/2026'])))
        ->toContain(e(__('portal_progress.billing.outstanding', ['amount' => '0 ₫'])))
        ->not->toContain(e(__('portal_progress.billing.state.overdue')));
});

/**
 * Luật I4 (docblock `Instalment::state()`): dòng của một hợp đồng KHÔNG `active` hiện trạng thái
 * HỢP ĐỒNG, không hiện `state()` của đợt. `CompleteContract` chỉ hoàn tất khi mọi đợt đã thu đủ hay
 * đã miễn, nên ca dưới đây không tới được qua Action — dữ liệu dựng thẳng để đo đúng điều kiện: một
 * đợt còn `pending`, quá ngày, thu một phần, trên hợp đồng đã hoàn tất. `state()` của riêng nó nói
 * "quá hạn"; khối tiền không được nói vậy cạnh "Còn lại: 0 ₫". Đợt đã miễn vẫn nói "Văn phòng đã miễn".
 */
it('never calls an instalment of a completed contract overdue, though its own state would, and still names a waiver', function () {
    $matter = Matter::factory()->for($this->clientA)->create();
    $contract = Contract::factory()->for($matter)->create(['total_amount' => 9_000_000]);
    $pending = Instalment::factory()->for($contract)->create(['sequence' => 1, 'name' => 'Đợt còn treo', 'amount' => 8_000_000, 'due_date' => '2026-09-01']);
    Instalment::factory()->for($contract)->waived()->create(['sequence' => 2, 'name' => 'Đợt được miễn', 'amount' => 1_000_000]);
    $contract->update(['status' => ContractStatus::Completed, 'signed_at' => '2026-08-20', 'ended_at' => '2026-10-05']);
    Payment::factory()->for($pending)->create(['amount' => 2_000_000, 'paid_on' => '2026-09-02']);

    expect($pending->fresh()->state()->value)->toBe('overdue');

    $block = bopBlock(bopHtml($this->userA, $matter));

    expect($block)->toContain('Đợt còn treo')
        ->toContain(e(__('portal_progress.billing.completed', ['date' => '05/10/2026'])))
        ->toContain(e(__('portal_progress.billing.state.waived')))
        ->not->toContain(e(__('portal_progress.billing.state.overdue')))
        ->not->toContain('var(--danger-600)');
});

it('words every kind of due date for the client, and never prints an internal stage key', function () {
    $matter = Matter::factory()->for($this->clientA)->create();
    $contract = Contract::factory()->for($matter)->create(['total_amount' => 10_000_000]);
    Instalment::factory()->for($contract)->onStage('drafting')->create(['sequence' => 1, 'name' => 'Theo bước soạn đơn', 'amount' => 4_000_000]);
    Instalment::factory()->for($contract)->onStage('buoc_khong_con_ton_tai')->create(['sequence' => 2, 'name' => 'Theo bước đã bỏ', 'amount' => 3_000_000]);
    Instalment::factory()->for($contract)->onSigning()->create(['sequence' => 3, 'name' => 'Khi ký', 'amount' => 2_000_000]);
    Instalment::factory()->for($contract)->scheduled()->create(['sequence' => 4, 'name' => 'Chưa hẹn ngày', 'amount' => 1_000_000]);
    $contract->update(['status' => ContractStatus::Active, 'signed_at' => '2026-10-10']);

    $block = bopBlock(bopHtml($this->userA, $matter));

    expect($block)->toContain(e(__('portal_progress.billing.due.stage', ['stage' => 'Đang soạn đơn khởi kiện'])))
        ->toContain(e(__('portal_progress.billing.due.stage_unnamed')))
        ->toContain(e(__('portal_progress.billing.due.on_signing')))
        ->toContain(e(__('portal_progress.billing.due.unscheduled')))
        ->toContain(e(__('portal_progress.billing.state.scheduled')))
        ->not->toContain('drafting')
        ->not->toContain('buoc_khong_con_ton_tai')
        ->not->toContain('Soạn đơn');
});

it('prints no tax line when the contract carries no tax rate, and a zero rate when it carries zero', function () {
    $none = Matter::factory()->for($this->clientA)->create();
    bopSimpleContract($none, ContractStatus::Active, 'Không thuế');
    $zero = Matter::factory()->for($this->clientA)->create();
    bopSimpleContract($zero, ContractStatus::Active, 'Thuế không phần trăm')->update(['vat_rate_percent' => 0]);

    $noneBlock = bopBlock(bopHtml($this->userA, $none));
    $zeroBlock = bopBlock(bopHtml($this->userA, $zero));

    $vatLead = Str::before(__('portal_progress.billing.vat', ['rate' => 0]), '0');

    expect($noneBlock)->not->toBeNull()->not->toContain(e($vatLead))
        ->and($zeroBlock)->toContain(e(__('portal_progress.billing.vat', ['rate' => 0])));
});

it('answers client B with 404 on the progress page of client A, money and all', function () {
    $response = $this->actingAs($this->userB, 'client')->get(bopUrl($this->matterA));

    $response->assertNotFound();

    expect($response->getContent())->not->toContain('HD-2026-0042')
        ->and($response->getContent())->not->toContain('Tạm ứng khi ký hợp đồng');
});

it('shows client B its own money on its own page and none of client A', function () {
    $matterB = Matter::factory()->for($this->clientB)->create();
    $contractB = bopSimpleContract($matterB, ContractStatus::Active, 'Đợt riêng của khách B');

    $block = bopBlock(bopHtml($this->userB, $matterB));

    expect($block)->toContain($contractB->code)
        ->toContain('Đợt riêng của khách B')
        ->not->toContain('HD-2026-0042')
        ->not->toContain('Tạm ứng khi ký hợp đồng');
});

it('answers a livewire call from client B on the page of client A with 404', function () {
    Filament::setCurrentPanel('portal');

    $this->actingAs($this->userB, 'client')
        ->livewire(MatterProgress::class, ['record' => $this->matterA->getKey()])
        ->assertNotFound();
});

it('answers the progress page of an unpublished matter with 404, and shows no money from it', function () {
    $hidden = Matter::factory()->for($this->clientA)->unpublished()->create();
    $contract = bopSimpleContract($hidden, ContractStatus::Active, 'Đợt của vụ chưa công bố');

    $response = $this->actingAs($this->userA, 'client')->get(bopUrl($hidden));

    $response->assertNotFound();
    expect($response->getContent())->not->toContain($contract->code);
});

it('answers the progress page with 404 once client access has expired, and served the money the day before', function () {
    MatterArchive::factory()->create(['matter_id' => $this->matterA->id, 'client_access_until' => '2026-10-20']);

    expect(bopBlock(bopHtml($this->userA, $this->matterA)))->toContain('HD-2026-0042');

    $this->travelTo(Carbon::parse('2026-10-21 00:00:00'));

    $response = $this->actingAs($this->userA, 'client')->get(bopUrl($this->matterA));

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('HD-2026-0042');
});

it('shows nothing at all to a client whose client record was soft deleted', function () {
    $this->clientA->delete();

    $response = $this->actingAs($this->userA, 'client')->get(bopUrl($this->matterA));

    // M9 Task 13 (minor m6 rà soát Task 10): khẳng định ĐÚNG đường trả lời — phiên của khách đã bị
    // xoá mềm bị đẩy về trang đăng nhập cổng (M6.5 `portal-3`) — chứ không chỉ "không phải 200",
    // vì một trang 500 cũng qua được câu đó.
    $response->assertRedirect(route('filament.portal.auth.login'));

    expect($response->getContent())->not->toContain('HD-2026-0042')
        ->and($this->userA->can('view', $this->schedule['contract']))->toBeFalse();
});

it('never puts a marker from any internal money column into the html of the page', function () {
    $html = bopHtml($this->userA, $this->matterA);

    // Vế dương: khối có mặt và có dữ liệu thật.
    expect(bopBlock($html))->toContain('HD-2026-0042');

    foreach ([BOP_MARKER, 'Ly do', 'Ghi chu', 'Bien lai', 'Phu luc scan', 'UNC-', '45.000.000'] as $needle) {
        expect($html)->not->toContain($needle);
    }
});

it('serialises no internal money column from any public method the browser can call', function () {
    Filament::setCurrentPanel('portal');
    $this->actingAs($this->userA, 'client');

    $page = new MatterProgress;
    $page->mount($this->matterA->getKey());

    $called = [];

    foreach ((new ReflectionClass($page))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isStatic() || $method->getDeclaringClass()->getName() !== MatterProgress::class) {
            continue;
        }

        if ($method->getNumberOfRequiredParameters() > 0) {
            continue;
        }

        $called[$method->getName()] = json_encode($method->invoke($page), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    expect($called)->toHaveKey('billing');

    $json = implode("\n", $called);

    expect($json)->toContain('HD-2026-0042')->toContain('Thanh toán đợt 2 khi nộp hồ sơ');

    foreach (bopInternalNeedles() as $needle) {
        expect($json)->not->toContain($needle);
    }
});

it('returns only the narrow money projection over a real livewire call', function () {
    Filament::setCurrentPanel('portal');

    $returned = null;

    $this->actingAs($this->userA, 'client')
        ->livewire(MatterProgress::class, ['record' => $this->matterA->getKey()])
        ->call('billing')
        ->assertReturned(function ($value) use (&$returned): bool {
            $returned = $value;

            return true;
        });

    $json = json_encode($returned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect($returned)->toBeArray()
        ->and(array_keys($returned))->toBe(['code', 'total', 'vat', 'signed_on', 'completed_on', 'instalments', 'payments'])
        ->and(array_keys($returned['instalments'][0]))->toBe(['name', 'amount', 'due', 'collected', 'outstanding', 'state', 'state_label'])
        ->and(array_keys($returned['payments'][0]))->toBe(['paid_on', 'amount', 'method'])
        ->and($json)->toContain('HD-2026-0042');

    foreach (bopInternalNeedles() as $needle) {
        expect($json)->not->toContain($needle);
    }
});

it('keeps a draft contract, a cancelled instalment and a voided payment off the page when the money scopes forget their rule', function () {
    $matter = Matter::factory()->for($this->clientA)->create();
    bopSimpleContract($matter, ContractStatus::Draft, 'Đợt của bản nháp BOPNHAP');

    [$draftHtml, $mainHtml] = bopWithEmptyPortalScope(BOP_MONEY_MODELS, function () use ($matter) {
        // Tầng truy vấn đã thủng.
        expect(ClientPortalScope::actingAs($this->userA, fn () => Instalment::query()->whereKey($this->schedule['cancelled']->id)->exists()))->toBeTrue();

        return [bopHtml($this->userA, $matter), bopHtml($this->userA, $this->matterA)];
    });

    expect(bopBlock($draftHtml))->toBeNull()
        ->and($draftHtml)->not->toContain('BOPNHAP')
        ->and(bopBlock($mainHtml))->not->toContain('BOPHUY')
        ->and(bopBlock($mainHtml))->not->toContain('3.333.000 ₫')
        // Vế dương trong cùng ngữ cảnh thủng.
        ->and(bopBlock($mainHtml))->toContain('Thanh toán đợt 2 khi nộp hồ sơ');
});

it('paints the money block only with colour variables the panel registers, and lays it out without a table', function () {
    $html = bopHtml($this->userA, $this->matterA);
    $block = bopBlock($html);

    expect(colourVariablesIn($block))->not->toBeEmpty()
        ->and(unregisteredColourVariables($block))->toBe([])
        ->and($block)->not->toContain('<table')
        // Không lớp tiện ích viết tay: dự án không có bước dựng CSS (CLAUDE.md).
        ->and($block)->not->toContain('class="');
});

// =========================================================================================
// GÓI BÀN GIAO — "Bảng kê thanh toán" trong MUC-LUC.pdf
// =========================================================================================

function bopIndexText(Matter $matter): string
{
    return PdfText::extract(app(RenderHandoverIndex::class)->handle($matter, collect()));
}

it('prints the payment statement into the handover index, with only what the portal shows', function () {
    $text = bopIndexText($this->matterA);
    $flat = PdfText::squash($text);

    expect($flat)->toContain(PdfText::squash(__('handover.pdf.billing.heading')))
        ->toContain('HD-2026-0042')
        ->toContain(PdfText::squash('50.000.000 ₫'))
        ->toContain(PdfText::squash('Thanh toán đợt 2 khi nộp hồ sơ'))
        ->toContain(PdfText::squash('Thanh toán khi toà thụ lý'))
        ->toContain(PdfText::squash(__('portal_progress.billing.due.stage_after', ['days' => 15, 'stage' => 'Toà đã nhận giải quyết'])))
        ->toContain(PdfText::squash(__('portal_progress.billing.state.waived')))
        ->toContain(PdfText::squash(__('portal_progress.billing.state.overdue')))
        ->toContain('16/09/2026')
        ->toContain(PdfText::squash(__('portal_progress.billing.method.cash')))
        // Cột nội bộ không có trong tệp.
        ->not->toContain(BOP_MARKER)
        ->not->toContain('Lydo')
        ->not->toContain('Ghichu')
        // Đợt huỷ, khoản thu huỷ không vào bảng kê.
        ->not->toContain('BOPHUY')
        ->not->toContain('3.333.000')
        // Nhãn nội bộ của giai đoạn và khoá thô không vào tài liệu giao cho khách.
        ->not->toContain(PdfText::squash('Toà thụ lý'))
        ->not->toContain('court_accepted');
});

it('prints no payment statement when the matter has no contract the client may see', function () {
    $matter = Matter::factory()->for($this->clientA)->create();
    bopSimpleContract($matter, ContractStatus::Draft, 'Đợt của bản nháp BOPNHAP');

    $flat = PdfText::squash(bopIndexText($matter));

    expect($flat)->not->toContain(PdfText::squash(__('handover.pdf.billing.heading')))
        ->not->toContain(PdfText::squash(__('handover.pdf.billing.as_of', ['date' => '20/10/2026'])))
        ->not->toContain('BOPNHAP')
        // Vế dương: phần còn lại của mục lục vẫn được in.
        ->toContain(PdfText::squash(__('handover.pdf.timeline_heading')));
});

/**
 * Việc sau gộp M9 + M10 (làn fu3, Task 2 mục D): từ fu2 thư công bố gói mời khách tải gói về và CẤT
 * GIỮ, và bảng kê trong `MUC-LUC.pdf` đóng băng lúc gói được lập. Một khoản khách trả sau ngày đó không
 * bao giờ vào tệp khách đang giữ — nên bảng kê nói ngày "tính đến" của nó (cùng ngày với dòng "Lập
 * ngày" đầu mục lục), và chỉ khách sang cổng, nơi khối tiền đọc dữ liệu lúc mở trang. SPEC §6.12,
 * bổ sung cùng ngày.
 */
it('dates the payment statement as of the day the package is generated, while the portal shows a payment recorded after that day', function () {
    $asOf = fn (string $date): string => PdfText::squash(__('handover.pdf.billing.as_of', ['date' => $date]));

    expect(__('handover.pdf.billing.as_of', ['date' => '20/10/2026']))
        ->toContain('lập gói')
        ->toContain('cổng khách hàng')
        // Lượt quét trước bản 1.0 (minor m2 rà soát Task 2 làn fu3): bảng kê đóng băng LÚC lập gói, không
        // phải cuối ngày đó — một khoản ghi chiều cùng ngày cũng không có; và không chỉ khoản thanh toán
        // mà cả miễn, huỷ, phụ lục ghi sau đó (SPEC §6.12 kể cả bốn).
        ->toContain('sau lúc lập gói')
        ->toContain('thay đổi khác về thanh toán')
        ->not->toContain('sau ngày này');

    $index = PdfText::squash(bopIndexText($this->matterA));

    expect($index)->toContain($asOf('20/10/2026'))
        ->toContain(PdfText::squash(__('handover.pdf.generated_at', ['date' => '20/10/2026'])));

    // Hai tuần sau, khách trả nốt đợt 2; văn phòng ghi khoản thu.
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00'));
    Payment::factory()->for($this->schedule['overdue'])->create([
        'amount' => 10_000_000,
        'paid_on' => '2026-11-02',
        'method' => PaymentMethod::Cash,
    ]);

    // Cổng đọc dữ liệu lúc mở trang: khoản mới có ngay.
    expect(bopBlock(bopHtml($this->userA, $this->matterA)))->toContain('02/11/2026');

    // Gói lập lại hôm nay mang ngày mới và khoản mới; ngày cũ không còn.
    expect(PdfText::squash(bopIndexText($this->matterA->fresh())))
        ->toContain($asOf('03/11/2026'))
        ->toContain('02/11/2026')
        ->not->toContain($asOf('20/10/2026'));
});

it('gives the portal and the handover index the very same statement on the same data', function () {
    Filament::setCurrentPanel('portal');

    $portal = null;

    $this->actingAs($this->userA, 'client')
        ->livewire(MatterProgress::class, ['record' => $this->matterA->getKey()])
        ->call('billing')
        ->assertReturned(function ($value) use (&$portal): bool {
            $portal = $value;

            return true;
        });

    auth('client')->logout();

    $handover = app(RenderHandoverIndex::class)->billingStatement($this->matterA->fresh());

    expect($handover)->toBe($portal)
        ->and(array_column($handover['instalments'], 'name'))->toBe([
            'Tạm ứng khi ký hợp đồng',
            'Thanh toán đợt 2 khi nộp hồ sơ',
            'Thanh toán khi toà thụ lý',
            'Phí đi lại phát sinh',
        ])
        ->and(count($handover['payments']))->toBe(2);
});

it('still prints the office footer, read through OfficeProfile, in an index that carries the statement', function () {
    $office = OfficeProfile::current();

    $flat = PdfText::squash(bopIndexText($this->matterA));

    expect($flat)->toContain(PdfText::squash(__('handover.pdf.billing.heading')))
        ->and($office->legalName())->not->toBeEmpty()
        ->and($flat)->toContain(PdfText::squash((string) $office->legalName()));
});

it('never puts a receipt scan or an amendment scan into the handover zip', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
    $workRoot = storage_path('framework/testing/handover-'.Str::random(16));
    config(['vkcrm.handover.work_dir' => $workRoot]);

    try {
        $lawyer = User::factory()->withRole(Role::Lawyer)->create();
        $this->matterA->update(['closed_at' => '2026-10-19', 'lead_lawyer_id' => $lawyer->id]);

        foreach (['receiptScan' => 'bien-lai.pdf', 'amendmentScan' => 'phu-luc.pdf'] as $key => $file) {
            $this->schedule[$key]->update(['status' => DocumentStatus::SignedFiled]);
            $this->schedule[$key]->addMediaFromString('scan '.$key)->usingFileName($file)->toMediaCollection('file');
        }

        $issued = Document::factory()->for($this->matterA)->group(DocumentGroup::Issued)->create([
            'title' => 'Bản án sơ thẩm',
            'status' => DocumentStatus::SignedFiled,
        ]);
        $issued->addMediaFromString('ban an')->usingFileName('ban-an.pdf')->toMediaCollection('file');

        $archive = MatterArchive::factory()->create([
            'matter_id' => $this->matterA->id,
            'archived_by' => $lawyer->id,
            'handover_status' => HandoverPackageStatus::Generating,
            'handover_requested_at' => now()->startOfSecond(),
            'handover_requested_by' => $lawyer->id,
        ]);

        $package = app(BuildHandoverPackage::class)->handle($this->matterA->id, $archive->fresh()->handover_requested_at->getTimestamp());

        expect($package)->not->toBeNull();

        $zip = new ZipArchive;
        expect($zip->open($package->getFirstMedia('file')->getPath(), ZipArchive::RDONLY))->toBeTrue();

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $index = PdfText::squash(PdfText::extract($zip->getFromName('MUC-LUC.pdf')));
        $zip->close();

        $joined = implode("\n", $names);

        expect($joined)->not->toContain('D/')
            ->not->toContain('Bien')
            ->not->toContain('Phu')
            // Vế dương: tài liệu nhóm B vào gói, và mục lục có bảng kê.
            ->toContain('B/')
            ->and($names)->toContain('MUC-LUC.pdf')
            ->and($index)->toContain(PdfText::squash(__('handover.pdf.billing.heading')))
            ->and($index)->not->toContain(BOP_MARKER);
    } finally {
        File::deleteDirectory($workRoot);
    }
});
