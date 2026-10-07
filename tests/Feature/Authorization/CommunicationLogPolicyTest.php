<?php

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\CommunicationLog;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * `CommunicationLogPolicy` sau M7 Task 8: nhật ký liên lạc là BẰNG CHỨNG (SPEC §4.17), nên cửa
 * ghi hỏi đúng VỤ VIỆC sẽ nhận dòng ghi (`create($matter)` — tên giữ cho M11), và cửa sửa/xoá
 * không rộng hơn cửa ghi. `view()` đúng từ d069424 và không bị đụng tới.
 *
 * Mỗi điều kiện của `create` có một ca riêng ở đây, để một mutation probe bỏ điều kiện đó ra
 * thì đúng một ca đỏ.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lead->id]);
});

it('lets the lead and an assistant on the team log onto the matter', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    expect($this->lead->can('create', [CommunicationLog::class, $this->matter]))->toBeTrue()
        ->and($assistant->can('create', [CommunicationLog::class, $this->matter]))->toBeTrue();
});

/** Không có vụ việc thì không có cửa "tạo ở đâu cũng được" nữa. */
it('refuses staff who name no matter at all', function () {
    expect($this->lead->can('create', CommunicationLog::class))->toBeFalse();
});

it('always refuses a client account, even on its own matter', function () {
    $account = ClientUser::factory()->create(['client_id' => $this->client->id]);

    expect($account->can('create', [CommunicationLog::class, $this->matter]))->toBeFalse()
        ->and($account->can('create', CommunicationLog::class))->toBeFalse();
});

/**
 * Xem được vụ nhưng không có `matter.update`. Kế toán thiếu cả `matter.view` nên không đủ để đo
 * riêng điều kiện này; người thứ hai là một thành viên đội ngũ chỉ có `matter.view` (gán thẳng —
 * không vai nào của SPEC §5 có đúng tổ hợp đó), và `view` trên vụ của họ là `true`.
 */
it('refuses someone without matter.update', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $readOnly = User::factory()->create();
    $readOnly->givePermissionTo('matter.view');
    $this->matter->addTeamMember($readOnly, MatterRole::Observer);

    expect($accountant->can('create', [CommunicationLog::class, $this->matter]))->toBeFalse()
        ->and($readOnly->can('view', $this->matter))->toBeTrue()
        ->and($readOnly->can('create', [CommunicationLog::class, $this->matter]))->toBeFalse();
});

/** Có `matter.update` nhưng không xem được vụ. */
it('refuses a lawyer outside the team, and a manager on a restricted matter they do not lead', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    expect($outsider->can('create', [CommunicationLog::class, $this->matter]))->toBeFalse()
        ->and($manager->can('create', [CommunicationLog::class, $this->matter]))->toBeTrue();

    $this->matter->update(['confidentiality' => Confidentiality::Restricted]);

    expect($manager->can('create', [CommunicationLog::class, $this->matter->fresh()]))->toBeFalse();
});

it('refuses a soft-deleted matter, even to an admin who can still open it', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->matter->delete();
    $trashed = Matter::withTrashed()->find($this->matter->id);

    expect($admin->can('view', $trashed))->toBeTrue()
        ->and($admin->can('create', [CommunicationLog::class, $trashed]))->toBeFalse();
});

/**
 * `update`/`delete` không rộng hơn `create`: bản trước mở cho BẤT KỲ AI xem được vụ — kể cả
 * người không có `matter.update`. Một dòng đã xoá mềm thì không xoá lại, không sửa.
 */
it('opens update and delete exactly as wide as create, and never on a deleted entry', function () {
    $log = CommunicationLog::factory()->for($this->matter)->create();
    // Một người trong đội chỉ có `matter.view` (không vai nào của SPEC §5 có đúng tổ hợp này,
    // nên quyền gán thẳng) — đúng hình dạng "xem được vụ, không ghi được vào vụ".
    $readOnly = User::factory()->create();
    $readOnly->givePermissionTo('matter.view');
    $this->matter->addTeamMember($readOnly, MatterRole::Observer);

    expect($readOnly->can('view', $log))->toBeTrue()
        ->and($readOnly->can('update', $log))->toBeFalse()
        ->and($readOnly->can('delete', $log))->toBeFalse()
        ->and($this->lead->can('update', $log))->toBeTrue()
        ->and($this->lead->can('delete', $log))->toBeTrue();

    $log->delete();
    $trashed = CommunicationLog::withTrashed()->find($log->id);

    expect($this->lead->can('update', $trashed))->toBeFalse()
        ->and($this->lead->can('delete', $trashed))->toBeFalse()
        ->and($this->lead->can('forceDelete', $trashed))->toBeFalse();
});

it('refuses update and delete to a client account', function () {
    $account = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $log = CommunicationLog::factory()->for($this->matter)->create(['is_visible_to_client' => true]);

    expect($account->can('update', $log))->toBeFalse()
        ->and($account->can('delete', $log))->toBeFalse();
});
