<?php

use App\Actions\Billing\DraftContract;
use App\Actions\Matter\RenderHandoverIndex;
use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\BillingSummary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fb, mục A1)
|--------------------------------------------------------------------------
| Hợp đồng đã HUỶ không còn khoá vĩnh viễn vụ việc: "một hợp đồng cho một vụ" (M9 quyết định 1) nay
| đọc là "một hợp đồng CHƯA HUỶ cho một vụ". Bản huỷ ở lại làm lịch sử (tiền đã thu vẫn là tiền đã
| thu), và vụ soạn được một hợp đồng mới. Chốt cuối ở CSDL: unique trên cột sinh `open_matter_id`
| (`matter_id` khi chưa huỷ, NULL khi đã huỷ).
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
});

function redraft(User $actor, Matter $matter, int $total = 50_000_000): Contract
{
    return app(DraftContract::class)->handle($actor, $matter, ['total_amount' => $total], [
        ['name' => 'Trọn gói', 'amount' => $total, 'trigger_type' => 'due_date', 'due_date' => today()->addMonth()->toDateString()],
    ]);
}

function cancelledContractWithPayment(Matter $matter): Contract
{
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 20_000_000]);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 20_000_000, 'status' => InstalmentStatus::Pending]);
    Payment::factory()->for($instalment)->create(['amount' => 5_000_000]);
    $contract->forceFill(['status' => ContractStatus::Cancelled, 'ended_at' => today()->toDateString(), 'ended_reason' => str_repeat('a', 20)])->save();

    return $contract;
}

it('drafts a new contract on a matter whose only contract was cancelled, keeping the cancelled one and its money as history', function () {
    $old = cancelledContractWithPayment($this->matter);

    $new = redraft($this->lead, $this->matter);

    expect($new->status)->toBe(ContractStatus::Draft)
        ->and($new->id)->not->toBe($old->id)
        ->and($old->fresh()->status)->toBe(ContractStatus::Cancelled)
        ->and(Payment::query()->whereHas('instalment', fn ($q) => $q->where('contract_id', $old->id))->sum('amount'))->toEqual(5_000_000)
        ->and($this->matter->fresh()->contract->is($new))->toBeTrue()
        ->and($this->matter->contracts()->count())->toBe(2);
});

it('still refuses a second contract while one is a draft, active or completed — and says how to change it instead', function (ContractStatus $status) {
    Contract::factory()->for($this->matter)->create(['status' => $status, 'total_amount' => 10_000_000]);

    try {
        redraft($this->lead, $this->matter);
        $errors = [];
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }

    expect($errors)->toBe(['matter_id' => [__('billing.validation.contract_exists')]])
        ->and(Contract::query()->where('matter_id', $this->matter->id)->count())->toBe(1);
})->with([ContractStatus::Draft, ContractStatus::Active, ContractStatus::Completed]);

it('refuses a third contract once the redrafted one is still open', function () {
    cancelledContractWithPayment($this->matter);
    redraft($this->lead, $this->matter);

    expect(fn () => redraft($this->lead, $this->matter))->toThrow(ValidationException::class)
        ->and(Contract::query()->where('matter_id', $this->matter->id)->count())->toBe(2);
});

it('keeps the database backstop: two contracts not cancelled on one matter cannot both exist', function () {
    cancelledContractWithPayment($this->matter);
    Contract::factory()->for($this->matter)->active()->create(['total_amount' => 1]);

    expect(fn () => Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 1]))
        ->toThrow(QueryException::class);
});

it('lets several cancelled contracts sit on the same matter', function () {
    cancelledContractWithPayment($this->matter);
    cancelledContractWithPayment($this->matter);

    expect($this->matter->contracts()->where('status', ContractStatus::Cancelled->value)->count())->toBe(2);
});

it('counts only the open contract in what is still owed, and shows the new signed contract in the handover statement', function () {
    cancelledContractWithPayment($this->matter);

    $new = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 30_000_000]);
    Instalment::factory()->for($new)->create(['amount' => 30_000_000, 'status' => InstalmentStatus::Pending]);

    expect(BillingSummary::outstandingForMatter($this->matter->id)['amount'])->toBe(30_000_000)
        ->and(app(RenderHandoverIndex::class)->billingStatement($this->matter->fresh())['code'] ?? null)->toBe($new->code);
});

it('reads the latest contract as the matter\'s contract, whatever order the rows come back in', function () {
    $old = cancelledContractWithPayment($this->matter);
    $new = redraft($this->lead, $this->matter);

    expect(Matter::query()->with('contract')->find($this->matter->id)->contract->is($new))->toBeTrue()
        ->and($this->matter->contract()->first()->is($new))->toBeTrue()
        ->and(Client::query()->whereHas('matters', fn ($m) => $m->whereHas('contracts', fn ($c) => $c->whereKey($old->id)))->exists())->toBeTrue();
});
