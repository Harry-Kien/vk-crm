<?php

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
 * đó, và buộc phép từ chối phải đến từ đúng MỘT chỗ: `$user instanceof User`.
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

it('lets the matter lead read a contract, but never a client user, even the matter\'s own client', function () {
    expect($this->lead->can('viewAny', Contract::class))->toBeTrue()
        ->and($this->lead->can('view', $this->contract))->toBeTrue()
        ->and($this->clientUser->can('viewAny', Contract::class))->toBeFalse()
        ->and($this->clientUser->can('view', $this->contract))->toBeFalse();
});

it('lets the matter lead read an instalment, but never a client user, even the matter\'s own client', function () {
    expect($this->lead->can('viewAny', Instalment::class))->toBeTrue()
        ->and($this->lead->can('view', $this->instalment))->toBeTrue()
        ->and($this->clientUser->can('viewAny', Instalment::class))->toBeFalse()
        ->and($this->clientUser->can('view', $this->instalment))->toBeFalse();
});

it('lets the matter lead read a payment, but never a client user, even the matter\'s own client', function () {
    expect($this->lead->can('viewAny', Payment::class))->toBeTrue()
        ->and($this->lead->can('view', $this->payment))->toBeTrue()
        ->and($this->clientUser->can('viewAny', Payment::class))->toBeFalse()
        ->and($this->clientUser->can('view', $this->payment))->toBeFalse();
});

it('lets the matter lead read a contract amendment, but never a client user, even the matter\'s own client', function () {
    expect($this->lead->can('viewAny', ContractAmendment::class))->toBeTrue()
        ->and($this->lead->can('view', $this->amendment))->toBeTrue()
        ->and($this->clientUser->can('viewAny', ContractAmendment::class))->toBeFalse()
        ->and($this->clientUser->can('view', $this->amendment))->toBeFalse();
});
