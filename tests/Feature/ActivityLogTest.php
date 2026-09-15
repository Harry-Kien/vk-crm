<?php

use App\Models\Client;
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
