<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\TimeEntryPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * M9 Task 12 — {@see TimeEntryPolicy}, khung cho tính phí theo giờ giai đoạn 2.
 * Không màn hình nào gọi policy này ở M9; test này chỉ ghim rằng cổng đã đóng đúng chỗ, theo
 * đúng thành ngữ của mọi bản ghi con của Matter ({@see ChecksMatterAccess}).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);

    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);

    $this->entry = TimeEntry::factory()->for($this->matter)->for($this->lead)->create();
});

it('lets whoever can see the matter see its time entries, and refuses whoever cannot', function () {
    expect($this->lead->can('view', $this->entry))->toBeTrue()
        ->and($this->assistant->can('view', $this->entry))->toBeTrue()
        ->and($this->outsider->can('view', $this->entry))->toBeFalse();
});

/**
 * `create` không có ngữ cảnh vụ việc (Laravel gọi `Gate::authorize('create', TimeEntry::class)`
 * không kèm bản ghi) — cùng thành ngữ `MatterPartyPolicy::create()`: một chốt chặn theo QUYỀN
 * (`matter.update`), không theo TỪNG vụ việc. `update`/`delete` MỚI là nơi phạm vi của vụ việc
 * cha (`ChecksMatterAccess::canUpdateMatter()`) từ chối người ngoài đội ngũ.
 */
it('gates create by the matter.update permission, and update/delete by belonging to the matters own team', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect($this->lead->can('create', TimeEntry::class))->toBeTrue()
        ->and($accountant->can('create', TimeEntry::class))->toBeFalse()
        ->and($this->lead->can('update', $this->entry))->toBeTrue()
        ->and($this->lead->can('delete', $this->entry))->toBeTrue()
        ->and($this->outsider->can('update', $this->entry))->toBeFalse()
        ->and($this->outsider->can('delete', $this->entry))->toBeFalse();
});

/**
 * Chốt chặn chính của Task 12 P1: cổng khách đóng kín MÃI, không có task nào sau này mở nó — khác
 * bốn model tiền. Mọi ability, không ngoại lệ.
 */
it('refuses a client user every ability, on every entry, without exception', function () {
    expect($this->clientUser->can('viewAny', TimeEntry::class))->toBeFalse()
        ->and($this->clientUser->can('view', $this->entry))->toBeFalse()
        ->and($this->clientUser->can('create', TimeEntry::class))->toBeFalse()
        ->and($this->clientUser->can('update', $this->entry))->toBeFalse()
        ->and($this->clientUser->can('delete', $this->entry))->toBeFalse();
});

/** Cổng khách đóng ở TẦNG TRUY VẤN, không chỉ ở policy — `applyClientPortalConstraints()` chặn `1 = 0`. */
it('never returns a time entry through the client portal query scope, even for the matters own client', function () {
    $this->actingAs($this->clientUser, 'client');

    expect(TimeEntry::query()->count())->toBe(0);
});
