<?php

use App\Enums\AiAccessMode;
use App\Models\ClientRequest;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\McpRateLimits;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\McpOAuth;
use Tests\Support\McpReadWorld;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 — mười một tool đọc của làn m11b đi qua bước truy cập, audit và rate limit của làn m11
|--------------------------------------------------------------------------
| Sau khi gộp `m11-mcp-tools` vào `m11-mcp-server`: tool đọc (Task 10/11) không có mã truy cập, audit
| hay rate limit riêng — chúng thừa hưởng `EnsureMcpAccess` (Task 6), `AuditToolCall` + `CallCrmTool`
| (Task 8) qua `CrmServer`. Tệp này ghim điều đó cho TỪNG tool đang đăng ký, qua HTTP thật:
|
|  - người đủ điều kiện: mỗi lần gọi có kết quả, và đúng một dòng `mcp_tool_called` outcome `ok`,
|    causer là người sở hữu token;
|  - tài khoản vô hiệu hoá, `ai_access = off`, công tắc `mcp.enabled` tắt: 401, KHÔNG phản hồi tool
|    nào, và một dòng `denied` mỗi lần gọi;
|  - hết lượt 60 một phút: 429, không phản hồi tool, một dòng `rate_limited`.
|
| Danh sách tool lấy từ `CrmServer::$tools`, nên một tool đọc mới chưa có tham số ở đây làm test đỏ.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->world = McpReadWorld::build();
    $this->request = ClientRequest::factory()->create(['matter_id' => $this->world->matter->id]);
});

/**
 * Một lần gọi hợp lệ cho mỗi tool đọc, trên vụ mà `$lead` thấy qua MCP.
 *
 * @return array<string, array<string, mixed>>
 */
function readToolArguments(McpReadWorld $world, ClientRequest $request): array
{
    $matterId = McpIds::encode(McpIds::MATTER, $world->matter->id);

    return [
        'whoami' => [],
        'search' => ['query' => 'Quokkavan'],
        'fetch' => ['id' => $matterId],
        'search_matters' => [],
        'get_matter' => ['id' => $matterId],
        'list_matter_updates' => ['matter_id' => $matterId],
        'list_deadlines' => [],
        'get_checklist' => ['matter_id' => $matterId],
        'list_documents' => ['matter_id' => $matterId],
        'list_client_requests' => [],
        'get_client_request' => ['id' => McpIds::encode(McpIds::REQUEST, $request->id)],
    ];
}

/** @return list<Activity> */
function readToolAuditRows(): array
{
    return Activity::query()->where('event', 'mcp_tool_called')->orderBy('id')->get()->all();
}

it('mọi tool đăng ký trên CrmServer là tool đọc có mặt trong danh sách của tệp này', function () {
    $names = array_map(fn (string $class): string => app($class)->name(), McpToolCall::registeredTools());

    expect($names)->toBe(array_keys(readToolArguments($this->world, $this->request)));
});

it('mỗi tool đọc: người đủ điều kiện nhận kết quả, và mỗi lần gọi sinh đúng một dòng mcp_tool_called outcome ok, causer là người sở hữu token', function () {
    $lead = $this->world->lead;
    $token = McpOAuth::accessToken($this, $lead);

    foreach (readToolArguments($this->world, $this->request) as $tool => $arguments) {
        $before = count(readToolAuditRows());

        McpToolCall::structured($this, $token, $tool, $arguments);

        $rows = readToolAuditRows();

        expect($rows)->toHaveCount($before + 1, $tool);

        $row = end($rows);

        expect($row->properties['tool'])->toBe($tool)
            ->and($row->properties['outcome'])->toBe('ok')
            ->and($row->properties['channel'])->toBe('mcp')
            ->and($row->causer_type)->toBe($lead->getMorphClass())
            ->and((int) $row->causer_id)->toBe($lead->getKey());
    }

    // Tóm tắt kết quả của tool thật: id đã trả có tiền tố, không giá trị.
    $getMatter = collect(readToolAuditRows())->first(fn (Activity $row): bool => $row->properties['tool'] === 'get_matter');

    expect($getMatter->properties['returned_ids'])->toContain(McpIds::encode(McpIds::MATTER, $this->world->matter->id));
});

it('mỗi tool đọc: tài khoản vô hiệu hoá, ai_access tắt hay công tắc mcp.enabled tắt thì 401, không phản hồi tool nào, và một dòng denied mỗi lần gọi', function (string $condition) {
    $lead = $this->world->lead;
    $token = McpOAuth::accessToken($this, $lead);

    match ($condition) {
        'deactivated' => $lead->forceFill(['is_active' => false])->save(),
        'ai_access_off' => $lead->forceFill(['ai_access' => AiAccessMode::Off])->save(),
        'switch_off' => McpOAuth::openServer(enabled: false),
    };

    $tools = readToolArguments($this->world, $this->request);

    foreach ($tools as $tool => $arguments) {
        McpToolCall::refused($this, $token, $tool, $arguments);
    }

    $rows = readToolAuditRows();

    expect($rows)->toHaveCount(count($tools))
        ->and(array_unique(array_map(fn (Activity $row): string => $row->properties['outcome'], $rows)))->toBe(['denied'])
        ->and(array_unique(array_map(fn (Activity $row): int => (int) $row->causer_id, $rows)))->toBe([$lead->getKey()]);
})->with(['deactivated', 'ai_access_off', 'switch_off']);

it('cặp dương của lần từ chối: cùng token, cùng tool, người đủ điều kiện thì có kết quả', function () {
    $token = McpOAuth::accessToken($this, $this->world->lead);

    McpOAuth::openServer(enabled: false);
    McpToolCall::refused($this, $token, 'get_matter', ['id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id)]);

    McpOAuth::openServer();
    expect(McpToolCall::structured($this, $token, 'get_matter', ['id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id)])['code'])
        ->toBe($this->world->matter->code);
});

it('mỗi tool đọc: hết 60 lượt một phút thì 429, không phản hồi tool, và một dòng rate_limited', function () {
    $lead = $this->world->lead;
    $token = McpOAuth::accessToken($this, $lead);

    for ($i = 0; $i < McpRateLimits::PER_MINUTE; $i++) {
        RateLimiter::hit("mcp:all:user:{$lead->getKey()}", 60);
    }

    $tools = readToolArguments($this->world, $this->request);

    foreach ($tools as $tool => $arguments) {
        $response = McpToolCall::call($this, $token, $tool, $arguments);

        $response->assertStatus(429)->assertHeader('Retry-After');

        expect($response->json('result'))->toBeNull($tool);
    }

    $rows = readToolAuditRows();

    expect($rows)->toHaveCount(count($tools))
        ->and(array_map(fn (Activity $row): string => $row->properties['tool'], $rows))->toBe(array_keys($tools))
        ->and(array_unique(array_map(fn (Activity $row): string => $row->properties['outcome'], $rows)))->toBe(['rate_limited']);
});

it('search và fetch thật: lượt riêng 30 một phút; hết lượt search thì 429, fetch và whoami vẫn chạy', function () {
    $lead = $this->world->lead;
    $token = McpOAuth::accessToken($this, $lead);

    for ($i = 0; $i < McpRateLimits::SEARCH_PER_MINUTE; $i++) {
        RateLimiter::hit("mcp:tool-search:user:{$lead->getKey()}", 60);
    }

    McpToolCall::call($this, $token, 'search', ['query' => 'Quokkavan'])->assertStatus(429);

    expect(McpToolCall::structured($this, $token, 'fetch', ['id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id)])['id'])
        ->toBe(McpIds::encode(McpIds::MATTER, $this->world->matter->id))
        ->and(McpToolCall::structured($this, $token, 'whoami')['matter_count'])->toBe(1);
});
