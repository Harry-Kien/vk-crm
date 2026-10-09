<?php

use App\Enums\AiAccessMode;
use App\Enums\CommunicationType;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Filament\Admin\Pages\MyAiConnections;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Support\Mcp\McpIds;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\McpOAuth;
use Tests\Support\McpReadWorld;
use Tests\Support\McpToolCall;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| SPEC §10.10 — lượt quét toàn hệ thống trước bản 1.0 (M8 Task 6, R5; lane v1 Task 1)
|--------------------------------------------------------------------------
|
| "Không có quyền và không tồn tại đều trả 404." Danh sách màn hình dựng từ ROUTER, không từ trí
| nhớ: test đầu tiên đọc `Route::getRoutes()` và đòi mọi route của ứng dụng (cả route Filament tự
| đăng ký, cả cổng khách, cả route M10/M12/M13) nằm ở ĐÚNG MỘT nhóm dưới đây. Một route mới mà
| không ai xếp nhóm làm test đỏ — đó là cách lượt quét này không cũ đi sau bản 1.0.
|
|  1. RECORD — route mang id của một bản ghi. Một người KHÔNG được xem bản ghi đó mở nó với id có
|     thật và với id không có: hai câu trả lời phải giống nhau từng byte (sau khi bỏ token phiên),
|     và đều là 404.
|  2. GATED — trang không mang id, đóng với một vai (cổng khách: với một loại tài khoản, ví dụ "Đổi
|     mật khẩu" chỉ mở cho tài khoản còn nợ lần đổi đầu): người không được vào nhận đúng trang 404 của
|     một đường dẫn không tồn tại trong cùng panel.
|  3. OPEN — trang mọi vai nhân sự (cổng khách: mọi tài khoản khách đã kích hoạt) của panel đó đều mở
|     (bảng tin, hồ sơ cá nhân, tìm kiếm, trang thiết bị nhận thông báo…): không nói gì về sự tồn tại
|     của bản ghi. Cả hai panel có test kiểm 200 (admin: từng vai; cổng: khách một vụ và hai vụ).
|  4. EXCEPTION — ngoại lệ có chủ đích, mỗi cái một lý do (xem `spec1010Exceptions()`): chữ ký URL
|     sai trả 403 (`routes/web.php`, nói về ĐƯỜNG DẪN chứ không về bản ghi — test riêng dưới đây
|     khẳng định 403 đó giống nhau cho id có thật và id bịa), tài nguyên tĩnh và công khai của PWA,
|     route của Livewire, `/up`, `/` chuyển hướng, và các route của máy chủ MCP (M11).
|
| M11 (gộp `main` c166ec6 vào làn, rà soát cuối vòng sửa 1, C1): không route nào của M11 mang id trên
| URI. Id của M11 nằm ở hai chỗ khác, và mỗi chỗ có phép so riêng dưới đây: tham số của tool trên
| `POST /mcp` (danh sách tool đọc từ `tools/list`, không từ trí nhớ) và đối số `client` của nút "Thu
| hồi" trên trang "Kết nối AI của tôi". Hai trang "Kết nối AI" thuộc nhóm GATED.
*/

const SPEC1010_MISSING_ID = 987654;

/**
 * Nhóm 1 — route mang id. Giá trị: tên tham số của route.
 *
 * @return array<string, string>
 */
function spec1010RecordRoutes(): array
{
    return [
        'filament.admin.resources.client-users.edit' => 'record',
        'filament.admin.resources.clients.edit' => 'record',
        'filament.admin.resources.intake-requests.convert' => 'record',
        'filament.admin.resources.intake-requests.edit' => 'record',
        'filament.admin.resources.matter-types.edit' => 'record',
        'filament.admin.resources.matters.view' => 'record',
        'filament.admin.resources.matters.edit' => 'record',
        'filament.admin.resources.outbound-messages.view' => 'record',
        'filament.admin.resources.users.edit' => 'record',
        'filament.admin.pages.team-member' => 'user',
        'filament.portal.pages.ho-so' => 'record',
        'filament.portal.pages.nop-giay-to' => 'record',
        'filament.portal.pages.yeu-cau' => 'record',
    ];
}

/**
 * Nhóm 2 — trang không mang id, đóng với ít nhất một vai. Giá trị: vai bị từ chối.
 *
 * @return array<string, Role>
 */
function spec1010GatedPages(): array
{
    return [
        'filament.admin.pages.activity-log-page' => Role::Lawyer,
        'filament.admin.pages.bulk-reassign' => Role::Lawyer,
        'filament.admin.pages.intake-report' => Role::Lawyer,
        'filament.admin.pages.office-profile' => Role::Lawyer,
        // M13 — "Hiệu suất theo kỳ": `matter.view` hoặc `performance.viewAny`, kế toán không có cả hai.
        'filament.admin.pages.performance' => Role::Accountant,
        // M13 — "Theo dõi đội ngũ": chỉ `performance.viewAny` (admin, quản lý).
        'filament.admin.pages.team' => Role::Lawyer,
        'filament.admin.pages.receivables' => Role::Assistant,
        'filament.admin.pages.revenue-dashboard' => Role::Assistant,
        'filament.admin.resources.client-users.index' => Role::Accountant,
        'filament.admin.resources.client-users.create' => Role::Accountant,
        'filament.admin.resources.clients.index' => Role::Accountant,
        'filament.admin.resources.clients.create' => Role::Accountant,
        'filament.admin.resources.intake-requests.index' => Role::Accountant,
        'filament.admin.resources.intake-requests.create' => Role::Accountant,
        'filament.admin.resources.matter-types.create' => Role::Lawyer,
        'filament.admin.resources.matters.create' => Role::Accountant,
        'filament.admin.resources.outbound-messages.index' => Role::Accountant,
        'filament.admin.resources.users.index' => Role::Lawyer,
        'filament.admin.resources.users.create' => Role::Lawyer,
        // M11 — "Kết nối AI" (công tắc, chế độ AI từng người, nhật ký MCP): chỉ `settings.manage`.
        'filament.admin.pages.ket-noi-ai' => Role::Lawyer,
        // M11 — "Kết nối AI của tôi": người giữ được quyền AI (`McpAccess::canHold()`, tức
        // `matter.view`); kế toán không có.
        'filament.admin.pages.ket-noi-ai-cua-toi' => Role::Accountant,
    ];
}

/**
 * Nhóm 2, phía cổng khách — trang không mang id, đóng với một loại tài khoản khách. Giá trị: tài
 * khoản bị từ chối. Cổng chỉ có một vai, nên cổng của trang nằm ở trạng thái tài khoản, không ở vai.
 * "Đổi mật khẩu" (`ChangePassword::canAccess()`) chỉ mở cho tài khoản còn nợ lần đổi mật khẩu đầu
 * (SPEC §8.1); trước rà soát lại Task 1 (r2) nó bị xếp nhầm vào OPEN.
 *
 * @return array<string, string>
 */
function spec1010PortalGatedPages(): array
{
    return [
        'filament.portal.pages.change-password' => 'tài khoản đã kích hoạt, không còn phải đổi mật khẩu',
    ];
}

/**
 * Nhóm 3 — mở cho mọi vai nhân sự (cổng khách: mọi tài khoản khách đã kích hoạt) của panel, cùng
 * trang đăng nhập/đăng xuất và cài 2FA. Không trang nào ở đây đọc một bản ghi theo id trên URL. Được
 * kiểm ở hai test "mở được mọi trang GET của panel admin…" (từng vai) và "…của cổng khách…".
 *
 * @return list<string>
 */
function spec1010OpenPages(): array
{
    return [
        'filament.admin.pages.dashboard',
        'filament.admin.auth.login',
        'filament.admin.auth.logout',
        'filament.admin.auth.profile',
        'filament.admin.auth.multi-factor-authentication.set-up-required',
        'filament.admin.pages.search',
        'filament.admin.pages.thong-bao-dien-thoai',
        'filament.admin.resources.matters.index',
        // Danh mục loại vụ việc: mọi nhân sự đọc được (`MatterTypePolicy::viewAny`), chỉ admin sửa.
        'filament.admin.resources.matter-types.index',
        // M12 — đăng ký/gỡ/gửi thử thiết bị CỦA CHÍNH người gọi; gỡ theo endpoint của người khác
        // và theo endpoint không có trả cùng 404 (test riêng dưới đây).
        'filament.admin.push.subscriptions.store',
        'filament.admin.push.subscriptions.destroy',
        'filament.admin.push.test',
        'filament.portal.pages.my-matters',
        'filament.portal.pages.thong-bao-dien-thoai',
        'filament.portal.auth.login',
        'filament.portal.auth.logout',
        'filament.portal.push.subscriptions.store',
        'filament.portal.push.subscriptions.destroy',
        'filament.portal.push.test',
    ];
}

/**
 * Nhóm 4 — ngoại lệ có chủ đích. Khoá là tên route, hoặc URI khi route không có tên.
 *
 * @return array<string, string> lý do
 */
function spec1010Exceptions(): array
{
    return [
        'documents.download' => '403 khi chữ ký sai hoặc hết hạn (routes/web.php); mọi từ chối khác 404 — test riêng',
        'documents.download.admin' => 'bí danh M12 của documents.download, cùng luật',
        'documents.download.portal' => 'bí danh M12 của documents.download, cùng luật',
        'filament.exports.download' => 'route của Filament; ứng dụng không có bảng exports nên mọi id đều 404 — test riêng',
        'filament.imports.failed-rows.download' => 'route của Filament; ứng dụng không có bảng imports nên mọi id đều 404 — test riêng',
        'pwa.admin.manifest' => 'tệp công khai của app (M12), không đọc bản ghi',
        'pwa.admin.offline' => 'trang ngoại tuyến công khai (M12)',
        'pwa.admin.sw' => 'service worker công khai (M12)',
        'pwa.portal.manifest' => 'tệp công khai của app (M12)',
        'pwa.portal.offline' => 'trang ngoại tuyến công khai (M12)',
        'pwa.portal.sw' => 'service worker công khai (M12)',
        'default-livewire.update' => 'request cập nhật Livewire — từ chối bên trong vòng đời component giữ 403 có chủ đích, snapshot niêm HMAC (AnswerDeniedPanelRequestsWithNotFound, DenialCodeTest)',
        'livewire.upload-file' => 'tải tệp tạm của Livewire, URL ký do component cấp',
        'livewire.preview-file' => 'xem trước tệp tạm của Livewire, URL ký',
        '/' => 'chuyển hướng tới /portal',
        'up' => 'kiểm tra sống của Laravel',
        'livewire-asset' => 'tệp JS/CSS của Livewire',
        // M11 — máy chủ MCP. Không route nào mang id trên URI.
        'mcp' => 'POST /mcp (M11): id nằm trong tham số của tool, không trên URL; vụ/yêu cầu có thật mà người gọi không được thấy và id không có trả cùng câu "Không tìm thấy" — test riêng',
        'mcp.metadata.protected-resource' => 'metadata OAuth công khai (M11), nội dung cố định dựng từ cấu hình',
        'mcp.metadata.protected-resource.mcp' => 'metadata OAuth công khai (M11), nội dung cố định dựng từ cấu hình',
        'mcp.metadata.authorization-server' => 'metadata OAuth công khai (M11), nội dung cố định dựng từ cấu hình',
        'mcp.metadata.authorization-server.mcp' => 'metadata OAuth công khai (M11), nội dung cố định dựng từ cấu hình',
        'mcp.oauth.register' => 'đăng ký client động công khai (M11, RFC 7591): tạo client, không đọc bản ghi nào',
        'passport.token' => 'đổi mã uỷ quyền / refresh token lấy access token (M11): lỗi theo RFC 6749, không đọc bản ghi của văn phòng',
        'passport.token.refresh' => 'làm mới cookie laravel_token của Passport (M11); cookie đó không mở được /mcp (OAuthRoutesStaffSessionTest)',
        'passport.authorizations.authorize' => 'màn hình đồng ý OAuth (M11): không id trên URL, client_id là định danh công khai của client; phiên nhân sự bị vô hiệu bị cắt ở đây — SessionCutSpec109Test',
        'passport.authorizations.approve' => 'nút "Đồng ý" (M11): đọc yêu cầu uỷ quyền trong phiên, không id trên URL',
        'passport.authorizations.deny' => 'nút "Từ chối" (M11): đọc yêu cầu uỷ quyền trong phiên, không id trên URL',
    ];
}

/** Khoá phân loại của một route: tên, hoặc URI khi không có tên; tài nguyên Livewire gộp một khoá. */
function spec1010Key(RoutingRoute $route): string
{
    $name = $route->getName();

    if ($name !== null) {
        return $name;
    }

    $uri = $route->uri();

    if (preg_match('#^livewire-[^/]+/(css|js|livewire\.)#', $uri) === 1) {
        return 'livewire-asset';
    }

    return $uri;
}

/** Bỏ những thứ đổi theo từng request (token CSRF, nonce CSP) trước khi so hai trang 404. */
function spec1010Normalise(TestResponse $response): string
{
    return preg_replace(
        ['/name="csrf-token" content="[^"]*"/', '/nonce="[^"]*"/', '/"csrf":"[^"]*"/', '/_token" value="[^"]*"/'],
        ['', '', '', ''],
        (string) $response->getContent(),
    );
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lead->id]);
    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);

    $this->otherClient = Client::factory()->create();
    $this->otherClientUser = ClientUser::factory()->activated()->create(['client_id' => $this->otherClient->id]);
    Matter::factory()->for($this->otherClient)->create(['lead_lawyer_id' => $this->lead->id]);

    $this->intake = IntakeRequest::factory()->create(['created_by' => $this->admin->id]);
    $this->message = OutboundMessage::factory()->create([
        'related_type' => $this->restricted->getMorphClass(),
        'related_id' => $this->restricted->id,
    ]);
});

/**
 * Ai bị từ chối, trên bản ghi có thật nào, cho từng route của nhóm 1.
 *
 * @return array{0: User|ClientUser, 1: string, 2: int}
 */
function spec1010Case(string $name): array
{
    $t = test();

    return match ($name) {
        'filament.admin.resources.client-users.edit' => [$t->accountant, 'web', $t->clientUser->id],
        'filament.admin.resources.clients.edit' => [$t->accountant, 'web', $t->client->id],
        'filament.admin.resources.intake-requests.convert' => [$t->assistant, 'web', $t->intake->id],
        'filament.admin.resources.intake-requests.edit' => [$t->assistant, 'web', $t->intake->id],
        'filament.admin.resources.matter-types.edit' => [$t->outsider, 'web', MatterType::query()->value('id')],
        'filament.admin.resources.matters.view' => [$t->outsider, 'web', $t->matter->id],
        'filament.admin.resources.matters.edit' => [$t->outsider, 'web', $t->matter->id],
        'filament.admin.resources.outbound-messages.view' => [$t->manager, 'web', $t->message->id],
        'filament.admin.resources.users.edit' => [$t->outsider, 'web', $t->lead->id],
        'filament.admin.pages.team-member' => [$t->outsider, 'web', $t->lead->id],
        'filament.portal.pages.ho-so',
        'filament.portal.pages.nop-giay-to',
        'filament.portal.pages.yeu-cau' => [$t->otherClientUser, 'client', $t->matter->id],
    };
}

it('xếp MỌI route của router vào đúng một nhóm của §10.10 (danh sách dựng từ router, không từ trí nhớ)', function () {
    $groups = [
        'record' => array_keys(spec1010RecordRoutes()),
        'gated' => [...array_keys(spec1010GatedPages()), ...array_keys(spec1010PortalGatedPages())],
        'open' => spec1010OpenPages(),
        'exception' => array_keys(spec1010Exceptions()),
    ];

    $classified = collect($groups)->flatten();
    expect($classified->duplicates()->values()->all())->toBe([], 'một route nằm ở hai nhóm');

    $routes = collect(Route::getRoutes()->getRoutes())->map(fn (RoutingRoute $route): string => spec1010Key($route))->unique()->values();

    expect($routes->diff($classified)->values()->all())->toBe([], 'route chưa được xếp nhóm §10.10')
        ->and($classified->diff($routes)->values()->all())->toBe([], 'nhóm §10.10 nhắc một route không còn tồn tại');

    // Mọi route mang tham số trong URI phải ở nhóm 1 hoặc là ngoại lệ có lý do.
    $parameterised = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_contains($route->uri(), '{'))
        ->map(fn (RoutingRoute $route): string => spec1010Key($route))
        ->unique()
        ->diff([...$groups['record'], ...$groups['exception']])
        ->values()
        ->all();

    expect($parameterised)->toBe([]);
});

it('trả cùng một trang 404 cho bản ghi có thật mà người này không được xem và cho id không tồn tại', function (string $name) {
    [$actor, $guard, $existing] = spec1010Case($name);
    $parameter = spec1010RecordRoutes()[$name];

    $this->actingAs($actor, $guard);
    $denied = $this->get(route($name, [$parameter => $existing]));

    $this->actingAs($actor, $guard);
    $missing = $this->get(route($name, [$parameter => SPEC1010_MISSING_ID]));

    expect($denied->getStatusCode())->toBe(404)
        ->and($missing->getStatusCode())->toBe(404)
        ->and(spec1010Normalise($denied))->toBe(spec1010Normalise($missing));
})->with(array_keys(spec1010RecordRoutes()));

/** Cặp dương: cùng URL đó mở được cho người có quyền — 404 ở trên không đến từ một URL hỏng. */
it('mở được chính bản ghi đó cho người có quyền', function (string $name) {
    [, $guard, $existing] = spec1010Case($name);
    $parameter = spec1010RecordRoutes()[$name];

    $allowed = $guard === 'client' ? $this->clientUser : $this->admin;

    $this->actingAs($allowed, $guard)->get(route($name, [$parameter => $existing]))->assertOk();
})->with(array_keys(spec1010RecordRoutes()));

it('trả cùng một trang 404 cho vụ restricted mà trưởng phòng không được xem và cho id không tồn tại', function () {
    $this->actingAs($this->manager, 'web');
    $denied = $this->get(route('filament.admin.resources.matters.view', ['record' => $this->restricted->id]));

    $this->actingAs($this->manager, 'web');
    $missing = $this->get(route('filament.admin.resources.matters.view', ['record' => SPEC1010_MISSING_ID]));

    expect($denied->getStatusCode())->toBe(404)
        ->and(spec1010Normalise($denied))->toBe(spec1010Normalise($missing));
});

it('trả cho người không có quyền đúng trang 404 của một đường dẫn không tồn tại trong panel', function (string $name) {
    $role = spec1010GatedPages()[$name];
    $actor = User::factory()->withRole($role)->create();

    $this->actingAs($actor, 'web');
    $denied = $this->get(route($name));

    $this->actingAs($actor, 'web');
    $unknown = $this->get('/admin/khong-co-trang-nay');

    expect($denied->getStatusCode())->toBe(404)
        ->and($unknown->getStatusCode())->toBe(404)
        ->and(spec1010Normalise($denied))->toBe(spec1010Normalise($unknown));
})->with(array_keys(spec1010GatedPages()));

it('mở được mọi trang của nhóm GATED cho quản trị viên', function (string $name) {
    $this->actingAs($this->admin, 'web')->get(route($name))->assertOk();
})->with(array_keys(spec1010GatedPages()));

it('trả cho tài khoản khách không được vào đúng trang 404 của một đường dẫn không tồn tại trong cổng', function (string $name) {
    // `spec1010PortalGatedPages()`: tài khoản bị từ chối là tài khoản đã kích hoạt, không còn nợ lần
    // đổi mật khẩu đầu — đúng `$this->clientUser`.
    expect($this->clientUser->must_change_password)->toBeFalse();

    $this->actingAs($this->clientUser, 'client');
    $denied = $this->get(route($name));

    $this->actingAs($this->clientUser, 'client');
    $unknown = $this->get('/portal/khong-co-trang-nay');

    expect($denied->getStatusCode())->toBe(404)
        ->and($unknown->getStatusCode())->toBe(404)
        ->and(spec1010Normalise($denied))->toBe(spec1010Normalise($unknown));
})->with(array_keys(spec1010PortalGatedPages()));

/** Cặp dương: tài khoản còn phải đổi mật khẩu lần đầu mở được chính trang đó. */
it('mở được trang của nhóm GATED phía cổng cho tài khoản còn phải đổi mật khẩu lần đầu', function (string $name) {
    $firstLogin = ClientUser::factory()->create(['client_id' => $this->client->id, 'must_change_password' => true]);

    $this->actingAs($firstLogin, 'client')->get(route($name))->assertOk();
})->with(array_keys(spec1010PortalGatedPages()));

/*
 * Lời khẳng định của nhóm OPEN được kiểm, không chỉ được ghi: mọi route GET của panel admin trong
 * nhóm đó mở được (200) cho TỪNG vai nhân sự. Bỏ qua hai route GET chuyển hướng người ĐÃ đăng nhập
 * (trang đăng nhập, trang buộc cài 2FA khi không bị buộc); đăng xuất và ba route thiết bị M12 không
 * phải GET nên lọc theo phương thức.
 * Một trang có cổng mà bị xếp nhầm vào OPEN — như "Hiệu suất theo kỳ" và "Theo dõi đội ngũ" trước
 * vòng sửa 1 — làm test này đỏ thay vì lọt khỏi phép so với trang 404 của nhóm GATED.
 */
it('mở được mọi trang GET của panel admin trong nhóm OPEN cho từng vai nhân sự', function (Role $role) {
    $actor = User::factory()->withRole($role)->create();

    $pages = collect(spec1010OpenPages())
        ->filter(fn (string $name): bool => str_starts_with($name, 'filament.admin.')
            && ! in_array($name, ['filament.admin.auth.login', 'filament.admin.auth.multi-factor-authentication.set-up-required'], true)
            && in_array('GET', Route::getRoutes()->getByName($name)->methods(), true))
        ->values();

    expect($pages)->not->toBeEmpty();

    $refused = $pages
        ->filter(fn (string $name): bool => $this->actingAs($actor, 'web')->get(route($name))->getStatusCode() !== 200)
        ->values()
        ->all();

    expect($refused)->toBe([], 'trang của nhóm OPEN đóng với vai '.$role->value);
})->with(Role::cases());

/*
 * Cùng lời khẳng định cho cổng khách ("mọi tài khoản khách"; rà soát lại Task 1, r2): mọi route GET
 * của cổng trong nhóm OPEN, trừ trang đăng nhập (chuyển người đã đăng nhập đi), mở được cho một khách
 * MỘT vụ và một khách HAI vụ. Khách một vụ được "Hồ sơ của tôi" chuyển thẳng sang hồ sơ của chính
 * mình, nên đi theo chuyển hướng và đòi trang cuối cùng là 200.
 */
it('mở được mọi trang GET của cổng khách trong nhóm OPEN cho tài khoản khách một vụ và hai vụ', function (string $account) {
    $actor = $account === 'một vụ' ? $this->clientUser : $this->otherClientUser;

    if ($account === 'hai vụ') {
        Matter::factory()->for($this->otherClient)->create(['lead_lawyer_id' => $this->lead->id]);
    }

    $pages = collect(spec1010OpenPages())
        ->filter(fn (string $name): bool => str_starts_with($name, 'filament.portal.')
            && $name !== 'filament.portal.auth.login'
            && in_array('GET', Route::getRoutes()->getByName($name)->methods(), true))
        ->values();

    expect($pages->all())->toContain('filament.portal.pages.my-matters', 'filament.portal.pages.thong-bao-dien-thoai');

    $refused = $pages
        ->filter(fn (string $name): bool => $this->actingAs($actor, 'client')->followingRedirects()->get(route($name))->getStatusCode() !== 200)
        ->values()
        ->all();

    expect($refused)->toBe([], 'trang của nhóm OPEN đóng với khách '.$account);
})->with(['một vụ', 'hai vụ']);

/*
 * Ngoại lệ có chủ đích: chữ ký URL sai trả 403. Nó nói về ĐƯỜNG DẪN, không về bản ghi — nên phải
 * giống nhau cho id có thật và id bịa. Còn chữ ký ĐÚNG mà người cầm không được tải: 404 như id không
 * tồn tại (cùng luật §10.10 với mọi màn hình khác).
 */
it('trả 403 như nhau cho chữ ký sai trên id có thật và id bịa, và 404 như nhau khi chữ ký đúng mà không được tải', function (string $route) {
    $document = Document::factory()->create([
        'matter_id' => $this->matter->id,
        'group' => DocumentGroup::ClientProvided,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
        'client_can_download' => true,
    ]);

    $this->actingAs($this->outsider, 'web');
    $this->get(route($route, ['document' => $document->id]))->assertForbidden();
    $this->get(route($route, ['document' => SPEC1010_MISSING_ID]))->assertForbidden();

    $signed = fn (int $id): string => URL::temporarySignedRoute($route, now()->addMinutes(5), [
        'document' => $id,
        Document::DOWNLOAD_RECIPIENT_PARAMETER => Document::recipientToken($this->outsider),
    ]);

    $denied = $this->get($signed($document->id));
    $missing = $this->get($signed(SPEC1010_MISSING_ID));

    expect($denied->getStatusCode())->toBe(404)
        ->and($missing->getStatusCode())->toBe(404)
        ->and(spec1010Normalise($denied))->toBe(spec1010Normalise($missing));
})->with(['documents.download', 'documents.download.admin', 'documents.download.portal']);

/*
 * `filament/exports/{export}/download` và `filament/imports/{import}/failed-rows/download` do
 * Filament đăng ký vô điều kiện, dù ứng dụng không dùng xuất/nhập của Filament và không có bảng
 * `exports`/`imports`. Trước lượt quét này route model binding chạm bảng không có và trả 500.
 */
it('trả 404 cho hai route xuất/nhập của Filament mà ứng dụng không dùng, với mọi id', function (string $uri) {
    $this->actingAs($this->admin, 'web')->get($uri)->assertNotFound();

    auth('web')->logout();
    $this->get($uri)->assertNotFound();
})->with([
    'xuất' => '/filament/exports/1/download',
    'nhập' => '/filament/imports/1/failed-rows/download',
]);

/** M12: gỡ thiết bị theo endpoint của NGƯỜI KHÁC và theo endpoint không có trả cùng một câu. */
it('trả cùng 404 khi gỡ thiết bị của người khác và thiết bị không tồn tại', function () {
    $keys = WebPushTestKeys::subscription();
    $endpoint = 'https://fcm.googleapis.com/fcm/send/spec1010:APA91b';
    $this->lead->updatePushSubscription($endpoint, $keys['p256dh'], $keys['auth'], 'aes128gcm');

    $this->actingAs($this->outsider, 'web');
    $other = $this->deleteJson('/admin/push/subscriptions', ['endpoint' => $endpoint]);

    $this->actingAs($this->outsider, 'web');
    $missing = $this->deleteJson('/admin/push/subscriptions', ['endpoint' => $endpoint.'-khong-co']);

    expect($other->getStatusCode())->toBe(404)
        ->and($missing->getStatusCode())->toBe(404)
        // Thân JSON ở môi trường test mang dấu vết ngăn xếp (APP_DEBUG): so câu trả lời, không so dòng.
        ->and($other->json('message'))->toBe($missing->json('message'));
});

/*
 * M11 — id trên `POST /mcp` nằm trong tham số của tool. Danh sách tool đọc từ `tools/list` THẬT
 * (người gọi đọc-ghi, hai công tắc bật, nên có cả bốn tool ghi), không từ trí nhớ: mọi tool có một
 * tham số đầu vào là id (`id` hoặc `…_id`) phải có mặt trong `spec1010McpIdTools()` — một tool mới
 * nhận id mà không ai xếp làm test đỏ, như route mới ở test đầu tệp.
 *
 * Với từng tool: vụ (hay yêu cầu của vụ) có thật mà người gọi không được thấy qua MCP — vụ đội khác,
 * vụ hạn chế do CHÍNH người gọi phụ trách, vụ chưa bật AI — và id không tồn tại cho cùng một
 * `result`, đúng câu "Không tìm thấy". Cặp dương: cùng tham số với vụ của chính người gọi không ra
 * câu đó (tham số hợp lệ, nên câu "Không tìm thấy" ở trên đến từ phạm vi, không từ bước kiểm tham
 * số). Mỗi tool lùi đồng hồ của ứng dụng thêm một phút (`travel`) để giới hạn tần suất của tool ghi
 * (10 lần một phút) không xen vào phép so; token vẫn còn hạn (bộ kiểm token đọc đồng hồ hệ thống).
 */

/**
 * Tool nhận id → [tên tham số, loại id, các tham số khác cho một lần gọi hợp lệ].
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function spec1010McpIdTools(): array
{
    return [
        'fetch' => ['id', McpIds::MATTER, []],
        'get_matter' => ['id', McpIds::MATTER, []],
        'list_matter_updates' => ['matter_id', McpIds::MATTER, []],
        'list_deadlines' => ['matter_id', McpIds::MATTER, []],
        'get_checklist' => ['matter_id', McpIds::MATTER, []],
        'list_documents' => ['matter_id', McpIds::MATTER, []],
        'list_client_requests' => ['matter_id', McpIds::MATTER, []],
        'get_client_request' => ['id', McpIds::REQUEST, []],
        'create_deadline' => ['matter_id', McpIds::MATTER, ['name' => 'Nộp bản tự khai', 'due_date' => today()->addDays(60)->toDateString()]],
        'log_communication' => ['matter_id', McpIds::MATTER, ['type' => CommunicationType::Meeting->value, 'occurred_at' => now()->subDay()->toIso8601String(), 'summary' => 'Gặp khách trao đổi hồ sơ']],
        'draft_progress_update' => ['matter_id', McpIds::MATTER, ['public_content' => 'Văn phòng đã nộp hồ sơ.', 'idempotency_key' => 'spec1010-progress-01']],
        'draft_request_reply' => ['request_id', McpIds::REQUEST, ['content' => 'Văn phòng đã nhận yêu cầu.', 'idempotency_key' => 'spec1010-reply-0001']],
    ];
}

it('M11 /mcp: mọi tool nhận id trả cùng câu "Không tìm thấy" cho vụ có thật mà người gọi không được thấy và cho id không tồn tại (danh sách tool từ tools/list)', function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer(write: true);
    $world = McpReadWorld::build();
    $world->lead->forceFill(['ai_access' => AiAccessMode::ReadWrite])->save();
    $token = McpOAuth::accessToken($this, $world->lead);

    $withId = collect(McpToolCall::listTools($this, $token)->assertOk()->json('result.tools'))
        ->filter(fn (array $tool): bool => collect(array_keys($tool['inputSchema']['properties'] ?? []))
            ->contains(fn (string $property): bool => $property === 'id' || str_ends_with($property, '_id')))
        ->pluck('name')
        ->sort()->values()->all();

    expect($withId)->toBe(collect(array_keys(spec1010McpIdTools()))->sort()->values()->all(), 'tool nhận id chưa được xếp vào spec1010McpIdTools()')
        ->and($withId)->toContain('create_deadline', 'draft_request_reply');

    $notFound = [['type' => 'text', 'text' => __('mcp.tool_errors.not_found')]];

    foreach (spec1010McpIdTools() as $tool => [$parameter, $kind, $rest]) {
        $this->travel(61)->seconds();

        $record = fn (Matter $matter): int => $kind === McpIds::REQUEST
            ? ClientRequest::factory()->create(['matter_id' => $matter->id])->id
            : $matter->id;
        $call = fn (int $id): array => McpToolCall::call($this, $token, $tool, [...$rest, $parameter => McpIds::encode($kind, $id)])
            ->assertOk()
            ->json('result');

        $missing = $call(SPEC1010_MISSING_ID);

        expect($missing['content'])->toBe($notFound, $tool)
            ->and($missing['isError'])->toBeTrue($tool);

        foreach ($world->hiddenFromLead() as $case => $matter) {
            expect($call($record($matter)))->toBe($missing, "{$tool}: {$case}");
        }

        // Cặp dương: vụ của chính người gọi, cùng tham số.
        expect($call($record($world->matter))['content'] ?? null)->not->toBe($notFound, "{$tool}: vụ của chính mình");
    }
});

/*
 * M11 — nút "Thu hồi" của trang "Kết nối AI của tôi" mang đối số `client` (`oauth_clients.id`). Thu
 * hồi bằng id của một client mà NGƯỜI KHÁC đang kết nối và bằng id không tồn tại: cùng thông báo,
 * không token nào của người kia bị chạm.
 */
it('M11 "Kết nối AI của tôi": thu hồi theo client của người khác và theo client không tồn tại cho cùng câu trả lời, không thu hồi gì của người kia', function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer();
    Filament::setCurrentPanel('admin');

    $owner = User::factory()->withRole(Role::Lawyer)->withAiAccess()->create();
    $other = User::factory()->withRole(Role::Lawyer)->withAiAccess()->create();
    $client = McpOAuth::client();
    McpOAuth::accessToken($this, $owner, $client);

    $live = fn (): int => DB::table('oauth_access_tokens')->where('user_id', $owner->id)->where('revoked', false)->count();
    expect($live())->toBe(1);

    foreach ([(string) $client->getKey(), (string) Str::uuid()] as $clientId) {
        Livewire::actingAs($other, 'web')
            ->test(MyAiConnections::class)
            ->callAction('revokeConnection', arguments: ['client' => $clientId])
            ->assertNotified(__('ai_connections.actions.nothing_revoked'));
    }

    expect($live())->toBe(1);

    // Cặp dương: chính người kết nối thu hồi được, và nhận câu khác.
    Livewire::actingAs($owner, 'web')
        ->test(MyAiConnections::class)
        ->callAction('revokeConnection', arguments: ['client' => (string) $client->getKey()])
        ->assertNotified(__('ai_connections.actions.revoked'));

    expect($live())->toBe(0);
});
