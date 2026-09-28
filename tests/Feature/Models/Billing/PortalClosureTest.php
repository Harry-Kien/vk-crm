<?php

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Support\Scopes\ClientPortalScope;

/**
 * P1 (M9): cả bốn model tiền đóng kín ở Task 2 — `applyClientPortalConstraints()` trả `1 = 0`
 * dưới guard client, sẽ mở có chủ đích ở Task 10. Mutation probe cho mỗi test dưới đây: gỡ
 * `RestrictedToClientPortal` khỏi model tương ứng, chạy lại đúng test đó, xác nhận nó chuyển ĐỎ
 * (trait bị gỡ thì global scope không còn được gắn nên `ClientPortalScope::actingAs()` không lọc
 * gì cả — dòng vẫn hiện ra), rồi khôi phục. Bằng chứng RED/GREEN nằm trong báo cáo Task 2, không
 * lặp lại ở đây vì mutation probe không phải bất biến của TEST SUITE — nó là một bước thao tác.
 */
beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['is_published_to_portal' => true]);
});

it('gives the client no contract rows', function () {
    $contract = Contract::factory()->for($this->matter)->create();

    $rows = ClientPortalScope::actingAs($this->clientUser, fn () => Contract::query()->get());

    expect($rows)->toBeEmpty()
        ->and(Contract::query()->whereKey($contract->id)->exists())->toBeTrue();
});

it('gives the client no instalment rows', function () {
    $contract = Contract::factory()->for($this->matter)->create();
    Instalment::factory()->for($contract)->create();

    $rows = ClientPortalScope::actingAs($this->clientUser, fn () => Instalment::query()->get());

    expect($rows)->toBeEmpty();
});

it('gives the client no payment rows', function () {
    $contract = Contract::factory()->for($this->matter)->create();
    $instalment = Instalment::factory()->for($contract)->create();
    Payment::factory()->for($instalment)->create();

    $rows = ClientPortalScope::actingAs($this->clientUser, fn () => Payment::query()->get());

    expect($rows)->toBeEmpty();
});

it('gives the client no contract amendment rows', function () {
    $contract = Contract::factory()->for($this->matter)->create();
    ContractAmendment::factory()->for($contract)->create();

    $rows = ClientPortalScope::actingAs($this->clientUser, fn () => ContractAmendment::query()->get());

    expect($rows)->toBeEmpty();
});

it('hides internal columns from serialization while the portal scope is active', function () {
    // KHÔNG re-query bên trong actingAs(): global scope của Contract cũng đang chặn (`1 = 0`),
    // nên `fresh()` ở đây sẽ trả `null`. `toArray()` trên instance ĐÃ NẠP không cần truy vấn gì
    // — đúng thứ `attributesToArray()` thao tác.
    $contract = Contract::factory()->for($this->matter)->create(['note' => 'Ghi chú nội bộ, không lên portal']);

    $array = ClientPortalScope::actingAs($this->clientUser, fn () => $contract->toArray());

    expect($array)->not->toHaveKey('note')
        ->and($contract->toArray())->toHaveKey('note');
});
