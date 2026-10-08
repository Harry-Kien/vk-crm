<?php

use App\Enums\ContractStatus;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\Gate;

/**
 * Tầng TRUY VẤN của bốn model tiền dưới phiên cổng, trên một hợp đồng NHÁP.
 *
 * Lịch sử: Task 2 đóng kín cả bốn model (`applyClientPortalConstraints()` trả `1 = 0`), và các
 * test ở đây khi ấy nói "khách không có dòng nào". M9 Task 10 (P1) mở có chủ đích: khách thấy hợp
 * đồng ĐÃ KÝ (`active`/`completed`) của vụ việc mình trên cổng, các đợt chưa huỷ, các khoản thu chưa
 * huỷ và phụ lục của nó. Các test dưới đây giữ đúng fixture cũ — hợp đồng mặc định của
 * `ContractFactory` là bản NHÁP — nên chúng giờ đo điều kiện "bản nháp không bao giờ tới khách" ở
 * tầng truy vấn, kéo theo đợt, khoản thu và phụ lục của nó. Cặp dương: CÙNG hình dạng chuỗi bản
 * ghi, ký xong, cả bốn hiện ra. Độ phủ đầy đủ của ba tầng nằm ở `tests/Feature/Portal/BillingOnPortalTest.php`.
 *
 * Mutation probe (Task 10): gỡ `scopeShownToClient()` khỏi `Contract::applyClientPortalConstraints()`
 * thì bốn test "draft" đỏ.
 */
beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['is_published_to_portal' => true]);
});

it('gives the client no rows of a draft contract', function () {
    $contract = Contract::factory()->for($this->matter)->create();

    $rows = ClientPortalScope::actingAs($this->clientUser, fn () => Contract::query()->get());

    expect($rows)->toBeEmpty()
        ->and(Contract::query()->whereKey($contract->id)->exists())->toBeTrue();
});

it('gives the client no instalment rows of a draft contract', function () {
    $contract = Contract::factory()->for($this->matter)->create();
    Instalment::factory()->for($contract)->create();

    $rows = ClientPortalScope::actingAs($this->clientUser, fn () => Instalment::query()->get());

    expect($rows)->toBeEmpty();
});

it('gives the client no payment rows of a draft contract', function () {
    $contract = Contract::factory()->for($this->matter)->create();
    $instalment = Instalment::factory()->for($contract)->create();
    Payment::factory()->for($instalment)->create();

    $rows = ClientPortalScope::actingAs($this->clientUser, fn () => Payment::query()->get());

    expect($rows)->toBeEmpty();
});

it('gives the client no amendment rows of a draft contract', function () {
    $contract = Contract::factory()->for($this->matter)->create();
    ContractAmendment::factory()->for($contract)->create();

    $rows = ClientPortalScope::actingAs($this->clientUser, fn () => ContractAmendment::query()->get());

    expect($rows)->toBeEmpty();
});

it('gives the client the rows of the same contract once it is signed', function () {
    $contract = Contract::factory()->for($this->matter)->create(['total_amount' => 12_000_000]);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 12_000_000]);
    $payment = Payment::factory()->for($instalment)->create();
    $amendment = ContractAmendment::factory()->for($contract)->create();

    $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->subDay()->toDateString()]);

    $rows = ClientPortalScope::actingAs($this->clientUser, fn () => [
        Contract::query()->pluck('id')->all(),
        Instalment::query()->pluck('id')->all(),
        Payment::query()->pluck('id')->all(),
        ContractAmendment::query()->pluck('id')->all(),
    ]);

    expect($rows)->toBe([[$contract->id], [$instalment->id], [$payment->id], [$amendment->id]]);
});

it('hides internal columns from serialization while the portal scope is active', function () {
    // KHÔNG re-query bên trong actingAs(): hợp đồng ở đây là bản nháp, global scope của Contract
    // chặn nó, nên `fresh()` ở đây sẽ trả `null`. `toArray()` trên instance ĐÃ NẠP không cần truy
    // vấn gì — đúng thứ `attributesToArray()` thao tác.
    $contract = Contract::factory()->for($this->matter)->create(['note' => 'Ghi chú nội bộ, không lên portal']);

    $array = ClientPortalScope::actingAs($this->clientUser, fn () => $contract->toArray());

    expect($array)->not->toHaveKey('note')
        ->and($contract->toArray())->toHaveKey('note');
});

/*
 * Lượt quét trước bản 1.0 (M9 Task 10, minor m4 của sổ làn m9f10, chuyển sang M8 Task 6 — làm ngay
 * vì M12 thêm bề mặt cổng): SPEC §8.3 (đính chính M9) nói khối tiền trên cổng không hiện "mã giao
 * dịch" (`payments.reference`), và SPEC §8 không cho định danh nội bộ ra cổng
 * (`instalments.trigger_stage_key` là khoá giai đoạn thô). Trang cổng hôm nay không serialize model,
 * nên đây là lớp phòng thủ thứ ba như `internal_note`: một view hay một response JSON mai sau quên
 * lọc cột thì cột vẫn không ra.
 */
it('hides the payment reference and the raw trigger stage key from serialization on the portal', function () {
    $contract = Contract::factory()->for($this->matter)->create(['total_amount' => 12_000_000]);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 12_000_000, 'trigger_stage_key' => 'nop-ho-so']);
    $payment = Payment::factory()->for($instalment)->create(['reference' => 'UNC-12345678']);
    $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->subDay()->toDateString()]);

    [$instalmentArray, $paymentArray] = ClientPortalScope::actingAs($this->clientUser, fn () => [$instalment->toArray(), $payment->toArray()]);

    expect($paymentArray)->not->toHaveKey('reference')
        ->and($instalmentArray)->not->toHaveKey('trigger_stage_key')
        ->and($payment->toArray())->toHaveKey('reference')
        ->and($instalment->toArray())->toHaveKey('trigger_stage_key');
});

/*
 * Lượt quét trước bản 1.0 (minor m1 của sổ làn m9f10): nhánh khách của `PaymentPolicy::view` và
 * `InstalmentPolicy::view` đọc điều kiện PHỦ ĐỊNH trên thuộc tính của chính dòng (`voided_at === null`,
 * `status !== Cancelled`). Một dòng nạp bằng select hẹp không có cột đó — dự án không bật
 * `preventAccessingMissingAttributes` — nên thuộc tính đọc ra `null` và cổng MỞ cho khoản thu đã huỷ,
 * đợt đã huỷ. Policy phải đọc lại cột thiếu (cùng tiền lệ `ChecksBillingAccess::matterForBillingGate()`).
 */
it('refuses a voided payment and a cancelled instalment to the client even when they were loaded without that column', function () {
    $this->clientUser->forceFill(['activated_at' => now(), 'must_change_password' => false])->save();
    $contract = Contract::factory()->for($this->matter)->create(['total_amount' => 12_000_000]);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 12_000_000, 'sequence' => 1]);
    $cancelled = Instalment::factory()->for($contract)->cancelled()->create(['amount' => 5_000_000, 'sequence' => 2]);
    $kept = Payment::factory()->for($instalment)->create();
    $voided = Payment::factory()->for($instalment)->voided()->create();
    $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->subDay()->toDateString()]);

    $narrowPayment = fn (Payment $payment): Payment => Payment::query()->withoutGlobalScopes()->select(['id', 'instalment_id', 'amount'])->findOrFail($payment->id);
    $narrowInstalment = fn (Instalment $row): Instalment => Instalment::query()->withoutGlobalScopes()->select(['id', 'contract_id', 'amount'])->findOrFail($row->id);
    $gate = Gate::forUser($this->clientUser);

    expect($gate->allows('view', $narrowPayment($voided)))->toBeFalse()
        ->and($gate->allows('view', $narrowInstalment($cancelled)))->toBeFalse()
        // Cặp dương: cùng select hẹp, dòng chưa huỷ vẫn được thấy — câu trên không xanh nhờ một lỗi khác.
        ->and($gate->allows('view', $narrowPayment($kept)))->toBeTrue()
        ->and($gate->allows('view', $narrowInstalment($instalment)))->toBeTrue();
});
