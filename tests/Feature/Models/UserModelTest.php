<?php

use App\Enums\UserPosition;
use App\Models\User;

it('stores position as an enum and defaults to active', function () {
    $user = User::factory()->create(['position' => UserPosition::Lawyer]);

    expect($user->fresh()->position)->toBe(UserPosition::Lawyer)
        ->and($user->is_active)->toBeTrue()
        ->and($user->deleted_at)->toBeNull();
});

it('soft deletes users', function () {
    $user = User::factory()->create();
    $user->delete();

    expect(User::count())->toBe(0)
        ->and(User::withTrashed()->count())->toBe(1);
});
