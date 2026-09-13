<?php

use App\Models\ClientUser;
use App\Models\User;

it('redirects guests to the portal login', function () {
    $this->get('/portal')->assertRedirect('/portal/login');
});

it('lets an active client user open the portal', function () {
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($clientUser, 'client')->get('/portal')->assertOk();
});

it('blocks an inactive client user', function () {
    $clientUser = ClientUser::factory()->create(['is_active' => false]);

    $this->actingAs($clientUser, 'client')->get('/portal')->assertForbidden();
});

it('does not accept a staff session on the portal', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')->get('/portal')->assertRedirect('/portal/login');
});
