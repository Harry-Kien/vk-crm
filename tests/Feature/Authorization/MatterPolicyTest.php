<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->teammate = User::factory()->withRole(Role::Lawyer)->create();
    $this->outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->teammate, MatterRole::Associate);

    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->restricted->addTeamMember($this->teammate, MatterRole::Associate);
});

it('lets a lawyer named in the team see the matter and hides it from everyone else', function () {
    expect($this->lead->can('view', $this->matter))->toBeTrue()
        ->and($this->teammate->can('view', $this->matter))->toBeTrue()
        ->and($this->outsider->can('view', $this->matter))->toBeFalse();
});

it('keeps a matter out of the list of a lawyer who is not on the team', function () {
    expect(Matter::query()->listableBy($this->outsider)->count())->toBe(0)
        ->and(Matter::query()->listableBy($this->teammate)->pluck('id')->all())->toBe([$this->matter->id]);
});

it('shows a restricted matter only to the lead lawyer and the admin', function () {
    expect($this->lead->can('view', $this->restricted))->toBeTrue()
        ->and($this->admin->can('view', $this->restricted))->toBeTrue()
        ->and($this->manager->can('view', $this->restricted))->toBeFalse()
        ->and($this->teammate->can('view', $this->restricted))->toBeFalse()
        ->and(Matter::query()->listableBy($this->manager)->pluck('id')->all())->toBe([$this->matter->id])
        ->and(Matter::query()->listableBy($this->admin)->count())->toBe(2);
});

it('gives the accountant the list but never the content', function () {
    expect($this->accountant->can('viewAny', Matter::class))->toBeTrue()
        ->and(Matter::query()->listableBy($this->accountant)->pluck('id')->all())->toBe([$this->matter->id])
        ->and($this->accountant->can('view', $this->matter))->toBeFalse()
        ->and($this->accountant->can('update', $this->matter))->toBeFalse()
        ->and($this->accountant->can('create', Matter::class))->toBeFalse();
});

it('lets a manager see every ordinary matter without being on the team', function () {
    expect($this->manager->can('view', $this->matter))->toBeTrue()
        ->and($this->manager->can('update', $this->matter))->toBeTrue();
});

it('allows a lawyer to update and transition only their own matters', function () {
    expect($this->lead->can('update', $this->matter))->toBeTrue()
        ->and($this->lead->can('transitionStage', $this->matter))->toBeTrue()
        ->and($this->outsider->can('update', $this->matter))->toBeFalse()
        ->and($this->outsider->can('transitionStage', $this->matter))->toBeFalse()
        ->and($this->assistant->can('transitionStage', $this->matter))->toBeFalse();
});

it('restricts creation and deletion to the roles the spec names', function () {
    expect($this->lead->can('create', Matter::class))->toBeTrue()
        ->and($this->assistant->can('create', Matter::class))->toBeFalse()
        ->and($this->admin->can('delete', $this->matter))->toBeTrue()
        ->and($this->manager->can('delete', $this->matter))->toBeFalse()
        ->and($this->admin->can('forceDelete', $this->matter))->toBeFalse();
});

it('answers for a portal user from the portal rules, not from spatie', function () {
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);

    $own = Matter::factory()->for($client)->create();
    $ownHidden = Matter::factory()->for($client)->unpublished()->create();
    $foreign = Matter::factory()->create();

    expect($clientUser->can('viewAny', Matter::class))->toBeTrue()
        ->and($clientUser->can('view', $own))->toBeTrue()
        ->and($clientUser->can('view', $ownHidden))->toBeFalse()
        ->and($clientUser->can('view', $foreign))->toBeFalse()
        ->and($clientUser->can('create', Matter::class))->toBeFalse()
        ->and($clientUser->can('update', $own))->toBeFalse()
        ->and($clientUser->can('transitionStage', $own))->toBeFalse()
        ->and($clientUser->can('delete', $own))->toBeFalse()
        ->and($clientUser->can('forceDelete', $own))->toBeFalse();
});

it('answers the same for a portal user whether or not the client guard is open', function () {
    // ChecksPortalVisibility must not depend on ambient auth state.
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);
    $own = Matter::factory()->for($client)->create();
    $foreign = Matter::factory()->create();

    expect($clientUser->can('view', $own))->toBeTrue()
        ->and($clientUser->can('view', $foreign))->toBeFalse();

    $this->actingAs($clientUser, 'client');

    expect($clientUser->can('view', $own))->toBeTrue()
        ->and($clientUser->can('view', $foreign))->toBeFalse();
});

it('still lets an admin act on a soft deleted matter', function () {
    $this->matter->delete();

    expect($this->admin->can('view', $this->matter))->toBeTrue()
        ->and($this->admin->can('restore', $this->matter))->toBeTrue()
        ->and($this->admin->can('forceDelete', $this->matter))->toBeFalse();
});

it('keeps a restricted matter out of the list of a lead lawyer who lost the view permission', function () {
    $demoted = User::factory()->withRole(Role::Accountant)->create();
    $theirs = Matter::factory()->restricted()->create(['lead_lawyer_id' => $demoted->id]);

    expect(Matter::query()->listableBy($demoted)->pluck('id')->all())->not->toContain($theirs->id)
        ->and($demoted->can('view', $theirs))->toBeFalse();
});
