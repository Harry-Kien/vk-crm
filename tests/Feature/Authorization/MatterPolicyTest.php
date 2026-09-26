<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;

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

    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->restricted->addTeamMember($this->teammate, MatterRole::Associate);
});

it('lets a lawyer named in the team see the matter and hides it from everyone else', function () {
    expect($this->lead->can('view', $this->matter))->toBeTrue()
        ->and($this->teammate->can('view', $this->matter))->toBeTrue()
        ->and($this->outsider->can('view', $this->matter))->toBeFalse();
});

it('keeps a matter out of the list of a lawyer who is not on the team', function () {
    expect(Matter::query()->listableBy($this->outsider)->count())->toBe(0)
        ->and(Matter::query()->listableBy($this->teammate)->pluck('id')->all())->toBe([$this->matter->id]);
});

it('shows a restricted matter only to the lead lawyer and the admin', function () {
    expect($this->lead->can('view', $this->restricted))->toBeTrue()
        ->and($this->admin->can('view', $this->restricted))->toBeTrue()
        ->and($this->manager->can('view', $this->restricted))->toBeFalse()
        ->and($this->teammate->can('view', $this->restricted))->toBeFalse()
        ->and(Matter::query()->listableBy($this->manager)->pluck('id')->all())->toBe([$this->matter->id])
        ->and(Matter::query()->listableBy($this->admin)->count())->toBe(2);
});

it('gives the accountant the list but never the content', function () {
    expect($this->accountant->can('viewAny', Matter::class))->toBeTrue()
        ->and(Matter::query()->listableBy($this->accountant)->pluck('id')->all())->toBe([$this->matter->id])
        ->and($this->accountant->can('view', $this->matter))->toBeFalse()
        ->and($this->accountant->can('update', $this->matter))->toBeFalse()
        ->and($this->accountant->can('create', Matter::class))->toBeFalse();
});

it('lets a manager see every ordinary matter without being on the team', function () {
    expect($this->manager->can('view', $this->matter))->toBeTrue()
        ->and($this->manager->can('update', $this->matter))->toBeTrue();
});

it('allows a lawyer to update and transition only their own matters', function () {
    expect($this->lead->can('update', $this->matter))->toBeTrue()
        ->and($this->lead->can('transitionStage', $this->matter))->toBeTrue()
        ->and($this->outsider->can('update', $this->matter))->toBeFalse()
        ->and($this->outsider->can('transitionStage', $this->matter))->toBeFalse()
        ->and($this->assistant->can('transitionStage', $this->matter))->toBeFalse();
});

it('restricts creation and deletion to the roles the spec names', function () {
    expect($this->lead->can('create', Matter::class))->toBeTrue()
        ->and($this->assistant->can('create', Matter::class))->toBeFalse()
        ->and($this->admin->can('delete', $this->matter))->toBeTrue()
        ->and($this->manager->can('delete', $this->matter))->toBeFalse()
        ->and($this->admin->can('forceDelete', $this->matter))->toBeFalse();
});

it('answers for a portal user from the portal rules, not from spatie', function () {
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);

    $own = Matter::factory()->for($client)->create();
    $ownHidden = Matter::factory()->for($client)->unpublished()->create();
    $foreign = Matter::factory()->create();

    expect($clientUser->can('viewAny', Matter::class))->toBeTrue()
        ->and($clientUser->can('view', $own))->toBeTrue()
        ->and($clientUser->can('view', $ownHidden))->toBeFalse()
        ->and($clientUser->can('view', $foreign))->toBeFalse()
        ->and($clientUser->can('create', Matter::class))->toBeFalse()
        ->and($clientUser->can('update', $own))->toBeFalse()
        ->and($clientUser->can('transitionStage', $own))->toBeFalse()
        ->and($clientUser->can('delete', $own))->toBeFalse()
        ->and($clientUser->can('forceDelete', $own))->toBeFalse();
});

/**
 * Task 2, vòng sửa 1 (Important #2): `releasedToPortal()` giờ hỏi thêm "khách hàng (Client) chưa
 * xoá mềm" — điều kiện thứ tư, khớp `Matter::applyClientPortalConstraints()`'s `whereHas('client')`
 * (Task 2, `portal/portal-3`, vòng đầu). Trước bản sửa vòng 1 này, xoá mềm CHÍNH khách hàng (không
 * phải vụ việc) không đụng gì tới `view()` của policy — vụ việc vẫn "xem được" ở tầng này dù
 * `applyClientPortalConstraints()` đã đóng cửa ở tầng truy vấn, và mọi bản ghi con (Document,
 * StageLog…) đi qua `ChecksMatterAccess::canSeeMatter()` cũng vì vậy vẫn mở.
 */
it('refuses view for a portal user once the client itself is soft deleted, even though the matter stays published', function () {
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);
    $own = Matter::factory()->for($client)->create();

    $client->delete();

    expect($clientUser->fresh()->can('view', $own->fresh()))->toBeFalse();
});

/**
 * Nghi thức ba tầng của M5 (thay `ClientPortalScope` bằng một scope rỗng rồi hỏi lại policy —
 * `tests/Feature/Authorization/PortalIsolationSweepTest.php` dựng nó cho toàn bộ cổng), áp riêng
 * cho điều kiện mới này: tầng truy vấn thủng thì tầng policy vẫn phải từ chối, một mình nó,
 * không dựa vào `ClientPortalScope`.
 *
 * Test này CỐ Ý KHÔNG nạp sẵn `client` (không `Matter::with('client')`), nên `releasedToPortal()`
 * đi qua nhánh DỰ PHÒNG bằng truy vấn (`$matter->client()->withoutGlobalScope(...)->exists()`),
 * không qua nhánh `relationLoaded('client')` — đường đó có test riêng ngay dưới.
 *
 * Mutation probe (Task 2, vòng sửa 2, khớp code hiện tại — bản trước docblock này ghi nhầm dòng
 * đã đổi hình dạng từ `&& $matter->client()->exists()` thành cụm điều kiện có ternary):
 * bỏ cả cụm `&& ($matter->relationLoaded('client') ? ... : ...)` khỏi `releasedToPortal()` thì
 * test này đỏ.
 */
it('still refuses on the policy layer for a soft deleted client when the portal scope forgets its rule', function () {
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);
    $own = Matter::factory()->for($client)->create();

    $client->delete();

    Matter::addGlobalScope(ClientPortalScope::class, function (): void {});

    try {
        // Tầng truy vấn đã thủng — nếu không thì khẳng định bên dưới không đo tầng policy.
        expect(Matter::find($own->id))->not->toBeNull();

        expect($clientUser->fresh()->can('view', $own))->toBeFalse();
    } finally {
        Matter::addGlobalScope(new ClientPortalScope);
    }
});

/**
 * `releasedToPortal()` có HAI đường cho điều kiện "khách hàng chưa xoá mềm": đường truy vấn
 * (test ngay trên) và đường trong bộ nhớ khi `client` đã được nạp sẵn (`relationLoaded('client')`
 * — thêm để giữ ngân sách truy vấn của `MyMattersTest`/`SubmitDocumentTest`, xem docblock của
 * `releasedToPortal()`). Test trên KHÔNG đo được đường thứ hai: nó cố tình không nạp `client`.
 * Test này đo THẲNG đường đó.
 *
 * **Không độc lập với tầng truy vấn nếu thiếu nghi thức ba tầng dưới đây — sửa ở Task 2, vòng sửa
 * 2 (N1).** Bản trước của test này KHÔNG hoán `ClientPortalScope`, và docblock cũ tuyên bố sai
 * rằng phép đo "độc lập với cả tầng truy vấn". Sai vì `view()` cho khách là
 * `releasedToPortal() && visibleToPortal()`, và `visibleToPortal()`
 * (`ChecksPortalVisibility.php:19-24`) LUÔN gọi lại `ClientPortalScope::actingAs($clientUser,
 * ...)` — hàm này đặt `self::$actingAs` thẳng, không đọc guard ambient, nên nó chạy VÔ ĐIỀU KIỆN
 * bất kể ambient auth — rồi chạy lại đúng `Matter::applyClientPortalConstraints()` (kèm
 * `whereHas('client')`, `Matter.php:215`) qua `ClientPortalScope` THẬT đang đăng ký. Không hoán
 * scope đó ra, `visibleToPortal()` một mình đã đủ từ chối một khách hàng đã xoá mềm — nhánh
 * `releasedToPortal()` có đúng hay sai không còn quan trọng, và test xanh dù nhánh
 * `relationLoaded('client')` bị lật sai (đúng thứ mutation probe 3 của báo cáo vòng sửa 1 đã lộ
 * ra: "vẫn xanh" — nhưng lúc đó bị hiểu nhầm là "đúng dự đoán" thay vì "phép đo có lỗ hổng").
 *
 * Giờ hoán `ClientPortalScope` ra một scope rỗng — CÙNG nghi thức ba tầng của test ngay trên —
 * nên tầng truy vấn của CẢ `releasedToPortal()` (không quan trọng, test này không đi qua nhánh
 * đó) LẪN `visibleToPortal()` đều thủng, và câu trả lời `false` chỉ có thể đến từ nhánh
 * `relationLoaded('client')` đang được đo.
 *
 * Mutation probe (Task 2, vòng sửa 2): đổi `$matter->client !== null` thành `true` trong nhánh
 * `relationLoaded('client')` của `releasedToPortal()` (cho qua vô điều kiện) thì test này đỏ. Vế
 * dương ngay dưới vẫn xanh với đúng đột biến này (nó đòi `true`, và mutation trả về đúng `true`)
 * — đó là lý do mutation trước (`=== null`, lật cả hai chiều) không tách được lỗi thật khỏi việc
 * chỉ đơn giản đã hoán sai chỗ.
 */
it('refuses via the relation-loaded fast path too, when client is eager loaded and turns out to be null', function () {
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);
    $own = Matter::factory()->for($client)->create();

    $client->delete();

    $matterWithClientLoaded = Matter::query()->with('client')->findOrFail($own->id);

    expect($matterWithClientLoaded->relationLoaded('client'))->toBeTrue()
        ->and($matterWithClientLoaded->client)->toBeNull();

    Matter::addGlobalScope(ClientPortalScope::class, function (): void {});

    try {
        // Tầng truy vấn của visibleToPortal() đã thủng — nếu không thì khẳng định bên dưới
        // không đo tầng policy, đúng lỗ hổng mà N1 (vòng sửa 2) chỉ ra.
        expect($clientUser->fresh()->can('view', $matterWithClientLoaded))->toBeFalse();
    } finally {
        Matter::addGlobalScope(new ClientPortalScope);
    }
});

/** Vế dương của test trên: `client` đã nạp sẵn và KHÔNG bị xoá thì đường trong bộ nhớ vẫn cho qua. */
it('allows via the relation-loaded fast path when client is eager loaded and not deleted', function () {
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);
    $own = Matter::factory()->for($client)->create();

    $matterWithClientLoaded = Matter::query()->with('client')->findOrFail($own->id);

    expect($matterWithClientLoaded->relationLoaded('client'))->toBeTrue()
        ->and($matterWithClientLoaded->client)->not->toBeNull()
        ->and($clientUser->fresh()->can('view', $matterWithClientLoaded))->toBeTrue();
});

it('answers the same for a portal user whether or not the client guard is open', function () {
    // ChecksPortalVisibility must not depend on ambient auth state.
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);
    $own = Matter::factory()->for($client)->create();
    $foreign = Matter::factory()->create();

    expect($clientUser->can('view', $own))->toBeTrue()
        ->and($clientUser->can('view', $foreign))->toBeFalse();

    $this->actingAs($clientUser, 'client');

    expect($clientUser->can('view', $own))->toBeTrue()
        ->and($clientUser->can('view', $foreign))->toBeFalse();
});

it('still lets an admin act on a soft deleted matter', function () {
    $this->matter->delete();

    expect($this->admin->can('view', $this->matter))->toBeTrue()
        ->and($this->admin->can('restore', $this->matter))->toBeTrue()
        ->and($this->admin->can('forceDelete', $this->matter))->toBeFalse();
});

it('makes a soft deleted matter read only until it is restored', function () {
    $this->matter->delete();

    expect($this->admin->can('view', $this->matter))->toBeTrue()
        ->and($this->admin->can('restore', $this->matter))->toBeTrue()
        ->and($this->admin->can('update', $this->matter))->toBeFalse()
        ->and($this->admin->can('transitionStage', $this->matter))->toBeFalse()
        ->and($this->manager->can('update', $this->matter))->toBeFalse();
});

it('keeps a restricted matter out of the list of a lead lawyer who lost the view permission', function () {
    $demoted = User::factory()->withRole(Role::Accountant)->create();
    $theirs = Matter::factory()->restricted()->create(['lead_lawyer_id' => $demoted->id]);

    expect(Matter::query()->listableBy($demoted)->pluck('id')->all())->not->toContain($theirs->id)
        ->and($demoted->can('view', $theirs))->toBeFalse();
});

/**
 * Câu trả lời cho nhân sự KHÔNG được đổi theo việc có ai đang mở phiên portal hay không.
 *
 * `ClientPortalScope::isActive()` bật khi guard `client` đã xác thực còn guard `web` thì chưa —
 * tức đúng một lời gọi `Gate::forUser($staff)` từ một job, một lệnh console, hay một Action chạy
 * bên trong một request portal. Không gỡ scope ra thì `Matter::query()` của `MatterPolicy::view`
 * bị cắt theo KHÁCH đang đăng nhập và trả `false` về một vụ việc nhân sự đó thấy rõ. Mang sang
 * từ rà soát M4 Task 4, nơi một mutation probe SỐNG SÓT vì chính chuyện này.
 *
 * `relationLoaded('team')` được khẳng định là `false` trước: đường trong bộ nhớ của `view()`
 * không chạy truy vấn nào, nên một fixture vô tình nạp sẵn `team` sẽ làm test này xanh mà không
 * hề chạm tới điều kiện nó nêu tên.
 */
it('answers the staff view ability the same way while a portal session of another client is open', function () {
    $matter = Matter::query()->findOrFail($this->matter->getKey());
    $restricted = Matter::query()->findOrFail($this->restricted->getKey());

    $this->actingAs(ClientUser::factory()->create(['client_id' => Client::factory()->create()->id]), 'client');

    expect(ClientPortalScope::isActive())->toBeTrue()
        ->and($matter->relationLoaded('team'))->toBeFalse()
        ->and($restricted->relationLoaded('team'))->toBeFalse()
        ->and($this->lead->can('view', $matter))->toBeTrue()
        ->and($this->teammate->can('view', $matter))->toBeTrue()
        ->and($this->admin->can('view', $restricted))->toBeTrue()
        // Vế âm, trong cùng ngữ cảnh: gỡ scope KHÔNG mở thêm cửa nào.
        ->and($this->outsider->can('view', $matter))->toBeFalse()
        ->and($this->accountant->can('view', $matter))->toBeFalse()
        ->and($this->teammate->can('view', $restricted))->toBeFalse();
});

// =========================================================================================
// updateConfidentiality (M6.5 Task 5, R5): matter.update VÀ không phải trợ lý — cùng luật
// với manageTeam.
// =========================================================================================

it('lets the lead, a manager, and an admin change confidentiality, but not an assistant', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    expect($this->lead->can('updateConfidentiality', $this->matter))->toBeTrue()
        ->and($this->manager->can('updateConfidentiality', $this->matter))->toBeTrue()
        ->and($this->admin->can('updateConfidentiality', $this->matter))->toBeTrue()
        ->and($assistant->can('updateConfidentiality', $this->matter))->toBeFalse();
});

it('refuses updateConfidentiality on a soft deleted matter, even for the lead', function () {
    $this->matter->delete();

    expect($this->lead->can('updateConfidentiality', $this->matter))->toBeFalse();
});

// =========================================================================================
// cancelMatter (M6.5 Task 5): admin-only, "huỷ hồ sơ mở nhầm".
// =========================================================================================

it('lets only the admin cancel a wrongly opened matter', function () {
    expect($this->admin->can('cancelMatter', $this->matter))->toBeTrue()
        ->and($this->manager->can('cancelMatter', $this->matter))->toBeFalse()
        ->and($this->lead->can('cancelMatter', $this->matter))->toBeFalse();
});
