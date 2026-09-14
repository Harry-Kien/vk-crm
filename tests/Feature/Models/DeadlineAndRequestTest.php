<?php

use App\Enums\ClientRequestStatus;
use App\Enums\DeadlineSeverity;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;

it('finds upcoming deadlines and tracks reminders sent', function () {
    $matter = Matter::factory()->create();
    $soon = Deadline::factory()->for($matter)->dueIn(2)->critical()->create();
    Deadline::factory()->for($matter)->dueIn(20)->create();
    Deadline::factory()->for($matter)->dueIn(1)->create(['is_completed' => true]);

    expect(Deadline::query()->upcoming(3)->pluck('id')->all())->toBe([$soon->id])
        ->and($soon->severity)->toBe(DeadlineSeverity::Critical)
        ->and($soon->reminders_sent)->toBe([]);

    $soon->markReminderSent('d3');
    $soon->markReminderSent('d3');

    expect($soon->fresh()->reminders_sent)->toBe(['d3'])
        ->and($soon->responsible)->toBeInstanceOf(User::class)
        ->and($matter->deadlines)->toHaveCount(3);
});

it('threads client requests with polymorphic replies', function () {
    $matter = Matter::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $matter->client_id]);
    $lawyer = $matter->leadLawyer;

    $request = ClientRequest::factory()->for($matter)->create(['client_user_id' => $clientUser->id]);
    ClientRequestReply::factory()->create(['request_id' => $request->id, 'author_type' => $lawyer->getMorphClass(), 'author_id' => $lawyer->id]);
    ClientRequestReply::factory()->create(['request_id' => $request->id, 'author_type' => $clientUser->getMorphClass(), 'author_id' => $clientUser->id]);

    expect($request->status)->toBe(ClientRequestStatus::New)
        ->and($request->replies)->toHaveCount(2)
        ->and($request->replies->first()->author->is($lawyer))->toBeTrue()
        ->and($request->replies->last()->author->is($clientUser))->toBeTrue()
        ->and($request->clientUser->is($clientUser))->toBeTrue()
        ->and($matter->clientRequests->first()->is($request))->toBeTrue();
});
