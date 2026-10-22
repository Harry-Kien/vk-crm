<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/*
 * Rà soát toàn hệ thống 2026-10-22 (làn intake, mục chưa sửa 3): ô tìm của danh sách Khách hàng so số
 * điện thoại như chuỗi thô — gõ "0912345678" không ra khách lưu "0912 345 678", gõ "+84…" không ra gì.
 * Danh sách Tiếp nhận đã tìm được theo mọi cách viết (làn fa); danh sách Khách hàng phải như vậy.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

it('finds a client by phone however the number is written', function (string $stored, string $typed) {
    $client = Client::factory()->create(['name' => 'Khách Cpsa', 'email' => 'cpsa@example.test', 'phone' => $stored]);
    $other = Client::factory()->create(['name' => 'Khách Cpsb', 'email' => 'cpsb@example.test', 'phone' => '0988 000 111']);
    $this->actingAs($this->admin, 'web');

    $this->livewire(ListClients::class)->searchTable($typed)
        ->assertCanSeeTableRecords([$client])
        ->assertCanNotSeeTableRecords([$other]);
})->with([
    'typed without spaces' => ['0912 345 678', '0912345678'],
    'typed with dots' => ['0912345678', '0912.345.678'],
    'typed international' => ['0912 345 678', '+84912345678'],
    'stored international' => ['+84 912 345 678', '0912345678'],
    'tail only' => ['0912-345-678', '345678'],
]);

it('still finds a client by name and email as before', function () {
    $client = Client::factory()->create(['name' => 'Khách Cpsc', 'email' => 'cpsc@example.test', 'phone' => '0912 345 678']);
    $this->actingAs($this->admin, 'web');

    $this->livewire(ListClients::class)->searchTable('Cpsc')->assertCanSeeTableRecords([$client]);
    $this->livewire(ListClients::class)->searchTable('cpsc@example')->assertCanSeeTableRecords([$client]);
    $this->livewire(ListClients::class)->searchTable('Cpsx')->assertCanNotSeeTableRecords([$client]);
});
