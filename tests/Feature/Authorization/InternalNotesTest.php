<?php

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->marker = 'DAU-HIEU-NOI-BO-'.Str::random(12);

    $this->client = Client::factory()->create(['note' => $this->marker]);
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['description_internal' => $this->marker]);
    $this->log = StageLog::factory()->for($this->matter)->published()->create(['internal_note' => $this->marker]);
});

it('keeps internal notes out of anything the portal serializes', function () {
    auth('client')->setUser($this->clientUser);

    $payload = json_encode([
        Matter::findOrFail($this->matter->id)->toArray(),
        StageLog::findOrFail($this->log->id)->toArray(),
        Client::findOrFail($this->client->id)->toArray(),
        Matter::with(['stageLogs', 'client'])->findOrFail($this->matter->id)->toArray(),
    ], JSON_UNESCAPED_UNICODE);

    expect($payload)->not->toContain($this->marker);
});

it('still lets staff read the internal columns', function () {
    $this->actingAs(User::factory()->create(), 'web');

    expect(json_encode(Matter::findOrFail($this->matter->id)->toArray()))->toContain($this->marker)
        ->and(json_encode(StageLog::findOrFail($this->log->id)->toArray()))->toContain($this->marker)
        ->and(json_encode(Client::findOrFail($this->client->id)->toArray()))->toContain($this->marker);
});

it('still exposes the internal value to code that asks for the attribute directly', function () {
    // Scope và trait bảo vệ tầng serialize, không làm hỏng nghiệp vụ nội bộ đọc thuộc tính.
    auth('client')->setUser($this->clientUser);

    expect(StageLog::findOrFail($this->log->id)->internal_note)->toBe($this->marker);
});

it('keeps the public content visible to the client', function () {
    auth('client')->setUser($this->clientUser);

    expect(StageLog::findOrFail($this->log->id)->toArray())
        ->toHaveKey('public_content')
        ->not->toHaveKey('internal_note');
});
