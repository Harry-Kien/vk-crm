<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\OutboundMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
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
|  2. GATED — trang không mang id, đóng với một vai: người không có quyền nhận đúng trang 404 của một
|     đường dẫn không tồn tại trong cùng panel.
|  3. OPEN — trang mọi tài khoản đã đăng nhập của panel đó đều mở (bảng tin, hồ sơ cá nhân, tìm
|     kiếm, trang thiết bị nhận thông báo, đổi mật khẩu…): không nói gì về sự tồn tại của bản ghi.
|  4. EXCEPTION — ngoại lệ có chủ đích, mỗi cái một lý do (xem `spec1010Exceptions()`): chữ ký URL
|     sai trả 403 (`routes/web.php`, nói về ĐƯỜNG DẪN chứ không về bản ghi — test riêng dưới đây
|     khẳng định 403 đó giống nhau cho id có thật và id bịa), tài nguyên tĩnh và công khai của PWA,
|     route của Livewire, `/up`, `/` chuyển hướng.
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
    ];
}

/**
 * Nhóm 3 — mở cho mọi tài khoản đã đăng nhập của panel (và trang đăng nhập/đăng xuất, cài 2FA).
 * Không trang nào ở đây đọc một bản ghi theo id trên URL.
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
        'filament.admin.pages.performance',
        'filament.admin.pages.team',
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
        'filament.portal.pages.change-password',
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
        'gated' => array_keys(spec1010GatedPages()),
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
