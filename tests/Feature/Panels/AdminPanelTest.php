<?php

use App\Models\ClientUser;
use App\Models\User;

it('redirects guests to the admin login', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('lets an active staff user open the admin dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')->get('/admin')->assertOk();
});

it('blocks an inactive staff user', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->actingAs($user, 'web')->get('/admin')->assertForbidden();
});

it('does not accept a client session on the admin panel', function () {
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($clientUser, 'client')->get('/admin')->assertRedirect('/admin/login');
});
