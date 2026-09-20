<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Clients\ClientResource;
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
