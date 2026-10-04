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
