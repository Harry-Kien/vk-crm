<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\ClientUsers\ClientUserResource;
use App\Filament\Admin\Resources\MatterTypes\MatterTypeResource;
use App\Http\Middleware\AnswerDeniedPanelRequestsWithNotFound;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/**
 * SPEC §10.10: "Không có quyền và không tồn tại đều trả 404." Mã M3 đã theo luật này ở mọi chỗ
 * viết tay, nhưng Filament tự `abort(403)` khi `canAccess()` sai — và trên trang có `{record}`
 * trong URL, Filament GIẢI BẢN GHI TRƯỚC rồi mới hỏi `canAccess()`
 * (`InteractsWithRecord::mountCanAuthorizeAccess()` gọi `getRecord()` ở dòng đầu). Hệ quả: cặp
 * (403, 404) trở thành một máy hỏi "bản ghi này có tồn tại không" cho đúng những người không
 * được biết điều đó — kế toán, người SPEC §5 không cấp quyền nào về khách hàng.
 *
 * Quyết định của task này: một request panel bị từ chối ở tầng route trả 404, một kiểu duy
 * nhất. Lập luận "đừng làm người dùng bối rối" thua ở đây vì mục điều hướng vốn đã bị ẩn với
 * chính những người này, nên họ chỉ tới được URL bằng cách gõ tay hoặc theo một đường dẫn cũ —
 * và thứ họ nhận hôm nay là một trang 403 TIẾNG ANH của Filament, khó hiểu hơn hẳn một trang
 * 404 của ứng dụng.
 *
 * "Ở tầng route" là một giới hạn thật, không phải cách nói: một lần từ chối phát sinh bên trong
 * vòng đời component Livewire vẫn là 403, cố ý — xem test cuối cùng và docblock của middleware.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $this->client = Client::factory()->create();
    Matter::factory()->for($this->client)->create();
});

it('answers a refused panel page with 404, never 403', function () {
    $this->actingAs($this->accountant, 'web');

    $this->get(ClientResource::getUrl('index', panel: 'admin'))->assertNotFound();
    $this->get(ClientResource::getUrl('create', panel: 'admin'))->assertNotFound();
    $this->get(ClientUserResource::getUrl('index', panel: 'admin'))->assertNotFound();
    $this->get(ActivityLogPage::getUrl(panel: 'admin'))->assertNotFound();

    $this->actingAs($this->lawyer, 'web');

    $this->get(MatterTypeResource::getUrl('create', panel: 'admin'))->assertNotFound();
});

/**
 * Cái mà SPEC §10.10 thật sự cấm: hai mã khác nhau cho hai câu trả lời khác nhau về sự tồn tại.
 * Kế toán có `matter.viewAny` nên `ClientResource::getEloquentQuery()` GIẢI ĐƯỢC bản ghi cho
 * họ; chỉ `canAccess()` chặn lại. Trước khi sửa, bản ghi có thật trả 403 còn id bịa trả 404.
 */
it('gives the same answer for a record that exists and one that does not', function () {
    $this->actingAs($this->accountant, 'web');

    $existing = $this->get(ClientResource::getUrl('edit', ['record' => $this->client], panel: 'admin'));
    $missing = $this->get(ClientResource::getUrl('edit', ['record' => 999999], panel: 'admin'));

    expect($existing->getStatusCode())->toBe($missing->getStatusCode())
        ->and($existing->getStatusCode())->toBe(404);
});

/** Cặp dương: cùng những URL đó vẫn mở bình thường cho người có quyền. */
it('still opens every one of those pages for someone allowed to see them', function () {
    $this->actingAs($this->admin, 'web');

    $this->get(ClientResource::getUrl('index', panel: 'admin'))->assertOk();
    $this->get(ClientResource::getUrl('create', panel: 'admin'))->assertOk();
    $this->get(ClientResource::getUrl('edit', ['record' => $this->client], panel: 'admin'))->assertOk();
    $this->get(ClientUserResource::getUrl('index', panel: 'admin'))->assertOk();
    $this->get(ActivityLogPage::getUrl(panel: 'admin'))->assertOk();
    $this->get(MatterTypeResource::getUrl('create', panel: 'admin'))->assertOk();
});

/**
 * Đổi một response 403 thành ngoại lệ 404 là vứt cả response đó đi, kèm mọi header của nó.
 * Middleware này nằm NGOÀI `StartSession` và `EncryptCookies`, nên cái bị vứt gồm cả
 * `Set-Cookie` của phiên vừa được tạo (đã mã hoá xong xuôi ở vòng ra). Chỉ cắn khi request ĐẦU
 * TIÊN của một phiên mới đã là một lần từ chối — người dùng nhận 404 mà không nhận cookie, nên
 * request sau lại là một phiên mới nữa, và token CSRF không bao giờ bám được.
 */
it('still hands back the session cookie when the first request of a session is refused', function () {
    $this->actingAs($this->accountant, 'web');

    $response = $this->get(ClientResource::getUrl('index', panel: 'admin'));

    $response->assertNotFound();
    expect(collect($response->headers->getCookies())->map(fn ($cookie) => $cookie->getName()))
        ->toContain(config('session.cookie'));
});

/** Cùng luật cho cả hai panel: khách gõ /admin, nhân sự gõ /portal, đều là 404. */
it('applies the same rule to whoever knocks on the wrong panel', function () {
    $clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);

    $this->actingAs($clientUser, 'web')->get('/admin')->assertNotFound();
    $this->actingAs($this->admin, 'client')->get('/portal')->assertNotFound();
});

/**
 * Năm test trên đi bằng HTTP nên chỉ phủ request tải trang. Ba test hành vi dưới đây đi bằng
 * request `/livewire/update` THẬT (test thứ tư, cuối tệp, chỉ ghim cấu hình): render trang bằng
 * một tài khoản có quyền, nhấc `wire:snapshot` của đúng component ra khỏi HTML, rồi POST lại
 * dưới một tài khoản khác.
 * Dựng được là nhờ checksum của Livewire là HMAC theo `APP_KEY` và KHÔNG gắn với phiên
 * (`Livewire\Mechanisms\HandleComponents\Checksum::generate`), nên snapshot của phiên này vẫn
 * hợp lệ ở phiên khác.
 *
 * MỖI TRƯỜNG HỢP PHẢI LÀ MỘT `it()` RIÊNG. `PersistentMiddleware::applyPersistentMiddleware()`
 * ghi nhớ đã chạy cho `"{method}|{path}"` nào trong `middlewareAppliedFor`, và bộ nhớ đó chỉ
 * được xoá ở sự kiện `flush-state` cuối request thật. Gộp hai POST cùng đường dẫn vào một test
 * thì POST thứ hai KHÔNG chạy middleware bền nào cả và trả 200 — đã đo được đúng như vậy khi
 * viết mấy test này, và nó là một xanh giả hoàn hảo.
 */
function panelPageSnapshot(string $html, string $component): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
            return $snapshot;
        }
    }

    throw new RuntimeException("Không có snapshot Livewire của [{$component}] trong HTML đã render.");
}

/** `Livewire::getUpdateUri()` vì đường dẫn thật mang tiền tố băm, không phải `/livewire/update`. */
function postPanelLivewireUpdate(string $snapshot): TestResponse
{
    return test()->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
    ]);
}

/** Snapshot của trang sửa khách hàng, lấy dưới một tài khoản quản trị thật sự mở được nó. */
function editClientSnapshot(Client $client): string
{
    return panelPageSnapshot(
        test()->get(ClientResource::getUrl('edit', ['record' => $client], panel: 'admin'))
            ->assertOk()
            ->getContent(),
        EditClient::class,
    );
}

/**
 * Cái mà `isPersistent: true` thật sự mua được. `Filament\Http\Middleware\Authenticate` tự nó
 * đã là middleware bền (Filament đăng ký sẵn ở `FilamentServiceProvider`), nên phiên của một
 * tài khoản vừa bị vô hiệu hoá bị chặn ngay ở request cập nhật kế tiếp dù ta có làm gì hay
 * không — SPEC §10.9 được giữ bởi Filament, không bởi middleware này. Thứ middleware này thêm
 * vào là HÌNH DẠNG câu trả lời của SPEC §10.10: nó đứng TRƯỚC `Authenticate` trong cùng đường
 * ống bền nên đổi cái 403 đó thành 404.
 *
 * Đã đo bằng mutation: bỏ `isPersistent` ở CẢ HAI panel thì test này đỏ với "Expected response
 * status code [404] but received 403" — 403, không phải 200, và không lần nào rò dữ liệu khách
 * hàng. Dòng `leaks` dưới đây vì vậy là lưới chắn cho một hồi quy nặng hơn, không phải mô tả
 * hành vi trước khi sửa.
 */
it('answers a livewire update from a deactivated account with 404', function () {
    $this->actingAs($this->admin, 'web');
    $snapshot = editClientSnapshot($this->client);

    $this->admin->update(['is_active' => false]);
    $this->actingAs($this->admin->fresh(), 'web');

    $response = postPanelLivewireUpdate($snapshot);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain($this->client->name);
});

/** Cùng luật cho request cập nhật: khách cầm snapshot của /admin cũng chỉ nhận 404. */
it('answers a livewire update from the wrong panel with 404', function () {
    $this->actingAs($this->admin, 'web');
    $snapshot = editClientSnapshot($this->client);

    $this->actingAs(ClientUser::factory()->create(['client_id' => $this->client->id]), 'web');

    $response = postPanelLivewireUpdate($snapshot);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain($this->client->name);
});

/**
 * RANH GIỚI, ghim cố ý chứ không phải một điều còn thiếu. Một lần từ chối phát sinh BÊN TRONG
 * vòng đời component — `hydrateCanAuthorizeAccess()` của Filament — vẫn là 403, và middleware
 * KHÔNG THỂ với tới nó: Livewire chạy middleware bền qua `Utils::applyMiddleware()`, mà đích
 * của đường ống đó là `fn () => new Response()`, tức một 200 mới tinh; khung của middleware đã
 * kết thúc trước khi component được hydrate.
 *
 * Không đuổi theo, vì hai lý do:
 *
 * - Ở đây cặp (403, 404) KHÔNG phải máy dò sự tồn tại như trên trang: `$record` là `#[Locked]`
 *   và snapshot được niêm bằng HMAC `APP_KEY`, nên muốn hỏi về một bản ghi thì phải có snapshot
 *   của chính trang bản ghi đó — mà muốn render được trang đó thì đã phải qua cổng, và người
 *   không qua cổng nhận 404 ngay ở bước ấy.
 * - Muốn phủ nốt thì phải gắn middleware lên chính route cập nhật của Livewire, và như vậy sẽ
 *   nuốt luôn hai `abort(403)` của `SchemasServiceProvider` — chúng nói về một LỜI GỌI PHƯƠNG
 *   THỨC chứ không về sự tồn tại của bản ghi, trong đó có cổng chặn tải tệp mà các ô upload của
 *   M4 sẽ nằm sau. Ở đó 403 là một tín hiệu lạm dụng đáng giữ nguyên.
 */
it('leaves a denial raised inside a livewire component at 403, by design', function () {
    $this->actingAs($this->admin, 'web');
    $snapshot = editClientSnapshot($this->client);

    $this->actingAs($this->accountant, 'web');

    postPanelLivewireUpdate($snapshot)->assertForbidden();
});

/**
 * Ghim cấu hình, bổ sung cho ba test hành vi ở trên chứ không thay chúng.
 *
 * Giới hạn đã biết: `Livewire::getPersistentMiddleware()` là danh sách CHUNG và được khử trùng
 * lặp, nên dòng cuối — và cả hai test hành vi 404 ở trên — chỉ đỏ khi CẢ HAI panel bỏ
 * `isPersistent`. Hai dòng đầu thì đỏ riêng theo từng panel.
 */
it('registers the middleware on both panels and keeps it persistent', function () {
    expect(Filament::getPanel('admin')->getMiddleware())
        ->toContain(AnswerDeniedPanelRequestsWithNotFound::class)
        ->and(Filament::getPanel('portal')->getMiddleware())
        ->toContain(AnswerDeniedPanelRequestsWithNotFound::class)
        ->and(Livewire::getPersistentMiddleware())
        ->toContain(AnswerDeniedPanelRequestsWithNotFound::class);
});
