<?php

use App\Enums\UserPosition;
use App\Models\ClientUser;
use App\Models\User;
use Database\Seeders\DemoAccountsSeeder;
use Illuminate\Support\Facades\Hash;

it('seeds one admin and one activated client login', function () {
    $this->seed(DemoAccountsSeeder::class);

    $admin = User::where('email', 'admin@luatvukhang.com')->firstOrFail();
    $client = ClientUser::where('email', 'khach1@example.com')->firstOrFail();

    expect($admin->position)->toBe(UserPosition::Admin)
        ->and(Hash::check('password', $admin->password))->toBeTrue()
        ->and($client->must_change_password)->toBeFalse()
        ->and($client->client->code)->toBe('KH-'.now()->format('Y').'-0001');
});

it('is idempotent', function () {
    $this->seed(DemoAccountsSeeder::class);
    $this->seed(DemoAccountsSeeder::class);

    expect(User::count())->toBe(1)->and(ClientUser::count())->toBe(1);
});
