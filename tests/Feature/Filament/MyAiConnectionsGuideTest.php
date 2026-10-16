<?php

use App\Enums\AiAccessMode;
use App\Enums\MyAiConnectionStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\MyAiConnections;
use App\Models\AiAcknowledgement;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mcp\McpEndpoint;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Laravel\Passport\Passport;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| Làn ai (2026-10-15) — trang "Kết nối AI của tôi" thành hướng dẫn từng bước
|--------------------------------------------------------------------------
| Một dòng trạng thái lớn (chưa được bật / máy chủ tắt / chưa cam kết / chưa kết nối / đã kết nối),
| bốn bước có dấu ✓ lấy từ trạng thái thật, nút sao chép địa chỉ máy chủ, thẻ theo ứng dụng (Claude,
| ChatGPT, ứng dụng khác) với các bước lấy từ `docs/KET-NOI-AI.md` — không bịa giao diện của bên thứ
| ba. Luật của M11 (cổng `matter.view`, ô cam kết, tự thu hồi) đo ở `MyAiConnectionsTest`.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    McpOAuth::openServer();
});

afterEach(function () {
    Livewire::flushState();
});

function myguideLawyer(AiAccessMode $mode = AiAccessMode::Read): User
{
    return User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->withAiAccess($mode)->create();
}

function myguideHtml(User $user): string
{
    test()->actingAs($user, 'web');

    return Livewire::test(MyAiConnections::class)->html();
}

/** @return array<string, bool> tên bước → đã xong */
function myguideSteps(string $html): array
{
    preg_match_all('/<li[^>]*data-my-ai-step="([a-z_]+)"[^>]*data-done="([01])"/', $html, $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn (array $m): array => [$m[1] => $m[2] === '1'])->all();
}

it('dòng trạng thái theo từng tình huống thật của chính người đang xem', function (Closure $arrange, MyAiConnectionStatus $expected) {
    $user = $arrange($this);

    $html = myguideHtml($user);

    expect($html)->toContain('data-my-ai-status="'.$expected->value.'"')
        ->and($html)->toContain(e($expected->label()));

    foreach (MyAiConnectionStatus::cases() as $other) {
        if ($other !== $expected) {
            expect($html)->not->toContain('data-my-ai-status="'.$other->value.'"');
        }
    }
})->with([
    'chưa được bật' => [fn () => myguideLawyer(AiAccessMode::Off), MyAiConnectionStatus::NotEnabled],
    'máy chủ tắt' => [function () {
        McpOAuth::openServer(false);

        return myguideLawyer();
    }, MyAiConnectionStatus::ServerOff],
    'chưa cam kết' => [function () {
        $user = myguideLawyer();
        AiAcknowledgement::query()->where('user_id', $user->getKey())->delete();

        return $user;
    }, MyAiConnectionStatus::NotAcknowledged],
    'chưa kết nối' => [fn () => myguideLawyer(), MyAiConnectionStatus::NotConnected],
    'đã kết nối' => [function ($test) {
        $user = myguideLawyer();
        McpOAuth::accessToken($test, $user);

        return $user;
    }, MyAiConnectionStatus::Connected],
]);

it('kết nối đã thu hồi không còn tính là "đã kết nối"', function () {
    $user = myguideLawyer();
    McpOAuth::accessToken($this, $user);

    Passport::token()->newQuery()->update(['revoked' => true]);
    Passport::refreshToken()->newQuery()->update(['revoked' => true]);

    expect(myguideHtml($user))->toContain('data-my-ai-status="'.MyAiConnectionStatus::NotConnected->value.'"');
});

it('bốn bước theo thứ tự; bước cam kết ✓ khi đã cam kết đúng phiên bản, bước cuối ✓ khi có kết nối', function () {
    $user = myguideLawyer();
    AiAcknowledgement::query()->where('user_id', $user->getKey())->delete();

    expect(myguideSteps(myguideHtml($user)))->toBe(['policy' => false, 'url' => false, 'app' => false, 'consent' => false]);

    AiAcknowledgement::factory()->for($user)->create();

    expect(myguideSteps(myguideHtml($user)))->toBe(['policy' => true, 'url' => false, 'app' => false, 'consent' => false]);

    McpOAuth::accessToken($this, $user);

    expect(myguideSteps(myguideHtml($user)))->toBe(['policy' => true, 'url' => true, 'app' => true, 'consent' => true]);
});

it('cam kết của phiên bản cũ: bước cam kết về ○', function () {
    $user = myguideLawyer();
    config(['vkcrm.mcp.policy_version' => '2099-01-01']);

    expect(myguideSteps(myguideHtml($user))['policy'])->toBeFalse();
});

it('bước 2 có nút sao chép đúng địa chỉ máy chủ, và địa chỉ vẫn chọn được bằng tay', function () {
    $html = myguideHtml(myguideLawyer());

    preg_match('/<button[^>]*data-ai-copy-url[^>]*>/s', $html, $button);

    expect($button[0] ?? '')->toContain('data-url="'.e(McpEndpoint::resource()).'"')
        ->and($button[0] ?? '')->toContain('navigator.clipboard.writeText')
        ->and($html)->toContain(e(__('ai_connections.guide.copy')))
        ->and($html)->toContain(e(McpEndpoint::resource()));
});

it('bước 3 chia thẻ Claude / ChatGPT / ứng dụng khác; mở sẵn thẻ Claude, mỗi lần chỉ vẽ thẻ đang mở', function () {
    $this->actingAs(myguideLawyer(), 'web');
    $page = Livewire::test(MyAiConnections::class);
    $html = $page->html();

    foreach (MyAiConnections::APP_TABS as $tab) {
        expect($html)->toContain('data-ai-app-tab="'.$tab.'"')
            ->and($html)->toContain(e(__('ai_connections.guide.tabs.'.$tab)));
    }

    foreach (__('ai_connections.guide.claude.steps') as $step) {
        expect($html)->toContain(e($step));
    }

    expect($html)->toContain('data-ai-app-panel="claude"')
        ->and($html)->not->toContain(e(__('ai_connections.guide.chatgpt.steps')[1]))
        ->and($html)->not->toContain(e(__('ai_connections.guide.chatgpt.write_note')));

    $html = $page->call('showApp', 'chatgpt')->html();

    foreach (__('ai_connections.guide.chatgpt.steps') as $step) {
        expect($html)->toContain(e($step));
    }

    expect($html)->toContain(e(__('ai_connections.guide.chatgpt.write_note')))
        ->and($html)->not->toContain(e(__('ai_connections.guide.claude.steps')[0]));

    expect($page->call('showApp', 'other')->html())->toContain(e(__('ai_connections.guide.other')));
});

it('thẻ ứng dụng: giá trị lạ bị bỏ qua, thuộc tính không đặt được từ trình duyệt', function () {
    $this->actingAs(myguideLawyer(), 'web');
    $page = Livewire::test(MyAiConnections::class)->call('showApp', 'gemini');

    expect($page->get('appTab'))->toBe('claude');

    expect(fn () => $page->set('appTab', 'chatgpt'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('nút đổi thẻ tự hỏi lại cổng, kể cả khi vòng đời Livewire bị bỏ qua', function () {
    $this->actingAs(User::factory()->withRole(Role::Accountant)->create(), 'web');

    expect(fn () => (new MyAiConnections)->showApp('chatgpt'))
        ->toThrow(NotFoundHttpException::class);
});

it('các bước trong ứng dụng AI không bịa giao diện: mọi đường menu trích ở thẻ đều có nguyên văn trong docs/KET-NOI-AI.md', function () {
    $guide = (string) file_get_contents(base_path('docs/KET-NOI-AI.md'));
    $menus = [];

    foreach ([...__('ai_connections.guide.claude.steps'), ...__('ai_connections.guide.chatgpt.steps')] as $step) {
        preg_match_all('/«([^»]+)»/u', $step, $matches);
        array_push($menus, ...$matches[1]);
    }

    expect($menus)->toContain('Customize → Connectors → Add custom connector')
        ->toContain('Settings → Security and login → Developer mode')
        ->toContain('chatgpt.com/plugins');

    foreach ($menus as $menu) {
        expect($guide)->toContain($menu);
    }
});

it('ghi chú ChatGPT nói đúng điều tài liệu nói: ghi được chỉ với Business, Enterprise, Edu; Pro chỉ đọc', function () {
    $guide = (string) file_get_contents(base_path('docs/KET-NOI-AI.md'));
    $note = __('ai_connections.guide.chatgpt.write_note');

    expect($note)->toContain('Business, Enterprise, Edu')->toContain('Pro')
        ->and($guide)->toContain('**Business, Enterprise, Edu**')
        ->and($guide)->toContain('**Pro chỉ đọc**');
});

it('đã kết nối: danh sách kết nối có lần dùng cuối dạng "… trước" và nút thu hồi', function () {
    $this->travelTo(now()->setDateTime(2026, 10, 15, 8, 0));
    $user = myguideLawyer();
    $client = McpOAuth::client();
    McpOAuth::accessToken($this, $user, $client);
    Audit::record('mcp_tool_called', null, [
        'channel' => 'mcp', 'tool' => 'search', 'outcome' => 'ok', 'oauth_client_id' => (string) $client->getKey(),
    ], causer: $user);

    $this->travelTo(now()->setDateTime(2026, 10, 15, 11, 0));

    $html = myguideHtml($user);

    expect($html)->toContain('3 giờ trước')
        ->and($html)->toContain('15/10/2026 08:00')
        ->and($html)->toContain(e(__('ai_connections.actions.revoke')));
});

it('ma trận vai: trang mở cho đúng các vai có matter.view, 404 cho các vai còn lại (kể cả Nhân viên kinh doanh)', function (Role $role) {
    $user = User::factory()->withRole($role)->create();
    $holds = in_array(Permission::MatterView, $role->permissions(), true);

    $response = $this->actingAs($user, 'web')->get(MyAiConnections::getUrl(panel: 'admin'));

    $holds
        ? $response->assertOk()->assertSee(__('ai_connections.guide.steps_heading'))
        : $response->assertNotFound()->assertDontSee(__('ai_connections.guide.steps_heading'));
})->with(Role::cases());
