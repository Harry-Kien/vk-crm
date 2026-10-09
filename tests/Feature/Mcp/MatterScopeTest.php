<?php

use App\Actions\Mcp\McpMatterScope;
use App\Enums\Confidentiality;
use App\Enums\MatterAiAccess;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| M11 R3 (Task 9) — tập vụ việc mà MCP thấy là GIAO của bốn điều kiện
|--------------------------------------------------------------------------
|
|  1. `listableBy($user)` VÀ `Gate::allows('view', $matter)`;
|  2. `confidentiality != restricted`, kể cả với luật sư phụ trách và admin;
|  3. `matters.ai_access = allowed` (R9);
|  4. chưa xoá mềm.
|
| Mỗi điều kiện một test âm có cặp dương: cặp dương chứng minh đúng bản ghi đó LỌT QUA khi chỉ
| điều kiện đang xét được gỡ (nên vắng mặt ở test âm là do điều kiện ấy, không do điều kiện khác).
| Định nghĩa DUY NHẤT ở `App\Actions\Mcp\McpMatterScope`; mọi tool của Task 10–13 đi qua nó.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->admin = User::factory()->admin()->create();

    // Vụ "chuẩn": thường, đã bật AI, của đội $lead (có $assistant).
    $this->matter = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);

    // Vụ của một đội khác, cũng thường và đã bật AI.
    $this->otherTeam = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $this->outsider->id]);
});

function mcpScope(): McpMatterScope
{
    return app(McpMatterScope::class);
}

/** @return list<int> */
function mcpScopedIds(User $user): array
{
    return mcpScope()->query($user)->pluck('matters.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
}

it('R3 (1) listableBy: a lawyer outside the team never gets another team\'s matter, the lead and a team member do', function () {
    expect(mcpScopedIds($this->lead))->toBe([$this->matter->id])
        ->and(mcpScopedIds($this->assistant))->toBe([$this->matter->id])
        ->and(mcpScopedIds($this->outsider))->toBe([$this->otherTeam->id])
        ->and(mcpScope()->find($this->outsider, $this->matter->id))->toBeNull()
        ->and(mcpScope()->find($this->lead, $this->matter->id)?->is($this->matter))->toBeTrue();
});

it('R3 (1) listableBy: matter.viewAny (manager, admin) sees every normal allowed matter of every team', function () {
    $both = collect([$this->matter->id, $this->otherTeam->id])->sort()->values()->all();

    expect(mcpScopedIds($this->manager))->toBe($both)
        ->and(mcpScopedIds($this->admin))->toBe($both);
});

it('R3 (1) Gate view: an accountant (matter.viewAny without matter.view) gets nothing, although listableBy alone lists every normal matter for them', function () {
    // Cặp dương: listableBy một mình MỞ cả hai vụ cho kế toán — chỉ vế Gate `view` (matter.view)
    // đóng lại.
    expect(Matter::query()->listableBy($this->accountant)->pluck('id')->all())
        ->toContain($this->matter->id, $this->otherTeam->id)
        ->and(Gate::forUser($this->accountant)->allows('view', $this->matter))->toBeFalse();

    expect(mcpScopedIds($this->accountant))->toBe([])
        ->and(mcpScope()->find($this->accountant, $this->matter->id))->toBeNull();
});

it('R3 (2) a restricted matter is absent for its own lead AND for an admin, although both see it on the web', function () {
    $restricted = Matter::factory()->aiAccessAllowed()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);

    // Web: cả hai thấy (Gate view). MCP: không ai.
    expect(Gate::forUser($this->lead)->allows('view', $restricted))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view', $restricted))->toBeTrue()
        ->and(mcpScopedIds($this->lead))->not->toContain($restricted->id)
        ->and(mcpScopedIds($this->admin))->not->toContain($restricted->id)
        ->and(mcpScope()->find($this->lead, $restricted->id))->toBeNull()
        ->and(mcpScope()->find($this->admin, $restricted->id))->toBeNull();

    // Cặp dương: đúng vụ đó, chuyển về thường, có mặt cho cả hai.
    $restricted->forceFill(['confidentiality' => Confidentiality::Normal])->save();

    expect(mcpScopedIds($this->lead))->toContain($restricted->id)
        ->and(mcpScopedIds($this->admin))->toContain($restricted->id);
});

it('R3 (3) a matter whose ai_access is denied is absent; allowing it brings it back', function () {
    $denied = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);

    expect($denied->refresh()->ai_access)->toBe(MatterAiAccess::Denied)
        ->and(Gate::forUser($this->lead)->allows('view', $denied))->toBeTrue()
        ->and(mcpScopedIds($this->lead))->not->toContain($denied->id)
        ->and(mcpScopedIds($this->admin))->not->toContain($denied->id)
        ->and(mcpScope()->find($this->lead, $denied->id))->toBeNull();

    $denied->forceFill(['ai_access' => MatterAiAccess::Allowed])->save();

    expect(mcpScopedIds($this->lead))->toContain($denied->id)
        ->and(mcpScope()->find($this->lead, $denied->id)?->is($denied))->toBeTrue();
});

it('R3 (4) a soft-deleted matter is absent, even when a caller adds withTrashed() on top; restoring it brings it back', function () {
    $this->matter->delete();

    // MatterPolicy::view cố ý cho vụ đã xoá mềm (admin còn khôi phục được) — MCP thì không.
    expect(Gate::forUser($this->lead)->allows('view', $this->matter))->toBeTrue()
        ->and(mcpScopedIds($this->lead))->toBe([])
        ->and(mcpScope()->query($this->lead)->withTrashed()->pluck('matters.id')->all())->toBe([])
        ->and(mcpScope()->query($this->admin)->withTrashed()->pluck('matters.id')->all())->not->toContain($this->matter->id)
        ->and(mcpScope()->find($this->lead, $this->matter->id))->toBeNull();

    $this->matter->restore();

    expect(mcpScopedIds($this->lead))->toBe([$this->matter->id]);
});

it('R3 is exactly the intersection: for every staff member and every matter, in scope iff Gate view AND normal AND allowed AND not trashed', function () {
    $restrictedOwn = Matter::factory()->aiAccessAllowed()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $deniedOwn = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $restrictedDenied = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->outsider->id]);
    $trashed = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $this->lead->id]);
    $trashed->delete();

    $matters = Matter::query()->withTrashed()->get();
    $users = [$this->lead, $this->outsider, $this->assistant, $this->manager, $this->accountant, $this->admin];

    expect($matters)->toHaveCount(6);

    foreach ($users as $user) {
        $inScope = mcpScopedIds($user);

        foreach ($matters as $matter) {
            $expected = Gate::forUser($user)->allows('view', $matter)
                && $matter->confidentiality === Confidentiality::Normal
                && $matter->ai_access === MatterAiAccess::Allowed
                && ! $matter->trashed();

            expect(in_array($matter->id, $inScope, true))->toBe($expected, "user {$user->id} / matter {$matter->id}")
                ->and(mcpScope()->find($user, $matter->id) !== null)->toBe($expected, "find: user {$user->id} / matter {$matter->id}");
        }
    }

    // Ít nhất một cặp mỗi chiều, để vòng lặp trên không xanh vì một tập rỗng.
    expect(mcpScopedIds($this->lead))->toBe([$this->matter->id])
        ->and([$restrictedOwn->id, $deniedOwn->id, $restrictedDenied->id, $trashed->id])->each->not->toBeIn(mcpScopedIds($this->admin));
});

it('find() answers an unknown id exactly like a forbidden one: null', function () {
    expect(mcpScope()->find($this->lead, 999999))->toBeNull()
        ->and(mcpScope()->find($this->lead, $this->otherTeam->id))->toBeNull();
});

it('ignores an ambient client portal session: the staff member gets their own scope, not the portal client\'s', function () {
    // Một phiên cổng khách đang mở trong cùng tiến trình, và guard web rỗng — đúng hình dạng của
    // một request /mcp (guard mcp, không phải web). ClientPortalScope::isActive() bật ở đây.
    $clientUser = ClientUser::factory()->activated()->create();
    $this->actingAs($clientUser, 'client');

    expect(Matter::query()->pluck('id')->all())->not->toContain($this->matter->id);

    expect(mcpScopedIds($this->lead))->toBe([$this->matter->id])
        ->and(mcpScope()->find($this->lead, $this->matter->id)?->is($this->matter))->toBeTrue();
});

it('constrain() limits a child query to matters in scope, and ignores an ambient client portal session there too', function () {
    $restricted = Matter::factory()->aiAccessAllowed()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $denied = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $trashed = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $this->lead->id]);

    $visible = Deadline::factory()->create(['matter_id' => $this->matter->id, 'responsible_user_id' => $this->lead->id]);
    $hidden = collect([$restricted, $denied, $trashed, $this->otherTeam])
        ->map(fn (Matter $matter) => Deadline::factory()->create(['matter_id' => $matter->id, 'responsible_user_id' => $this->lead->id])->id);
    $trashed->delete();

    $ids = fn (): array => mcpScope()->constrain(Deadline::query(), $this->lead)->pluck('id')->all();

    expect($ids())->toBe([$visible->id]);

    // Cặp dương của phần "phiên cổng": không có constrain(), cùng truy vấn bị cổng khách cắt
    // (mốc chưa công bố, vụ của khách khác) — mốc của luật sư biến mất.
    $this->actingAs(ClientUser::factory()->activated()->create(), 'client');

    expect(Deadline::query()->pluck('id')->all())->not->toContain($visible->id)
        ->and($ids())->toBe([$visible->id])
        ->and($hidden->all())->each->not->toBeIn($ids());
});
