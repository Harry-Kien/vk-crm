<?php

use App\Models\ClientUser;
use App\Models\User;

it('authenticates staff on the web guard only', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    expect(auth('web')->check())->toBeTrue()
        ->and(auth('client')->check())->toBeFalse();
});

it('authenticates clients on the client guard only', function () {
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($clientUser, 'client');

    expect(auth('client')->check())->toBeTrue()
        ->and(auth('client')->user()->is($clientUser))->toBeTrue()
        ->and(auth('web')->check())->toBeFalse();
});
