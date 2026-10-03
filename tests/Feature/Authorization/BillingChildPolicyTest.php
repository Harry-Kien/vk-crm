<?php

use App\Enums\ContractStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Tầng POLICY của bốn model tiền M9, đo TRỰC TIẾP qua `Gate` (`$user->can(...)`) — không qua một
 * truy vấn, nên `ClientPortalScope` không có mặt ở đây để làm test xanh giùm. Cùng nghi thức với
 * `ChildPolicyTest` ("never lets a client user see data of another client") và tầng 2 của
 * `PortalIsolationSweepTest` ("still refuses on the policy layer when every portal scope forgets
 * its rule"): ba tầng phòng thủ của SPEC §11 được đo ĐỘC LẬP, và đây là tầng còn thiếu ở Task 2
 * (fix round 1, I2).
 *
 * `$this->clientUser` SỞ HỮU `$this->matter` (cùng `client_id`, vụ việc đã công bố portal) một
 * cách cố ý: nếu policy chỉ vô tình đúng nhờ một điều kiện sở hữu nào đó bị thiếu, một khách hàng
 * KHÔNG sở hữu vụ việc vẫn có thể lọt qua đường khác — dùng đúng khách sở hữu thật loại khả năng
 * đó.
 *
 * **Đổi nghĩa ở M9 Task 10 (P1).** Trước Task 10 phép từ chối khách đến từ đúng một chỗ
 * (`$user instanceof User`), và các tiêu đề nói "never". Từ Task 10 khách ĐỌC được tiền của chính
 * mình khi hợp đồng đã ký: hợp đồng dựng ở `beforeEach` là bản NHÁP (mặc định của
 * `ContractFactory`), nên bốn test đầu giờ đo đúng điều kiện "chưa ký thì chưa tới khách" ở tầng
 * policy, và test cuối là cặp dương của chúng trên CÙNG chuỗi bản ghi, sau khi ký. `viewAny` vẫn
 * đóng với khách ở cả hai phía: không màn hình nào của cổng liệt kê tiền ngoài một vụ việc.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create([
        'lead_lawyer_id' => $this->lead->id,
        'is_published_to_portal' => true,
    ]);

    $this->contract = Contract::factory()->for($this->matter)->create();
    $this->instalment = Instalment::factory()->for($this->contract)->create();
    $this->payment = Payment::factory()->for($this->instalment)->create();
    $this->amendment = ContractAmendment::factory()->for($this->contract)->create();
});

it('lets the matter lead read a draft contract, but not a client user, not even the matter\'s own client', function () {
    expect($this->lead->can('viewAny', Contract::class))->toBeTrue()
        ->and($this->lead->can('view', $this->contract))->toBeTrue()
        ->and($this->clientUser->can('viewAny', Contract::class))->toBeFalse()
        ->and($this->clientUser->can('view', $this->contract))->toBeFalse();
});

it('lets the matter lead read an instalment of a draft contract, but not a client user, not even the matter\'s own client', function () {
    expect($this->lead->can('viewAny', Instalment::class))->toBeTrue()
        ->and($this->lead->can('view', $this->instalment))->toBeTrue()
        ->and($this->clientUser->can('viewAny', Instalment::class))->toBeFalse()
        ->and($this->clientUser->can('view', $this->instalment))->toBeFalse();
});

it('lets the matter lead read a payment on a draft contract, but not a client user, not even the matter\'s own client', function () {
    expect($this->lead->can('viewAny', Payment::class))->toBeTrue()
        ->and($this->lead->can('view', $this->payment))->toBeTrue()
        ->and($this->clientUser->can('viewAny', Payment::class))->toBeFalse()
        ->and($this->clientUser->can('view', $this->payment))->toBeFalse();
});

it('lets the matter lead read an amendment of a draft contract, but not a client user, not even the matter\'s own client', function () {
    expect($this->lead->can('viewAny', ContractAmendment::class))->toBeTrue()
        ->and($this->lead->can('view', $this->amendment))->toBeTrue()
        ->and($this->clientUser->can('viewAny', ContractAmendment::class))->toBeFalse()
        ->and($this->clientUser->can('view', $this->amendment))->toBeFalse();
});

it('lets the matter\'s own client read the same four rows once the contract is signed, still without listing them', function () {
    // Bất biến tổng của hợp đồng `active`: tổng các đợt chưa huỷ phải bằng giá trị hợp đồng.
    $this->contract->update(['total_amount' => $this->instalment->amount]);
    $this->contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->subDay()->toDateString()]);

    $stranger = ClientUser::factory()->create();

    foreach ([$this->contract, $this->instalment, $this->payment, $this->amendment] as $record) {
        $record = $record->fresh();

        expect($this->clientUser->can('view', $record))->toBeTrue()
            ->and($stranger->can('view', $record))->toBeFalse()
            ->and($this->clientUser->can('viewAny', $record::class))->toBeFalse();
    }
});
