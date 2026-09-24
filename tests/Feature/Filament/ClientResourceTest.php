<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\Matter;
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
