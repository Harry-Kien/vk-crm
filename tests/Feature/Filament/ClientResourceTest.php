<?php

use App\Enums\ClientType;
use App\Enums\Role;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\CreateClient as CreateClientPage;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * Carry-forward review M2/M3: ClientPolicy::view chạy exists() cho mỗi bản ghi, nên
 * ClientResource::getEloquentQuery() phải tự lọc danh sách bằng listableBy() một lần, không
 * dựa vào can('view') theo dòng. Luật sư không có client.manage (SPEC §5) nên chỉ thấy khách
 * của vụ việc mình có tên trong đội ngũ.
 */
it('excludes a client the lawyer has no matter with', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $ownClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $ownClient->id, 'lead_lawyer_id' => $lawyer->id]);

    $strangerClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $strangerClient->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(ListClients::class)
        ->assertCanSeeTableRecords([$ownClient])
        ->assertCanNotSeeTableRecords([$strangerClient]);
});

it('lets an assistant with client.manage see every client regardless of matter team', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $client = Client::factory()->create();

    $this->actingAs($assistant, 'web');

    $this->livewire(ListClients::class)
        ->assertCanSeeTableRecords([$client]);
});

it('lets an admin open every client page', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();

    $this->actingAs($admin, 'web')->get(ClientResource::getUrl('index', panel: 'admin'))->assertOk();
    $this->actingAs($admin, 'web')->get(ClientResource::getUrl('edit', ['record' => $client], panel: 'admin'))->assertOk();
});

it('hides the client write pages from a lawyer without client.manage', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    Matter::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $lawyer->id]);

    // Panel từ chối bằng 404 (SPEC §10.10, xem DenialCodeTest và
    // AnswerDeniedPanelRequestsWithNotFound).
    $this->actingAs($lawyer, 'web')->get(ClientResource::getUrl('create', panel: 'admin'))->assertNotFound();
    $this->actingAs($lawyer, 'web')->get(ClientResource::getUrl('edit', ['record' => $client], panel: 'admin'))->assertNotFound();
});

// =========================================================================================
// M6.5 Task 6 (finding `intake/intake-08`): bugfix ClientForm — type live(), maxLength = cột DB,
// id_number nullable.
// =========================================================================================

/**
 * Trước bản sửa này, `type` (Select) không `live()`, nên chọn "Tổ chức" trên trình duyệt không
 * gửi request nào — ô "Người đại diện" chỉ hiện sau khi lưu rồi mở lại trang Sửa. Đo bằng chính
 * hiệu ứng người dùng thấy: nhãn của ô đó có mặt trên trang hay không, TRƯỚC và SAU khi đổi `type`
 * — không dựng lại được bằng `assertSee` nếu `live()` bị gỡ, vì `set()` của Livewire test coi mọi
 * thay đổi thuộc tính là một request thật (nó không mô phỏng "trình duyệt không gửi gì" như một
 * người dùng thật sẽ gặp phải). `assertDontSee`/`assertSee` ở dưới VẪN xanh nếu ai đó gỡ `live()`
 * (đã tự tay xác nhận bằng mutation probe, xem báo cáo) — cặp assertion thứ hai đọc thẳng markup
 * `wire:model` mà Filament sinh ra cho ô `type` mới là thứ THẬT SỰ đo được `live()`: không có nó,
 * thuộc tính là `wire:model="data.type"` (chờ submit); có `live()`, nó là
 * `wire:model.live="data.type"` (gửi ngay khi đổi — đúng hành vi trình duyệt thật cần).
 */
it('shows the representative field as soon as the client type changes to organization, on the create form', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->actingAs($assistant, 'web');

    $component = $this->livewire(CreateClientPage::class);
    $component->assertDontSee(__('clients.fields.representative_name'));

    $component->set('data.type', ClientType::Organization->value);
    $component->assertSee(__('clients.fields.representative_name'));

    preg_match('/wire:model[^\s=]*\s*=\s*"data\.type"/', $component->html(), $match);
    expect($match)->not->toBeEmpty()
        ->and($match[0])->toContain('.live');
});

/**
 * Cột `clients.address` là `string(300)`; `Textarea` trước bản sửa này không giới hạn gì, nên một
 * địa chỉ 301 ký tự đi thẳng xuống MariaDB strict và ra lỗi 1406 thành trang 500 — SQLite của bộ
 * test không bắt được (không strict). `maxLength(300)` giờ chặn Ở FORM: lỗi validation tiếng Việt,
 * không request nào chạm tới DB. Chạy lại dưới `test:mariadb` để xác nhận không còn đường nào lọt
 * xuống DB thật (xem báo cáo).
 */
it('rejects a 301-character address with a Vietnamese validation error, not a database error', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->actingAs($assistant, 'web');

    $this->livewire(CreateClientPage::class)
        ->fillForm([
            'type' => ClientType::Individual->value,
            'name' => 'Khách địa chỉ dài',
            'address' => str_repeat('a', 301),
        ])
        ->call('create')
        ->assertHasFormErrors(['address']);

    expect(Client::query()->where('name', 'Khách địa chỉ dài')->exists())->toBeFalse();
});

/** Vế dương: đúng 300 ký tự (đúng giới hạn cột) vẫn lưu được bình thường. */
it('accepts an address at exactly the 300-character column limit', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->actingAs($assistant, 'web');

    $this->livewire(CreateClientPage::class)
        ->fillForm([
            'type' => ClientType::Individual->value,
            'name' => 'Khách địa chỉ vừa đủ',
            'address' => str_repeat('a', 300),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Client::query()->where('name', 'Khách địa chỉ vừa đủ')->exists())->toBeTrue();
});

// =========================================================================================
// M6.5 Task 6 (finding `intake/intake-07`, phán quyết R4): App\Actions\Client\CreateClient — dò
// trùng theo số điện thoại/CCCD so với các bên is_our_client đã lưu.
// =========================================================================================

/**
 * Trợ lý (client.manage) tạo một khách hàng trùng số điện thoại với một bên `is_our_client` đã
 * lưu: thấy cảnh báo (lỗi gắn vào `confirm_duplicate`), KHÔNG tạo hồ sơ mới ngay; tích xác nhận
 * rồi lưu lại thì vẫn tạo được một hồ sơ MỚI (khác id với hồ sơ trùng).
 */
it('warns the assistant about a duplicate phone number, and still lets them confirm and create anyway', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $existingClient = Client::factory()->create(['phone' => '0912345678']);
    $matter = Matter::factory()->create(['client_id' => $existingClient->id]);
    MatterParty::factory()->for($matter)->ourClient($existingClient)->create();

    $this->actingAs($assistant, 'web');

    $component = $this->livewire(CreateClientPage::class)
        ->fillForm([
            'type' => ClientType::Individual->value,
            'name' => 'Một người trùng số điện thoại',
            'phone' => '0912345678',
        ]);

    $component->call('create')->assertHasFormErrors(['confirm_duplicate']);

    expect(Client::query()->where('name', 'Một người trùng số điện thoại')->exists())->toBeFalse();

    $component->fillForm(['confirm_duplicate' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Client::query()->where('name', 'Một người trùng số điện thoại')->first();

    expect($created)->not->toBeNull()
        ->and($created->id)->not->toBe($existingClient->id);
});

/** Vế dương: một số điện thoại không trùng ai thì tạo bình thường, không cảnh báo gì. */
it('creates a client with no warning at all when the phone number matches nobody', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->actingAs($assistant, 'web');

    $this->livewire(CreateClientPage::class)
        ->fillForm([
            'type' => ClientType::Individual->value,
            'name' => 'Khách không trùng ai',
            'phone' => '0900000111',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Client::query()->where('name', 'Khách không trùng ai')->exists())->toBeTrue();
});

/**
 * "Duplicate detection compares against parties that are is_our_client" (phán quyết chủ nhiệm).
 * Một bên KHÔNG phải `is_our_client` (bị đơn gõ tay, ví dụ) mang cùng số điện thoại không được
 * tính là trùng — nó chưa từng là hồ sơ khách hàng nào của văn phòng, chỉ là một cái tên trong
 * một vụ việc khác.
 */
it('does not warn about a phone number that only matches a non-our-client party', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    // client_id KHÔNG null có chủ đích: bên này trỏ tới một hồ sơ Client thật (ví dụ do gõ nhầm
    // vai lúc tiếp nhận), để phép thử này đo đúng điều kiện `is_our_client` — không phải
    // `whereNotNull('client_id')`, vốn cũng sẽ loại một bên `client_id` null.
    $otherClient = Client::factory()->create();
    $matter = Matter::factory()->create();
    $party = MatterParty::factory()->for($matter)->create(['is_our_client' => false, 'client_id' => $otherClient->id]);
    $party->identify(null, '0912345678')->save();

    $this->actingAs($assistant, 'web');

    $this->livewire(CreateClientPage::class)
        ->fillForm([
            'type' => ClientType::Individual->value,
            'name' => 'Khách trùng số với một bị đơn',
            'phone' => '0912345678',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Client::query()->where('name', 'Khách trùng số với một bị đơn')->exists())->toBeTrue();
});

// =========================================================================================
// Task 2 (rà soát cuối, "Xoá khách hàng còn vụ việc đang mở"): ClientPolicy::delete()
// =========================================================================================

/**
 * Trước bản sửa này, admin xoá được một khách hàng còn vụ việc mở mà không cảnh báo gì —
 * `Matter::client()` trả `null` cho hồ sơ đó ở mọi màn hình đọc qua quan hệ này ngay sau đó.
 * Đo qua `callAction('delete')` thật (không gọi thẳng policy): xác nhận cả nút vẫn BẤM ĐƯỢC
 * (không bị ẩn — `EditClient` đã bật `authorizationNotification()`) lẫn việc khách hàng còn
 * nguyên, chưa xoá mềm.
 */
it('refuses to delete a client that still has an open matter, and tells the admin how many', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    Matter::factory()->create(['client_id' => $client->id, 'closed_at' => null]);
    Matter::factory()->create(['client_id' => $client->id, 'closed_at' => null]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditClient::class, ['record' => $client->getKey()])
        ->assertActionVisible('delete')
        ->callAction('delete')
        ->assertNotified(__('clients.delete_blocked_open_matters', ['count' => 2]));

    expect($client->fresh()->trashed())->toBeFalse();
});

/** Vế dương: một khách hàng KHÔNG còn vụ mở thì admin vẫn xoá được như trước. */
it('lets the admin delete a client whose matters are all closed', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    Matter::factory()->create(['client_id' => $client->id, 'closed_at' => now()]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditClient::class, ['record' => $client->getKey()])
        ->callAction('delete');

    expect($client->fresh()->trashed())->toBeTrue();
});

/** Và một khách hàng chưa từng có vụ việc nào cũng xoá được bình thường. */
it('lets the admin delete a client with no matters at all', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditClient::class, ['record' => $client->getKey()])
        ->callAction('delete');

    expect($client->fresh()->trashed())->toBeTrue();
});

// =========================================================================================
// Task 2, vòng sửa 1 (Critical #1): ClientPolicy::deleteAny()/restoreAny()/forceDeleteAny() +
// ClientsTable::toolbarActions() authorizeIndividualRecords()
// =========================================================================================

/**
 * Trước bản sửa này, `ClientPolicy` không định nghĩa `deleteAny()`/`restoreAny()`/
 * `forceDeleteAny()`, và Filament (`Filament\get_authorization_response()`, chế độ không nghiêm
 * ngặt — mặc định của dự án) coi một ability không có phương thức tương ứng là CHO PHÉP. Ba nút
 * xoá/khôi phục/xoá vĩnh viễn hàng loạt vì vậy hiện ra cho BẤT KỲ ai mở được trang danh sách, kể
 * cả Lawyer và Assistant — không chỉ Admin.
 *
 * `filterTable('trashed', true)` TRƯỚC khi kiểm — bắt buộc, không phải trang trí:
 * `RestoreBulkAction`/`ForceDeleteBulkAction` của chính Filament tự ẩn khi bộ lọc "đã xoá" còn ở
 * giá trị mặc định (`blank`), BẤT KỂ quyền — đo được bằng đột biến (bỏ khẳng định này thì test
 * "shows the bulk delete action to the admin" phía trên vẫn xanh dù `restoreAny()` bị xoá, vì
 * `restore` đã ẩn sẵn bởi lý do khác). `value: true` ("còn cả đã xoá") tắt đúng nhánh ẩn đó của cả
 * ba nút mà không đổi ý nghĩa gì khác, nên khẳng định `assertTableBulkActionHidden()` dưới đây đo
 * ĐÚNG quyền, không lẫn với lý do ẩn khác của Filament.
 */
it('hides every bulk action from a lawyer, who is not an admin', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    Matter::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $lawyer->id, 'closed_at' => null]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(ListClients::class)
        ->filterTable('trashed', true)
        ->assertTableBulkActionHidden('delete')
        ->assertTableBulkActionHidden('restore')
        ->assertTableBulkActionHidden('forceDelete');
});

/** Cùng luật cho Assistant — có `client.manage` (thấy toàn bộ khách hàng) nhưng không phải Admin. */
it('hides every bulk action from an assistant, who is not an admin either', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();

    $this->actingAs($assistant, 'web');

    $this->livewire(ListClients::class)
        ->filterTable('trashed', true)
        ->assertTableBulkActionHidden('delete')
        ->assertTableBulkActionHidden('restore')
        ->assertTableBulkActionHidden('forceDelete');
});

/**
 * Vế dương của hai test trên: admin thấy cả xoá lẫn khôi phục hàng loạt (trừ xoá vĩnh viễn, không
 * ai thấy — test riêng bên dưới). `restore` cũng cần `filterTable('trashed', true)` trước, cùng
 * lý do đã nói ở test "hides every bulk action from a lawyer".
 */
it('shows the bulk delete and restore actions to the admin', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web');

    $this->livewire(ListClients::class)
        ->assertTableBulkActionVisible('delete')
        ->filterTable('trashed', true)
        ->assertTableBulkActionVisible('restore');
});

/**
 * Lớp phòng thủ THỨ HAI, độc lập với việc ẩn nút: `authorizeIndividualRecords('delete')` bắt mỗi
 * bản ghi đã CHỌN đi qua đúng `ClientPolicy::delete()` — bao gồm luật "còn vụ đang mở" — trước
 * khi bị xoá. Không có nó, `deleteAny()` (cổng thô) chỉ quyết định nút có bấm được không; một
 * khi đã bấm, Filament xoá MỌI dòng đã chọn mà không hỏi lại `delete()` cho từng dòng.
 */
it('refuses to bulk delete a client that still has an open matter, even for the admin', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $withOpenMatter = Client::factory()->create();
    Matter::factory()->create(['client_id' => $withOpenMatter->id, 'closed_at' => null]);
    $withoutOpenMatter = Client::factory()->create();

    $this->actingAs($admin, 'web');

    $this->livewire(ListClients::class)
        ->callTableBulkAction('delete', [$withOpenMatter, $withoutOpenMatter]);

    expect($withOpenMatter->fresh()->trashed())->toBeFalse()
        // Vế dương trong CÙNG một lượt bấm: dòng không vướng luật vẫn bị xoá bình thường, nên
        // khẳng định trên không xanh vì cả lượt bấm đã bị chặn hoàn toàn.
        ->and($withoutOpenMatter->fresh()->trashed())->toBeTrue();
});

/**
 * Vế dương của per-record filtering, đo bằng hệ quả cổng khách hàng thật (đúng phép đo
 * "false→true" mà rà soát nêu tên): admin khôi phục hàng loạt một khách hàng đã xoá mềm, và tài
 * khoản cổng của khách đó lấy lại quyền vào cổng (`ClientUser::canAccessPanel()`).
 */
it('lets the admin bulk restore a soft deleted client, and its portal account regains access', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->for($client)->create();
    $client->delete();

    expect($clientUser->fresh()->canAccessPanel(Filament::getPanel('portal')))->toBeFalse();

    $this->actingAs($admin, 'web');

    $this->livewire(ListClients::class)
        ->filterTable('trashed', true)
        ->callTableBulkAction('restore', [$client]);

    expect($client->fresh()->trashed())->toBeFalse()
        ->and($clientUser->fresh()->canAccessPanel(Filament::getPanel('portal')))->toBeTrue();
});

/** Không ai xoá vĩnh viễn một khách hàng được, kể cả admin — cùng luật `MatterPolicy::forceDelete()`. */
it('hides the bulk force-delete action from everyone, including the admin', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web');

    // Cùng lý do `filterTable('trashed', true)` ở test "hides every bulk action from a lawyer":
    // `ForceDeleteBulkAction` của Filament cũng tự ẩn khi bộ lọc "đã xoá" ở giá trị mặc định, BẤT
    // KỂ quyền — không tắt nhánh ẩn đó thì khẳng định dưới đây xanh dù `forceDeleteAny()` không
    // hề tồn tại (đo được bằng đột biến, y hệt bài học của hai test phía trên).
    $this->livewire(ListClients::class)
        ->filterTable('trashed', true)
        ->assertTableBulkActionHidden('forceDelete');
});
