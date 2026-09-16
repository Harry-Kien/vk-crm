<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\ClientUsers\ClientUserResource;
use App\Filament\Admin\Resources\MatterTypes\MatterTypeResource;
use App\Http\Middleware\AnswerDeniedPanelRequestsWithNotFound;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * SPEC §10.10: "Không có quyền và không tồn tại đều trả 404." Mã M3 đã theo luật này ở mọi chỗ
 * viết tay, nhưng Filament tự `abort(403)` khi `canAccess()` sai — và trên trang có `{record}`
 * trong URL, Filament GIẢI BẢN GHI TRƯỚC rồi mới hỏi `canAccess()`
 * (`InteractsWithRecord::mountCanAuthorizeAccess()` gọi `getRecord()` ở dòng đầu). Hệ quả: cặp
 * (403, 404) trở thành một máy hỏi "bản ghi này có tồn tại không" cho đúng những người không
 * được biết điều đó — kế toán, người SPEC §5 không cấp quyền nào về khách hàng.
 *
 * Quyết định của task này: panel từ chối bằng 404, một kiểu duy nhất. Lập luận "đừng làm người
 * dùng bối rối" thua ở đây vì mục điều hướng vốn đã bị ẩn với chính những người này, nên họ chỉ
 * tới được URL bằng cách gõ tay hoặc theo một đường dẫn cũ — và thứ họ nhận hôm nay là một
 * trang 403 TIẾNG ANH của Filament, khó hiểu hơn hẳn một trang 404 của ứng dụng.
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

/** Cùng luật cho cả hai panel: khách gõ /admin, nhân sự gõ /portal, đều là 404. */
it('applies the same rule to whoever knocks on the wrong panel', function () {
    $clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);

    $this->actingAs($clientUser, 'web')->get('/admin')->assertNotFound();
    $this->actingAs($this->admin, 'client')->get('/portal')->assertNotFound();
});

/**
 * Bốn test trên đi bằng HTTP nên chỉ phủ request tải trang. Một request cập nhật Livewire không
 * đi qua middleware của panel trừ khi middleware đó đăng ký `isPersistent: true`, mà
 * `hydrateCanAuthorizeAccess()` của Filament vẫn `abort(403)` trên đúng đường đó. Dựng một
 * request `/livewire/update` hợp lệ trong test đòi snapshot và checksum thật, nên ghim ở tầng
 * cấu hình.
 *
 * Giới hạn đã biết, ghi ra để không ai đọc nhầm: `Livewire::getPersistentMiddleware()` là danh
 * sách CHUNG và được khử trùng lặp, nên dòng cuối chỉ đỏ khi CẢ HAI panel bỏ `isPersistent`.
 * Hai dòng đầu thì đỏ riêng theo từng panel.
 */
it('carries the same rule into livewire update requests', function () {
    expect(Filament::getPanel('admin')->getMiddleware())
        ->toContain(AnswerDeniedPanelRequestsWithNotFound::class)
        ->and(Filament::getPanel('portal')->getMiddleware())
        ->toContain(AnswerDeniedPanelRequestsWithNotFound::class)
        ->and(Livewire::getPersistentMiddleware())
        ->toContain(AnswerDeniedPanelRequestsWithNotFound::class);
});
