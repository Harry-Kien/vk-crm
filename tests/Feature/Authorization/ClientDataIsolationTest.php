<?php

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;

beforeEach(function () {
    $this->clientA = Client::factory()->create();
    $this->clientB = Client::factory()->create();
    $this->userA = ClientUser::factory()->create(['client_id' => $this->clientA->id]);

    $this->matterA = Matter::factory()->for($this->clientA)->create();
    $this->matterB = Matter::factory()->for($this->clientB)->create();
    $this->hiddenA = Matter::factory()->for($this->clientA)->unpublished()->create();
});

it('hides other clients matters from a portal user', function () {
    $this->actingAs($this->userA, 'client');

    expect(Matter::pluck('id')->all())->toBe([$this->matterA->id])
        ->and(Matter::find($this->matterB->id))->toBeNull()
        ->and(Matter::whereKey($this->matterB->id)->exists())->toBeFalse();
});

it('cannot be defeated by a manual client_id filter', function () {
    $this->actingAs($this->userA, 'client');

    expect(Matter::where('client_id', $this->clientB->id)->get())->toBeEmpty()
        ->and(Matter::whereIn('client_id', [$this->clientA->id, $this->clientB->id])->pluck('id')->all())
        ->toBe([$this->matterA->id]);
});

it('hides a matter of the own client that is not published to the portal', function () {
    $this->actingAs($this->userA, 'client');

    expect(Matter::find($this->hiddenA->id))->toBeNull();
});

it('does not restrict staff on the web guard', function () {
    $this->actingAs(User::factory()->create(), 'web');

    expect(Matter::count())->toBe(3);
});

it('does not restrict an unauthenticated context such as a queue job', function () {
    expect(Matter::count())->toBe(3);
});

it('keeps the admin panel unrestricted when a staff user is also logged into the portal', function () {
    // Hai panel dùng chung cookie phiên nên cả hai guard có thể cùng xác thực.
    $this->actingAs(User::factory()->create(), 'web');
    $this->actingAs($this->userA, 'client');

    expect(auth('web')->check())->toBeTrue()
        ->and(auth('client')->check())->toBeTrue()
        ->and(Matter::count())->toBe(3);
});
