<?php

use App\Enums\AiAccessMode;
use App\Enums\McpPlatform;
use App\Enums\Role;
use App\Filament\Admin\Pages\AiConnections;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| M11 Task 17 — nhật ký lần LÀM MỚI kết nối AI (R8 "ghi cả kết nối, làm mới token, thu hồi, bật/tắt")
|--------------------------------------------------------------------------
| Rà soát Task 8 (m5) để lại cho Task 17 quyết: đồng ý, thu hồi, bật/tắt đã có dòng nhật ký, lần làm
| mới token ở `/oauth/token` (grant `refresh_token`) thì chưa. Mỗi lần làm mới THÀNH CÔNG của một client
| MCP sinh đúng một dòng `mcp_token_refreshed`: chủ thể và causer là người sở hữu token (truyền tường
| minh), `channel = mcp`, client OAuth, nền tảng suy từ host redirect — không token, không mã nào.
| Đo qua HTTP thật với token Passport thật; hiển thị đo qua Livewire trên trang "Kết nối AI".
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    McpOAuth::openServer();

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->withAiAccess(AiAccessMode::Read)->create(['name' => 'Luật Sư Làm Mới']);
    $this->client = McpOAuth::client();
    $this->tokens = McpOAuth::issueTokens($this, $this->lawyer, $this->client);
});

function trRefresh(string $clientId, string $refreshToken): TestResponse
{
    Auth::forgetGuards();
    Once::flush();

    return test()->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'refresh_token' => $refreshToken,
    ]);
}

it('một lần làm mới thành công của client MCP ghi đúng một dòng mcp_token_refreshed mang người sở hữu token, client và nền tảng — không token nào', function () {
    // Cặp âm trước: đổi mã lấy token lần đầu (beforeEach) KHÔNG phải một lần làm mới — dòng của nó là
    // `mcp_connection_authorized` của màn hình đồng ý.
    expect(Activity::query()->where('event', 'mcp_token_refreshed')->count())->toBe(0);

    $response = trRefresh($this->client->getKey(), $this->tokens['refresh_token'])->assertOk();

    $row = Activity::query()->where('event', 'mcp_token_refreshed')->sole();

    expect($row->causer_type)->toBe($this->lawyer->getMorphClass())
        ->and((int) $row->causer_id)->toBe($this->lawyer->id)
        ->and($row->subject_type)->toBe($this->lawyer->getMorphClass())
        ->and((int) $row->subject_id)->toBe($this->lawyer->id)
        ->and($row->properties->toArray())->toBe([
            'channel' => 'mcp',
            'oauth_client_id' => (string) $this->client->getKey(),
            'platform' => McpPlatform::Claude->value,
        ]);

    // Không token, không mã làm mới nào nằm trong dòng nhật ký (cũ hay mới).
    $logged = json_encode($row->getAttributes());

    foreach ([$this->tokens['access_token'], $this->tokens['refresh_token'], $response->json('access_token'), $response->json('refresh_token')] as $secret) {
        expect(str_contains((string) $logged, substr((string) $secret, 0, 40)))->toBeFalse();
    }

    // Lần làm mới thứ hai bằng mã mới: thêm đúng một dòng nữa.
    trRefresh($this->client->getKey(), $response->json('refresh_token'))->assertOk();

    expect(Activity::query()->where('event', 'mcp_token_refreshed')->count())->toBe(2);
});

it('lần làm mới bị từ chối (mã làm mới cũ dùng lại, client sai) không ghi dòng nào', function () {
    trRefresh($this->client->getKey(), $this->tokens['refresh_token'])->assertOk();

    // Mã cũ đã xoay vòng: invalid_grant, không dòng mới.
    trRefresh($this->client->getKey(), $this->tokens['refresh_token'])->assertStatus(400);
    // Mã của client này gửi dưới client khác.
    trRefresh(McpOAuth::client()->getKey(), $this->tokens['refresh_token'])->assertStatus(400);

    expect(Activity::query()->where('event', 'mcp_token_refreshed')->count())->toBe(1);
});

it('client OAuth không mang cờ mcp làm mới token: không phải một kết nối AI, không ghi dòng nào (cặp của test đầu)', function () {
    Client::query()->whereKey($this->client->getKey())->update(['is_mcp' => false]);

    trRefresh($this->client->getKey(), $this->tokens['refresh_token'])->assertOk();

    expect(Activity::query()->where('event', 'mcp_token_refreshed')->count())->toBe(0);
});

it('trang "Kết nối AI" hiện lần làm mới trong khối Nhật ký MCP, với nhãn tiếng Việt, khi lọc theo người đó', function () {
    trRefresh($this->client->getKey(), $this->tokens['refresh_token'])->assertOk();

    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->admin()->create(), 'web');

    expect(__('activity.events.mcp_token_refreshed'))->not->toBe('activity.events.mcp_token_refreshed');

    Livewire::test(AiConnections::class)
        ->set('auditFilters.user', $this->lawyer->getKey())
        ->assertSee(__('activity.events.mcp_token_refreshed'))
        ->assertSee('Luật Sư Làm Mới');

    Livewire::flushState();
});
