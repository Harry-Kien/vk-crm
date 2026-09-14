<?php

use App\Models\Client;
use App\Models\User;

it('records who created and updated a client when a staff user is logged in', function () {
    $creator = User::factory()->create();
    $editor = User::factory()->create();

    $this->actingAs($creator, 'web');
    $client = Client::factory()->create();

    expect($client->created_by)->toBe($creator->id)
        ->and($client->updated_by)->toBe($creator->id)
        ->and($client->creator->is($creator))->toBeTrue();

    $this->actingAs($editor, 'web');
    $client->update(['note' => 'đã gọi lại']);

    expect($client->fresh()->updated_by)->toBe($editor->id)
        ->and($client->fresh()->created_by)->toBe($creator->id);
});

it('leaves blame columns null when nobody is logged in', function () {
    $client = Client::factory()->create();

    expect($client->created_by)->toBeNull()->and($client->updated_by)->toBeNull();
});
