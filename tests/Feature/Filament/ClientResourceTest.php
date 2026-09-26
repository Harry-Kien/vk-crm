<?php

use App\Enums\InstalmentStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\Money;
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

/**
 * M9 Task 5 — mở rộng ĐÚNG kiểm tra "vụ đang mở" ở trên (không viết kiểm tra thứ hai): một vụ việc
 * đã ĐÓNG (`closed_at` khác null, nên không bị chặn bởi kiểm tra "vụ đang mở") mà còn hợp đồng
 * `active` dư nợ vẫn phải chặn xoá mềm khách hàng — xoá mềm vụ việc làm dư nợ đó bốc hơi khỏi mọi
 * báo cáo doanh thu (chúng bỏ qua vụ đã xoá mềm).
 */
it('refuses to delete a client whose closed matter still carries an outstanding balance', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $matter = Matter::factory()->create(['client_id' => $client->id, 'closed_at' => now()]);
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 10_000_000]);
    Instalment::factory()->for($contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Pending,
    ]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditClient::class, ['record' => $client->getKey()])
        ->assertActionVisible('delete')
        ->callAction('delete')
        ->assertNotified(__('clients.delete_blocked_outstanding_balance', [
            'amount' => Money::format(10_000_000),
            'count' => 1,
        ]));

    expect($client->fresh()->trashed())->toBeFalse();
});

/** Cặp dương: cùng một vụ đã đóng, nhưng đợt đã thu đủ — dư nợ về 0, xoá được như thường. */
it('lets the admin delete a client whose closed matter has been fully collected', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $matter = Matter::factory()->create(['client_id' => $client->id, 'closed_at' => now()]);
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 10_000_000]);
    Payment::factory()->for($instalment)->create(['amount' => 10_000_000]);
    $instalment->fill(['status' => InstalmentStatus::Paid])->save();

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
