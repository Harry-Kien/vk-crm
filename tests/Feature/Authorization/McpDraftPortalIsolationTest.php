<?php

use App\Enums\Role;
use App\Filament\Portal\Pages\MatterProgress;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReplyDraft;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\McpConfirmation;
use App\Models\StageLogDraft;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| M11 R5 (Task 7) — "Không truy vấn nào dưới guard `client` đọc được một dòng nháp"
|--------------------------------------------------------------------------
|
| Hai bảng nháp và `mcp_confirmations` dùng `RestrictedToClientPortal` với điều kiện `1 = 0` (luật
| M2, `PortalCoverageTest`): một nháp là thứ văn phòng CHƯA quyết định nói ra, và một mã xác nhận là
| dấu vết nội bộ của AI. Không màn hình portal nào đọc chúng; chúng vẫn phải trả lời đúng khi bị hỏi.
|
| Dữ liệu dựng ở đây là trường hợp KHÓ NHẤT cho tầng truy vấn: nháp thuộc CHÍNH vụ việc đã công bố
| của chính khách đang đăng nhập — mọi điều kiện theo khách hàng hay theo vụ việc đều cho qua, chỉ
| còn điều kiện "không bao giờ" chặn. Mỗi lời từ chối có vế dương bên cạnh (cùng dữ liệu, phía nhân
| sự đọc được), để một test xanh vì "chặn tất cả" cũng không xanh được.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);

    $this->matter = Matter::factory()->for($this->client)->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'is_published_to_portal' => true,
    ]);
    $this->request = ClientRequest::factory()->for($this->matter)->create(['client_user_id' => $this->clientUser->id]);

    $this->stageLogDraft = StageLogDraft::factory()->for($this->matter)->create([
        'created_by' => $this->lawyer->id,
        'public_content' => 'Nháp chưa gửi khách',
        'internal_note' => 'Ghi chú nội bộ trong nháp',
    ]);
    $this->replyDraft = ClientRequestReplyDraft::factory()->for($this->request, 'request')->create([
        'created_by' => $this->lawyer->id,
        'content' => 'Nháp trả lời chưa gửi khách',
    ]);
    $deadline = Deadline::factory()->for($this->matter)->create(['is_published' => true]);
    $this->confirmation = McpConfirmation::factory()->create([
        'user_id' => $this->lawyer->id,
        'result_type' => $deadline->getMorphClass(),
        'result_id' => $deadline->id,
    ]);
});

it('reads no draft and no confirmation row under the client guard, even of the clients own published matter', function () {
    // Vế dương ngay trong ngữ cảnh khách: dữ liệu cha của các nháp này là thứ khách ĐỌC ĐƯỢC.
    $this->actingAs($this->clientUser, 'client');

    expect(ClientPortalScope::isActive())->toBeTrue()
        ->and(Matter::query()->whereKey($this->matter->id)->exists())->toBeTrue()
        ->and(ClientRequest::query()->whereKey($this->request->id)->exists())->toBeTrue();

    expect(StageLogDraft::query()->count())->toBe(0)
        ->and(StageLogDraft::query()->find($this->stageLogDraft->id))->toBeNull()
        ->and(ClientRequestReplyDraft::query()->count())->toBe(0)
        ->and(ClientRequestReplyDraft::query()->find($this->replyDraft->id))->toBeNull()
        ->and(McpConfirmation::query()->count())->toBe(0)
        ->and(McpConfirmation::query()->find($this->confirmation->id))->toBeNull();
});

it('reaches no draft through a relation going down from a parent the client can read', function () {
    $this->actingAs($this->clientUser, 'client');

    $matter = Matter::query()->findOrFail($this->matter->id);
    $request = ClientRequest::query()->findOrFail($this->request->id);

    expect($matter->stageLogDrafts()->count())->toBe(0)
        ->and($matter->stageLogDrafts)->toBeEmpty()
        ->and($request->replyDrafts()->count())->toBe(0)
        ->and(Matter::query()->whereKey($matter->id)->withCount('stageLogDrafts')->first()->stage_log_drafts_count)->toBe(0)
        ->and(Matter::query()->whereHas('stageLogDrafts')->exists())->toBeFalse();
});

it('keeps the same answer when the portal is entered through ClientPortalScope::actingAs', function () {
    $counts = ClientPortalScope::actingAs($this->clientUser, fn (): array => [
        StageLogDraft::query()->count(),
        ClientRequestReplyDraft::query()->count(),
        McpConfirmation::query()->count(),
    ]);

    expect($counts)->toBe([0, 0, 0]);
});

it('reads every draft and confirmation row on the staff side', function () {
    $this->actingAs($this->lawyer, 'web');

    expect(StageLogDraft::query()->pluck('id')->all())->toBe([$this->stageLogDraft->id])
        ->and(ClientRequestReplyDraft::query()->pluck('id')->all())->toBe([$this->replyDraft->id])
        ->and(McpConfirmation::query()->pluck('id')->all())->toBe([$this->confirmation->id])
        ->and($this->matter->stageLogDrafts()->count())->toBe(1)
        ->and($this->request->replyDrafts()->count())->toBe(1);
});

it('still refuses a client on the policy layer when the portal scope of every draft table forgets its rule', function () {
    foreach ([StageLogDraft::class, ClientRequestReplyDraft::class, McpConfirmation::class] as $model) {
        $model::addGlobalScope(ClientPortalScope::class, function (): void {});
    }

    try {
        $this->actingAs($this->clientUser, 'client');

        // Tầng truy vấn đã thủng — nếu không thì khẳng định bên dưới không đo tầng policy.
        expect(StageLogDraft::query()->find($this->stageLogDraft->id))->not->toBeNull();

        expect($this->clientUser->can('viewAny', StageLogDraft::class))->toBeFalse()
            ->and($this->clientUser->can('view', $this->stageLogDraft))->toBeFalse()
            ->and($this->clientUser->can('viewAny', ClientRequestReplyDraft::class))->toBeFalse()
            ->and($this->clientUser->can('view', $this->replyDraft))->toBeFalse()
            ->and($this->clientUser->can('viewAny', McpConfirmation::class))->toBeFalse()
            ->and($this->clientUser->can('view', $this->confirmation))->toBeFalse();
    } finally {
        foreach ([StageLogDraft::class, ClientRequestReplyDraft::class, McpConfirmation::class] as $model) {
            $model::addGlobalScope(new ClientPortalScope);
        }
    }
});

/** Vế dương của tầng policy: nhân sự thấy vụ việc thì thấy nháp của nó; người ngoài vụ thì không. */
it('lets staff who can see the matter view its drafts, and nobody else', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    expect($this->lawyer->can('viewAny', StageLogDraft::class))->toBeTrue()
        ->and($this->lawyer->can('view', $this->stageLogDraft))->toBeTrue()
        ->and($this->lawyer->can('view', $this->replyDraft))->toBeTrue()
        ->and($outsider->can('view', $this->stageLogDraft))->toBeFalse()
        ->and($outsider->can('view', $this->replyDraft))->toBeFalse();
});

it('lets nobody delete a draft through the policy', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect($admin->can('delete', $this->stageLogDraft))->toBeFalse()
        ->and($admin->can('delete', $this->replyDraft))->toBeFalse();
});

/** Không màn hình nào đọc `mcp_confirmations`: chỉ đường ghi của MCP (Task 13) tra theo `jti`. */
it('lets no screen read the confirmation table, not even an admin', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect($admin->can('viewAny', McpConfirmation::class))->toBeFalse()
        ->and($admin->can('view', $this->confirmation))->toBeFalse();
});

it('never paints a draft into the portal matter page', function () {
    // `must_change_password` còn bật thì `RequirePortalPasswordChange` chặn mọi trang cổng (SPEC
    // §8.1) và test chết ở một cổng SỚM HƠN điều kiện nó nêu tên.
    $this->clientUser->forceFill(['must_change_password' => false])->save();

    $html = $this->actingAs($this->clientUser, 'client')
        ->get(MatterProgress::getUrl(['record' => $this->matter->id], panel: 'portal'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(e($this->matter->title))
        ->and($html)->not->toContain('Nháp chưa gửi khách')
        ->and($html)->not->toContain('Ghi chú nội bộ trong nháp')
        ->and($html)->not->toContain('Nháp trả lời chưa gửi khách');
});
