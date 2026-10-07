<?php

use App\Actions\Intake\FindIntakeDuplicates;
use App\Actions\Intake\RecordIntake;
use App\Enums\Confidentiality;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\ClientLookupThrottle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function dupStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

function dupRecord(User $actor, array $overrides = []): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Trần Thị Lan',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides])->intake;
}

it('finds an earlier record by the same phone, whichever way it was typed', function () {
    $actor = dupStaff();
    $first = dupRecord($actor, ['contact_name' => 'Lần một']);

    foreach (['0832270898', '+84832270898', '84832270898', '(+84) 832 270 898'] as $form) {
        $found = app(FindIntakeDuplicates::class)->handle($actor, $form, null, 'Tên khác hẳn');

        expect($found->sameIdentity->pluck('id')->all())->toBe([$first->id], "dạng {$form}")
            // Bản duy nhất khớp là bản người này XEM ĐƯỢC: không có gì 'ẩn' để báo cờ.
            ->and($found->hasHiddenSameIdentity)->toBeFalse();
    }
});

it('reports the duplicates of a fresh recording, excluding the record itself', function () {
    $actor = dupStaff();
    $first = dupRecord($actor, ['contact_name' => 'Lần một']);

    $second = app(RecordIntake::class)->handle($actor, [
        'contact_name' => 'Lần hai', 'contact_phone' => '+84 832 270 898', 'source' => 'zalo',
    ]);

    expect($second->duplicates->sameIdentity->pluck('id')->all())->toBe([$first->id])
        ->and($second->duplicates->sameIdentity->contains($second->intake))->toBeFalse();
});

it('finds an earlier record by the same id number hash', function () {
    $actor = dupStaff();
    $first = dupRecord($actor, ['contact_phone' => '0901000111', 'contact_id_number' => '079012345678']);

    $found = app(FindIntakeDuplicates::class)->handle($actor, '0999888777', '079-012-345-678', 'Tên khác hẳn');

    expect($found->sameIdentity->pluck('id')->all())->toBe([$first->id]);
});

it('never suggests a record by name to someone who cannot see every record', function () {
    $assistant = dupStaff();
    dupRecord($assistant, ['contact_name' => 'Nguyễn Văn Tú', 'contact_phone' => '0955000111']);

    $asAssistant = app(FindIntakeDuplicates::class)->handle($assistant, '0955000999', null, 'nguyen van tu');

    expect($asAssistant->sameName)->toBeEmpty()
        ->and($asAssistant->sameIdentity)->toBeEmpty();
});

it('suggests a record by name to someone with intake.viewAny, but not when the phone already matched', function () {
    $assistant = dupStaff();
    $byPhone = dupRecord($assistant, ['contact_name' => 'Nguyễn Văn Tú', 'contact_phone' => '0955000111']);
    $byNameOnly = dupRecord($assistant, ['contact_name' => 'Nguyễn Văn Tú', 'contact_phone' => '0955000222']);
    $manager = dupStaff(Role::Manager);

    $found = app(FindIntakeDuplicates::class)->handle($manager, '0955000111', null, 'NGUYỄN VĂN TÚ');

    expect($found->sameIdentity->pluck('id')->all())->toBe([$byPhone->id])
        ->and($found->sameName->pluck('id')->all())->toBe([$byNameOnly->id]);
});

it('shows a record the actor cannot see only as a flag, with no code and no count', function () {
    $owner = dupStaff();
    dupRecord($owner, ['contact_phone' => '0955000111']);
    $other = dupStaff();

    $found = app(FindIntakeDuplicates::class)->handle($other, '0955000111', null, 'Người khác');

    expect($found->sameIdentity)->toBeEmpty()
        ->and($found->hasHiddenSameIdentity)->toBeTrue()
        ->and($found->isEmpty())->toBeFalse();
});

it('does not turn a converted intake into a flag: that would reveal a client of a restricted matter', function () {
    $owner = dupStaff();
    $intake = dupRecord($owner, ['contact_phone' => '0955000111']);
    $matter = Matter::factory()->create(['confidentiality' => Confidentiality::Restricted]);
    $intake->forceFill(['status' => IntakeStatus::Won, 'matter_id' => $matter->id])->save();

    $found = app(FindIntakeDuplicates::class)->handle(dupStaff(), '0955000111', null, 'Người khác');

    expect($found->hasHiddenSameIdentity)->toBeFalse()
        ->and($found->sameIdentity)->toBeEmpty();
});

it('ignores merged and anonymised records, and the record named as excluded', function () {
    $actor = dupStaff();
    $merged = dupRecord($actor, ['contact_name' => 'Đã gộp']);
    $merged->forceFill(['status' => IntakeStatus::Merged, 'merged_into_id' => dupRecord($actor, ['contact_phone' => '0900000001'])->id])->save();
    $anonymised = dupRecord($actor, ['contact_name' => 'Đã ẩn danh']);
    $anonymised->forceFill(['anonymised_at' => now()])->save();
    $kept = dupRecord($actor, ['contact_name' => 'Còn mở']);

    $found = app(FindIntakeDuplicates::class)->handle($actor, '0832270898', null, 'x');
    expect($found->sameIdentity->pluck('id')->all())->toBe([$kept->id]);

    $excluding = app(FindIntakeDuplicates::class)->handle($actor, '0832270898', null, 'x', $kept->id);
    expect($excluding->sameIdentity)->toBeEmpty()->and($excluding->hasHiddenSameIdentity)->toBeFalse();
});

it('only says that a phone belongs to a client of the office: no name, no code, no list', function () {
    $client = Client::factory()->create(['phone' => '0912000111', 'name' => 'Khách Hàng Đã Có']);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();
    $actor = dupStaff(Role::Manager);

    $found = app(FindIntakeDuplicates::class)->handle($actor, '0912 000 111', null, 'Ai đó');

    expect($found->isExistingClient)->toBeTrue()
        ->and($found->sameIdentity)->toBeEmpty()
        ->and(json_encode($found, JSON_UNESCAPED_UNICODE))->not->toContain('Khách Hàng Đã Có');

    // Một số gần đúng (sai một chữ số) trả lời y hệt một số không tồn tại.
    expect(app(FindIntakeDuplicates::class)->handle($actor, '0912000112', null, 'Ai đó')->isExistingClient)->toBeFalse();
});

it('answers "no" for a client whose only matter is restricted and not visible to the asker', function () {
    $client = Client::factory()->create(['phone' => '0912000111']);
    $lead = dupStaff(Role::Lawyer);
    $restricted = Matter::factory()->create(['confidentiality' => Confidentiality::Restricted, 'lead_lawyer_id' => $lead->id, 'client_id' => $client->id]);
    MatterParty::factory()->for($restricted)->ourClient($client)->create();

    $otherLawyer = dupStaff(Role::Lawyer);

    $found = app(FindIntakeDuplicates::class)->handle($otherLawyer, '0912000111', null, 'Ai đó');

    expect($found->isExistingClient)->toBeFalse();
});

it('shares the client-lookup throttle, and turns the hint off instead of failing the recording', function () {
    $actor = dupStaff();
    RateLimiter::clear(ClientLookupThrottle::keyFor($actor));

    for ($i = 0; $i < ClientLookupThrottle::MAX_ATTEMPTS; $i++) {
        ClientLookupThrottle::hit($actor);
    }

    $found = app(FindIntakeDuplicates::class)->handle($actor, '0912000111', null, 'Ai đó');

    expect($found->clientLookupUnavailable)->toBeTrue()
        ->and($found->isExistingClient)->toBeFalse()
        ->and(Activity::query()->where('event', 'client_lookup_throttled')->count())->toBe(1);

    // Việc ghi nhận vẫn thành công khi gợi ý bị tắt.
    $result = app(RecordIntake::class)->handle($actor, ['contact_name' => 'Vẫn ghi được', 'contact_phone' => '0912000111', 'source' => 'phone']);
    expect($result->intake->exists)->toBeTrue()
        ->and($result->duplicates->clientLookupUnavailable)->toBeTrue();
});

it('does not look up a client when no phone or id number was given', function () {
    $actor = dupStaff();

    $found = app(FindIntakeDuplicates::class)->handle($actor, null, null, 'Chỉ có tên');

    expect($found->isEmpty())->toBeTrue()
        ->and(Activity::query()->where('event', 'client_lookup')->count())->toBe(0);
});

it('is for someone who may record a contact', function () {
    expect(fn () => app(FindIntakeDuplicates::class)->handle(dupStaff(Role::Accountant), '0832270898', null, 'x'))
        ->toThrow(AuthorizationException::class);
});

it('finds a client by id number when the phone is unknown, and still throttles that path', function () {
    $client = Client::factory()->create(['phone' => '0912000111', 'id_number' => '079012345678', 'name' => 'Khách Có Căn Cước']);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();
    $actor = dupStaff(Role::Manager);

    $found = app(FindIntakeDuplicates::class)->handle($actor, null, '079 012 345 678', 'Ai đó');

    expect($found->isExistingClient)->toBeTrue()
        ->and(Activity::query()->where('event', 'client_lookup')->count())->toBe(1);
});

it('does not suggest by name a record that became a restricted matter the asker cannot see', function () {
    $assistant = dupStaff();
    $converted = dupRecord($assistant, ['contact_name' => 'Nguyễn Văn Tú', 'contact_phone' => '0955000111']);
    $lead = dupStaff(Role::Lawyer);
    $restricted = Matter::factory()->create(['confidentiality' => Confidentiality::Restricted, 'lead_lawyer_id' => $lead->id]);
    $converted->forceFill(['status' => IntakeStatus::Won, 'matter_id' => $restricted->id])->save();
    $open = dupRecord($assistant, ['contact_name' => 'Nguyễn Văn Tú', 'contact_phone' => '0955000222']);

    // Quản lý có `intake.viewAny` nhưng không phụ trách vụ restricted: chỉ thấy bản còn mở.
    $found = app(FindIntakeDuplicates::class)->handle(dupStaff(Role::Manager), '0955000999', null, 'Nguyễn Văn Tú');

    expect($found->sameName->pluck('id')->all())->toBe([$open->id]);
});
