<?php

use App\Enums\AiAccessMode;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Http\Middleware\Mcp\ThrottleMcpAuthenticationFailures;
use App\Mcp\Tools\Concerns\CrmTool;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use League\OAuth2\Server\Exception\OAuthServerException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\McpOAuth;
use Tests\Support\McpToolCalls;

/*
|--------------------------------------------------------------------------
| M11 Task 8 — rate limit của `/mcp` (R8, SPEC §10.3 "API 60 request/phút")
|--------------------------------------------------------------------------
| Laravel `RateLimiter` trên cache mặc định của app (`database` ở máy thật, `array` trong test).
| Khoá theo NGƯỜI và theo ACCESS TOKEN:
|  - mọi tool: 60 lần một phút;
|  - `search`, `fetch`: 30 lần một phút (mỗi tool);
|  - tool ghi: 10 lần một phút và 100 lần một ngày [DC:182-185].
| Vượt thì HTTP 429 kèm `Retry-After` và `X-RateLimit-*` [DC:187], và lần gọi đó vẫn có một dòng
| `mcp_tool_called` với `outcome = rate_limited` (AuditTest đo các outcome khác).
|
| Trước khi xác thực: một bearer bị từ chối 401 quá 30 lần một phút từ cùng một IP thì chính bearer
| đó bị chặn 429 một phút — không chặn bearer khác, không chặn request không mang bearer (rà soát
| Task 8, I2) — và lỗi token sai không còn ghi một dòng log lỗi kèm stack trace mỗi lần (rà soát
| Task 2, m2).
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    McpOAuth::openServer(write: true);

    app('translator')->addLines([
        'mcp.tools.rl_doc.title' => 'Thử đọc (Task 8)',
        'mcp.tools.rl_doc.description' => 'Dùng khi thử đọc. Không dùng để ghi.',
        'mcp.tools.search.title' => 'Tìm kiếm (thử)',
        'mcp.tools.search.description' => 'Dùng khi thử tìm. Không dùng để ghi.',
        'mcp.tools.fetch.title' => 'Mở bản ghi (thử)',
        'mcp.tools.fetch.description' => 'Dùng khi thử mở. Không dùng để ghi.',
        'mcp.tools.rl_ghi.title' => 'Thử ghi (Task 8)',
        'mcp.tools.rl_ghi.description' => 'Dùng khi thử ghi. Không dùng để gửi gì.',
    ], 'vi');

    app()->instance('rl.tool-calls', new ArrayObject);

    McpToolCalls::serve([
        rateLimitTool('rl_doc', writes: false),
        rateLimitTool('search', writes: false),
        rateLimitTool('fetch', writes: false),
        rateLimitTool('rl_ghi', writes: true),
    ]);
});

function rateLimitTool(string $name, bool $writes): CrmTool
{
    $tool = new class extends CrmTool
    {
        public bool $writesFlag = false;

        public function named(string $name, bool $writes): static
        {
            $this->name = $name;
            $this->writesFlag = $writes;

            return $this;
        }

        protected function writes(): bool
        {
            return $this->writesFlag;
        }

        public function handle(Request $request): Response
        {
            app('rl.tool-calls')[] = $this->name();

            return Response::text('ok');
        }
    };

    return $tool->named($name, $writes);
}

function rateLimitStaff(AiAccessMode $mode = AiAccessMode::Read): User
{
    return User::factory()
        ->position(UserPosition::Lawyer)
        ->withRole(Role::Lawyer)
        ->withAiAccess($mode)
        ->create();
}

/** Gọi `$times` lần, mỗi lần phải là 200 với kết quả tool không lỗi. */
function rateLimitCallOk(string $token, string $tool, int $times): void
{
    for ($i = 1; $i <= $times; $i++) {
        $response = McpToolCalls::call(test(), $token, $tool);

        expect($response->status())->toBe(200, "lần gọi {$tool} thứ {$i} phải qua")
            ->and($response->json('result.isError'))->toBeFalse();
    }
}

function rateLimitExpectThrottled(TestResponse $response, int $limit): void
{
    $response->assertStatus(429)
        ->assertHeader('X-RateLimit-Limit', (string) $limit)
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertJsonPath('error.message', __('mcp_audit.rate_limited'));

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
}

/** @return list<string> */
function rateLimitHandled(): array
{
    return app('rl.tool-calls')->getArrayCopy();
}

/*
|--------------------------------------------------------------------------
| Mọi tool: 60 lần một phút
|--------------------------------------------------------------------------
*/

it('R8 lần gọi thứ 61 trong một phút: 429, Retry-After, X-RateLimit-Remaining = 0; tool không chạy', function () {
    $token = McpOAuth::accessToken($this, rateLimitStaff());

    rateLimitCallOk($token, 'rl_doc', 60);

    $response = McpToolCalls::call($this, $token, 'rl_doc');

    rateLimitExpectThrottled($response, 60);
    expect((int) $response->headers->get('Retry-After'))->toBeLessThanOrEqual(60)
        ->and(rateLimitHandled())->toHaveCount(60);
});

it('R8 lần gọi bị chặn vẫn sinh đúng một dòng audit, outcome = rate_limited', function () {
    $staff = rateLimitStaff();
    $token = McpOAuth::accessToken($this, $staff);

    rateLimitCallOk($token, 'rl_doc', 60);
    McpToolCalls::call($this, $token, 'rl_doc')->assertStatus(429);

    $rows = Activity::query()->where('event', 'mcp_tool_called')->orderBy('id')->get();

    expect($rows)->toHaveCount(61)
        ->and($rows->last()->properties['outcome'])->toBe('rate_limited')
        ->and($rows->last()->properties['tool'])->toBe('rl_doc')
        ->and((int) $rows->last()->causer_id)->toBe($staff->getKey());
});

it('R8 lần gọi qua được mang X-RateLimit-Limit và X-RateLimit-Remaining', function () {
    $token = McpOAuth::accessToken($this, rateLimitStaff());

    McpToolCalls::call($this, $token, 'rl_doc')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '60')
        ->assertHeader('X-RateLimit-Remaining', '59');
});

it('R8 sau một phút, lượt gọi mở lại', function () {
    $token = McpOAuth::accessToken($this, rateLimitStaff());

    rateLimitCallOk($token, 'rl_doc', 60);
    McpToolCalls::call($this, $token, 'rl_doc')->assertStatus(429);

    $this->travel(61)->seconds();

    rateLimitCallOk($token, 'rl_doc', 1);
});

it('R8 tool có tên không tồn tại cũng tính vào lượt 60 một phút', function () {
    $token = McpOAuth::accessToken($this, rateLimitStaff());

    for ($i = 0; $i < 60; $i++) {
        McpToolCalls::call($this, $token, 'khong_co_tool_nay');
    }

    rateLimitExpectThrottled(McpToolCalls::call($this, $token, 'rl_doc'), 60);
});

/*
|--------------------------------------------------------------------------
| search và fetch: 30 lần một phút
|--------------------------------------------------------------------------
*/

it('R8 search lần thứ 31 trong một phút: 429; tool đọc khác vẫn chạy', function () {
    $token = McpOAuth::accessToken($this, rateLimitStaff());

    rateLimitCallOk($token, 'search', 30);

    rateLimitExpectThrottled(McpToolCalls::call($this, $token, 'search'), 30);
    rateLimitCallOk($token, 'rl_doc', 1);
});

it('R8 fetch lần thứ 31 trong một phút: 429', function () {
    $token = McpOAuth::accessToken($this, rateLimitStaff());

    rateLimitCallOk($token, 'fetch', 30);

    rateLimitExpectThrottled(McpToolCalls::call($this, $token, 'fetch'), 30);
});

/*
|--------------------------------------------------------------------------
| Tool ghi: 10 lần một phút, 100 lần một ngày
|--------------------------------------------------------------------------
*/

it('R8 tool ghi lần thứ 11 trong một phút: 429; tool đọc vẫn chạy', function () {
    $token = McpOAuth::accessToken($this, rateLimitStaff(AiAccessMode::ReadWrite));

    rateLimitCallOk($token, 'rl_ghi', 10);

    rateLimitExpectThrottled(McpToolCalls::call($this, $token, 'rl_ghi'), 10);
    rateLimitCallOk($token, 'rl_doc', 1);
});

it('R8 tool ghi lần thứ 101 trong một ngày: 429, dù lượt một phút còn', function () {
    $token = McpOAuth::accessToken($this, rateLimitStaff(AiAccessMode::ReadWrite));

    for ($minute = 0; $minute < 10; $minute++) {
        rateLimitCallOk($token, 'rl_ghi', 10);
        $this->travel(61)->seconds();
    }

    $response = McpToolCalls::call($this, $token, 'rl_ghi');

    rateLimitExpectThrottled($response, 100);
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(60)
        ->and(rateLimitHandled())->toHaveCount(100);
});

/*
|--------------------------------------------------------------------------
| Khoá theo người và theo token
|--------------------------------------------------------------------------
*/

it('R8 hai token của cùng một người dùng chung khoá theo người; người khác không bị ảnh hưởng', function () {
    $staff = rateLimitStaff();
    $first = McpOAuth::accessToken($this, $staff);
    $second = McpOAuth::accessToken($this, $staff, McpOAuth::client(['http://127.0.0.1/callback']));
    $colleague = McpOAuth::accessToken($this, rateLimitStaff());

    rateLimitCallOk($first, 'rl_doc', 30);
    rateLimitCallOk($second, 'rl_doc', 30);

    rateLimitExpectThrottled(McpToolCalls::call($this, $first, 'rl_doc'), 60);
    rateLimitExpectThrottled(McpToolCalls::call($this, $second, 'rl_doc'), 60);

    McpToolCalls::call($this, $colleague, 'rl_doc')
        ->assertOk()
        ->assertHeader('X-RateLimit-Remaining', '59');
});

/*
|--------------------------------------------------------------------------
| Trước khi xác thực: bearer bị từ chối 401 (rà soát Task 8, I2)
|--------------------------------------------------------------------------
| Bộ đếm theo IP VÀ theo chính bearer đó: claude.ai và ChatGPT gọi thay mọi người dùng của họ từ
| chung một dải IP [PL:177], [PL:181], nên một người lạ bơm 401 từ IP của nền tảng không được làm
| token hợp lệ của nhân sự nhận 429. Request không mang bearer (bước `initialize` đầu tiên của mọi
| connector) không bao giờ bị đếm hay bị chặn.
*/

function rateLimitFromIp(string $ip): void
{
    McpToolCalls::fresh();
    test()->withServerVariables(['REMOTE_ADDR' => $ip]);
}

/** `initialize` KHÔNG có header `Authorization` — bước đầu của mọi connector trước khi có token. */
function rateLimitInitializeWithoutBearer(): TestResponse
{
    McpToolCalls::fresh();

    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'thu', 'version' => '1'],
        ],
    ]);
}

it('R8 cùng một bearer hỏng bị từ chối 401 quá 30 lần một phút thì chính bearer đó nhận 429; IP khác không; hết một phút thì mở lại', function () {
    rateLimitFromIp('203.0.113.50');

    for ($i = 1; $i <= 30; $i++) {
        McpToolCalls::call($this, 'token-sai', 'rl_doc', fresh: false)->assertUnauthorized();
        McpToolCalls::fresh();
    }

    $blocked = McpToolCalls::call($this, 'token-sai', 'rl_doc', fresh: false);

    $blocked->assertStatus(429)->assertJsonPath('message', __('mcp_audit.too_many_failures'));
    expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    // Cùng bearer từ IP khác: chưa hỏng lần nào ở đó.
    rateLimitFromIp('203.0.113.51');
    McpToolCalls::call($this, 'token-sai', 'rl_doc', fresh: false)->assertUnauthorized();

    $this->travel(61)->seconds();
    rateLimitFromIp('203.0.113.50');
    McpToolCalls::call($this, 'token-sai', 'rl_doc', fresh: false)->assertUnauthorized();
});

it('R8 IP đã có hơn 30 lần 401: token hợp lệ từ cùng IP vẫn 200, bearer hỏng khác vẫn 401, chỉ chính bearer hỏng đó nhận 429', function () {
    $token = McpOAuth::accessToken($this, rateLimitStaff());

    rateLimitFromIp('203.0.113.70');

    for ($i = 1; $i <= 30; $i++) {
        McpToolCalls::call($this, 'token-sai', 'rl_doc', fresh: false)->assertUnauthorized();
        McpToolCalls::fresh();
    }

    McpToolCalls::call($this, 'token-sai-khac', 'rl_doc', fresh: false)->assertUnauthorized(); // lần 401 thứ 31 của IP

    McpToolCalls::fresh();
    McpToolCalls::call($this, $token, 'rl_doc', fresh: false)->assertOk();

    McpToolCalls::fresh();
    McpToolCalls::call($this, 'token-sai', 'rl_doc', fresh: false)->assertStatus(429);

    expect(rateLimitHandled())->toBe(['rl_doc']);
});

it('R8 request không mang bearer không bị đếm và không bị chặn: lần thứ 32 từ cùng IP vẫn 401 kèm WWW-Authenticate', function () {
    rateLimitFromIp('203.0.113.80');

    for ($i = 1; $i <= 32; $i++) {
        $response = rateLimitInitializeWithoutBearer();

        expect($response->status())->toBe(401, "initialize không bearer lần thứ {$i} phải là 401");
    }

    expect((string) $response->headers->get('WWW-Authenticate'))->toContain('resource_metadata=');
});

it('R8 nhiều bearer rác khác nhau từ cùng IP không đẩy được một bearer đang bị chặn ra khỏi bộ đếm, và bộ đếm của IP có giới hạn', function () {
    rateLimitFromIp('203.0.113.90');

    for ($i = 1; $i <= 30; $i++) {
        McpToolCalls::call($this, 'token-ket', 'rl_doc', fresh: false)->assertUnauthorized();
        McpToolCalls::fresh();
    }

    for ($i = 1; $i <= ThrottleMcpAuthenticationFailures::MAX_TRACKED_BEARERS + 5; $i++) {
        McpToolCalls::call($this, 'token-rac-'.$i, 'rl_doc', fresh: false)->assertUnauthorized();
        McpToolCalls::fresh();
    }

    McpToolCalls::call($this, 'token-ket', 'rl_doc', fresh: false)->assertStatus(429);

    expect(Cache::get(ThrottleMcpAuthenticationFailures::cacheKey('203.0.113.90')))
        ->toBeArray()
        ->toHaveCount(ThrottleMcpAuthenticationFailures::MAX_TRACKED_BEARERS);
});

it('R8 bộ đếm của IP đã đầy bearer rác (mỗi cái một lần): một bearer mới bắt đầu lặp vẫn được đếm và bị chặn ở lần thứ 31', function () {
    rateLimitFromIp('203.0.113.92');

    for ($i = 1; $i <= ThrottleMcpAuthenticationFailures::MAX_TRACKED_BEARERS; $i++) {
        McpToolCalls::call($this, 'token-rac-'.$i, 'rl_doc', fresh: false)->assertUnauthorized();
        McpToolCalls::fresh();
    }

    for ($i = 1; $i <= 30; $i++) {
        McpToolCalls::call($this, 'token-moi', 'rl_doc', fresh: false)->assertUnauthorized();
        McpToolCalls::fresh();
    }

    McpToolCalls::call($this, 'token-moi', 'rl_doc', fresh: false)->assertStatus(429);
});

it('R8 mỗi bearer một cửa sổ một phút riêng: bearer bị chặn mở lại đúng hạn của nó dù IP còn bearer khác mới hỏng', function () {
    $this->freezeTime();
    rateLimitFromIp('203.0.113.93');

    for ($i = 1; $i <= 30; $i++) {
        McpToolCalls::call($this, 'token-a', 'rl_doc', fresh: false)->assertUnauthorized();
        McpToolCalls::fresh();
    }

    $this->travel(40)->seconds();
    McpToolCalls::call($this, 'token-b', 'rl_doc', fresh: false)->assertUnauthorized();
    McpToolCalls::fresh();
    McpToolCalls::call($this, 'token-a', 'rl_doc', fresh: false)->assertStatus(429);

    // Cửa sổ của token-a (bắt đầu ở lần 401 đầu) đã hết; mục của IP còn sống vì token-b.
    $this->travel(21)->seconds();
    McpToolCalls::fresh();
    McpToolCalls::call($this, 'token-a', 'rl_doc', fresh: false)->assertUnauthorized();
});

it('R8 bộ đếm 401 không giữ bearer thô: khoá của bearer là dấu băm sha256', function () {
    rateLimitFromIp('203.0.113.91');

    McpToolCalls::call($this, 'token-sai-bi-mat', 'rl_doc', fresh: false)->assertUnauthorized();

    $tracked = Cache::get(ThrottleMcpAuthenticationFailures::cacheKey('203.0.113.91'));

    expect(array_keys($tracked))->toBe([hash('sha256', 'token-sai-bi-mat')])
        ->and((string) json_encode($tracked))->not->toContain('token-sai-bi-mat');
});

it('R8 request xác thực được không tính vào bộ đếm 401', function () {
    $token = McpOAuth::accessToken($this, rateLimitStaff());

    rateLimitFromIp('203.0.113.60');

    for ($i = 0; $i < 31; $i++) {
        McpToolCalls::call($this, $token, 'rl_doc', fresh: false)->assertOk();
        McpToolCalls::fresh();
    }

    expect(Cache::get(ThrottleMcpAuthenticationFailures::cacheKey('203.0.113.60')))->toBeNull();
});

it('R8 bearer sai không còn được report (không ghi log lỗi kèm stack trace mỗi lần)', function () {
    Exceptions::fake();

    McpToolCalls::call($this, 'khong-phai-jwt', 'rl_doc')->assertUnauthorized();

    Exceptions::assertNotReported(OAuthServerException::class);
});
