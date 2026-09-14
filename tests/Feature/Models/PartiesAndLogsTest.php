<?php

use App\Enums\CommunicationType;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\PartyRole;
use App\Models\Client;
use App\Models\CommunicationLog;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\MatterParty;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Support\Normalizer;
use Illuminate\Database\QueryException;

it('stores only hashed and normalized identity for parties', function () {
    $matter = Matter::factory()->create();

    $party = MatterParty::factory()->for($matter)->defendant()
        ->identify('079 090 001 234', '0901234567')
        ->create(['name' => 'Trần Thị Bích Đào']);

    expect($party->id_number_hash)->toBe(hash('sha256', '079090001234'))
        ->and($party->phone_normalized)->toBe('84901234567')
        ->and($party->name_normalized)->toBe('tran thi bich dao')
        ->and($party->role)->toBe(PartyRole::Defendant)
        ->and($party->is_our_client)->toBeFalse()
        ->and(Schema::hasColumn('matter_parties', 'id_number'))->toBeFalse();
});

it('links our client as a party with client_id', function () {
    $client = Client::factory()->create(['id_number' => '012345678901', 'phone' => '0912345678']);
    $matter = Matter::factory()->for($client)->create();

    $party = MatterParty::factory()->ourClient($client)->for($matter)->create();

    expect($party->is_our_client)->toBeTrue()
        ->and($party->client->is($client))->toBeTrue()
        ->and($party->id_number_hash)->toBe(Normalizer::idNumberHash('012345678901'))
        ->and($matter->parties)->toHaveCount(1);
});

it('cannot receive a raw identity through mass assignment and identify() is the only write path', function () {
    $party = MatterParty::factory()->for(Matter::factory()->create())->make(['id_number_hash' => '079090001234', 'phone_normalized' => '0901234567']);

    expect($party->id_number_hash)->toBeNull()
        ->and($party->phone_normalized)->toBeNull();

    $party->identify('079 090 001 234', '0901234567')->save();

    expect($party->fresh()->id_number_hash)->toBe(hash('sha256', '079090001234'))
        ->and($party->fresh()->phone_normalized)->toBe('84901234567');
});

it('finds parties matching an identity across matters', function () {
    $a = MatterParty::factory()->identify('111', '0900000001')->create();
    MatterParty::factory()->identify('222', '0900000002')->create();

    expect(MatterParty::query()->matchingIdentity(Normalizer::idNumberHash('111'), null)->pluck('id')->all())->toBe([$a->id])
        ->and(MatterParty::query()->matchingIdentity(null, '84900000001')->pluck('id')->all())->toBe([$a->id])
        ->and(MatterParty::query()->matchingIdentity(null, null)->count())->toBe(0);
});

it('records communication logs with a type and author', function () {
    $log = CommunicationLog::factory()->create(['type' => CommunicationType::CallOut]);

    expect($log->type)->toBe(CommunicationType::CallOut)
        ->and($log->is_visible_to_client)->toBeFalse()
        ->and($log->matter->communicationLogs->first()->is($log))->toBeTrue();
});

it('records outbound messages against a related model', function () {
    $stageLog = StageLog::factory()->create();
    $message = OutboundMessage::factory()->create([
        'related_type' => $stageLog->getMorphClass(), 'related_id' => $stageLog->id,
        'payload' => ['matter_code' => 'VK-2026-DD-0001'],
    ]);

    expect($message->channel)->toBe(MessageChannel::Email)
        ->and($message->status)->toBe(MessageStatus::Queued)
        ->and($message->payload)->toBe(['matter_code' => 'VK-2026-DD-0001'])
        ->and($message->related->is($stageLog))->toBeTrue();
});

it('allows one archive per matter', function () {
    $archive = MatterArchive::factory()->create();

    expect($archive->matter->archive->is($archive))->toBeTrue()
        ->and($archive->retention_until->year)->toBe(now()->addYears(config('vkcrm.retention_years'))->year)
        ->and(fn () => MatterArchive::factory()->create(['matter_id' => $archive->matter_id]))
        ->toThrow(QueryException::class);
});
