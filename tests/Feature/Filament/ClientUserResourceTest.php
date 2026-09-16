<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\ClientUsers\ClientUserResource;
use App\Filament\Admin\Resources\ClientUsers\Pages\ListClientUsers;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * Cùng luật ClientResourceTest: kế toán là vai duy nhất không có clientUser.manage (SPEC §5),
 * nên ClientUserResource::getEloquentQuery() phải tự lọc theo client của những vụ việc kế toán
 * xem được, không gọi can('view') theo dòng.
 */
it('excludes a client user of a client the accountant has no matter with', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $withMatter = Client::factory()->create();
    Matter::factory()->create(['client_id' => $withMatter->id]);
    $visibleUser = ClientUser::factory()->for($withMatter)->create();

    $withoutMatter = Client::factory()->create();
    $hiddenUser = ClientUser::factory()->for($withoutMatter)->create();

    $this->actingAs($accountant, 'web');

    $this->livewire(ListClientUsers::class)
        ->assertCanSeeTableRecords([$visibleUser])
        ->assertCanNotSeeTableRecords([$hiddenUser]);
});

it('lets a lawyer with clientUser.manage see every client user regardless of matter team', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($lawyer, 'web');

    $this->livewire(ListClientUsers::class)
        ->assertCanSeeTableRecords([$clientUser]);
});

it('forbids the accountant from writing a client user', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web')->get(ClientUserResource::getUrl('create', panel: 'admin'))->assertForbidden();
});
