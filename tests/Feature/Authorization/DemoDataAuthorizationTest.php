<?php

use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('gives each role the reach the spec describes on the seeded office', function () {
    $admin = User::where('email', 'admin@luatvukhang.com')->firstOrFail();
    $lawyer = User::where('email', 'luatsu1@luatvukhang.com')->firstOrFail();
    $accountant = User::where('email', 'ketoan@luatvukhang.com')->firstOrFail();

    $all = Matter::count();
    $lawyerMatters = Matter::query()->listableBy($lawyer)->count();

    expect($all)->toBe(20)
        ->and(Matter::query()->listableBy($admin)->count())->toBe($all)
        ->and(Matter::query()->listableBy($accountant)->count())->toBe($all)
        ->and($lawyerMatters)->toBeGreaterThan(0)->toBeLessThan($all)
        ->and($accountant->can('view', Matter::first()))->toBeFalse();

    Matter::query()->listableBy($lawyer)->get()
        ->each(fn (Matter $matter) => expect($lawyer->can('view', $matter))->toBeTrue());
});

it('shows a seeded client only their own matters and nothing internal', function () {
    $clientUser = ClientUser::where('email', 'khach1@example.com')->firstOrFail();
    $this->actingAs($clientUser, 'client');

    $matters = Matter::get();

    expect($matters)->not->toBeEmpty()
        ->and($matters->pluck('client_id')->unique()->all())->toBe([$clientUser->client_id])
        ->and(StageLog::where('is_published', false)->count())->toBe(0)
        ->and(Document::where('group', 'D')->count())->toBe(0);
});

it('never leaks an internal note into what the portal serializes', function () {
    $clientUser = ClientUser::where('email', 'khach1@example.com')->firstOrFail();
    $notes = StageLog::withoutGlobalScopes()->whereNotNull('internal_note')->pluck('internal_note');

    $this->actingAs($clientUser, 'client');
    $payload = json_encode(Matter::with(['stageLogs', 'client', 'checklistItems'])->get()->toArray(), JSON_UNESCAPED_UNICODE);

    expect($notes)->not->toBeEmpty();
    $notes->each(fn (string $note) => expect($payload)->not->toContain($note));
});
