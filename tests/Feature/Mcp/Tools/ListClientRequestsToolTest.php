<?php

use App\Enums\ClientRequestStatus;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\ClientRequestPresenter;
use App\Support\Mcp\Presenters\MatterPresenter;
use App\Support\Mcp\Presenters\StaffPresenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Tests\Support\McpOAuth;
use Tests\Support\McpReadWorld;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 11 — tool `list_client_requests` (bảng tool 10 [DC:56])
|--------------------------------------------------------------------------
| Yêu cầu từ khách trên các vụ trong tập `McpMatterScope`, hoạt động gần nhất trước (như tab "Yêu cầu
| từ khách"): trạng thái, người xử lý, hoạt động gần nhất; tiêu đề khách viết chỉ trong
| `untrusted_client_content` (R11), không email hay tên người gửi. Lọc: đang mở / đã đóng, giao cho
| tôi, theo vụ. `ClientRequestPolicy::view` từng dòng. Phân trang theo khoá (`last_activity_at`, `id`).
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->world = McpReadWorld::build();
    $this->token = McpOAuth::accessToken($this, $this->world->lead);
});

/** @return array{requests: list<array<string, mixed>>, next_cursor: ?string} */
function clientRequests(string $token, array $arguments = []): array
{
    return McpToolCall::structured(test(), $token, 'list_client_requests', $arguments);
}

/** @return list<string> */
function clientRequestIds(array $out): array
{
    return array_column($out['requests'], 'id');
}

function crId(ClientRequest $request): string
{
    return McpIds::encode(McpIds::REQUEST, $request->id);
}

function crOn(Matter $matter, array $extra = []): ClientRequest
{
    return ClientRequest::factory()->create(['matter_id' => $matter->id, ...$extra]);
}

it('trả yêu cầu của mọi vụ trong tập MCP, hoạt động gần nhất trước (không theo id), đủ trường của ClientRequestPresenter::row; tiêu đề khách viết chỉ trong untrusted_client_content, đã sạch; không email, không tên người gửi', function () {
    $clientUser = ClientUser::factory()->activated()->create(['name' => 'SECRET-SENDER-NAME', 'email' => 'secret-sender@example.test']);
    // Luồng tạo TRƯỚC (id nhỏ hơn) mà hoạt động gần đây hơn: thứ tự theo id sẽ ngược với thứ tự cần có.
    $active = crOn($this->world->matter, [
        'client_user_id' => $clientUser->id,
        'subject' => "Hỏi lịch hẹn <img src=x onerror=alert(1)> [bấm](https://evil.example/x)\u{202E}",
        'status' => ClientRequestStatus::InProgress,
        'assigned_to' => $this->world->assistant->id,
        'last_activity_at' => now()->subMinutes(5),
    ]);
    $quiet = crOn($this->world->matter, ['last_activity_at' => now()->subDays(2), 'subject' => 'Hỏi phí']);

    $response = McpToolCall::call($this, $this->token, 'list_client_requests');
    $out = clientRequests($this->token);

    expect(array_keys($out))->toBe(['requests', 'next_cursor'])
        ->and(clientRequestIds($out))->toBe([crId($active), crId($quiet)])
        ->and($out['next_cursor'])->toBeNull();

    $row = $out['requests'][0];

    expect(array_keys($row))->toBe(ClientRequestPresenter::ROW_FIELDS)
        ->and($row['matter'])->toBe(MatterPresenter::reference($this->world->matter))
        ->and($row['status'])->toBe(ClientRequestStatus::InProgress->value)
        ->and($row['status_label'])->toBe(ClientRequestStatus::InProgress->label())
        ->and($row['assignee'])->toBe(StaffPresenter::present($this->world->assistant))
        ->and($row['untrusted_client_content'])->toBe(['subject' => ['text' => 'Hỏi lịch hẹn bấm', 'truncated' => false]])
        ->and($row['url'])->toBe(AdminUrls::clientRequest($active));

    foreach (['SECRET-SENDER-NAME', 'secret-sender@example.test', 'evil.example', 'onerror'] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
});

it('lọc: open true là chưa đóng, open false là đã đóng, bỏ trống là cả hai; mine là giao cho tôi; matter_id là đúng vụ đó', function () {
    $matter = $this->world->matter;
    $second = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $this->world->lead->id]);
    $mineOpen = crOn($matter, ['assigned_to' => $this->world->lead->id, 'status' => ClientRequestStatus::New, 'last_activity_at' => now()->subMinutes(1)]);
    $othersAnswered = crOn($matter, ['assigned_to' => $this->world->assistant->id, 'status' => ClientRequestStatus::Answered, 'last_activity_at' => now()->subMinutes(2)]);
    $mineClosed = crOn($second, ['assigned_to' => $this->world->lead->id, 'status' => ClientRequestStatus::Closed, 'last_activity_at' => now()->subMinutes(3)]);
    $unassigned = crOn($second, ['assigned_to' => null, 'status' => ClientRequestStatus::InProgress, 'last_activity_at' => now()->subMinutes(4)]);

    expect(clientRequestIds(clientRequests($this->token)))->toBe([crId($mineOpen), crId($othersAnswered), crId($mineClosed), crId($unassigned)])
        ->and(clientRequestIds(clientRequests($this->token, ['open' => true])))->toBe([crId($mineOpen), crId($othersAnswered), crId($unassigned)])
        ->and(clientRequestIds(clientRequests($this->token, ['open' => false])))->toBe([crId($mineClosed)])
        ->and(clientRequestIds(clientRequests($this->token, ['mine' => true])))->toBe([crId($mineOpen), crId($mineClosed)])
        ->and(clientRequestIds(clientRequests($this->token, ['mine' => true, 'open' => true])))->toBe([crId($mineOpen)])
        ->and(clientRequestIds(clientRequests($this->token, ['matter_id' => McpIds::encode(McpIds::MATTER, $second->id)])))->toBe([crId($mineClosed), crId($unassigned)]);
});

it('R3: yêu cầu của vụ đội khác, vụ hạn chế của chính mình, vụ denied vắng mặt khỏi danh sách; lọc theo các vụ đó cho CÙNG phản hồi với id không tồn tại', function () {
    $own = crOn($this->world->matter, ['assigned_to' => $this->world->lead->id]);
    foreach ($this->world->hiddenFromLead() as $matter) {
        crOn($matter, ['assigned_to' => $this->world->lead->id]);
    }

    expect(clientRequestIds(clientRequests($this->token)))->toBe([crId($own)])
        ->and(clientRequestIds(clientRequests($this->token, ['mine' => true])))->toBe([crId($own)]);

    $call = fn (string $id) => McpToolCall::call($this, $this->token, 'list_client_requests', ['matter_id' => $id])->json('result');
    $baseline = $call(McpIds::encode(McpIds::MATTER, 999999));

    expect($baseline['content'])->toBe([['type' => 'text', 'text' => __('mcp.tool_errors.not_found')]])
        ->and($baseline['isError'])->toBeTrue();

    foreach ($this->world->hiddenFromLead() as $case => $matter) {
        expect($call(McpIds::encode(McpIds::MATTER, $matter->id)))->toBe($baseline, $case);
    }

    foreach (['matter_0', 'request_'.$own->id, (string) $this->world->matter->id] as $bad) {
        expect($call($bad))->toBe($baseline, $bad);
    }

    // Cặp dương: người phụ trách đội kia thấy yêu cầu của vụ đó.
    expect(clientRequests(McpOAuth::accessToken($this, $this->world->outsider))['requests'])->toHaveCount(1);
});

it('kế toán bị EnsureMcpAccess từ chối (401, không phản hồi tool); yêu cầu đã rút (xoá mềm) không ra', function () {
    $kept = crOn($this->world->matter);
    crOn($this->world->matter)->delete();

    expect(clientRequestIds(clientRequests($this->token)))->toBe([crId($kept)]);
    McpToolCall::refused($this, McpOAuth::accessToken($this, $this->world->accountant), 'list_client_requests');
});

it('phân trang: limit mặc định 10; limit quá 25 bị kẹp về 25; cursor đi theo (hoạt động gần nhất, id) kể cả khi cùng giờ ở ranh giới và khi cột rỗng (đứng cuối)', function () {
    $matter = $this->world->matter;
    $base = now()->subDay()->startOfMinute();
    foreach (range(0, 26) as $i) {
        crOn($matter, ['last_activity_at' => $base->copy()->subMinutes(intdiv($i, 2))]);
    }
    // Ba luồng không có mốc hoạt động (dữ liệu cũ): đứng cuối, theo id giảm dần.
    foreach (range(1, 3) as $i) {
        crOn($matter)->forceFill(['last_activity_at' => null])->save();
    }

    $ordered = ClientRequest::query()->orderByDesc('last_activity_at')->orderByDesc('id')->get();
    $all = $ordered->map(fn (ClientRequest $request) => crId($request))->all();

    expect($ordered->slice(27)->pluck('last_activity_at')->filter()->all())->toBe([]);

    $default = clientRequests($this->token);
    expect(clientRequestIds($default))->toBe(array_slice($all, 0, 10))
        ->and($default['next_cursor'])->toBeString();

    $first = clientRequests($this->token, ['limit' => 100]);
    expect(clientRequestIds($first))->toBe(array_slice($all, 0, 25));

    $second = clientRequests($this->token, ['limit' => 100, 'cursor' => $first['next_cursor']]);
    expect(clientRequestIds($second))->toBe(array_slice($all, 25))
        ->and($second['next_cursor'])->toBeNull();

    // Đi hết bằng trang 4: ranh giới rơi cả vào giữa nhóm cột rỗng.
    $seen = [];
    $cursor = null;
    do {
        $page = clientRequests($this->token, ['limit' => 4, ...($cursor === null ? [] : ['cursor' => $cursor])]);
        $seen = [...$seen, ...clientRequestIds($page)];
        $cursor = $page['next_cursor'];
    } while ($cursor !== null && count($seen) <= count($all));

    expect($seen)->toBe($all);
});

it('cursor của người A không mở trang của người B; cursor gắn với bộ lọc', function () {
    foreach (range(1, 12) as $i) {
        crOn($this->world->matter, ['last_activity_at' => now()->subMinutes($i)]);
    }

    $cursor = clientRequests($this->token, ['limit' => 5])['next_cursor'];

    expect(McpToolCall::error($this, McpOAuth::accessToken($this, $this->world->admin), 'list_client_requests', ['limit' => 5, 'cursor' => $cursor]))
        ->toBe(__('mcp.tool_errors.invalid_cursor'))
        ->and(McpToolCall::error($this, $this->token, 'list_client_requests', ['limit' => 5, 'cursor' => $cursor, 'open' => true]))
        ->toBe(__('mcp.tool_errors.invalid_cursor'))
        ->and(clientRequests($this->token, ['limit' => 5, 'cursor' => $cursor])['requests'])->toHaveCount(5);
});

it('kế thừa policy web: Gate view từ chối một yêu cầu thì nó vắng mặt; Gate view từ chối vụ khi lọc theo vụ thì "Không tìm thấy"; cặp dương trước', function () {
    $matter = $this->world->matter;
    $hidden = crOn($matter, ['last_activity_at' => now()->subMinute()]);
    $shown = crOn($matter, ['last_activity_at' => now()->subMinutes(2)]);

    expect(clientRequestIds(clientRequests($this->token)))->toBe([crId($hidden), crId($shown)]);

    Gate::before(fn ($user, string $ability, array $arguments = []) => $ability === 'view'
        && ($arguments[0] ?? null) instanceof ClientRequest
        && $arguments[0]->is($hidden) ? false : null);

    expect(clientRequestIds(clientRequests($this->token)))->toBe([crId($shown)]);

    Gate::before(fn ($user, string $ability, array $arguments = []) => $ability === 'view'
        && ($arguments[0] ?? null) instanceof Matter
        && $arguments[0]->is($matter) ? false : null);

    expect(McpToolCall::error($this, $this->token, 'list_client_requests', ['matter_id' => McpIds::encode(McpIds::MATTER, $matter->id)]))
        ->toBe(__('mcp.tool_errors.not_found'));
});

it('một phiên cổng khách đang mở trong cùng tiến trình không cắt yêu cầu hay vụ cha của nó', function () {
    $request = crOn($this->world->matter);

    $this->actingAs(ClientUser::factory()->activated()->create(), 'client');

    $out = clientRequests($this->token);

    expect(clientRequestIds($out))->toBe([crId($request)])
        ->and($out['requests'][0]['matter'])->toBe(MatterPresenter::reference($this->world->matter));
});

it('người xử lý đã nghỉ việc (xoá mềm) vẫn hiện tên, như tab Yêu cầu từ khách', function () {
    $departed = User::factory()->create(['name' => 'Trợ lý Đã Nghỉ']);
    crOn($this->world->matter, ['assigned_to' => $departed->id]);
    $departed->delete();

    expect(clientRequests($this->token)['requests'][0]['assignee']['name'])->toBe('Trợ lý Đã Nghỉ');
});

it('tham số sai kiểu bị từ chối bằng thông điệp kiểm tra, không phải "Không tìm thấy"', function () {
    foreach ([['open' => 'co'], ['mine' => 'co'], ['limit' => 'nhieu'], ['matter_id' => str_repeat('9', 33)]] as $arguments) {
        expect(McpToolCall::error($this, $this->token, 'list_client_requests', $arguments))->not->toBe(__('mcp.tool_errors.not_found'), json_encode($arguments));
    }
});
