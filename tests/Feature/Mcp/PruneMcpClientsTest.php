<?php

use App\Actions\Mcp\PruneStaleMcpClients;
use App\Actions\Mcp\RegisterMcpClient;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| M11 Task 3 — dọn client DCR: `vkcrm:mcp-prune-clients`
|--------------------------------------------------------------------------
| DCR tạo một client mới ở MỖI lần kết nối [PL:67], [PL:103]. Lệnh dọn xoá client mang cờ `is_mcp`
| đã quá 30 ngày tuổi mà không còn access token, refresh token hay mã uỷ quyền nào CÒN SỐNG (chưa
| thu hồi, chưa hết hạn), cùng mọi dòng token chết của nó. Client không mang cờ (tạo bằng
| `passport:client`) không bao giờ bị đụng tới.
|
| Dòng token được dựng thẳng trong CSDL để điều khiển từng cờ `revoked` / `expires_at`; luồng cấp
| token thật đã có test HTTP riêng (`ClientRegistrationTest`, `OAuthMetadataTest`).
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    // Task 6: `/oauth/register` chỉ nhận đăng ký khi công tắc toàn hệ thống mở.
    McpOAuth::openServer();
});

/** Client DCR (qua chính Action của `/oauth/register`) tạo cách đây `$days` ngày. */
function pruneDcrClient(float $days): Client
{
    $client = app(RegisterMcpClient::class)->handle('Claude', [McpOAuth::REDIRECT_URI]);
    $client->forceFill(['created_at' => now()->subMinutes((int) round($days * 24 * 60))])->save();

    return $client;
}

/** @param  array<string, mixed>  $attributes */
function pruneAccessToken(Client $client, array $attributes = []): string
{
    $id = Str::random(80);

    Passport::token()->newQuery()->forceCreate(array_merge([
        'id' => $id,
        'user_id' => null,
        'client_id' => $client->getKey(),
        'name' => null,
        'scopes' => ['mcp:use'],
        'revoked' => false,
        'expires_at' => now()->addHour(),
    ], $attributes));

    return $id;
}

/** @param  array<string, mixed>  $attributes */
function pruneRefreshToken(string $accessTokenId, array $attributes = []): string
{
    $id = Str::random(80);

    Passport::refreshToken()->newQuery()->forceCreate(array_merge([
        'id' => $id,
        'access_token_id' => $accessTokenId,
        'revoked' => false,
        'expires_at' => now()->addDays(30),
    ], $attributes));

    return $id;
}

/** @param  array<string, mixed>  $attributes */
function pruneAuthCode(Client $client, array $attributes = []): string
{
    $id = Str::random(80);

    Passport::authCode()->newQuery()->forceCreate(array_merge([
        'id' => $id,
        'user_id' => 1,
        'client_id' => $client->getKey(),
        // `AuthCode` của Passport không cast `scopes` (khác `Token`): ghi chuỗi JSON như Passport ghi.
        'scopes' => json_encode(['mcp:use']),
        'revoked' => false,
        'expires_at' => now()->addMinutes(10),
    ], $attributes));

    return $id;
}

function pruneClientExists(Client $client): bool
{
    return Passport::client()->newQuery()->whereKey($client->getKey())->exists();
}

/**
 * Lệnh artisan ĐÚNG NHƯ lịch chạy nó — tác vụ tên `$name` ở `routes/console.php`, kèm mọi tham số —
 * để test đi qua cặp lịch thật `mcp.tokens.purge` (03:00) rồi `mcp.clients.prune` (03:15), không phải
 * `passport:purge` với mặc định của Passport.
 */
function pruneScheduledCommand(string $name): string
{
    $events = collect(Schedule::events())
        ->filter(fn (Event $event) => $event->description === $name)
        ->values();

    expect($events)->toHaveCount(1, "phải có đúng một tác vụ lịch tên {$name}")
        ->and(preg_match("/artisan'? (.+)$/", (string) $events->first()->command, $matches))->toBe(1);

    return $matches[1];
}

it('R7 xoá client DCR quá 30 ngày (thêm 15 phút) không còn token nào; giữ client DCR thiếu 15 phút nữa mới đủ 30 ngày', function () {
    $stale = pruneDcrClient(30.01);
    $young = pruneDcrClient(29.99);

    expect(app(PruneStaleMcpClients::class)->handle())->toBe(1)
        ->and(pruneClientExists($stale))->toBeFalse()
        ->and(pruneClientExists($young))->toBeTrue();
});

it('R7 access token CÒN SỐNG giữ client lại; access token đã thu hồi hoặc đã hết hạn thì không', function () {
    $live = pruneDcrClient(45);
    pruneAccessToken($live);

    $revoked = pruneDcrClient(45);
    pruneAccessToken($revoked, ['revoked' => true]);

    $expired = pruneDcrClient(45);
    pruneAccessToken($expired, ['expires_at' => now()->subMinute()]);

    app(PruneStaleMcpClients::class)->handle();

    expect(pruneClientExists($live))->toBeTrue()
        ->and(pruneClientExists($revoked))->toBeFalse()
        ->and(pruneClientExists($expired))->toBeFalse();
});

it('R7 access token không có hạn (expires_at NULL) và chưa thu hồi được tính là còn sống', function () {
    $client = pruneDcrClient(45);
    pruneAccessToken($client, ['expires_at' => null]);

    app(PruneStaleMcpClients::class)->handle();

    expect(pruneClientExists($client))->toBeTrue();
});

it('R7 refresh token CÒN SỐNG giữ client lại dù access token đã hết hạn (access token 1 giờ, refresh token 30 ngày); refresh token đã thu hồi hoặc hết hạn thì không', function () {
    $live = pruneDcrClient(45);
    pruneRefreshToken(pruneAccessToken($live, ['expires_at' => now()->subDay()]));

    $revoked = pruneDcrClient(45);
    pruneRefreshToken(pruneAccessToken($revoked, ['expires_at' => now()->subDay()]), ['revoked' => true]);

    $expired = pruneDcrClient(45);
    pruneRefreshToken(pruneAccessToken($expired, ['expires_at' => now()->subDay()]), ['expires_at' => now()->subMinute()]);

    $noExpiry = pruneDcrClient(45);
    pruneRefreshToken(pruneAccessToken($noExpiry, ['expires_at' => now()->subDay()]), ['expires_at' => null]);

    app(PruneStaleMcpClients::class)->handle();

    expect(pruneClientExists($live))->toBeTrue()
        ->and(pruneClientExists($revoked))->toBeFalse()
        ->and(pruneClientExists($expired))->toBeFalse()
        ->and(pruneClientExists($noExpiry))->toBeTrue();
});

it('R7 refresh token sống của client KHÁC không giữ client này lại', function () {
    $other = pruneDcrClient(45);
    pruneRefreshToken(pruneAccessToken($other, ['expires_at' => now()->subDay()]));

    $client = pruneDcrClient(45);
    pruneAccessToken($client, ['expires_at' => now()->subDay()]);

    app(PruneStaleMcpClients::class)->handle();

    expect(pruneClientExists($other))->toBeTrue()
        ->and(pruneClientExists($client))->toBeFalse();
});

it('R7 mã uỷ quyền CÒN SỐNG (người dùng đang ở giữa luồng đồng ý) giữ client lại; mã đã dùng hoặc hết hạn thì không', function () {
    $live = pruneDcrClient(45);
    pruneAuthCode($live);

    $revoked = pruneDcrClient(45);
    pruneAuthCode($revoked, ['revoked' => true]);

    $expired = pruneDcrClient(45);
    pruneAuthCode($expired, ['expires_at' => now()->subMinute()]);

    app(PruneStaleMcpClients::class)->handle();

    expect(pruneClientExists($live))->toBeTrue()
        ->and(pruneClientExists($revoked))->toBeFalse()
        ->and(pruneClientExists($expired))->toBeFalse();
});

it('R7 client không mang cờ is_mcp (tạo bằng passport:client) không bao giờ bị dọn, kể cả quá 30 ngày không token nào', function () {
    Artisan::call('passport:client', ['--public' => true, '--name' => 'Thu cong', '--redirect_uri' => McpOAuth::REDIRECT_URI]);
    $manual = Passport::client()->newQuery()->where('name', 'Thu cong')->firstOrFail();
    $manual->forceFill(['created_at' => now()->subDays(400)])->save();

    expect(app(PruneStaleMcpClients::class)->handle())->toBe(0)
        ->and(pruneClientExists($manual))->toBeTrue();
});

it('R7 xoá client kéo theo mọi dòng token chết của nó (access, refresh, mã uỷ quyền), không đụng dòng của client khác', function () {
    $stale = pruneDcrClient(45);
    $deadAccess = pruneAccessToken($stale, ['revoked' => true]);
    $deadRefresh = pruneRefreshToken($deadAccess, ['revoked' => true]);
    $deadCode = pruneAuthCode($stale, ['revoked' => true]);

    $kept = pruneDcrClient(45);
    $liveAccess = pruneAccessToken($kept);
    $keptRefresh = pruneRefreshToken($liveAccess, ['revoked' => true]);
    $keptCode = pruneAuthCode($kept, ['revoked' => true]);

    app(PruneStaleMcpClients::class)->handle();

    expect(Passport::token()->newQuery()->whereKey($deadAccess)->exists())->toBeFalse()
        ->and(Passport::refreshToken()->newQuery()->whereKey($deadRefresh)->exists())->toBeFalse()
        ->and(Passport::authCode()->newQuery()->whereKey($deadCode)->exists())->toBeFalse()
        ->and(Passport::token()->newQuery()->whereKey($liveAccess)->exists())->toBeTrue()
        ->and(Passport::refreshToken()->newQuery()->whereKey($keptRefresh)->exists())->toBeTrue()
        ->and(Passport::authCode()->newQuery()->whereKey($keptCode)->exists())->toBeTrue();
});

/*
 * Chạy đua: một token được cấp cho client NGAY SAU câu chọn client cũ, trước câu xoá. Dựng bằng một
 * listener truy vấn chèn token đúng lúc câu chọn (SELECT … oauth_clients … not exists) vừa chạy xong.
 */
it('R7 token cấp cho client giữa lúc chọn và lúc xoá giữ client lại và không bị xoá (điều kiện kiểm lại trong chính câu DELETE)', function () {
    $client = pruneDcrClient(45);
    $fired = false;
    $raced = null;

    DB::listen(function (QueryExecuted $query) use ($client, &$fired, &$raced): void {
        $sql = strtolower($query->sql);

        if (! $fired && str_starts_with($sql, 'select') && str_contains($sql, 'oauth_clients') && str_contains($sql, 'not exists')) {
            $fired = true; // trước khi chèn: câu INSERT bên dưới cũng đi qua listener này
            $raced = pruneAccessToken($client);
        }
    });

    expect(app(PruneStaleMcpClients::class)->handle())->toBe(0)
        ->and($raced)->toBeString()
        ->and(pruneClientExists($client))->toBeTrue()
        ->and(Passport::token()->newQuery()->whereKey($raced)->exists())->toBeTrue();
});

it('R7 đường thật: client vừa đăng ký qua /oauth/register và cấp token thật còn đó hôm nay; 31 ngày sau, mọi token đã hết hạn, lệnh dọn xoá nó', function () {
    $user = User::factory()->withRole(Role::Lawyer)->create();
    $registered = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [McpOAuth::REDIRECT_URI]])->assertCreated();
    $client = Passport::client()->newQuery()->findOrFail($registered->json('client_id'));

    McpOAuth::issueTokens($this, $user, $client);

    $this->artisan('vkcrm:mcp-prune-clients')->assertSuccessful();
    expect(pruneClientExists($client))->toBeTrue();

    // Hạn của token do league tính theo đồng hồ HỆ THỐNG (1 giờ / 30 ngày kể từ lúc cấp thật), nên
    // nhảy 31 ngày theo Carbon thì cả hai đã hết hạn so với `now()` của lệnh dọn.
    $this->travel(31)->days();

    $this->artisan('vkcrm:mcp-prune-clients')
        ->expectsOutputToContain(__('mcp.prune.done', ['count' => 1]))
        ->assertSuccessful();

    expect(pruneClientExists($client))->toBeFalse()
        ->and(Passport::token()->newQuery()->where('client_id', $client->getKey())->exists())->toBeFalse();
});

/*
 * Rà soát Task 3, I1 — cặp lịch thật `passport:purge` (03:00) rồi `vkcrm:mcp-prune-clients` (03:15).
 * Refresh token chỉ nối được về client QUA dòng access token của nó (`oauth_refresh_tokens` không có
 * `client_id`). Với mặc định của Passport (`--hours=168`), purge xoá dòng access token hết hạn quá 7
 * ngày dù refresh token (30 ngày) của nó còn sống; lượt dọn 15 phút sau không còn thấy refresh token
 * đó, xoá client, và lần làm mới kế tiếp của Claude nhận 401 `invalid_client` — nhân sự phải kết nối
 * lại. Lệnh purge được lấy từ CHÍNH tác vụ lịch (kèm tham số), nên lịch đổi thì test này đổi theo.
 *
 * Hạn token do league tính theo đồng hồ HỆ THỐNG; `travel()` chỉ dời `now()` của Carbon (purge, dọn).
 * Với refresh token, league so `expire_time` trong chính chuỗi token với `time()` thật, nên sau khi
 * nhảy 29 ngày nó vẫn còn hạn như ngoài đời.
 */
it('R7 lịch thật: client DCR quá 30 ngày tuổi, nhân sự nghỉ vài ngày mà refresh token còn sống — purge 03:00 rồi dọn 03:15 giữ client và access token của nó, Claude quay lại làm mới được (200)', function (int $idleDays) {
    $user = User::factory()->withRole(Role::Lawyer)->create();
    $registered = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [McpOAuth::REDIRECT_URI]])->assertCreated();
    $client = Passport::client()->newQuery()->findOrFail($registered->json('client_id'));
    // Kết nối lập từ 40 ngày trước (client DCR tuổi đó); cặp token cuối vừa được cấp.
    $client->forceFill(['created_at' => now()->subDays(40)])->save();

    $tokens = McpOAuth::issueTokens($this, $user, $client);

    $this->travel($idleDays)->days();

    $this->artisan(pruneScheduledCommand('mcp.tokens.purge'))->assertSuccessful();

    expect(Passport::token()->newQuery()->where('client_id', $client->getKey())->where('revoked', false)->exists())
        ->toBeTrue('purge không được xoá dòng access token khi refresh token của nó còn sống');

    $this->artisan(pruneScheduledCommand('mcp.clients.prune'))->assertSuccessful();

    expect(pruneClientExists($client))->toBeTrue();

    // Nhân sự quay lại: Claude làm mới ở một request mới (PHP-FPM không nhớ client của request trước).
    Auth::forgetGuards();
    Once::flush();

    $this->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $client->getKey(),
        'refresh_token' => $tokens['refresh_token'],
    ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
})->with([
    'nghỉ 8 ngày (vừa quá 7 ngày purge giữ mặc định)' => [8],
    'nghỉ 10 ngày (Tết)' => [10],
    'nghỉ 29 ngày (refresh token còn 1 ngày)' => [29],
]);

it('R7 lịch thật: refresh token đã chết (31 ngày không dùng) — purge 03:00 rồi dọn 03:15 xoá client cùng access token VÀ refresh token của nó, không để dòng refresh token mồ côi', function () {
    $user = User::factory()->withRole(Role::Lawyer)->create();
    $registered = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [McpOAuth::REDIRECT_URI]])->assertCreated();
    $client = Passport::client()->newQuery()->findOrFail($registered->json('client_id'));

    McpOAuth::issueTokens($this, $user, $client);

    $accessIds = Passport::token()->newQuery()->where('client_id', $client->getKey())->pluck('id')->all();
    $refreshIds = Passport::refreshToken()->newQuery()->whereIn('access_token_id', $accessIds)->pluck('id')->all();

    expect($accessIds)->toHaveCount(1)
        ->and($refreshIds)->toHaveCount(1);

    $this->travel(31)->days();

    $this->artisan(pruneScheduledCommand('mcp.tokens.purge'))->assertSuccessful();
    $this->artisan(pruneScheduledCommand('mcp.clients.prune'))
        ->expectsOutputToContain(__('mcp.prune.done', ['count' => 1]))
        ->assertSuccessful();

    expect(pruneClientExists($client))->toBeFalse()
        ->and(Passport::token()->newQuery()->whereKey($accessIds)->exists())->toBeFalse()
        ->and(Passport::refreshToken()->newQuery()->whereKey($refreshIds)->exists())->toBeFalse();
});
