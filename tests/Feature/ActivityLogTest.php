<?php

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Spatie\Activitylog\Models\Activity;

it('logs an activity with the logged in user as causer and the matter as subject when a matter is edited', function () {
    $lawyer = User::factory()->create();
    $matter = Matter::factory()->create();

    $this->actingAs($lawyer);

    $matter->update(['title' => 'Tranh chấp đất đai mới']);

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($lawyer))->toBeTrue()
        ->and($activity->subject?->is($matter))->toBeTrue()
        ->and($activity->properties->get('attributes')['title'])->toBe('Tranh chấp đất đai mới');
});

it('records an event through Audit::record without attaching a model', function () {
    $lawyer = User::factory()->create();
    $this->actingAs($lawyer);

    Audit::record('login_success', null, ['guard' => 'web']);

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe('login_success')
        ->and($activity->subject)->toBeNull()
        ->and($activity->causer?->is($lawyer))->toBeTrue()
        ->and($activity->properties->get('guard'))->toBe('web');
});

it('logs through Audit::record even when nobody is logged in, with a null causer', function () {
    expect(fn () => Audit::record('login_failed', null, ['email' => 'someone@example.com']))
        ->not->toThrow(Throwable::class);

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer)->toBeNull()
        ->and($activity->event)->toBe('login_failed');
});

it('records the acting portal user as the causer', function () {
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($clientUser, 'client');
    Audit::record('document_downloaded', null, ['document_id' => 7]);

    $activity = Activity::query()->latest('id')->firstOrFail();

    expect($activity->causer)->not->toBeNull()
        ->and($activity->causer->is($clientUser))->toBeTrue();
});

it('prefers the staff user when both guards are authenticated', function () {
    $staff = User::factory()->create();
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($staff, 'web');
    $this->actingAs($clientUser, 'client');
    Audit::record('exported');

    expect(Activity::query()->latest('id')->firstOrFail()->causer->is($staff))->toBeTrue();
});

it('never logs the client id number', function () {
    $lawyer = User::factory()->create();
    $this->actingAs($lawyer);

    $client = Client::factory()->create(['id_number' => '079012345678']);
    // On its own, changing id_number produces no log at all (dontSubmitEmptyLogs + logOnlyDirty).
    $client->update(['id_number' => '079099999999', 'phone' => '0900000000']);

    $activities = $client->activities;

    expect($activities)->not->toBeEmpty();

    foreach ($activities as $activity) {
        expect($activity->properties->toArray())->not->toHaveKey('id_number')
            ->and(collect($activity->properties->get('attributes', [])))->not->toHaveKey('id_number')
            ->and(collect($activity->properties->get('old', [])))->not->toHaveKey('id_number');
    }
});
