<?php

use App\Enums\Confidentiality;
use App\Enums\MatterAiAccess;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Models\Matter;
use App\Models\User;
use App\Support\Mcp\McpIds;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\McpOAuth;
use Tests\Support\McpReadWorld;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 10 — tool `whoami`
|--------------------------------------------------------------------------
| Tên, vai trò, số vụ thấy được qua MCP, các giới hạn đang áp [DC:47]. Không email, không số điện
| thoại của chính người hỏi. Số vụ đếm trên tập `McpMatterScope` (R3): không vụ hạn chế, không vụ
| `denied`, không vụ của đội khác.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->world = McpReadWorld::build();
});

it('trả tên, chức danh, vai trò và id có tiền tố của người sở hữu token — không email, không số điện thoại', function () {
    $lead = $this->world->lead;
    $lead->forceFill(['email' => 'lead-secret@example.test', 'phone' => '0907111222', 'bar_number' => 'LS-SECRET-9'])->save();

    $token = McpOAuth::accessToken($this, $lead);
    $response = McpToolCall::call($this, $token, 'whoami');
    $out = McpToolCall::structured($this, $token, 'whoami');

    expect($out['user'])->toBe([
        'id' => McpIds::encode(McpIds::USER, $lead->id),
        'name' => 'Luật sư Phụ Trách',
        'position' => UserPosition::Lawyer->value,
        'position_label' => UserPosition::Lawyer->label(),
    ])
        ->and($out['roles'])->toBe([['role' => Role::Lawyer->value, 'role_label' => Role::Lawyer->label()]]);

    expect($response->getContent())->not->toContain('lead-secret@example.test')
        ->not->toContain('0907111222')
        ->not->toContain('LS-SECRET-9');
});

it('đếm vụ thấy được qua MCP: vụ hạn chế của chính mình, vụ denied, vụ đội khác không được đếm', function () {
    $out = McpToolCall::structured($this, McpOAuth::accessToken($this, $this->world->lead), 'whoami');

    expect($out['matter_count'])->toBe(1);
});

it('cặp dương của phép đếm: chuyển vụ hạn chế về thường và bật AI cho vụ denied thì số vụ tăng đúng theo', function () {
    $token = McpOAuth::accessToken($this, $this->world->lead);

    $this->world->restricted->forceFill(['confidentiality' => Confidentiality::Normal])->save();
    expect(McpToolCall::structured($this, $token, 'whoami')['matter_count'])->toBe(2);

    $this->world->denied->forceFill(['ai_access' => MatterAiAccess::Allowed])->save();
    expect(McpToolCall::structured($this, $token, 'whoami')['matter_count'])->toBe(3);
});

it('admin đếm mọi vụ thường đã bật AI của mọi đội, nhưng không vụ hạn chế, không vụ denied', function () {
    $out = McpToolCall::structured($this, McpOAuth::accessToken($this, $this->world->admin), 'whoami');

    // $matter và $otherTeam; không $restricted, không $denied.
    expect($out['matter_count'])->toBe(2)
        ->and($out['roles'][0]['role'])->toBe(Role::Admin->value);
});

it('kế toán (không matter.view) đếm 0 dù liệt kê được vụ thường trên web', function () {
    $out = McpToolCall::structured($this, McpOAuth::accessToken($this, $this->world->accountant), 'whoami');

    expect($out['matter_count'])->toBe(0);
});

it('cùng hình dạng cho người chỉ có vụ hạn chế, người chỉ có vụ denied, người chỉ có vụ đội khác và người không có vụ nào: matter_count 0, không gợi ý gì thêm', function () {
    $onlyRestricted = User::factory()->withRole(Role::Lawyer)->create();
    Matter::factory()->aiAccessAllowed()->restricted()->create(['lead_lawyer_id' => $onlyRestricted->id]);

    $onlyDenied = User::factory()->withRole(Role::Lawyer)->create();
    Matter::factory()->create(['lead_lawyer_id' => $onlyDenied->id]);

    $nothing = User::factory()->withRole(Role::Lawyer)->create();

    $strip = function (User $user): array {
        $out = McpToolCall::structured($this, McpOAuth::accessToken($this, $user), 'whoami');
        unset($out['user']);

        return $out;
    };

    $baseline = $strip($nothing);

    expect($baseline['matter_count'])->toBe(0)
        ->and($strip($onlyRestricted))->toBe($baseline)
        ->and($strip($onlyDenied))->toBe($baseline)
        // $outsider chỉ có $otherTeam — đã bật AI, nên ở đây là cặp dương: đếm 1, không phải 0.
        ->and($strip($this->world->outsider)['matter_count'])->toBe(1);
});

it('liệt kê các giới hạn đang áp: không vụ hạn chế, chỉ vụ đã bật AI, không nhóm D, không CCCD, không ghi chú nội bộ, không gửi gì cho khách', function () {
    $out = McpToolCall::structured($this, McpOAuth::accessToken($this, $this->world->lead), 'whoami');

    expect(array_column($out['limits'], 'code'))->toBe([
        'no_restricted_matters',
        'consented_matters_only',
        'no_internal_documents',
        'no_identity_numbers',
        'no_internal_notes',
        'third_party_pseudonyms',
        'nothing_reaches_clients',
    ]);

    foreach ($out['limits'] as $limit) {
        expect($limit['label'])->toBe(__('mcp.whoami.limits.'.$limit['code']));
    }
});

it('chế độ đầy đủ (MCP_PARTY_NAMES=full) thì không còn giới hạn tên giả', function () {
    config(['vkcrm.mcp.party_names' => 'full']);

    $out = McpToolCall::structured($this, McpOAuth::accessToken($this, $this->world->lead), 'whoami');

    expect(array_column($out['limits'], 'code'))->not->toContain('third_party_pseudonyms')
        ->toContain('no_restricted_matters');
});

it('R2 whoami trả chế độ read / read_write của người gọi')
    ->todo('TODO(m11-task6-whoami-mode): cột users.ai_access và enum AiAccessMode do Task 6 của làn m11 dựng; khi gộp, thêm khoá `mode` vào WhoAmIPresenter + outputSchema và test này.');
