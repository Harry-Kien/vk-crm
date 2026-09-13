<?php

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\ClientUser;
use Illuminate\Support\Facades\DB;

it('belongs to a client and must change password on creation', function () {
    $clientUser = ClientUser::factory()->create();

    expect($clientUser->client)->toBeInstanceOf(Client::class)
        ->and($clientUser->must_change_password)->toBeTrue()
        ->and($clientUser->is_active)->toBeTrue();
});

it('encrypts the client id_number at rest', function () {
    $client = Client::factory()->create([
        'type' => ClientType::Individual,
        'id_number' => '079123456789',
    ]);

    $raw = DB::table('clients')->where('id', $client->id)->value('id_number');

    expect($raw)->not->toBe('079123456789')
        ->and($client->fresh()->id_number)->toBe('079123456789');
});

it('generates a unique client code per client', function () {
    $a = Client::factory()->create();
    $b = Client::factory()->create();

    expect($a->code)->toMatch('/^KH-\d{4}-\d{4}$/')
        ->and($a->code)->not->toBe($b->code);
});
